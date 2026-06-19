<?php

return [
    'enabled' => env('COSMOS_MONITOR_ENABLED', true),

    'redis_connection' => env('COSMOS_MONITOR_REDIS_CONNECTION'),
    'key_prefix' => env('COSMOS_MONITOR_REDIS_PREFIX', 'cosmos-monitor'),

    'route_prefix' => env('COSMOS_MONITOR_ROUTE_PREFIX', 'api/cosmos-monitor/v1'),
    'middleware' => array_filter(explode(',', env('COSMOS_MONITOR_MIDDLEWARE', 'api'))),
    'api_token' => env('COSMOS_MONITOR_API_TOKEN'),

    'app_id' => env('COSMOS_MONITOR_APP_ID', env('APP_NAME', 'laravel')),
    'environment' => env('COSMOS_MONITOR_ENVIRONMENT', env('APP_ENV', 'production')),
    'hostname' => env('COSMOS_MONITOR_HOSTNAME', gethostname() ?: 'unknown'),
    'package_version' => env('COSMOS_MONITOR_PACKAGE_VERSION', '1.0.0'),

    'capture' => [
        'requests' => env('COSMOS_MONITOR_CAPTURE_REQUESTS', true),
        'database' => env('COSMOS_MONITOR_CAPTURE_DATABASE', true),
        'queues' => env('COSMOS_MONITOR_CAPTURE_QUEUES', true),
        'schedules' => env('COSMOS_MONITOR_CAPTURE_SCHEDULES', true),
        'exceptions' => env('COSMOS_MONITOR_CAPTURE_EXCEPTIONS', true),
        'cache' => env('COSMOS_MONITOR_CAPTURE_CACHE', true),
    ],

    'retention' => [
        'raw_seconds' => (int) env('COSMOS_MONITOR_RAW_RETENTION_SECONDS', 604800),
        'rollup_seconds' => (int) env('COSMOS_MONITOR_ROLLUP_RETENTION_SECONDS', 2592000),
    ],

    'sampling' => [
        'request_rate' => (float) env('COSMOS_MONITOR_REQUEST_SAMPLE_RATE', 1.0),
        'database_rate' => (float) env('COSMOS_MONITOR_DATABASE_SAMPLE_RATE', 1.0),
        'performance_rate' => (float) env('COSMOS_MONITOR_PERFORMANCE_SAMPLE_RATE', 1.0),
    ],

    'limits' => [
        'max_payload_bytes' => (int) env('COSMOS_MONITOR_MAX_PAYLOAD_BYTES', 8192),
        'max_stack_frames' => (int) env('COSMOS_MONITOR_MAX_STACK_FRAMES', 20),
        'max_page_size' => (int) env('COSMOS_MONITOR_MAX_PAGE_SIZE', 100),
        'max_scan_size' => (int) env('COSMOS_MONITOR_MAX_SCAN_SIZE', 1000),
        'max_raw_events_per_stream' => (int) env('COSMOS_MONITOR_MAX_RAW_EVENTS_PER_STREAM', 250000),
        'query_batch_size' => (int) env('COSMOS_MONITOR_QUERY_BATCH_SIZE', 250),
        'max_filter_scan_size' => (int) env('COSMOS_MONITOR_MAX_FILTER_SCAN_SIZE', 5000),
        'prune_batch_size' => (int) env('COSMOS_MONITOR_PRUNE_BATCH_SIZE', 1000),
        'max_timeseries_points' => (int) env('COSMOS_MONITOR_MAX_TIMESERIES_POINTS', 720),
    ],

    'storage' => [
        'write_fail_open' => env('COSMOS_MONITOR_WRITE_FAIL_OPEN', true),
        'payload_compression' => env('COSMOS_MONITOR_PAYLOAD_COMPRESSION', false),
        'index_fields' => ['level', 'status', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection', 'category'],
    ],

    'redaction' => [
        'keys' => [
            'authorization',
            'cookie',
            'password',
            'password_confirmation',
            'token',
            'api_key',
            'secret',
            'credit_card',
        ],
        'replacement' => '[redacted]',
    ],

    'thresholds' => [
        'slow_request_ms' => (float) env('COSMOS_MONITOR_SLOW_REQUEST_MS', 1000),
        'slow_query_ms' => (float) env('COSMOS_MONITOR_SLOW_QUERY_MS', 200),
        'queue_size_warning' => (int) env('COSMOS_MONITOR_QUEUE_SIZE_WARNING', 1000),
    ],

    'logs' => [
        'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
    ],

    'queues' => [
        ['connection' => env('QUEUE_CONNECTION'), 'queue' => env('COSMOS_MONITOR_DEFAULT_QUEUE', 'default')],
    ],

    'notifications' => [
        'enabled' => env('COSMOS_MONITOR_NOTIFICATIONS_ENABLED', true),
        'webhook_url' => env('COSMOS_MONITOR_WEBHOOK_URL'),
        'mail_to' => array_filter(explode(',', env('COSMOS_MONITOR_MAIL_TO', ''))),
    ],

    'actions' => [
        'enabled' => env('COSMOS_MONITOR_ACTIONS_ENABLED', false),
    ],
];
