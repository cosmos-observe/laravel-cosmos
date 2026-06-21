<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Services\StorageSnapshotService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Console\Command;

/**
 * Created to sample configured Laravel storage disks on the host scheduler.
 */
class SampleStorageCommand extends Command
{
    protected $signature = 'cosmos-monitor:sample-storage';

    protected $description = 'Sample configured storage disk file counts and disk pressure into Cosmos Monitor telemetry.';

    /**
     * Created to apply durable settings before collecting disk snapshots.
     */
    public function handle(StorageSnapshotService $storage, TelemetryRepository $telemetry, SettingsService $settings): int
    {
        $telemetry->applySettings($settings->all());
        $snapshots = $storage->sampleConfiguredDisks();

        $this->components->info('Cosmos Monitor storage disks sampled: ' . count($snapshots));

        return self::SUCCESS;
    }
}
