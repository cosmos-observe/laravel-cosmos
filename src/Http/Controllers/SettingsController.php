<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose durable monitor settings for retention, thresholds, notification channels, and sampling.
 */
class SettingsController extends Controller
{
    /**
     * Created to return effective settings from config defaults plus database overrides.
     */
    public function index(SettingsService $settings): JsonResponse
    {
        return $this->envelope($settings->all());
    }

    /**
     * Created to update supported monitor settings without changing high-volume telemetry rows directly.
     */
    public function update(Request $request, SettingsService $settings, TelemetryRepository $telemetry): JsonResponse
    {
        $validated = $request->validate([
            'retention' => ['sometimes', 'array'],
            'retention.raw_seconds' => ['sometimes', 'integer', 'min:60'],
            'retention.rollup_seconds' => ['sometimes', 'integer', 'min:60'],
            'thresholds' => ['sometimes', 'array'],
            'thresholds.slow_request_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.slow_query_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.slow_external_request_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.slow_mail_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.queue_size_warning' => ['sometimes', 'integer', 'min:1'],
            'notifications' => ['sometimes', 'array'],
            'notifications.enabled' => ['sometimes', 'boolean'],
            'notifications.webhook_url' => ['nullable', 'url'],
            'notifications.mail_to' => ['sometimes', 'array'],
            'notifications.mail_to.*' => ['email'],
            'notifications.fcm_enabled' => ['sometimes', 'boolean'],
            'notifications.fcm_tokens' => ['sometimes', 'array'],
            'notifications.fcm_tokens.*' => ['string', 'max:4096'],
            'notifications.fcm_link' => ['nullable', 'url'],
            'sampling' => ['sometimes', 'array'],
            'sampling.request_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.database_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.performance_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.external_request_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.mail_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'capture' => ['sometimes', 'array'],
            'capture.requests' => ['sometimes', 'boolean'],
            'capture.database' => ['sometimes', 'boolean'],
            'capture.queues' => ['sometimes', 'boolean'],
            'capture.schedules' => ['sometimes', 'boolean'],
            'capture.exceptions' => ['sometimes', 'boolean'],
            'capture.cache' => ['sometimes', 'boolean'],
            'capture.storage' => ['sometimes', 'boolean'],
            'capture.external_services' => ['sometimes', 'boolean'],
            'capture.external_requests' => ['sometimes', 'boolean'],
            'capture.mail' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.max_payload_bytes' => ['sometimes', 'integer', 'min:1024'],
            'limits.max_page_size' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'limits.max_raw_events_per_stream' => ['sometimes', 'integer', 'min:1000'],
            'limits.query_batch_size' => ['sometimes', 'integer', 'min:25', 'max:5000'],
            'limits.max_filter_scan_size' => ['sometimes', 'integer', 'min:100', 'max:50000'],
            'limits.prune_batch_size' => ['sometimes', 'integer', 'min:100', 'max:10000'],
            'limits.max_timeseries_points' => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'storage' => ['sometimes', 'array'],
            'storage.write_fail_open' => ['sometimes', 'boolean'],
            'storage.payload_compression' => ['sometimes', 'boolean'],
            'clickhouse' => ['sometimes', 'array'],
            'clickhouse.url' => ['sometimes', 'url'],
            'clickhouse.database' => ['sometimes', 'string', 'max:120'],
            'clickhouse.username' => ['sometimes', 'string', 'max:255'],
            'clickhouse.password' => ['sometimes', 'string', 'max:4096'],
            'clickhouse.retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'clickhouse.async_insert' => ['sometimes', 'boolean'],
            'clickhouse.wait_for_async_insert' => ['sometimes', 'boolean'],
            'clickhouse.timeout_seconds' => ['sometimes', 'numeric', 'min:0.1', 'max:60'],
            'storage_monitor' => ['sometimes', 'array'],
            'storage_monitor.disks' => ['sometimes', 'array'],
            'storage_monitor.disks.*' => ['string', 'max:120'],
            'storage_monitor.max_files_per_disk' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'storage_monitor.warning_used_percent' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'storage_monitor.critical_used_percent' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'external_services' => ['sometimes', 'array'],
            'external_services.timeout_seconds' => ['sometimes', 'numeric', 'min:0.5', 'max:60'],
            'external_services.connect_timeout_seconds' => ['sometimes', 'numeric', 'min:0.5', 'max:60'],
            'external_services.user_agent' => ['sometimes', 'string', 'max:255'],
            'actions' => ['sometimes', 'array'],
            'actions.enabled' => ['sometimes', 'boolean'],
        ]);

        $effectiveSettings = $settings->update($validated, optional($request->user())->getAuthIdentifier());
        $telemetry->applySettings($effectiveSettings);

        return $this->envelope($effectiveSettings);
    }
}
