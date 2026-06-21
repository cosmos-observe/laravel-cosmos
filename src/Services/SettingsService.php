<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Models\MonitorSetting;

/**
 * Created to manage durable notification, retention, and threshold settings separately from high-volume telemetry.
 */
class SettingsService
{
    /**
     * Created to return effective settings by overlaying database values on top of package config defaults.
     */
    public function all(): array
    {
        $settings = $this->defaults();

        try {
            foreach (MonitorSetting::query()->get() as $setting) {
                $settings[$setting->key] = is_array($settings[$setting->key] ?? null) && is_array($setting->value)
                    ? array_replace_recursive($settings[$setting->key], $setting->value)
                    : $setting->value;
            }
        } catch (\Throwable) {
            return $settings;
        }

        return $settings;
    }

    /**
     * Created to update only supported settings keys and keep the DB table from becoming an arbitrary key-value dump.
     */
    public function update(array $values, ?string $updatedBy = null): array
    {
        $allowed = array_keys($this->defaults());

        foreach ($values as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            MonitorSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => 'json',
                    'description' => $this->descriptions()[$key] ?? null,
                    'updated_by' => $updatedBy,
                ]
            );
        }

        return $this->all();
    }

    /**
     * Created to provide stable defaults that match the production retention and notification plan.
     */
    public function defaults(): array
    {
        return [
            'retention' => config('cosmos-monitor.retention'),
            'thresholds' => config('cosmos-monitor.thresholds'),
            'notifications' => config('cosmos-monitor.notifications'),
            'sampling' => config('cosmos-monitor.sampling'),
            'capture' => config('cosmos-monitor.capture'),
            'limits' => config('cosmos-monitor.limits'),
            'storage' => config('cosmos-monitor.storage'),
            'clickhouse' => config('cosmos-monitor.clickhouse'),
            'storage_monitor' => config('cosmos-monitor.storage_monitor'),
            'external_services' => config('cosmos-monitor.external_services'),
            'actions' => config('cosmos-monitor.actions'),
        ];
    }

    /**
     * Created to explain persisted settings when operators inspect the settings table directly.
     */
    protected function descriptions(): array
    {
        return [
            'retention' => 'Legacy raw and rollup retention windows for telemetry.',
            'thresholds' => 'Slow request, slow query, and queue size thresholds.',
            'notifications' => 'Webhook, mail, FCM browser tokens, and enabled flags for monitor notifications.',
            'sampling' => 'Sampling rates for high-volume telemetry collectors.',
            'capture' => 'Feature flags for runtime telemetry collectors.',
            'limits' => 'Bounded query, payload, stream, and retention-reporting limits for telemetry.',
            'storage' => 'Fail-open, payload compression, and secondary-index storage controls.',
            'clickhouse' => 'ClickHouse connection and retention controls for runtime telemetry.',
            'storage_monitor' => 'Selected filesystem disks and pressure thresholds for storage sampling.',
            'external_services' => 'Timeout and client defaults for external service health checks.',
            'actions' => 'Disabled-by-default diagnostic action controls.',
        ];
    }
}
