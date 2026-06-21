<?php

namespace Cosmos\LaravelMonitor\Storage\ClickHouse;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\TelemetryQuery;

/**
 * Created to store production telemetry in ClickHouse with typed filter columns, JSON payloads, TTL, and rollup reads.
 */
class ClickHouseTelemetryRepository implements TelemetryRepository
{
    protected array $config;

    public function __construct(
        protected ClickHouseClient $client,
        array $config
    ) {
        $this->config = $config;
    }

    public function applySettings(array $settings): void
    {
        foreach (['retention', 'sampling', 'thresholds', 'notifications', 'limits', 'storage', 'storage_monitor', 'external_services', 'capture', 'clickhouse'] as $key) {
            if (isset($settings[$key]) && is_array($settings[$key])) {
                $this->config[$key] = array_replace_recursive((array) ($this->config[$key] ?? []), $settings[$key]);
            }
        }
    }

    public function recordEvent(string $stream, array $payload, ?int $timestampMs = null): string
    {
        return $this->record($stream, 'event', $payload, $timestampMs);
    }

    public function recordAggregate(string $stream, array $payload, ?int $timestampMs = null): void
    {
        $this->record($stream, 'aggregate', $payload, $timestampMs);
    }

    public function listEvents(string $stream, array $filters = []): array
    {
        $stream = $this->normalizeStream($stream);
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - ($this->retentionDays() * 86400 * 1000));
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), (int) ($this->config['limits']['max_page_size'] ?? 100));
        $cursor = TelemetryQuery::timestampMs($filters['cursor'] ?? null);
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($cursor !== null) {
            if ($order === 'desc') {
                $to = min($to, $cursor - 1);
            } else {
                $from = max($from, $cursor + 1);
            }
        }

        $where = $this->whereForEvents($stream, (int) $from, (int) $to, $filters);
        $sortColumn = $this->sortColumn((string) ($filters['sort'] ?? 'timestamp_ms'));
        $limit = $cursor !== null ? $perPage + 1 : $perPage;
        $offset = $cursor !== null ? 0 : ($page - 1) * $perPage;

        try {
            $rows = $this->client->select(sprintf(
                'SELECT payload_json, timestamp_ms FROM %s WHERE %s ORDER BY %s %s, id %s LIMIT %d OFFSET %d',
                $this->client->qualifiedTable('cosmos_events'),
                implode(' AND ', $where),
                $sortColumn,
                strtoupper($order),
                strtoupper($order),
                $limit,
                $offset
            ));

            $totalRows = $this->client->select(sprintf(
                'SELECT count() AS total FROM %s WHERE %s',
                $this->client->qualifiedTable('cosmos_events'),
                implode(' AND ', $where)
            ));

            $total = (int) ($totalRows[0]['total'] ?? 0);
            $hasMore = count($rows) > $perPage;
            $rows = array_slice($rows, 0, $perPage);
            $events = array_map(fn (array $row): array => $this->eventFromRow($row), $rows);
            $last = end($events) ?: null;
            $nextCursor = $hasMore && $last !== null ? (string) ($last['timestamp_ms'] ?? null) : null;

            return [
                'data' => array_values($events),
                'meta' => [
                    'stream' => $stream,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'scanned' => count($rows),
                    'scan_limit' => (int) ($filters['scan_limit'] ?? $limit),
                    'cursor' => $filters['cursor'] ?? null,
                    'next_cursor' => $nextCursor,
                    'has_more' => $hasMore || ($cursor === null && $total > ($offset + count($rows))),
                    'indexed' => true,
                    'source' => 'clickhouse',
                ],
                'links' => [
                    'next' => $nextCursor,
                ],
            ];
        } catch (\Throwable $exception) {
            $this->handleFailure('list_read_failed', $exception, $stream);

            return [
                'data' => [],
                'meta' => [
                    'stream' => $stream,
                    'error' => 'telemetry_unavailable',
                    'source' => 'clickhouse',
                ],
                'links' => [],
            ];
        }
    }

    public function summary(array $streams, array $filters = []): array
    {
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - 3600000);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $summary = [];

        foreach ($streams as $stream) {
            $stream = $this->normalizeStream((string) $stream);

            try {
                $where = $this->whereForEvents($stream, (int) $from, (int) $to, $filters);
                $rows = $this->client->select(sprintf(
                    'SELECT count() AS total FROM %s WHERE %s',
                    $this->client->qualifiedTable('cosmos_events'),
                    implode(' AND ', $where)
                ));

                $summary[$stream] = [
                    'count' => (int) ($rows[0]['total'] ?? 0),
                    'latest' => $this->listEvents($stream, array_merge($filters, ['page' => 1, 'per_page' => 1]))['data'][0] ?? null,
                ];
            } catch (\Throwable $exception) {
                $this->handleFailure('summary_read_failed', $exception, $stream);
                $summary[$stream] = ['count' => 0, 'latest' => null, 'error' => 'telemetry_unavailable'];
            }
        }

        return $summary;
    }

    public function timeseries(string $stream, array $filters = []): array
    {
        $stream = $this->normalizeStream($stream);
        $interval = in_array(($filters['interval'] ?? 'minute'), ['minute', 'hour'], true) ? $filters['interval'] : 'minute';
        $bucketSize = $interval === 'hour' ? 3600000 : 60000;
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, $this->nowMs() - 3600000);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, $this->nowMs());
        $from = $this->boundedTimeseriesFrom((int) $from, (int) $to, $bucketSize);
        $breakdown = $this->breakdownField($filters['breakdown'] ?? null);
        $points = $this->emptyPoints((int) $from, (int) $to, $bucketSize);

        try {
            $timestampExpression = $this->bucketTimestampExpression($interval);
            $rows = $this->client->select(sprintf(
                'SELECT %s AS timestamp_ms, sum(count) AS count, sum(duration_ms_sum) AS duration_ms_sum, sum(duration_ms_count) AS duration_ms_count FROM %s WHERE stream = %s AND bucket >= fromUnixTimestamp64Milli(%d) AND bucket <= fromUnixTimestamp64Milli(%d) AND breakdown_field = %s GROUP BY timestamp_ms ORDER BY timestamp_ms ASC',
                $timestampExpression,
                $this->client->qualifiedTable('cosmos_rollups_minute'),
                $this->client->quoteString($stream),
                (int) $from,
                (int) $to,
                $this->client->quoteString('')
            ));

            foreach ($rows as $row) {
                $timestamp = (int) ($row['timestamp_ms'] ?? 0);
                if (! isset($points[$timestamp])) {
                    continue;
                }

                $durationCount = (int) ($row['duration_ms_count'] ?? 0);
                $durationSum = (float) ($row['duration_ms_sum'] ?? 0);
                $points[$timestamp]['count'] = (int) ($row['count'] ?? 0);
                $points[$timestamp]['avg_duration_ms'] = $durationCount > 0 ? round($durationSum / $durationCount, 2) : null;
            }

            $this->applyDurationBuckets($points, $stream, $interval, (int) $from, (int) $to);

            if ($breakdown !== null) {
                $this->applyBreakdown($points, $stream, $breakdown, $interval, (int) $from, (int) $to);
            }
        } catch (\Throwable $exception) {
            $this->handleFailure('timeseries_read_failed', $exception, $stream);
        }

        return array_values($points);
    }

    public function prune(?int $nowMs = null): array
    {
        return [
            'driver' => 'clickhouse',
            'retention' => 'ttl',
            'retention_days' => $this->retentionDays(),
            'message' => 'ClickHouse TTL deletes expired telemetry during table merges.',
        ];
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    protected function record(string $stream, string $kind, array $payload, ?int $timestampMs = null): string
    {
        $timestampMs ??= $this->nowMs();
        $stream = $this->normalizeStream($stream);
        $id = $this->eventId($stream, $timestampMs);
        $payload = $this->withCommonFields($payload, $stream, $id, $timestampMs);

        try {
            $this->client->insertJsonEachRow('cosmos_events', [$this->rowForPayload($payload, $kind)]);

            return $id;
        } catch (\Throwable $exception) {
            $this->handleFailure($kind . '_write_failed', $exception, $stream);

            if (! $this->writeFailOpen()) {
                throw $exception;
            }

            return 'dropped:' . $id;
        }
    }

    protected function rowForPayload(array $payload, string $kind): array
    {
        $status = $payload['status'] ?? null;
        $duration = $payload['duration_ms'] ?? null;

        return [
            'id' => (string) $payload['id'],
            'stream' => (string) $payload['stream'],
            'event_kind' => $kind,
            'timestamp' => $this->dateTime64((int) $payload['timestamp_ms']),
            'timestamp_ms' => (int) $payload['timestamp_ms'],
            'app_id' => (string) ($payload['app_id'] ?? ''),
            'environment' => (string) ($payload['environment'] ?? ''),
            'hostname' => (string) ($payload['hostname'] ?? ''),
            'method' => $this->nullableString($payload['method'] ?? null),
            'route' => $this->nullableString($payload['route'] ?? null),
            'status' => $this->nullableString($status),
            'status_family' => $this->nullableString($payload['status_family'] ?? (is_numeric($status) ? ((int) floor(((int) $status) / 100)) . 'xx' : null)),
            'level' => $this->nullableString($payload['level'] ?? null),
            'queue' => $this->nullableString($payload['queue'] ?? null),
            'job' => $this->nullableString($payload['job'] ?? null),
            'hash' => $this->nullableString($payload['hash'] ?? null),
            'event' => $this->nullableString($payload['event'] ?? null),
            'connection' => $this->nullableString($payload['connection'] ?? null),
            'category' => $this->nullableString($payload['category'] ?? null),
            'disk' => $this->nullableString($payload['disk'] ?? null),
            'service_id' => $this->nullableString($payload['service_id'] ?? null),
            'service_name' => $this->nullableString($payload['service_name'] ?? null),
            'host' => $this->nullableString($payload['host'] ?? null),
            'source' => $this->nullableString($payload['source'] ?? null),
            'mailer' => $this->nullableString($payload['mailer'] ?? null),
            'transport' => $this->nullableString($payload['transport'] ?? null),
            'recipient_domain' => $this->nullableString($payload['recipient_domain'] ?? null),
            'duration_ms' => is_numeric($duration) ? (float) $duration : null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    protected function whereForEvents(string $stream, int $from, int $to, array $filters): array
    {
        $where = [
            'event_kind = ' . $this->client->quoteString('event'),
            'stream = ' . $this->client->quoteString($stream),
            'timestamp_ms >= ' . $from,
            'timestamp_ms <= ' . $to,
        ];

        foreach ($this->filterColumns() as $filter => $column) {
            if (($filters[$filter] ?? null) !== null) {
                $where[] = $column . ' = ' . $this->typedValue((string) $column, $filters[$filter]);
            }
        }

        if (($filters['min_duration'] ?? null) !== null) {
            $where[] = 'duration_ms >= ' . (float) $filters['min_duration'];
        }

        if (($filters['search'] ?? null) !== null) {
            $where[] = 'positionCaseInsensitive(payload_json, ' . $this->client->quoteString((string) $filters['search']) . ') > 0';
        }

        return $where;
    }

    protected function filterColumns(): array
    {
        return [
            'level' => 'level',
            'status' => 'status',
            'status_family' => 'status_family',
            'queue' => 'queue',
            'job' => 'job',
            'route' => 'route',
            'method' => 'method',
            'hash' => 'hash',
            'event' => 'event',
            'connection' => 'connection',
            'category' => 'category',
            'disk' => 'disk',
            'service_id' => 'service_id',
            'service_name' => 'service_name',
            'host' => 'host',
            'source' => 'source',
            'mailer' => 'mailer',
            'transport' => 'transport',
            'recipient_domain' => 'recipient_domain',
        ];
    }

    protected function typedValue(string $column, mixed $value): string
    {
        return $this->client->quoteString((string) $value);
    }

    protected function sortColumn(string $sort): string
    {
        return in_array($sort, ['timestamp_ms', 'duration_ms', 'status', 'level', 'route', 'method', 'host', 'mailer', 'transport'], true)
            ? $sort
            : 'timestamp_ms';
    }

    protected function eventFromRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $payload = is_array($payload) ? $payload : [];

        if (! isset($payload['timestamp_ms']) && isset($row['timestamp_ms'])) {
            $payload['timestamp_ms'] = (int) $row['timestamp_ms'];
        }

        return $payload;
    }

    protected function applyDurationBuckets(array &$points, string $stream, string $interval, int $from, int $to): void
    {
        $timestampExpression = $this->bucketTimestampExpression($interval);
        $rows = $this->client->select(sprintf(
            'SELECT %s AS timestamp_ms, breakdown_value AS duration_bucket, sum(count) AS count FROM %s WHERE stream = %s AND bucket >= fromUnixTimestamp64Milli(%d) AND bucket <= fromUnixTimestamp64Milli(%d) AND breakdown_field = %s GROUP BY timestamp_ms, duration_bucket ORDER BY timestamp_ms ASC',
            $timestampExpression,
            $this->client->qualifiedTable('cosmos_rollups_minute'),
            $this->client->quoteString($stream),
            $from,
            $to,
            $this->client->quoteString('duration_bucket')
        ));

        foreach ($rows as $row) {
            $timestamp = (int) ($row['timestamp_ms'] ?? 0);
            $bucket = (string) ($row['duration_bucket'] ?? '');

            if ($bucket !== '' && isset($points[$timestamp]['duration_buckets'][$bucket])) {
                $points[$timestamp]['duration_buckets'][$bucket] = (int) ($row['count'] ?? 0);
            }
        }
    }

    protected function applyBreakdown(array &$points, string $stream, string $breakdown, string $interval, int $from, int $to): void
    {
        $timestampExpression = $this->bucketTimestampExpression($interval);
        $rows = $this->client->select(sprintf(
            'SELECT %s AS timestamp_ms, breakdown_value, sum(count) AS count FROM %s WHERE stream = %s AND bucket >= fromUnixTimestamp64Milli(%d) AND bucket <= fromUnixTimestamp64Milli(%d) AND breakdown_field = %s GROUP BY timestamp_ms, breakdown_value ORDER BY timestamp_ms ASC',
            $timestampExpression,
            $this->client->qualifiedTable('cosmos_rollups_minute'),
            $this->client->quoteString($stream),
            $from,
            $to,
            $this->client->quoteString($breakdown)
        ));

        foreach ($rows as $row) {
            $timestamp = (int) ($row['timestamp_ms'] ?? 0);
            if (! isset($points[$timestamp])) {
                continue;
            }

            $points[$timestamp]['breakdown'][(string) ($row['breakdown_value'] ?? '')] = (int) ($row['count'] ?? 0);
        }
    }

    protected function emptyPoints(int $from, int $to, int $bucketSize): array
    {
        $points = [];
        $start = (int) floor($from / $bucketSize) * $bucketSize;

        for ($bucket = $start; $bucket <= $to; $bucket += $bucketSize) {
            $points[$bucket] = [
                'timestamp_ms' => $bucket,
                'count' => 0,
                'avg_duration_ms' => null,
                'breakdown' => [],
                'duration_buckets' => $this->emptyDurationBuckets(),
            ];
        }

        return $points;
    }

    protected function bucketTimestampExpression(string $interval): string
    {
        return $interval === 'hour'
            ? 'toUnixTimestamp(toStartOfHour(bucket)) * 1000'
            : 'toUnixTimestamp64Milli(bucket)';
    }

    protected function emptyDurationBuckets(): array
    {
        return [
            '0-50ms' => 0,
            '50-100ms' => 0,
            '100-250ms' => 0,
            '250-500ms' => 0,
            '500ms-1s' => 0,
            '1s-2.5s' => 0,
            '2.5s-5s' => 0,
            '5s+' => 0,
        ];
    }

    protected function breakdownField(mixed $field): ?string
    {
        $field = is_string($field) ? $field : null;

        return in_array($field, ['status', 'status_family', 'host', 'mailer', 'transport'], true) ? $field : null;
    }

    protected function boundedTimeseriesFrom(int $from, int $to, int $bucketSize): int
    {
        $maxPoints = max(1, (int) ($this->config['limits']['max_timeseries_points'] ?? 720));
        $maxWindow = ($maxPoints - 1) * $bucketSize;

        return max($from, $to - $maxWindow);
    }

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

    protected function handleFailure(string $event, \Throwable $exception, string $stream): void
    {
        if (! $this->writeFailOpen()) {
            throw $exception;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        return $value === null || is_array($value) || is_object($value) ? null : (string) $value;
    }

    protected function normalizeStream(string $stream): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '-', strtolower($stream)) ?: 'unknown';
    }

    protected function dateTime64(int $timestampMs): string
    {
        return gmdate('Y-m-d H:i:s', (int) floor($timestampMs / 1000)) . sprintf('.%03d', $timestampMs % 1000);
    }

    protected function retentionDays(): int
    {
        return max(1, (int) ($this->config['clickhouse']['retention_days'] ?? 30));
    }

    protected function writeFailOpen(): bool
    {
        return (bool) ($this->config['storage']['write_fail_open'] ?? true);
    }

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    protected function eventId(string $stream, int $timestampMs): string
    {
        return $stream . ':' . $timestampMs . ':' . bin2hex(random_bytes(8));
    }
}
