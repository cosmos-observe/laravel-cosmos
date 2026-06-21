# Cosmos Laravel Monitor API

Base path: `/api/cosmos-monitor/v1`

All successful responses use:

```json
{
  "data": {},
  "meta": {},
  "links": {}
}
```

Laravel validation and authentication errors use the host application's normal JSON error format.

## Authentication

Protect routes with `cosmos-monitor.middleware`. If `COSMOS_MONITOR_API_TOKEN` is set, also send:

```http
Authorization: Bearer <token>
```

## Shared List Query Parameters

| Query | Description |
| --- | --- |
| `from`, `to` | Unix seconds, Unix milliseconds, or date strings |
| `level` | Log level |
| `status` | HTTP status, queue status, job status, or schedule status |
| `queue` | Queue name |
| `job` | Job class/name |
| `route` | Route name or URI |
| `method` | HTTP method |
| `hash` | Exception grouping hash |
| `event` | Stream event name such as `processed`, `failed`, `hit`, `missed` |
| `connection` | Database, queue, or cache connection |
| `category` | Performance category such as `http`, `database`, `slow_query` |
| `disk` | Storage disk name |
| `service_id` | External service ID |
| `service_name` | External service name |
| `status_family` | HTTP status family such as `2xx`, `4xx`, `5xx`, or `failed` |
| `host` | Outbound HTTP host |
| `source` | Capture source such as `laravel_http`, `guzzle_middleware`, or `external_service_check` |
| `mailer` | Laravel mailer name |
| `transport` | Mail transport name |
| `recipient_domain` | First recipient domain for mail metadata filters |
| `min_duration` | Minimum duration in milliseconds |
| `search` | Case-insensitive JSON payload search |
| `sort` | Scalar event field, default `timestamp_ms` |
| `order` | `asc` or `desc`, default `desc` |
| `page`, `per_page` | Legacy pagination; page reads are bounded by `max_filter_scan_size` |
| `cursor`, `scan_limit` | Preferred heavy-data pagination |

Cursor responses include:

```json
{
  "meta": {
    "cursor": "1781825582602",
    "next_cursor": "1781825581999",
    "has_more": true,
    "scan_limit": 250,
    "indexed": true
  },
  "links": {
    "next": "1781825581999"
  }
}
```

## Runtime

### `GET /health`

Returns app identity, ClickHouse telemetry storage status, settings-table health, DB ping latency, PHP/Laravel/package versions, queue config, hostname, and environment.

### `GET /metrics/summary`

Returns counts and latest event per stream. Optional:

```http
GET /metrics/summary?streams=requests,logs,exceptions
```

### `GET /metrics/timeseries`

Returns minute or hour rollup points. `max_timeseries_points` caps very large windows. Optional `breakdown=status|status_family|host|mailer|transport` returns per-bucket dimension counts. Points also include `duration_buckets` for latency heatmap panels.

```http
GET /metrics/timeseries?stream=external-requests&interval=hour&breakdown=status_family&from=2026-06-18T10:00:00Z
```

## Telemetry Streams

### `GET /requests`

HTTP request telemetry: method, path, route, status, duration, query, user id, IP, and exception marker.

### `GET /queues`

Queue depth samples from `cosmos-monitor:sample-queues`: connection, queue, size, status, oldest pending age, failed job count.

### `GET /queues/{queue}/jobs`

Queue lifecycle events: `processing`, `processed`, `failed`, job name, queue, attempts, runtime, exception summary.

### `GET /logs`

Sanitized Monolog records captured by `CosmosMonitorTap`.

### `GET /exceptions`

Sanitized exception records with class, message, file, line, bounded trace, grouping hash, and durable workflow status overlay.

### `PUT /exceptions/{hash}/status`

Updates the durable workflow state for an exception group.

```json
{
  "status": "resolved",
  "note": "Fixed in release 2026.06.18"
}
```

For snooze:

```json
{
  "status": "snoozed",
  "snoozed_until": "2026-06-25T12:00:00Z"
}
```

### `GET /schedules`

Scheduler telemetry for starting, finished, failed, skipped, missed, and heartbeat states.

### `GET /database/latency`

Database query telemetry: connection, SQL, duration, statement hash, and category.

### `GET /performance`

Cross-cutting performance events such as slow HTTP requests, slow database queries, and job runtime samples.

### `GET /cache`

Cache hit, missed, written, and forgotten telemetry where Laravel emits cache events.

### `GET /storage`

Storage disk snapshots from `cosmos-monitor:sample-storage`. Local disks include `total_files`, `file_count_delta`, `total_bytes`, `disk_total_bytes`, `disk_free_bytes`, `used_percent`, `status`, and scan metadata. Remote disks report bounded listability checks without write probes.

```http
GET /storage?disk=public&status=warning
```

### `GET /external-requests`

Outbound HTTP request telemetry from Laravel `Http::` events and the opt-in Guzzle middleware. Stored metadata includes method, sanitized URL without query string, host, path, status, status family, duration, source, and bounded error data. Request/response bodies and headers are not stored.

```http
GET /external-requests?host=api.openai.com&status_family=5xx&min_duration=1000
```

Registered external-service checks are tagged with `source=external_service_check`. Direct raw `curl_*` calls are outside v1 automatic capture.

### `GET /mail`

Metadata-only mail telemetry for sent and failed messages. Stored fields include status, mailer, transport, duration, message id, recipient count, recipient domains, salted recipient hashes, attachment presence, and error metadata. Body text, subject, attachments, and full recipient addresses are not stored.

```http
GET /mail?mailer=smtp&status=failed
```

### External Service Registry

External service definitions are durable database records. Check history is stored in the `external-services` telemetry stream in ClickHouse.

#### `GET /external-services`

Returns registered services with latest status fields.

#### `POST /external-services`

```json
{
  "name": "OpenAI API",
  "url": "https://api.openai.com/v1/models"
}
```

#### `PUT /external-services/{id}`

Partial updates are accepted:

```json
{
  "name": "OpenAI API",
  "url": "https://api.openai.com/v1/models",
  "enabled": true
}
```

#### `DELETE /external-services/{id}`

Deletes the service definition and stops future scheduled checks.

#### `POST /external-services/{id}/check`

Runs an immediate check and updates latest status fields. Scheduled checks run through `cosmos-monitor:check-external-services`.

HTTP classification:

| Result | Status |
| --- | --- |
| `2xx` or `3xx` | `up` |
| `4xx` | `reachable_warning` |
| `5xx`, timeout, DNS, TLS, connection error | `down` |

## Settings

### `GET /settings`

Returns effective settings: package config defaults merged with DB overrides.

### `PUT /settings`

Updates durable settings. Partial updates are accepted and merged with defaults.

```json
{
  "thresholds": {
    "slow_request_ms": 1000,
    "slow_query_ms": 200,
    "slow_external_request_ms": 1000,
    "slow_mail_ms": 1000,
    "queue_size_warning": 1000
  },
  "notifications": {
    "enabled": true,
    "webhook_url": "https://example.com/hooks/cosmos",
    "mail_to": ["ops@example.com"],
    "fcm_enabled": true,
    "fcm_tokens": ["browser-registration-token"],
    "fcm_link": "https://dashboard.example.com"
  },
  "sampling": {
    "request_rate": 1,
    "database_rate": 1,
    "performance_rate": 1,
    "external_request_rate": 1,
    "mail_rate": 1
  },
  "capture": {
    "external_requests": true,
    "mail": true
  },
  "limits": {
    "query_batch_size": 250,
    "max_filter_scan_size": 5000,
    "max_timeseries_points": 720
  },
  "storage": {
    "write_fail_open": true,
    "payload_compression": false
  },
  "clickhouse": {
    "url": "http://127.0.0.1:8123",
    "database": "cosmos_monitor",
    "username": "default",
    "password": "",
    "retention_days": 30,
    "async_insert": true,
    "wait_for_async_insert": true,
    "timeout_seconds": 2
  },
  "storage_monitor": {
    "disks": ["local", "public"],
    "max_files_per_disk": 50000,
    "warning_used_percent": 85,
    "critical_used_percent": 95
  },
  "external_services": {
    "timeout_seconds": 5,
    "connect_timeout_seconds": 3,
    "user_agent": "Cosmos Laravel Monitor"
  },
  "actions": {
    "enabled": false
  }
}
```

## Notifications

### `POST /notifications/test`

Dispatches a test notification through configured channels and emits `CosmosMonitorNotificationTriggered`.

```json
{
  "message": "Cosmos monitor test",
  "severity": "info"
}
```

### `POST /notifications/fcm/register`

Registers a browser Firebase Cloud Messaging token for monitor push tests.

```json
{
  "token": "browser-registration-token"
}
```

### `DELETE /notifications/fcm/register`

Removes a browser Firebase Cloud Messaging token.

```json
{
  "token": "browser-registration-token"
}
```

## Protected Diagnostics

Diagnostics require `actions.enabled=true`.

### `POST /diagnostics/logs/test`

Writes a package diagnostic log event through Laravel logging.

### `POST /diagnostics/exceptions/test`

Records a controlled diagnostic exception without crashing the host app.

### `POST /diagnostics/database/test-query`

Runs a fast or slow test query:

```json
{
  "mode": "slow"
}
```

## Dashboard Env

```env
DASHBOARD_MODE=live
LARAVEL_MONITOR_BASE_URL=http://127.0.0.1:8012/api/cosmos-monitor/v1
LARAVEL_MONITOR_API_TOKEN=e2e-secret-token
```

## Scheduler

```php
$schedule->command('cosmos-monitor:sample-queues')->everyMinute();
$schedule->command('cosmos-monitor:sample-storage')->everyMinute();
$schedule->command('cosmos-monitor:check-external-services')->everyMinute();
$schedule->command('cosmos-monitor:prune')->hourly(); // optional retention-status report
```

Install ClickHouse schema before live capture:

```bash
curl http://127.0.0.1:8123/
php artisan cosmos-monitor:install-clickhouse-schema
```

ClickHouse TTL removes expired telemetry during background merges, so retention cleanup is not immediate and the package does not force `OPTIMIZE FINAL` during normal pruning.
