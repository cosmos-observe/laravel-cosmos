<?php

return [
    'enabled' => env('COSMOS_MONITOR_ENABLED', true),

    'storage_driver' => env('COSMOS_MONITOR_STORAGE_DRIVER', 'clickhouse'),

    'clickhouse' => [
        'url' => env('COSMOS_MONITOR_CLICKHOUSE_URL', env('CLICKHOUSE_URL', 'http://127.0.0.1:8123')),
        'database' => env('COSMOS_MONITOR_CLICKHOUSE_DATABASE', 'cosmos_monitor'),
        'username' => env('COSMOS_MONITOR_CLICKHOUSE_USERNAME', 'default'),
        'password' => env('COSMOS_MONITOR_CLICKHOUSE_PASSWORD', ''),
        'retention_days' => (int) env('COSMOS_MONITOR_CLICKHOUSE_RETENTION_DAYS', 30),
        'async_insert' => env('COSMOS_MONITOR_CLICKHOUSE_ASYNC_INSERT', true),
        'wait_for_async_insert' => env('COSMOS_MONITOR_CLICKHOUSE_WAIT_FOR_ASYNC_INSERT', true),
        'timeout_seconds' => (float) env('COSMOS_MONITOR_CLICKHOUSE_TIMEOUT_SECONDS', 2),
    ],

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
        'storage' => env('COSMOS_MONITOR_CAPTURE_STORAGE', true),
        'external_services' => env('COSMOS_MONITOR_CAPTURE_EXTERNAL_SERVICES', true),
        'external_requests' => env('COSMOS_MONITOR_CAPTURE_EXTERNAL_REQUESTS', true),
        'mail' => env('COSMOS_MONITOR_CAPTURE_MAIL', true),
    ],

    'retention' => [
        'raw_seconds' => (int) env('COSMOS_MONITOR_RAW_RETENTION_SECONDS', 604800),
        'rollup_seconds' => (int) env('COSMOS_MONITOR_ROLLUP_RETENTION_SECONDS', 2592000),
    ],

    'sampling' => [
        'request_rate' => (float) env('COSMOS_MONITOR_REQUEST_SAMPLE_RATE', 1.0),
        'database_rate' => (float) env('COSMOS_MONITOR_DATABASE_SAMPLE_RATE', 1.0),
        'performance_rate' => (float) env('COSMOS_MONITOR_PERFORMANCE_SAMPLE_RATE', 1.0),
        'external_request_rate' => (float) env('COSMOS_MONITOR_EXTERNAL_REQUEST_SAMPLE_RATE', 1.0),
        'mail_rate' => (float) env('COSMOS_MONITOR_MAIL_SAMPLE_RATE', 1.0),
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
        'index_fields' => ['level', 'status', 'status_family', 'queue', 'job', 'route', 'method', 'hash', 'event', 'connection', 'category', 'disk', 'service_id', 'service_name', 'host', 'source', 'mailer', 'transport', 'recipient_domain'],
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
        'slow_external_request_ms' => (float) env('COSMOS_MONITOR_SLOW_EXTERNAL_REQUEST_MS', 1000),
        'slow_mail_ms' => (float) env('COSMOS_MONITOR_SLOW_MAIL_MS', 1000),
        'queue_size_warning' => (int) env('COSMOS_MONITOR_QUEUE_SIZE_WARNING', 1000),
    ],

    'logs' => [
        'levels' => ['warning', 'error', 'critical', 'alert', 'emergency'],
    ],

    'queues' => [
        ['connection' => env('QUEUE_CONNECTION'), 'queue' => env('COSMOS_MONITOR_DEFAULT_QUEUE', 'default')],
    ],

    'storage_monitor' => [
        'disks' => array_filter(array_map('trim', explode(',', env('COSMOS_MONITOR_STORAGE_DISKS', 'local,public')))),
        'max_files_per_disk' => (int) env('COSMOS_MONITOR_STORAGE_MAX_FILES_PER_DISK', 50000),
        'warning_used_percent' => (float) env('COSMOS_MONITOR_STORAGE_WARNING_USED_PERCENT', 85),
        'critical_used_percent' => (float) env('COSMOS_MONITOR_STORAGE_CRITICAL_USED_PERCENT', 95),
    ],

    'external_services' => [
        'timeout_seconds' => (float) env('COSMOS_MONITOR_EXTERNAL_SERVICE_TIMEOUT_SECONDS', 5),
        'connect_timeout_seconds' => (float) env('COSMOS_MONITOR_EXTERNAL_SERVICE_CONNECT_TIMEOUT_SECONDS', 3),
        'user_agent' => env('COSMOS_MONITOR_EXTERNAL_SERVICE_USER_AGENT', 'Cosmos Laravel Monitor'),
    ],

    'notifications' => [
        'enabled' => env('COSMOS_MONITOR_NOTIFICATIONS_ENABLED', true),
        'webhook_url' => env('COSMOS_MONITOR_WEBHOOK_URL'),
        'mail_to' => array_filter(explode(',', env('COSMOS_MONITOR_MAIL_TO', ''))),
        'fcm_enabled' => env('COSMOS_MONITOR_FIREBASE_ENABLED', false),
        'fcm_tokens' => array_values(array_filter(array_map('trim', explode(',', env('COSMOS_MONITOR_FCM_TOKENS', ''))))),
        'fcm_link' => env('COSMOS_MONITOR_FCM_LINK', env('APP_URL')),
    ],

    'firebase' => [
        'enabled' => env('COSMOS_MONITOR_FIREBASE_ENABLED', false),
        'project_id' => env('COSMOS_MONITOR_FIREBASE_PROJECT_ID'),
        'client_email' => env('COSMOS_MONITOR_FIREBASE_CLIENT_EMAIL'),
        'private_key' => env('COSMOS_MONITOR_FIREBASE_PRIVATE_KEY'),
        'token_uri' => env('COSMOS_MONITOR_FIREBASE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
    ],

    'actions' => [
        'enabled' => env('COSMOS_MONITOR_ACTIONS_ENABLED', false),
    ],
];
