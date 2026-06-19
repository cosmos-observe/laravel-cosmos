<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
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
     * Created to update supported monitor settings without changing high-volume Redis telemetry.
     */
    public function update(Request $request, SettingsService $settings, RedisTelemetryRepository $telemetry): JsonResponse
    {
        $validated = $request->validate([
            'retention' => ['sometimes', 'array'],
            'retention.raw_seconds' => ['sometimes', 'integer', 'min:60'],
            'retention.rollup_seconds' => ['sometimes', 'integer', 'min:60'],
            'thresholds' => ['sometimes', 'array'],
            'thresholds.slow_request_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.slow_query_ms' => ['sometimes', 'numeric', 'min:1'],
            'thresholds.queue_size_warning' => ['sometimes', 'integer', 'min:1'],
            'notifications' => ['sometimes', 'array'],
            'notifications.enabled' => ['sometimes', 'boolean'],
            'notifications.webhook_url' => ['nullable', 'url'],
            'notifications.mail_to' => ['sometimes', 'array'],
            'notifications.mail_to.*' => ['email'],
            'sampling' => ['sometimes', 'array'],
            'sampling.request_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.database_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'sampling.performance_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
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
            'actions' => ['sometimes', 'array'],
            'actions.enabled' => ['sometimes', 'boolean'],
        ]);

        $effectiveSettings = $settings->update($validated, optional($request->user())->getAuthIdentifier());
        $telemetry->applySettings($effectiveSettings);

        return $this->envelope($effectiveSettings);
    }
}
