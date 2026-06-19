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

Returns app identity, Redis ping, settings-table health, DB ping latency, PHP/Laravel/package versions, queue config, hostname, and environment.

### `GET /metrics/summary`

Returns counts and latest event per stream. Optional:

```http
GET /metrics/summary?streams=requests,logs,exceptions
```

### `GET /metrics/timeseries`

Returns minute or hour rollup points. `max_timeseries_points` caps very large windows.

```http
GET /metrics/timeseries?stream=requests&interval=minute&from=2026-06-18T10:00:00Z
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

## Settings

### `GET /settings`

Returns effective settings: package config defaults merged with DB overrides.

### `PUT /settings`

Updates durable settings. Partial updates are accepted and merged with defaults.

```json
{
  "retention": {
    "raw_seconds": 604800,
    "rollup_seconds": 2592000
  },
  "thresholds": {
    "slow_request_ms": 1000,
    "slow_query_ms": 200,
    "queue_size_warning": 1000
  },
  "notifications": {
    "enabled": true,
    "webhook_url": "https://example.com/hooks/cosmos",
    "mail_to": ["ops@example.com"]
  },
  "sampling": {
    "request_rate": 1,
    "database_rate": 1,
    "performance_rate": 1
  },
  "limits": {
    "max_raw_events_per_stream": 250000,
    "query_batch_size": 250,
    "max_filter_scan_size": 5000,
    "prune_batch_size": 1000,
    "max_timeseries_points": 720
  },
  "storage": {
    "write_fail_open": true,
    "payload_compression": false
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
