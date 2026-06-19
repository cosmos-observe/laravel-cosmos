<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Console\Command;

/**
 * Created to give operators a scheduled command for enforcing Redis retention limits.
 */
class PruneTelemetryCommand extends Command
{
    protected $signature = 'cosmos-monitor:prune';

    protected $description = 'Prune expired Cosmos Monitor Redis telemetry.';

    /**
     * Created to remove stale raw telemetry and old rollups from Redis.
     */
    public function handle(RedisTelemetryRepository $telemetry, SettingsService $settings): int
    {
        $telemetry->applySettings($settings->all());

        $deleted = $telemetry->prune();

        $this->components->info('Cosmos Monitor telemetry pruned.');
        $this->line(json_encode($deleted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
