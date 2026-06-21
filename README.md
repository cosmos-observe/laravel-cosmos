<p align="center">
  <img src="docs/assets/laravel-cosmos-logo.png" alt="Laravel Cosmos logo" width="720">
</p>

# Cosmos Laravel Monitor

Production-safe, ClickHouse-first monitoring APIs for Laravel `10`, `11`, `12`, and `13`.

Cosmos Monitor gives you Telescope-style operational visibility without shipping a Blade UI and without storing high-volume telemetry in the host application's OLTP database. Runtime telemetry lives in ClickHouse with typed filter columns, JSON payloads, materialized rollups, cursor pagination, sampling, redaction, fail-open writes, and TTL-based retention. Durable low-volume settings, exception workflow state, and external service definitions stay in the host application's database.

The official package and dashboard logo is the Laravel Cosmos heartbeat mark shown above.

## What It Captures

- HTTP RPS, status counts, latency, slow requests, and performance events.
- Queue depth, processed/failed job counts, job runtime, failed-job summaries, and oldest pending age where the host queue driver supports it.
- Sanitized Monolog records through an opt-in Laravel log tap.
- Sanitized exceptions with bounded stack traces and stable grouping hashes.
- Scheduler start, finish, failure, missed, skipped, and heartbeat telemetry.
- Database query latency, slow queries, SQL hashes, and aggregate rollups.
- Cache hit, miss, write, and forget events where Laravel emits cache events.
- Storage disk snapshots: selected disk file counts, file-count deltas, byte totals, local disk pressure, and remote disk listability checks.
- External HTTP dependency checks: user-registered service name/URL probes with latest status, latency, status code, and transition alerts.
- Outbound external HTTP requests: Laravel `Http::` traffic automatically, plus an opt-in Guzzle middleware for direct Guzzle clients.
- Mail sends: metadata-only send/failure telemetry with mailer, transport, duration, recipient count, domains, and salted recipient hashes.
- Runtime health: app identity, PHP/Laravel/package versions, ClickHouse ping, DB ping latency, queue config, and memory peak.

## Install

```bash
composer require cosmos-observe/laravel-monitor

php artisan vendor:publish --tag=cosmos-monitor-config
php artisan vendor:publish --tag=cosmos-monitor-migrations
php artisan migrate
```

Install and start ClickHouse before enabling live telemetry. A local install should answer with `Ok.`:

```bash
curl http://127.0.0.1:8123/
php artisan cosmos-monitor:install-clickhouse-schema
```

## Minimal Configuration

```env
COSMOS_MONITOR_ENABLED=true
COSMOS_MONITOR_STORAGE_DRIVER=clickhouse
COSMOS_MONITOR_CLICKHOUSE_URL=http://127.0.0.1:8123
COSMOS_MONITOR_CLICKHOUSE_DATABASE=cosmos_monitor
COSMOS_MONITOR_CLICKHOUSE_USERNAME=default
COSMOS_MONITOR_CLICKHOUSE_PASSWORD=
COSMOS_MONITOR_CLICKHOUSE_RETENTION_DAYS=30
COSMOS_MONITOR_CLICKHOUSE_ASYNC_INSERT=true
COSMOS_MONITOR_CLICKHOUSE_WAIT_FOR_ASYNC_INSERT=true
COSMOS_MONITOR_ROUTE_PREFIX=api/cosmos-monitor/v1
COSMOS_MONITOR_MIDDLEWARE=api,auth:sanctum
COSMOS_MONITOR_API_TOKEN=
COSMOS_MONITOR_APP_ID="${APP_NAME}"
COSMOS_MONITOR_ENVIRONMENT="${APP_ENV}"
COSMOS_MONITOR_FIREBASE_ENABLED=false
COSMOS_MONITOR_FIREBASE_PROJECT_ID=cosmos-monitor-cc3da
COSMOS_MONITOR_FIREBASE_CLIENT_EMAIL=firebase-adminsdk-fbsvc@cosmos-monitor-cc3da.iam.gserviceaccount.com
COSMOS_MONITOR_FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
COSMOS_MONITOR_FIREBASE_TOKEN_URI=https://oauth2.googleapis.com/token
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
$schedule->command('cosmos-monitor:sample-storage')->everyMinute();
$schedule->command('cosmos-monitor:check-external-services')->everyMinute();
$schedule->command('cosmos-monitor:prune')->hourly(); // optional retention-status report
```

`sample-queues` records queue depth, failed job count, and oldest pending age where supported. ClickHouse TTL deletes expired telemetry during table merges; `prune` reports retention status and does not force `OPTIMIZE FINAL`.
`sample-storage` records selected filesystem disk snapshots. `check-external-services` probes enabled external service URLs and records availability transitions.

## Storage And External Services

Selected storage disks default to local/public and can be changed without touching code:

```env
COSMOS_MONITOR_STORAGE_DISKS=local,public
COSMOS_MONITOR_STORAGE_MAX_FILES_PER_DISK=50000
COSMOS_MONITOR_STORAGE_WARNING_USED_PERCENT=85
COSMOS_MONITOR_STORAGE_CRITICAL_USED_PERCENT=95
COSMOS_MONITOR_EXTERNAL_SERVICE_TIMEOUT_SECONDS=5
COSMOS_MONITOR_EXTERNAL_SERVICE_CONNECT_TIMEOUT_SECONDS=3
COSMOS_MONITOR_CAPTURE_EXTERNAL_REQUESTS=true
COSMOS_MONITOR_CAPTURE_MAIL=true
COSMOS_MONITOR_EXTERNAL_REQUEST_SAMPLE_RATE=1
COSMOS_MONITOR_MAIL_SAMPLE_RATE=1
COSMOS_MONITOR_SLOW_EXTERNAL_REQUEST_MS=1000
COSMOS_MONITOR_SLOW_MAIL_MS=1000
```

Local disks expose `total_files`, `file_count_delta`, `total_bytes`, `disk_total_bytes`, `disk_free_bytes`, and `used_percent`. Remote disks are checked for bounded listability without write probes.

External services are registered through the API with `name` and `url`; scheduled checks classify `2xx/3xx` as `up`, `4xx` as `reachable_warning`, and `5xx`, timeout, DNS, or TLS failures as `down`. Notifications are emitted only when the latest status changes.

## Firebase Browser Push

Firebase Cloud Messaging uses server-only service-account env values and the HTTP v1 API. Do not publish the private key or store it in dashboard-facing settings.

```env
COSMOS_MONITOR_FIREBASE_ENABLED=true
COSMOS_MONITOR_FIREBASE_PROJECT_ID=cosmos-monitor-cc3da
COSMOS_MONITOR_FIREBASE_CLIENT_EMAIL=firebase-adminsdk-fbsvc@cosmos-monitor-cc3da.iam.gserviceaccount.com
COSMOS_MONITOR_FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
COSMOS_MONITOR_FIREBASE_TOKEN_URI=https://oauth2.googleapis.com/token
```

Browser tokens are persisted through `POST /notifications/fcm/register` and removed through `DELETE /notifications/fcm/register`. Test notifications use `POST /notifications/test`.

## External Requests And Mail

Outbound HTTP request monitoring captures Laravel `Http::` events without storing request headers, request bodies, response bodies, or sensitive query strings. The stored URL is normalized to scheme, host, port, and path. Registered service health checks are tagged as `source=external_service_check` so the dashboard can separate synthetic checks from application traffic.

Apps that instantiate Guzzle directly can opt in by pushing the package middleware onto their handler stack:

```php
use Cosmos\LaravelMonitor\Http\Client\ExternalRequestGuzzleMiddleware;
use GuzzleHttp\HandlerStack;

$stack = HandlerStack::create();
$stack->push(app(ExternalRequestGuzzleMiddleware::class));
```

Raw `curl_*` calls do not emit Laravel or Guzzle events and are outside v1 automatic capture.

Mail monitoring stores metadata only: send status, mailer, transport, duration, message id, recipient count, recipient domains, salted recipient hashes, attachment presence, and error class/message on failures. It does not store body text, subject, attachments, or full recipient addresses.

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

Only levels configured in `cosmos-monitor.logs.levels` are written to telemetry. Sensitive keys such as `authorization`, `cookie`, `password`, `token`, `api_key`, and `secret` are redacted.

## Heavy Data Controls

The package is designed for production load:

```php
'limits' => [
    'max_payload_bytes' => 8192,
    'max_page_size' => 100,
    'query_batch_size' => 250,
    'max_filter_scan_size' => 5000,
    'max_timeseries_points' => 720,
],

'storage' => [
    'write_fail_open' => true,
    'payload_compression' => false,
],

'clickhouse' => [
    'retention_days' => 30,
    'async_insert' => true,
    'wait_for_async_insert' => true,
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
- `GET /storage`
- `GET /external-requests`
- `GET /mail`
- `GET /external-services`
- `POST /external-services`
- `PUT /external-services/{id}`
- `DELETE /external-services/{id}`
- `POST /external-services/{id}/check`
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

## ClickHouse Storage Notes

Sizing depends on event rate, payload size, indexed fields, and retention. A practical first pass:

- Keep ClickHouse retention to the operations window you actually inspect, for example `30d`.
- Install the schema with `php artisan cosmos-monitor:install-clickhouse-schema` before live capture.
- Use `async_insert=1,wait_for_async_insert=1` for reliable high-concurrency inserts.
- ClickHouse TTL deletion is merge-driven and is not immediate; expired rows are removed during background merges.
- Turn on sampling for very high RPS apps.
- Keep `max_payload_bytes` low and rely on hashes/traces for drill-down.
- Prefer cursor pagination and indexed filters over deep page numbers.
- Watch `/health` for `clickhouse=ok` and `telemetry_storage=ok`.

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

This repository was validated against a separate Laravel 12 app with SQLite app data and local ClickHouse telemetry, and its Composer constraints support Laravel 13 applications:

```text
/Users/mohammad/Documents/laravel-cosmos-monitor-e2e
```

The E2E app installs this package through a Composer path repository, triggers real HTTP, DB, cache, log, exception, queue, schedule, settings, diagnostics, notification, heavy-data, cursor, and retention scenarios, and includes a command for 100k mixed telemetry events:

```bash
php artisan cosmos-e2e:seed-heavy --count=100000
```

## Documentation

- Full API guide: `docs/api.md`
- OpenAPI spec: `docs/openapi.yaml`
