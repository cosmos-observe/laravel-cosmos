<?php

namespace Cosmos\LaravelMonitor\Storage;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\TelemetryQuery;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Created to store production telemetry in Redis with bounded raw data, rollups, cursor reads, and secondary indexes.
 */
class RedisTelemetryRepository implements TelemetryRepository
{
    public const STREAMS = [
        'requests',
        'queues',
        'jobs',
        'logs',
        'exceptions',
        'schedules',
        'database',
        'performance',
        'notifications',
        'cache',
        'monitor',
    ];

    /**
     * Created to keep Redis access and package configuration available to every repository operation.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected array $config
    ) {
    }

    /**
     * Created to store one event in Redis while failing open when production Redis telemetry is unavailable.
     */
    public function recordEvent(string $stream, array $payload, ?int $timestampMs = null): string
    {
        return $this->recordEventInternal($stream, $payload, $timestampMs, true);
    }

    /**
     * Created to let low-frequency commands apply durable DB settings without adding DB queries to every telemetry write.
     */
    public function applySettings(array $settings): void
    {
        foreach (['retention', 'sampling', 'thresholds', 'notifications', 'limits', 'storage'] as $key) {
            if (isset($settings[$key]) && is_array($settings[$key])) {
                $this->config[$key] = array_replace_recursive((array) ($this->config[$key] ?? []), $settings[$key]);
            }
        }
    }

    /**
     * Created to update minute and hour rollups for dashboards without retaining every raw event forever.
     */
    public function recordAggregate(string $stream, array $payload, ?int $timestampMs = null): void
    {
        $timestampMs ??= $this->nowMs();
        $stream = $this->normalizeStream($stream);

        try {
            $connection = $this->connection();

            foreach (['minute' => 60000, 'hour' => 3600000] as $bucketName => $bucketSize) {
                $bucket = (int) floor($timestampMs / $bucketSize) * $bucketSize;
                $key = $this->rollupKey($stream, $bucketName, $bucket);

                $connection->hincrby($key, 'count', 1);

                if (isset($payload['duration_ms']) && is_numeric($payload['duration_ms'])) {
                    $connection->hincrbyfloat($key, 'duration_ms_sum', (float) $payload['duration_ms']);
                    $connection->hincrby($key, 'duration_ms_count', 1);
                }

                foreach ($this->dimensions($payload) as $dimension => $value) {
                    $connection->hincrby($key, $dimension . ':' . $value, 1);
                }

                $connection->zadd($this->rollupIndexKey($stream, $bucketName), $bucket, $key);
                $connection->expire($this->rollupIndexKey($stream, $bucketName), $this->rollupRetentionSeconds() + 86400);
                $connection->expire($key, $this->rollupRetentionSeconds() + 86400);
            }
        } catch (\Throwable $exception) {
            $this->handleRedisFailure('aggregate_write_failed', $exception, $stream);
        }
    }

    /**
     * Created to retrieve telemetry with bounded scanning, cursor pagination, secondary indexes, and legacy page metadata.
     */
    public function listEvents(string $stream, array $filters = []): array
    {
        $stream = $this->normalizeStream($stream);
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - ($this->rawRetentionSeconds() * 1000));
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), (int) ($this->config['limits']['max_page_size'] ?? 100));
        $cursor = TelemetryQuery::timestampMs($filters['cursor'] ?? null);
        $minimumScanSize = $cursor !== null ? $perPage : $page * $perPage;
        $scanLimit = min(max((int) ($filters['scan_limit'] ?? $this->queryBatchSize()), $minimumScanSize), $this->maxFilterScanSize());
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $key = $this->bestReadKey($stream, $filters) ?? $this->rawKey($stream);

        if ($cursor !== null) {
            if ($order === 'desc') {
                $to = min($to, $cursor - 1);
            } else {
                $from = max($from, $cursor + 1);
            }
        }

        try {
            $ids = $this->rangeByScore($key, $from, $to, $order, 0, $scanLimit);
            $events = [];
            $lastTimestamp = null;

            foreach ($ids as $id) {
                $event = $this->eventById($stream, (string) $id);

                if (! is_array($event)) {
                    continue;
                }

                $lastTimestamp = (int) ($event['timestamp_ms'] ?? $this->timestampFromId((string) $id));

                if (! $this->matchesFilters($event, $filters)) {
                    continue;
                }

                $events[] = $event;
            }

            $events = $this->sortEvents($events, (string) ($filters['sort'] ?? 'timestamp_ms'), $order);
            $total = count($events);
            $data = $cursor !== null ? array_slice($events, 0, $perPage) : array_slice($events, ($page - 1) * $perPage, $perPage);
            $hasMore = count($ids) >= $scanLimit;
            $nextCursor = $hasMore && $lastTimestamp !== null ? (string) $lastTimestamp : null;

            return [
                'data' => array_values($data),
                'meta' => [
                    'stream' => $stream,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'scanned' => count($ids),
                    'scan_limit' => $scanLimit,
                    'cursor' => $filters['cursor'] ?? null,
                    'next_cursor' => $nextCursor,
                    'has_more' => $hasMore,
                    'indexed' => $key !== $this->rawKey($stream),
                ],
                'links' => [
                    'next' => $nextCursor,
                ],
            ];
        } catch (\Throwable $exception) {
            $this->handleRedisFailure('list_read_failed', $exception, $stream);

            return [
                'data' => [],
                'meta' => [
                    'stream' => $stream,
                    'error' => 'telemetry_unavailable',
                ],
                'links' => [],
            ];
        }
    }

    /**
     * Created to calculate stream totals and latest events for dashboard summary cards.
     */
    public function summary(array $streams, array $filters = []): array
    {
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - 3600000);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $summary = [];

        foreach ($streams as $stream) {
            $stream = $this->normalizeStream((string) $stream);

            try {
                $summary[$stream] = [
                    'count' => (int) $this->connection()->zcount($this->rawKey($stream), (string) $from, (string) $to),
                    'latest' => $this->listEvents($stream, array_merge($filters, ['page' => 1, 'per_page' => 1]))['data'][0] ?? null,
                ];
            } catch (\Throwable $exception) {
                $this->handleRedisFailure('summary_read_failed', $exception, $stream);
                $summary[$stream] = ['count' => 0, 'latest' => null, 'error' => 'telemetry_unavailable'];
            }
        }

        return $summary;
    }

    /**
     * Created to read minute or hour rollups into chart-ready time buckets.
     */
    public function timeseries(string $stream, array $filters = []): array
    {
        $stream = $this->normalizeStream($stream);
        $interval = in_array(($filters['interval'] ?? 'minute'), ['minute', 'hour'], true) ? $filters['interval'] : 'minute';
        $bucketSize = $interval === 'hour' ? 3600000 : 60000;
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - 3600000);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $from = $this->boundedTimeseriesFrom((int) $from, (int) $to, $bucketSize);
        $points = [];

        try {
            for ($bucket = (int) floor($from / $bucketSize) * $bucketSize; $bucket <= $to; $bucket += $bucketSize) {
                $values = $this->connection()->hgetall($this->rollupKey($stream, $interval, $bucket));
                $count = (int) ($values['count'] ?? 0);
                $durationCount = (int) ($values['duration_ms_count'] ?? 0);
                $durationSum = (float) ($values['duration_ms_sum'] ?? 0);

                $points[] = [
                    'timestamp_ms' => $bucket,
                    'count' => $count,
                    'avg_duration_ms' => $durationCount > 0 ? round($durationSum / $durationCount, 2) : null,
                ];
            }
        } catch (\Throwable $exception) {
            $this->handleRedisFailure('timeseries_read_failed', $exception, $stream);
        }

        return $points;
    }

    /**
     * Created to remove old Redis payloads, indexes, and rollups in bounded batches so pruning stays production-safe.
     */
    public function prune(?int $nowMs = null): array
    {
        $nowMs ??= $this->nowMs();
        $rawCutoff = $nowMs - ($this->rawRetentionSeconds() * 1000);
        $rollupCutoff = $nowMs - ($this->rollupRetentionSeconds() * 1000);
        $deleted = [];

        foreach (self::STREAMS as $stream) {
            $deleted[$stream] = $this->pruneRawStream($stream, $rawCutoff);
            $this->pruneRollups($stream, $rollupCutoff);
        }

        $this->recordEventInternal('monitor', [
            'type' => 'monitor_self',
            'event' => 'prune_completed',
            'deleted' => $deleted,
        ], $nowMs, false);

        return $deleted;
    }

    /**
     * Created to let health checks confirm Redis connectivity without writing telemetry.
     */
    public function ping(): bool
    {
        try {
            $response = $this->connection()->ping();

            return $response === true || strtoupper((string) $response) === 'PONG' || $response === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Created to store one raw event, indexes, and rollups with optional self-health reporting.
     */
    protected function recordEventInternal(string $stream, array $payload, ?int $timestampMs = null, bool $recordHealth = true): string
    {
        $timestampMs ??= $this->nowMs();
        $stream = $this->normalizeStream($stream);
        $id = $this->eventId($stream, $timestampMs);
        $payload = $this->withCommonFields($payload, $stream, $id, $timestampMs);
        $encoded = $this->encodePayload($payload);

        try {
            $connection = $this->connection();
            $connection->zadd($this->rawKey($stream), $timestampMs, $id);
            $connection->hset($this->payloadKey($stream), $id, $encoded);
            $connection->expire($this->rawKey($stream), $this->rawRetentionSeconds() + 86400);
            $connection->expire($this->payloadKey($stream), $this->rawRetentionSeconds() + 86400);

            $this->indexEvent($stream, $id, $payload, $timestampMs);
            $this->recordAggregate($stream, $payload, $timestampMs);
            $this->enforceRawLimit($stream);

            return $id;
        } catch (\Throwable $exception) {
            if ($recordHealth) {
                $this->handleRedisFailure('event_write_failed', $exception, $stream);
            }

            if (! $this->writeFailOpen()) {
                throw $exception;
            }

            return 'dropped:' . $id;
        }
    }

    /**
     * Created to fetch the configured Redis connection from Laravel.
     */
    protected function connection(): mixed
    {
        return $this->redis->connection($this->config['redis_connection'] ?? null);
    }

    /**
     * Created to normalize stream names before they become Redis key segments.
     */
    protected function normalizeStream(string $stream): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '-', strtolower($stream)) ?: 'unknown';
    }

    /**
     * Created to build the Redis prefix with app, environment, and hostname tags.
     */
    protected function prefix(): string
    {
        $parts = [
            $this->config['key_prefix'] ?? 'cosmos-monitor',
            $this->config['app_id'] ?? 'laravel',
            $this->config['environment'] ?? 'production',
            $this->config['hostname'] ?? 'unknown',
        ];
        $normalized = [];

        foreach ($parts as $part) {
            $normalized[] = preg_replace('/[^a-z0-9_.-]/i', '-', (string) $part) ?: 'unknown';
        }

        return implode(':', $normalized);
    }

    /**
     * Created to keep raw sorted set key generation consistent across writes, reads, and pruning.
     */
    protected function rawKey(string $stream): string
    {
        return $this->prefix() . ':raw:' . $stream;
    }

    /**
     * Created to keep event payload hash key generation consistent across writes, reads, and pruning.
     */
    protected function payloadKey(string $stream): string
    {
        return $this->prefix() . ':payload:' . $stream;
    }

    /**
     * Created to keep rollup key generation deterministic for time-series reads and retention cleanup.
     */
    protected function rollupKey(string $stream, string $interval, int $bucket): string
    {
        return $this->prefix() . ':rollup:' . $interval . ':' . $stream . ':' . $bucket;
    }

    /**
     * Created to index rollup keys by bucket timestamp so pruning can delete exact stale rollups without Redis KEYS scans.
     */
    protected function rollupIndexKey(string $stream, string $interval): string
    {
        return $this->prefix() . ':rollup-index:' . $interval . ':' . $stream;
    }

    /**
     * Created to build secondary index keys for common API filters.
     */
    protected function indexKey(string $stream, string $field, mixed $value): string
    {
        return $this->prefix() . ':index:' . $stream . ':' . $field . ':' . $this->normalizeIndexValue($value);
    }

    /**
     * Created to remember secondary index keys so pruning can clean indexes without Redis KEYS scans.
     */
    protected function indexRegistryKey(string $stream): string
    {
        return $this->prefix() . ':index-registry:' . $stream;
    }

    /**
     * Created to include package-level identity tags on every raw event.
     */
    protected function withCommonFields(array $payload, string $stream, string $id, int $timestampMs): array
    {
        return array_merge($payload, [
            'id' => $id,
            'stream' => $stream,
            'timestamp_ms' => $timestampMs,
            'app_id' => $this->config['app_id'] ?? 'laravel',
            'environment' => $this->config['environment'] ?? 'production',
            'hostname' => $this->config['hostname'] ?? 'unknown',
        ]);
    }

    /**
     * Created to derive bounded rollup dimensions from known telemetry fields.
     */
    protected function dimensions(array $payload): array
    {
        $dimensions = [];

        foreach ($this->indexableFields() as $field) {
            if (! isset($payload[$field]) || is_array($payload[$field]) || is_object($payload[$field])) {
                continue;
            }

            $dimensions[$field] = $this->normalizeIndexValue($payload[$field]);
        }

        return $dimensions;
    }

    /**
     * Created to apply the shared query filters after bounded Redis scanning.
     */
    protected function matchesFilters(array $event, array $filters): bool
    {
        foreach ($this->indexableFields() as $field) {
            if (($filters[$field] ?? null) !== null && (string) ($event[$field] ?? '') !== (string) $filters[$field]) {
                return false;
            }
        }

        if (($filters['min_duration'] ?? null) !== null && (float) ($event['duration_ms'] ?? 0) < (float) $filters['min_duration']) {
            return false;
        }

        if (($filters['search'] ?? null) !== null) {
            $haystack = strtolower(json_encode($event, JSON_UNESCAPED_SLASHES) ?: '');

            if (! str_contains($haystack, strtolower((string) $filters['search']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Created to support API sorting on timestamp, duration, status, level, and other scalar event fields.
     */
    protected function sortEvents(array $events, string $field, string $order): array
    {
        /**
         * Created to compare two event payloads by the requested scalar sort field.
         */
        usort($events, function (array $left, array $right) use ($field, $order): int {
            $leftValue = $left[$field] ?? null;
            $rightValue = $right[$field] ?? null;
            $comparison = $leftValue <=> $rightValue;

            return $order === 'asc' ? $comparison : -$comparison;
        });

        return $events;
    }

    /**
     * Created to encode event payloads and optionally compress larger Redis payloads.
     */
    protected function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            $encoded = json_encode($this->withCommonFields(['message' => 'encoding failed'], $payload['stream'] ?? 'unknown', $payload['id'] ?? 'unknown', $payload['timestamp_ms'] ?? $this->nowMs())) ?: '{}';
        }

        if (! $this->payloadCompression() || strlen($encoded) < 1024) {
            return $encoded;
        }

        $compressed = gzencode($encoded, 4);

        return $compressed === false ? $encoded : 'gz:' . base64_encode($compressed);
    }

    /**
     * Created to decode plain or compressed Redis payloads transparently for API reads.
     */
    protected function decodePayload(string $encoded): ?array
    {
        if (str_starts_with($encoded, 'gz:')) {
            $decoded = base64_decode(substr($encoded, 3), true);
            $encoded = $decoded === false ? '' : (gzdecode($decoded) ?: '');
        }

        $payload = json_decode($encoded, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Created to fetch and decode a raw event payload by id.
     */
    protected function eventById(string $stream, string $id): ?array
    {
        $encoded = $this->connection()->hget($this->payloadKey($stream), $id);

        return is_string($encoded) ? $this->decodePayload($encoded) : null;
    }

    /**
     * Created to add secondary indexes for configured filter fields.
     */
    protected function indexEvent(string $stream, string $id, array $payload, int $timestampMs): void
    {
        foreach ($this->indexableFields() as $field) {
            if (! isset($payload[$field]) || is_array($payload[$field]) || is_object($payload[$field])) {
                continue;
            }

            $indexKey = $this->indexKey($stream, $field, $payload[$field]);
            $this->connection()->zadd($indexKey, $timestampMs, $id);
            $this->connection()->zadd($this->indexRegistryKey($stream), $timestampMs, $indexKey);
            $this->connection()->expire($indexKey, $this->rawRetentionSeconds() + 86400);
            $this->connection()->expire($this->indexRegistryKey($stream), $this->rawRetentionSeconds() + 86400);
        }
    }

    /**
     * Created to choose the most selective available secondary index for a filtered read.
     */
    protected function bestReadKey(string $stream, array $filters): ?string
    {
        foreach ($this->indexableFields() as $field) {
            if (($filters[$field] ?? null) !== null) {
                return $this->indexKey($stream, $field, $filters[$field]);
            }
        }

        return null;
    }

    /**
     * Created to read sorted set members by score with a bounded limit across Predis, PhpRedis, and the fake test client.
     */
    protected function rangeByScore(string $key, int $from, int $to, string $order, int $offset, int $count): array
    {
        $connection = $this->connection();

        try {
            if ($order === 'desc') {
                $ids = $connection->zrevrangebyscore($key, (string) $to, (string) $from, ['limit' => [$offset, $count]]);
            } else {
                $ids = $connection->zrangebyscore($key, (string) $from, (string) $to, ['limit' => [$offset, $count]]);
            }
        } catch (\Throwable) {
            $ids = $connection->zrangebyscore($key, (string) $from, (string) $to);
            $ids = is_array($ids) ? array_slice($order === 'desc' ? array_reverse($ids) : $ids, $offset, $count) : [];
        }

        return is_array($ids) ? $ids : [];
    }

    /**
     * Created to enforce a maximum raw event count per stream so Redis memory remains bounded even before time pruning runs.
     */
    protected function enforceRawLimit(string $stream): void
    {
        $max = (int) ($this->config['limits']['max_raw_events_per_stream'] ?? 250000);

        if ($max <= 0) {
            return;
        }

        $count = (int) $this->connection()->zcard($this->rawKey($stream));
        $overflow = $count - $max;

        if ($overflow <= 0) {
            return;
        }

        $ids = $this->connection()->zrange($this->rawKey($stream), 0, $overflow - 1);
        $ids = is_array($ids) ? array_map('strval', $ids) : [];
        $this->deleteRawIds($stream, $ids);
        $this->recordEventInternal('monitor', [
            'type' => 'monitor_self',
            'event' => 'raw_limit_enforced',
            'stream_name' => $stream,
            'deleted' => count($ids),
        ], null, false);
    }

    /**
     * Created to prune old raw stream entries in bounded batches.
     */
    protected function pruneRawStream(string $stream, int $rawCutoff): int
    {
        $deleted = 0;
        $batchSize = $this->pruneBatchSize();

        try {
            do {
                $ids = $this->rangeByScore($this->rawKey($stream), PHP_INT_MIN, $rawCutoff, 'asc', 0, $batchSize);

                if ($ids === []) {
                    break;
                }

                $deleted += count($ids);
                $this->deleteRawIds($stream, array_map('strval', $ids));
            } while (count($ids) === $batchSize);
        } catch (\Throwable $exception) {
            $this->handleRedisFailure('raw_prune_failed', $exception, $stream);
        }

        return $deleted;
    }

    /**
     * Created to delete raw ids from payload hashes, raw indexes, and secondary indexes.
     */
    protected function deleteRawIds(string $stream, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->connection()->hdel($this->payloadKey($stream), $id);
            $this->connection()->zrem($this->rawKey($stream), $id);
        }

        foreach ($this->indexKeysForStream($stream) as $indexKey) {
            foreach ($ids as $id) {
                $this->connection()->zrem($indexKey, $id);
            }
        }
    }

    /**
     * Created to delete rollup hash keys older than the configured rollup retention window.
     */
    protected function pruneRollups(string $stream, int $rollupCutoff): void
    {
        foreach (['minute', 'hour'] as $interval) {
            $indexKey = $this->rollupIndexKey($stream, $interval);

            try {
                do {
                    $keys = $this->rangeByScore($indexKey, PHP_INT_MIN, $rollupCutoff, 'asc', 0, $this->pruneBatchSize());

                    foreach ($keys as $key) {
                        $this->connection()->del((string) $key);
                        $this->connection()->zrem($indexKey, (string) $key);
                    }
                } while (count($keys) === $this->pruneBatchSize());
            } catch (\Throwable $exception) {
                $this->handleRedisFailure('rollup_prune_failed', $exception, $stream);
            }
        }
    }

    /**
     * Created to list registered secondary index keys for one stream.
     */
    protected function indexKeysForStream(string $stream): array
    {
        $keys = $this->connection()->zrange($this->indexRegistryKey($stream), 0, -1);

        return is_array($keys) ? array_values(array_unique(array_map('strval', $keys))) : [];
    }

    /**
     * Created to record monitor self-health when Redis operations fail but the package is configured to fail open.
     */
    protected function handleRedisFailure(string $event, \Throwable $exception, string $stream): void
    {
        if (! $this->writeFailOpen()) {
            throw $exception;
        }

        try {
            $this->recordEventInternal('monitor', [
                'type' => 'monitor_self',
                'event' => $event,
                'stream_name' => $stream,
                'message' => $exception->getMessage(),
            ], null, false);
        } catch (\Throwable) {
        }
    }

    /**
     * Created to normalize secondary-index values before they become Redis key segments.
     */
    protected function normalizeIndexValue(mixed $value): string
    {
        $normalized = preg_replace('/[^a-z0-9_.:-]/i', '-', (string) $value);

        return substr($normalized ?: 'unknown', 0, 120);
    }

    /**
     * Created to infer a timestamp from an event id when payload timestamp data is missing.
     */
    protected function timestampFromId(string $id): int
    {
        $parts = explode(':', $id);

        return isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : $this->nowMs();
    }

    /**
     * Created to keep raw retention reads consistent with config and database settings defaults.
     */
    protected function rawRetentionSeconds(): int
    {
        return max(60, (int) ($this->config['retention']['raw_seconds'] ?? 604800));
    }

    /**
     * Created to keep rollup retention reads consistent with config and database settings defaults.
     */
    protected function rollupRetentionSeconds(): int
    {
        return max(60, (int) ($this->config['retention']['rollup_seconds'] ?? 2592000));
    }

    /**
     * Created to read query batch size from config with safe production bounds.
     */
    protected function queryBatchSize(): int
    {
        return max(25, (int) ($this->config['limits']['query_batch_size'] ?? 250));
    }

    /**
     * Created to read the maximum filtered scan size from config with safe production bounds.
     */
    protected function maxFilterScanSize(): int
    {
        return max(100, (int) ($this->config['limits']['max_filter_scan_size'] ?? 5000));
    }

    /**
     * Created to read prune batch size from config with safe production bounds.
     */
    protected function pruneBatchSize(): int
    {
        return max(100, (int) ($this->config['limits']['prune_batch_size'] ?? 1000));
    }

    /**
     * Created to keep time-series reads bounded even when clients accidentally request epoch-scale windows.
     */
    protected function boundedTimeseriesFrom(int $from, int $to, int $bucketSize): int
    {
        $maxPoints = max(1, (int) ($this->config['limits']['max_timeseries_points'] ?? 720));
        $maxWindow = ($maxPoints - 1) * $bucketSize;

        return max($from, $to - $maxWindow);
    }

    /**
     * Created to read the fail-open storage flag from config.
     */
    protected function writeFailOpen(): bool
    {
        return (bool) ($this->config['storage']['write_fail_open'] ?? true);
    }

    /**
     * Created to read the payload compression flag from config.
     */
    protected function payloadCompression(): bool
    {
        return (bool) ($this->config['storage']['payload_compression'] ?? false);
    }

    /**
     * Created to read configured secondary index fields.
     */
    protected function indexableFields(): array
    {
        return (array) ($this->config['storage']['index_fields'] ?? ['level', 'status', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection']);
    }

    /**
     * Created to generate sortable millisecond timestamps without depending on Carbon in hot paths.
     */
    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /**
     * Created to generate collision-resistant event identifiers that still include stream and timestamp context.
     */
    protected function eventId(string $stream, int $timestampMs): string
    {
        return $stream . ':' . $timestampMs . ':' . bin2hex(random_bytes(8));
    }
}
