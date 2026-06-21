<?php

namespace Cosmos\LaravelMonitor\Tests\Fakes;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\TelemetryQuery;

/**
 * Created to exercise package feature tests through the telemetry contract without requiring ClickHouse.
 */
class FakeTelemetryRepository implements TelemetryRepository
{
    protected array $rows = [];

    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function applySettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (is_array($value) && is_array($this->config[$key] ?? null)) {
                $this->config[$key] = array_replace_recursive($this->config[$key], $value);
            } else {
                $this->config[$key] = $value;
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
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, 0);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, PHP_INT_MAX);
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), (int) ($this->config['limits']['max_page_size'] ?? 100));
        $cursor = TelemetryQuery::timestampMs($filters['cursor'] ?? null);
        $order = strtolower((string) ($filters['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($cursor !== null) {
            if ($order === 'desc') {
                $to = min((int) $to, $cursor - 1);
            } else {
                $from = max((int) $from, $cursor + 1);
            }
        }

        $events = array_values(array_filter($this->rows, function (array $row) use ($stream, $from, $to, $filters): bool {
            if ($row['_kind'] !== 'event' || $row['stream'] !== $stream) {
                return false;
            }

            if ((int) $row['timestamp_ms'] < (int) $from || (int) $row['timestamp_ms'] > (int) $to) {
                return false;
            }

            return $this->matchesFilters($row, $filters);
        }));

        $sort = (string) ($filters['sort'] ?? 'timestamp_ms');
        usort($events, function (array $left, array $right) use ($sort, $order): int {
            $comparison = ($left[$sort] ?? null) <=> ($right[$sort] ?? null);

            return $order === 'asc' ? $comparison : -$comparison;
        });

        $total = count($events);
        $data = $cursor !== null
            ? array_slice($events, 0, $perPage + 1)
            : array_slice($events, ($page - 1) * $perPage, $perPage);
        $hasMore = $cursor !== null ? count($data) > $perPage : $total > ($page * $perPage);
        $data = array_slice($data, 0, $perPage);
        $last = end($data) ?: null;
        $nextCursor = $hasMore && $last !== null ? (string) $last['timestamp_ms'] : null;

        return [
            'data' => array_map(fn (array $row): array => $this->withoutInternalFields($row), $data),
            'meta' => [
                'stream' => $stream,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'scanned' => count($data),
                'scan_limit' => (int) ($filters['scan_limit'] ?? $perPage),
                'cursor' => $filters['cursor'] ?? null,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
                'indexed' => true,
                'source' => 'fake',
            ],
            'links' => [
                'next' => $nextCursor,
            ],
        ];
    }

    public function summary(array $streams, array $filters = []): array
    {
        $summary = [];

        foreach ($streams as $stream) {
            $stream = $this->normalizeStream((string) $stream);
            $result = $this->listEvents($stream, array_merge($filters, ['page' => 1, 'per_page' => 1]));
            $summary[$stream] = [
                'count' => $result['meta']['total'] ?? 0,
                'latest' => $result['data'][0] ?? null,
            ];
        }

        return $summary;
    }

    public function timeseries(string $stream, array $filters = []): array
    {
        $stream = $this->normalizeStream($stream);
        $interval = in_array(($filters['interval'] ?? 'minute'), ['minute', 'hour'], true) ? $filters['interval'] : 'minute';
        $bucketSize = $interval === 'hour' ? 3600000 : 60000;
        $from = TelemetryQuery::timestampMs($filters['from'] ?? null, 0);
        $to = TelemetryQuery::timestampMs($filters['to'] ?? null, (int) floor(microtime(true) * 1000));
        $from = $this->boundedFrom((int) $from, (int) $to, $bucketSize);
        $points = [];

        for ($bucket = (int) floor($from / $bucketSize) * $bucketSize; $bucket <= $to; $bucket += $bucketSize) {
            $points[$bucket] = [
                'timestamp_ms' => $bucket,
                'count' => 0,
                'avg_duration_ms' => null,
                'breakdown' => [],
                'duration_buckets' => $this->emptyDurationBuckets(),
                '_duration_sum' => 0.0,
                '_duration_count' => 0,
            ];
        }

        foreach ($this->rows as $row) {
            if ($row['stream'] !== $stream || (int) $row['timestamp_ms'] < $from || (int) $row['timestamp_ms'] > $to) {
                continue;
            }

            $bucket = (int) floor(((int) $row['timestamp_ms']) / $bucketSize) * $bucketSize;

            if (! isset($points[$bucket])) {
                continue;
            }

            $points[$bucket]['count']++;

            if (isset($row['duration_ms']) && is_numeric($row['duration_ms'])) {
                $duration = (float) $row['duration_ms'];
                $points[$bucket]['_duration_sum'] += $duration;
                $points[$bucket]['_duration_count']++;
                $points[$bucket]['duration_buckets'][$this->durationBucket($duration)]++;
            }

            $breakdown = $filters['breakdown'] ?? null;
            if (is_string($breakdown) && isset($row[$breakdown]) && is_scalar($row[$breakdown])) {
                $value = (string) $row[$breakdown];
                $points[$bucket]['breakdown'][$value] = ($points[$bucket]['breakdown'][$value] ?? 0) + 1;
            }
        }

        return array_values(array_map(function (array $point): array {
            if ($point['_duration_count'] > 0) {
                $point['avg_duration_ms'] = round($point['_duration_sum'] / $point['_duration_count'], 2);
            }

            unset($point['_duration_sum'], $point['_duration_count']);

            return $point;
        }, $points));
    }

    public function prune(?int $nowMs = null): array
    {
        return [
            'driver' => 'fake',
            'retention' => 'ttl',
        ];
    }

    public function ping(): bool
    {
        return true;
    }

    protected function record(string $stream, string $kind, array $payload, ?int $timestampMs): string
    {
        $timestampMs ??= (int) floor(microtime(true) * 1000);
        $stream = $this->normalizeStream($stream);
        $id = $stream . ':' . $timestampMs . ':' . bin2hex(random_bytes(4));
        $status = $payload['status'] ?? null;

        $this->rows[] = array_merge($payload, [
            '_kind' => $kind,
            'id' => $id,
            'stream' => $stream,
            'timestamp_ms' => $timestampMs,
            'status_family' => $payload['status_family'] ?? (is_numeric($status) ? ((int) floor(((int) $status) / 100)) . 'xx' : null),
            'app_id' => $this->config['app_id'] ?? 'laravel',
            'environment' => $this->config['environment'] ?? 'testing',
            'hostname' => $this->config['hostname'] ?? 'test-host',
        ]);

        return $id;
    }

    protected function matchesFilters(array $row, array $filters): bool
    {
        foreach (['level', 'status', 'status_family', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection', 'category', 'disk', 'service_id', 'service_name', 'host', 'source', 'mailer', 'transport', 'recipient_domain'] as $field) {
            if (($filters[$field] ?? null) !== null && (string) ($row[$field] ?? '') !== (string) $filters[$field]) {
                return false;
            }
        }

        if (($filters['min_duration'] ?? null) !== null && (float) ($row['duration_ms'] ?? 0) < (float) $filters['min_duration']) {
            return false;
        }

        if (($filters['search'] ?? null) !== null) {
            $encoded = strtolower(json_encode($row, JSON_UNESCAPED_SLASHES) ?: '');

            if (! str_contains($encoded, strtolower((string) $filters['search']))) {
                return false;
            }
        }

        return true;
    }

    protected function withoutInternalFields(array $row): array
    {
        unset($row['_kind']);

        return $row;
    }

    protected function boundedFrom(int $from, int $to, int $bucketSize): int
    {
        $maxPoints = max(1, (int) ($this->config['limits']['max_timeseries_points'] ?? 720));

        return max($from, $to - (($maxPoints - 1) * $bucketSize));
    }

    protected function durationBucket(float $durationMs): string
    {
        return match (true) {
            $durationMs < 50 => '0-50ms',
            $durationMs < 100 => '50-100ms',
            $durationMs < 250 => '100-250ms',
            $durationMs < 500 => '250-500ms',
            $durationMs < 1000 => '500ms-1s',
            $durationMs < 2500 => '1s-2.5s',
            $durationMs < 5000 => '2.5s-5s',
            default => '5s+',
        };
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

    protected function normalizeStream(string $stream): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '-', strtolower($stream)) ?: 'unknown';
    }
}
