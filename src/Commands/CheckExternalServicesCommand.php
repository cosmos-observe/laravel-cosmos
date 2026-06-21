<?php

namespace Cosmos\LaravelMonitor\Commands;

use Cosmos\LaravelMonitor\Services\ExternalServiceChecker;
use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Console\Command;

/**
 * Created to probe registered external HTTP services on the host scheduler.
 */
class CheckExternalServicesCommand extends Command
{
    protected $signature = 'cosmos-monitor:check-external-services';

    protected $description = 'Check registered external service URLs and record availability telemetry.';

    /**
     * Created to apply durable settings before checking enabled services.
     */
    public function handle(ExternalServiceChecker $checker, TelemetryRepository $telemetry, SettingsService $settings): int
    {
        $telemetry->applySettings($settings->all());
        $results = $checker->checkEnabledServices();

        $this->components->info('Cosmos Monitor external services checked: ' . count($results));

        return self::SUCCESS;
    }
}
