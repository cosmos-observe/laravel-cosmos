<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Console\Command;

/**
 * Created to give operators a scheduled command for reporting telemetry retention status.
 */
class PruneTelemetryCommand extends Command
{
    protected $signature = 'cosmos-monitor:prune';

    protected $description = 'Report Cosmos Monitor telemetry retention status.';

    /**
     * Created to apply durable settings before asking the telemetry backend for retention status.
     */
    public function handle(TelemetryRepository $telemetry, SettingsService $settings): int
    {
        $telemetry->applySettings($settings->all());

        $deleted = $telemetry->prune();

        $this->components->info('Cosmos Monitor telemetry retention checked.');
        $this->line(json_encode($deleted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
