<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Storage\ClickHouse\ClickHouseClient;
use Illuminate\Console\Command;
use Illuminate\Container\Container;

/**
 * Created to install the ClickHouse database, raw event table, rollup table, and materialized views.
 */
class InstallClickHouseSchemaCommand extends Command
{
    protected $signature = 'cosmos-monitor:install-clickhouse-schema';

    protected $description = 'Install ClickHouse schema for Cosmos Monitor telemetry.';

    public function handle(ClickHouseClient $clickhouse): int
    {
        foreach ($this->statements($clickhouse) as $statement) {
            $clickhouse->command($statement);
        }

        $this->components->info('Cosmos Monitor ClickHouse schema installed.');

        return self::SUCCESS;
    }

    /**
     * Created to keep ClickHouse DDL deterministic and directly testable.
     */
    public function statements(ClickHouseClient $clickhouse): array
    {
        $database = $clickhouse->quoteIdentifier($clickhouse->database());
        $events = $clickhouse->qualifiedTable('cosmos_events');
        $rollups = $clickhouse->qualifiedTable('cosmos_rollups_minute');
        $retentionDays = $this->retentionDays();

        return [
            "CREATE DATABASE IF NOT EXISTS {$database}",
            <<<SQL
CREATE TABLE IF NOT EXISTS {$events}
(
    id String,
    stream LowCardinality(String),
    event_kind LowCardinality(String),
    timestamp DateTime64(3, 'UTC'),
    timestamp_ms UInt64,
    app_id LowCardinality(String),
    environment LowCardinality(String),
    hostname LowCardinality(String),
    method Nullable(String),
    route Nullable(String),
    status Nullable(String),
    status_family Nullable(String),
    level Nullable(String),
    queue Nullable(String),
    job Nullable(String),
    hash Nullable(String),
    event Nullable(String),
    connection Nullable(String),
    category Nullable(String),
    disk Nullable(String),
    service_id Nullable(String),
    service_name Nullable(String),
    host Nullable(String),
    source Nullable(String),
    mailer Nullable(String),
    transport Nullable(String),
    recipient_domain Nullable(String),
    duration_ms Nullable(Float64),
    payload_json String,
    INDEX idx_status status TYPE set(1024) GRANULARITY 4,
    INDEX idx_method method TYPE set(1024) GRANULARITY 4,
    INDEX idx_route route TYPE set(10000) GRANULARITY 4,
    INDEX idx_level level TYPE set(256) GRANULARITY 4,
    INDEX idx_hash hash TYPE set(4096) GRANULARITY 4,
    INDEX idx_host host TYPE set(10000) GRANULARITY 4,
    INDEX idx_mailer mailer TYPE set(512) GRANULARITY 4,
    INDEX idx_transport transport TYPE set(512) GRANULARITY 4
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(timestamp)
ORDER BY (stream, timestamp, hostname, id)
TTL timestamp + INTERVAL {$retentionDays} DAY DELETE
SQL,
            <<<SQL
CREATE TABLE IF NOT EXISTS {$rollups}
(
    bucket DateTime64(3, 'UTC'),
    stream LowCardinality(String),
    app_id LowCardinality(String),
    environment LowCardinality(String),
    hostname LowCardinality(String),
    breakdown_field LowCardinality(String),
    breakdown_value String,
    count UInt64,
    duration_ms_sum Float64,
    duration_ms_count UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(bucket)
ORDER BY (stream, bucket, breakdown_field, breakdown_value, app_id, environment, hostname)
TTL bucket + INTERVAL {$retentionDays} DAY DELETE
SQL,
            <<<SQL
CREATE MATERIALIZED VIEW IF NOT EXISTS {$clickhouse->qualifiedTable('cosmos_rollups_minute_totals_mv')}
TO {$rollups}
AS
SELECT
    toStartOfMinute(timestamp) AS bucket,
    stream,
    app_id,
    environment,
    hostname,
    '' AS breakdown_field,
    '' AS breakdown_value,
    count() AS count,
    sum(ifNull(duration_ms, 0)) AS duration_ms_sum,
    countIf(isNotNull(duration_ms)) AS duration_ms_count
FROM {$events}
GROUP BY bucket, stream, app_id, environment, hostname
SQL,
            <<<SQL
CREATE MATERIALIZED VIEW IF NOT EXISTS {$clickhouse->qualifiedTable('cosmos_rollups_minute_duration_mv')}
TO {$rollups}
AS
SELECT
    toStartOfMinute(timestamp) AS bucket,
    stream,
    app_id,
    environment,
    hostname,
    'duration_bucket' AS breakdown_field,
    multiIf(
        duration_ms < 50, '0-50ms',
        duration_ms < 100, '50-100ms',
        duration_ms < 250, '100-250ms',
        duration_ms < 500, '250-500ms',
        duration_ms < 1000, '500ms-1s',
        duration_ms < 2500, '1s-2.5s',
        duration_ms < 5000, '2.5s-5s',
        '5s+'
    ) AS breakdown_value,
    count() AS count,
    sum(ifNull(duration_ms, 0)) AS duration_ms_sum,
    countIf(isNotNull(duration_ms)) AS duration_ms_count
FROM {$events}
WHERE isNotNull(duration_ms)
GROUP BY bucket, stream, app_id, environment, hostname, breakdown_value
SQL,
            <<<SQL
CREATE MATERIALIZED VIEW IF NOT EXISTS {$clickhouse->qualifiedTable('cosmos_rollups_minute_breakdowns_mv')}
TO {$rollups}
AS
SELECT
    bucket,
    stream,
    app_id,
    environment,
    hostname,
    tupleElement(breakdown, 1) AS breakdown_field,
    tupleElement(breakdown, 2) AS breakdown_value,
    count() AS count,
    sum(duration_ms_value) AS duration_ms_sum,
    sum(duration_ms_present) AS duration_ms_count
FROM
(
    SELECT
        toStartOfMinute(timestamp) AS bucket,
        stream,
        app_id,
        environment,
        hostname,
        ifNull(duration_ms, 0) AS duration_ms_value,
        if(isNull(duration_ms), 0, 1) AS duration_ms_present,
        arrayJoin([
            ('status', ifNull(status, '')),
            ('status_family', ifNull(status_family, '')),
            ('host', ifNull(host, '')),
            ('mailer', ifNull(mailer, '')),
            ('transport', ifNull(transport, ''))
        ]) AS breakdown
    FROM {$events}
)
WHERE tupleElement(breakdown, 2) != ''
GROUP BY bucket, stream, app_id, environment, hostname, breakdown_field, breakdown_value
SQL,
        ];
    }

    protected function retentionDays(): int
    {
        if (Container::getInstance()->bound('config')) {
            return max(1, (int) config('cosmos-monitor.clickhouse.retention_days', 30));
        }

        return 30;
    }
}
