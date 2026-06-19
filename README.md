<p align="center">
  <img src="docs/assets/laravel-cosmos-logo.png" alt="Laravel Cosmos logo" width="720">
</p>

# Cosmos Laravel Monitor

Production-safe, Redis-first monitoring APIs for Laravel `10`, `11`, and `12`.

Cosmos Monitor gives you Telescope-style operational visibility without shipping a Blade UI and without storing high-volume telemetry in SQL. Recent telemetry lives in Redis with bounded payloads, secondary indexes, rollups, cursor pagination, sampling, redaction, compression, fail-open writes, and scheduled pruning. Durable low-volume settings and exception workflow state live in the host application's database.

The official package and dashboard logo is the Laravel Cosmos heartbeat mark shown above.

## What It Captures

- HTTP RPS, status counts, latency, slow requests, and performance events.
- Queue depth, processed/failed job counts, job runtime, failed-job summaries, and oldest pending age where the host queue driver supports it.
- Sanitized Monolog records through an opt-in Laravel log tap.
- Sanitized exceptions with bounded stack traces and stable grouping hashes.
- Scheduler start, finish, failure, missed, skipped, and heartbeat telemetry.
- Database query latency, slow queries, SQL hashes, and aggregate rollups.
- Cache hit, miss, write, and forget events where Laravel emits cache events.
- Runtime health: app identity, PHP/Laravel/package versions, Redis ping, DB ping latency, queue config, and memory peak.

## Install

```bash
composer require cosmos-observe/laravel-monitor

php artisan vendor:publish --tag=cosmos-monitor-config
php artisan vendor:publish --tag=cosmos-monitor-migrations
php artisan migrate
```

If your PHP runtime does not have `ext-redis`, install Predis in the host app:

```bash
composer require predis/predis
```

## Minimal Configuration

```env
COSMOS_MONITOR_ENABLED=true
COSMOS_MONITOR_REDIS_CONNECTION=default
COSMOS_MONITOR_REDIS_PREFIX=cosmos-monitor
COSMOS_MONITOR_ROUTE_PREFIX=api/cosmos-monitor/v1
COSMOS_MONITOR_MIDDLEWARE=api,auth:sanctum
COSMOS_MONITOR_API_TOKEN=
COSMOS_MONITOR_APP_ID="${APP_NAME}"
COSMOS_MONITOR_ENVIRONMENT="${APP_ENV}"
COSMOS_MONITOR_RAW_RETENTION_SECONDS=604800
COSMOS_MONITOR_ROLLUP_RETENTION_SECONDS=2592000
```

Default base path:

```text
/api/cosmos-monitor/v1
```

All responses use a stable API envelope:

```json
{
  "data": {},
  "meta": {},
  "links": {}
}
```

## Security Model

Use your Laravel app middleware as the primary protection layer:

```php
'middleware' => ['api', 'auth:sanctum'],
```

For dashboards, service-to-service calls, or staging E2E apps, you can also set:

```env
COSMOS_MONITOR_API_TOKEN=super-secret-token
```

When the token is set, requests must include:

```http
Authorization: Bearer super-secret-token
```

## Scheduler

Register these commands in your host app scheduler:

```php
$schedule->command('cosmos-monitor:sample-queues')->everyMinute();
$schedule->command('cosmos-monitor:prune')->hourly();
```

`sample-queues` records queue depth, failed job count, and oldest pending age where supported. `prune` deletes old raw payloads, sorted sets, secondary indexes, and rollup keys in batches.

## Log Capture

Add the tap to any logging channel you want monitored:

```php
'single' => [
    'driver' => 'single',
    'path' => storage_path('logs/laravel.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'tap' => [
        Cosmos\LaravelMonitor\Logging\CosmosMonitorTap::class,
    ],
],
```

Only levels configured in `cosmos-monitor.logs.levels` are written to Redis. Sensitive keys such as `authorization`, `cookie`, `password`, `token`, `api_key`, and `secret` are redacted.

## Heavy Data Controls

The package is designed for production load:

```php
'limits' => [
    'max_payload_bytes' => 8192,
    'max_page_size' => 100,
    'max_raw_events_per_stream' => 250000,
    'query_batch_size' => 250,
    'max_filter_scan_size' => 5000,
    'prune_batch_size' => 1000,
    'max_timeseries_points' => 720,
],

'storage' => [
    'write_fail_open' => true,
    'payload_compression' => false,
    'index_fields' => ['level', 'status', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection', 'category'],
],
```

Use cursor pagination for high-volume list screens:

```http
GET /requests?method=GET&per_page=50&scan_limit=500&cursor=1781825582602
```

The response includes:

```json
{
  "meta": {
    "next_cursor": "1781825581999",
    "has_more": true,
    "scan_limit": 500,
    "indexed": true
  }
}
```

## API Catalog

- `GET /health`
- `GET /metrics/summary`
- `GET /metrics/timeseries`
- `GET /requests`
- `GET /queues`
- `GET /queues/{queue}/jobs`
- `GET /logs`
- `GET /exceptions`
- `PUT /exceptions/{hash}/status`
- `GET /schedules`
- `GET /database/latency`
- `GET /performance`
- `GET /cache`
- `GET /settings`
- `PUT /settings`
- `POST /notifications/test`
- `POST /diagnostics/logs/test`
- `POST /diagnostics/exceptions/test`
- `POST /diagnostics/database/test-query`

Diagnostic endpoints are blocked unless:

```env
COSMOS_MONITOR_ACTIONS_ENABLED=true
```

## Dashboard Integration

The companion dashboard can run in mock, hybrid, or live mode:

```env
DASHBOARD_MODE=live
LARAVEL_MONITOR_BASE_URL=http://127.0.0.1:8012/api/cosmos-monitor/v1
LARAVEL_MONITOR_API_TOKEN=e2e-secret-token
```

The dashboard proxy maps package envelopes into the existing dashboard data shape and keeps mock mode available for demos.

## Redis Sizing Notes

Sizing depends on event rate, payload size, indexed fields, and retention. A practical first pass:

- Keep raw retention short enough for active operations: `1d-7d`.
- Keep rollups longer: `14d-90d`.
- Turn on sampling for very high RPS apps.
- Keep `max_payload_bytes` low and rely on hashes/traces for drill-down.
- Prefer cursor pagination and indexed filters over deep page numbers.
- Run `cosmos-monitor:prune` on a schedule and watch the `monitor` stream for prune stats or storage failures.

## CI/CD

GitHub Actions runs Composer validation, PHP linting, and PHPUnit on pushes and pull requests.

Release tags matching `v*` run the release workflow, create or update a GitHub Release, and can notify Packagist when these repository secrets are configured:

```text
PACKAGIST_USERNAME
PACKAGIST_TOKEN
```

Create releases with semantic version tags:

```bash
git tag v1.0.2
git push origin v1.0.2
```

## E2E Test Host

This repository was validated against a separate Laravel 12 app with SQLite app data and local Redis telemetry:

```text
/Users/mohammad/Documents/laravel-cosmos-monitor-e2e
```

The E2E app installs this package through a Composer path repository, uses Predis, triggers real HTTP, DB, cache, log, exception, queue, schedule, settings, diagnostics, notification, heavy-data, cursor, and pruning scenarios, and includes a command for 100k mixed telemetry events:

```bash
php artisan cosmos-e2e:seed-heavy --count=100000
```

## Documentation

- Full API guide: `docs/api.md`
- OpenAPI spec: `docs/openapi.yaml`
