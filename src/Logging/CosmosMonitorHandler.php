<?php

namespace Cosmos\LaravelMonitor\Logging;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Created to let Laravel logging channels write selected Monolog records into telemetry.
 */
class CosmosMonitorHandler extends AbstractProcessingHandler
{
    /**
     * Created to configure log level filtering separately from Monolog's channel level.
     */
    public function __construct(
        protected TelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer,
        protected array $levels = [],
        bool $bubble = true
    ) {
        parent::__construct(Level::Debug, $bubble);
    }

    /**
     * Created to persist a sanitized log record when its level is included in monitor configuration.
     */
    protected function write(LogRecord $record): void
    {
        $level = strtolower($record->level->getName());

        if ($this->levels !== [] && ! in_array($level, array_map('strtolower', $this->levels), true)) {
            return;
        }

        $this->telemetry->recordEvent('logs', $this->sanitizer->clean([
            'type' => 'log',
            'level' => $level,
            'message' => $record->message,
            'context' => $record->context,
            'channel' => $record->channel,
            'extra' => $record->extra,
        ]));
    }
}
