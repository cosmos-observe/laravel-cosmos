<?php

namespace Cosmos\LaravelMonitor\Logging;

use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Logger;

/**
 * Created to make log capture opt-in through Laravel's logging channel tap configuration.
 */
class CosmosMonitorTap
{
    /**
     * Changed to accept both Laravel's logger wrapper and raw Monolog loggers so the tap works across Laravel 10, 11, and 12 production apps.
     */
    public function __invoke(mixed $logger): void
    {
        $monolog = $this->monologLogger($logger);

        if (! $monolog instanceof Logger) {
            return;
        }

        $monolog->pushHandler(new CosmosMonitorHandler(
            app(\Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository::class),
            app(\Cosmos\LaravelMonitor\Support\PayloadSanitizer::class),
            (array) config('cosmos-monitor.logs.levels', [])
        ));
    }

    /**
     * Created to normalize Laravel's logging wrapper into a Monolog logger without coupling host apps to one Laravel minor version.
     */
    protected function monologLogger(mixed $logger): ?Logger
    {
        if ($logger instanceof Logger) {
            return $logger;
        }

        if ($logger instanceof LaravelLogger) {
            $inner = $logger->getLogger();

            return $inner instanceof Logger ? $inner : null;
        }

        return null;
    }
}
