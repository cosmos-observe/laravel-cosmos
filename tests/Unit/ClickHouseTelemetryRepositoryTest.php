<?php

namespace Cosmos\LaravelMonitor\Tests\Unit;

use Cosmos\LaravelMonitor\Commands\InstallClickHouseSchemaCommand;
use Cosmos\LaravelMonitor\Storage\ClickHouse\ClickHouseTelemetryRepository;
use Cosmos\LaravelMonitor\Tests\Fakes\FakeClickHouseClient;
use PHPUnit\Framework\TestCase;

/**
 * Created to verify ClickHouse repository SQL and schema DDL without a ClickHouse daemon.
 */
class ClickHouseTelemetryRepositoryTest extends TestCase
{
    public function test_it_inserts_events_and_aggregates_as_jsoneachrow_rows(): void
    {
        $client = new FakeClickHouseClient();
        $repository = new ClickHouseTelemetryRepository($client, $this->config());

        $id = $repository->recordEvent('requests', [
            'method' => 'GET',
            'route' => 'health',
            'status' => 200,
            'duration_ms' => 12.5,
        ], 1780000000123);

        $repository->recordAggregate('database', [
            'connection' => 'mysql',
            'duration_ms' => 44,
        ], 1780000001000);

        $this->assertStringStartsWith('requests:1780000000123:', $id);
        $this->assertCount(2, $client->inserts);
        $this->assertSame('cosmos_events', $client->inserts[0]['table']);
        $this->assertSame('event', $client->inserts[0]['rows'][0]['event_kind']);
        $this->assertSame('aggregate', $client->inserts[1]['rows'][0]['event_kind']);
        $this->assertSame('2xx', $client->inserts[0]['rows'][0]['status_family']);
        $this->assertJson($client->inserts[0]['rows'][0]['payload_json']);
    }

    public function test_list_events_builds_filtered_sorted_cursor_query(): void
    {
        $client = new FakeClickHouseClient();
        $client->selectResponses = [
            [
                [
                    'payload_json' => json_encode([
                        'id' => 'requests:1780000000001:test',
                        'stream' => 'requests',
                        'timestamp_ms' => 1780000000001,
                        'route' => 'health',
                    ]),
                    'timestamp_ms' => 1780000000001,
                ],
            ],
            [['total' => 1]],
        ];

        $repository = new ClickHouseTelemetryRepository($client, $this->config());
        $result = $repository->listEvents('requests', [
            'method' => 'GET',
            'status' => 200,
            'from' => 1780000000000,
            'to' => 1780000001000,
            'cursor' => 1780000000500,
            'per_page' => 10,
            'sort' => 'duration_ms',
            'order' => 'desc',
        ]);

        $this->assertSame('health', $result['data'][0]['route']);
        $this->assertSame('clickhouse', $result['meta']['source']);
        $this->assertStringContainsString("event_kind = 'event'", $client->selects[0]);
        $this->assertStringContainsString("stream = 'requests'", $client->selects[0]);
        $this->assertStringContainsString('timestamp_ms <= 1780000000499', $client->selects[0]);
        $this->assertStringContainsString('method = ', $client->selects[0]);
        $this->assertStringContainsString("status = '200'", $client->selects[0]);
        $this->assertStringContainsString('ORDER BY duration_ms DESC', $client->selects[0]);
    }

    public function test_timeseries_reads_minute_rollups_and_breakdowns(): void
    {
        $client = new FakeClickHouseClient();
        $client->selectResponses = [
            [
                [
                    'timestamp_ms' => 1780000020000,
                    'count' => 2,
                    'duration_ms_sum' => 300,
                    'duration_ms_count' => 2,
                ],
            ],
            [
                [
                    'timestamp_ms' => 1780000020000,
                    'duration_bucket' => '100-250ms',
                    'count' => 2,
                ],
            ],
            [
                [
                    'timestamp_ms' => 1780000020000,
                    'breakdown_value' => '5xx',
                    'count' => 1,
                ],
            ],
        ];

        $repository = new ClickHouseTelemetryRepository($client, $this->config());
        $points = $repository->timeseries('external-requests', [
            'from' => 1780000020000,
            'to' => 1780000020000,
            'interval' => 'minute',
            'breakdown' => 'status_family',
        ]);

        $this->assertSame(2, $points[0]['count']);
        $this->assertSame(150.0, $points[0]['avg_duration_ms']);
        $this->assertSame(2, $points[0]['duration_buckets']['100-250ms']);
        $this->assertSame(1, $points[0]['breakdown']['5xx']);
        $this->assertStringContainsString('cosmos_rollups_minute', $client->selects[0]);
        $this->assertStringContainsString("breakdown_field = ''", $client->selects[0]);
    }

    public function test_schema_command_defines_events_rollups_ttl_and_materialized_views(): void
    {
        $client = new FakeClickHouseClient();
        $statements = (new InstallClickHouseSchemaCommand())->statements($client);
        $sql = implode("\n\n", $statements);

        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS `cosmos_test`', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `cosmos_test`.`cosmos_events`', $sql);
        $this->assertStringContainsString('ENGINE = MergeTree', $sql);
        $this->assertStringContainsString('PARTITION BY toYYYYMM(timestamp)', $sql);
        $this->assertStringContainsString('ORDER BY (stream, timestamp, hostname, id)', $sql);
        $this->assertStringContainsString('TTL timestamp + INTERVAL 30 DAY DELETE', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `cosmos_test`.`cosmos_rollups_minute`', $sql);
        $this->assertStringContainsString('ENGINE = SummingMergeTree', $sql);
        $this->assertStringContainsString('CREATE MATERIALIZED VIEW IF NOT EXISTS', $sql);
        $this->assertStringContainsString('cosmos_rollups_minute_breakdowns_mv', $sql);
    }

    protected function config(): array
    {
        return [
            'app_id' => 'test-app',
            'environment' => 'testing',
            'hostname' => 'test-host',
            'clickhouse' => [
                'retention_days' => 30,
            ],
            'limits' => [
                'max_page_size' => 100,
                'max_timeseries_points' => 720,
            ],
            'storage' => [
                'write_fail_open' => true,
            ],
        ];
    }
}
