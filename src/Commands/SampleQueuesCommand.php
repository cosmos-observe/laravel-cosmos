<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Services\NotificationService;
use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Created to sample queue sizes on a schedule because Laravel queue events do not expose pending queue depth.
 */
class SampleQueuesCommand extends Command
{
    protected $signature = 'cosmos-monitor:sample-queues';

    protected $description = 'Sample configured queue sizes into Cosmos Monitor telemetry.';

    /**
     * Created to record queue depth and trigger notifications when configured thresholds are exceeded.
     */
    public function handle(QueueFactory $queues, TelemetryRepository $telemetry, NotificationService $notifications, SettingsService $settings): int
    {
        $effectiveSettings = $settings->all();
        $telemetry->applySettings($effectiveSettings);
        $threshold = (int) ($effectiveSettings['thresholds']['queue_size_warning'] ?? config('cosmos-monitor.thresholds.queue_size_warning', 1000));

        foreach ((array) config('cosmos-monitor.queues', []) as $queueConfig) {
            $connectionName = $queueConfig['connection'] ?? null;
            $queueName = $queueConfig['queue'] ?? 'default';
            try {
                $size = $queues->connection($connectionName)->size($queueName);
            } catch (\Throwable $exception) {
                $telemetry->recordEvent('monitor', [
                    'type' => 'monitor_self',
                    'event' => 'queue_sample_failed',
                    'connection' => $connectionName ?: config('queue.default'),
                    'queue' => $queueName,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $payload = [
                'type' => 'queue_depth',
                'event' => 'sampled',
                'connection' => $connectionName ?: config('queue.default'),
                'queue' => $queueName,
                'size' => $size,
                'status' => $size >= $threshold ? 'warning' : 'ok',
                'oldest_pending_age_seconds' => $this->oldestPendingAgeSeconds($queueName),
                'failed_jobs_count' => $this->failedJobsCount(),
            ];

            $telemetry->recordEvent('queues', $payload);

            if ($size >= $threshold) {
                $notifications->dispatch('queue_size_warning', $payload);
            }
        }

        $this->components->info('Cosmos Monitor queue sizes sampled.');

        return self::SUCCESS;
    }

    /**
     * Created to estimate pending job age for database queues when the host app exposes the jobs table.
     */
    protected function oldestPendingAgeSeconds(string $queueName): ?int
    {
        try {
            if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
                return null;
            }

            $oldest = DB::table('jobs')->where('queue', $queueName)->min('created_at');

            return $oldest ? max(0, time() - (int) $oldest) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Created to expose failed-job backlog counts when Laravel's failed_jobs table is available.
     */
    protected function failedJobsCount(): ?int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return null;
            }

            return DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return null;
        }
    }
}
