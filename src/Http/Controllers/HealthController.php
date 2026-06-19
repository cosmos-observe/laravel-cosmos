<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Created to expose package, Redis, and settings-table health to the external monitoring panel.
 */
class HealthController extends Controller
{
    /**
     * Created to return a lightweight health response without reading high-volume telemetry.
     */
    public function __invoke(RedisTelemetryRepository $telemetry): JsonResponse
    {
        $redisOk = $telemetry->ping();
        $settingsTable = $this->settingsTableExists();

        return $this->envelope([
            'status' => $redisOk ? 'ok' : 'degraded',
            'redis' => $redisOk ? 'ok' : 'unavailable',
            'settings_table' => $settingsTable ? 'ok' : 'missing',
            'database' => $this->databaseHealth(),
            'runtime' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'package_version' => config('cosmos-monitor.package_version'),
            ],
            'queue' => [
                'default_connection' => config('queue.default'),
                'manager' => app()->bound('queue') ? Queue::getFacadeRoot()::class : null,
                'monitored' => config('cosmos-monitor.queues', []),
            ],
            'app_id' => config('cosmos-monitor.app_id'),
            'environment' => config('cosmos-monitor.environment'),
            'hostname' => config('cosmos-monitor.hostname'),
        ]);
    }

    /**
     * Created to avoid failing the health endpoint when the settings migration has not been published yet.
     */
    protected function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('cosmos_monitor_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Created to expose lightweight DB ping latency for runtime health dashboards.
     */
    protected function databaseHealth(): array
    {
        try {
            $started = microtime(true);
            DB::select('select 1');

            return [
                'status' => 'ok',
                'latency_ms' => round((microtime(true) - $started) * 1000, 2),
                'connection' => config('database.default'),
            ];
        } catch (\Throwable $exception) {
            return [
                'status' => 'unavailable',
                'error' => $exception->getMessage(),
                'connection' => config('database.default'),
            ];
        }
    }
}
