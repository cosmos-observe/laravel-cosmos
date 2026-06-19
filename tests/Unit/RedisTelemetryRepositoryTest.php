<?php

namespace Cosmos\LaravelMonitor\Tests\Unit;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Tests\Fakes\FakeRedisFactory;
use PHPUnit\Framework\TestCase;

/**
 * Created to verify Redis repository behavior without requiring a Redis daemon.
 */
class RedisTelemetryRepositoryTest extends TestCase
{
    /**
     * Created to ensure raw events can be recorded, filtered, sorted, summarized, rolled up, and pruned.
     */
    public function test_it_records_lists_summarizes_timeseries_and_prunes_events(): void
    {
        $repository = new RedisTelemetryRepository(new FakeRedisFactory(), $this->config());

        $repository->recordEvent('requests', [
            'method' => 'GET',
            'route' => 'users.index',
            'status' => 200,
            'duration_ms' => 15,
        ], 100000);

        $repository->recordEvent('requests', [
            'method' => 'POST',
            'route' => 'users.store',
            'status' => 500,
            'duration_ms' => 75,
        ], 110000);

        $listed = $repository->listEvents('requests', [
            'status' => 500,
            'sort' => 'duration_ms',
            'order' => 'desc',
            'page' => 1,
            'per_page' => 10,
            'from' => 1,
            'to' => 200,
        ]);

        $this->assertSame(1, $listed['meta']['total']);
        $this->assertSame('users.store', $listed['data'][0]['route']);

        $summary = $repository->summary(['requests'], ['from' => 1, 'to' => 200]);
        $this->assertSame(2, $summary['requests']['count']);

        $points = $repository->timeseries('requests', ['from' => 1, 'to' => 200, 'interval' => 'minute']);
        $this->assertNotEmpty($points);

        $deleted = $repository->prune(110000 + (3601 * 1000));
        $this->assertSame(2, $deleted['requests']);
        $this->assertSame(0, $repository->listEvents('requests')['meta']['total']);
    }

    /**
     * Created to prevent regressions where descending cursor reads accidentally scan the oldest indexed members first.
     */
    public function test_cursor_pagination_reads_descending_indexed_events(): void
    {
        $repository = new RedisTelemetryRepository(new FakeRedisFactory(), $this->config());

        $baseTimestampMs = 1780000000000;

        for ($index = 1; $index <= 30; $index++) {
            $repository->recordEvent('requests', [
                'method' => 'GET',
                'route' => 'cursor.test',
                'status' => 200,
                'duration_ms' => $index,
            ], $baseTimestampMs + $index);
        }

        $firstPage = $repository->listEvents('requests', [
            'method' => 'GET',
            'from' => $baseTimestampMs,
            'to' => $baseTimestampMs + 100,
            'per_page' => 5,
            'scan_limit' => 10,
        ]);

        $secondPage = $repository->listEvents('requests', [
            'method' => 'GET',
            'from' => $baseTimestampMs,
            'to' => $baseTimestampMs + 100,
            'cursor' => $firstPage['meta']['next_cursor'],
            'per_page' => 5,
            'scan_limit' => 10,
        ]);

        $this->assertCount(5, $firstPage['data']);
        $this->assertCount(5, $secondPage['data']);
        $this->assertGreaterThan($secondPage['data'][0]['timestamp_ms'], $firstPage['data'][0]['timestamp_ms']);
        $this->assertTrue($firstPage['meta']['indexed']);
    }

    /**
     * Created to keep repository tests aligned with production retention defaults while using deterministic identity tags.
     */
    protected function config(): array
    {
        return [
            'redis_connection' => null,
            'key_prefix' => 'test-monitor',
            'app_id' => 'test-app',
            'environment' => 'testing',
            'hostname' => 'test-host',
            'retention' => [
                'raw_seconds' => 3600,
                'rollup_seconds' => 3600,
            ],
            'limits' => [
                'max_page_size' => 100,
                'max_scan_size' => 1000,
                'query_batch_size' => 250,
                'max_filter_scan_size' => 5000,
                'prune_batch_size' => 1000,
                'max_raw_events_per_stream' => 250000,
                'max_timeseries_points' => 720,
            ],
            'storage' => [
                'write_fail_open' => true,
                'payload_compression' => false,
                'index_fields' => ['level', 'status', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection', 'category'],
            ],
        ];
    }
}
