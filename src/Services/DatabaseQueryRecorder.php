<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Created to track database latency and slow queries without storing query result data.
 */
class DatabaseQueryRecorder
{
    /**
     * Created to share Redis telemetry and sanitization with the database listener.
     */
    public function __construct(
        protected RedisTelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to aggregate every sampled query and retain raw records only when latency crosses the configured threshold.
     */
    public function record(QueryExecuted $event): void
    {
        if (! $this->sample((float) config('cosmos-monitor.sampling.database_rate', 1.0))) {
            return;
        }

        $payload = $this->sanitizer->clean([
            'type' => 'database_query',
            'connection' => $event->connectionName,
            'duration_ms' => round((float) $event->time, 2),
            'sql' => method_exists($event, 'toRawSql') ? $event->toRawSql() : $event->sql,
            'statement_hash' => sha1($event->sql),
        ]);

        $this->telemetry->recordAggregate('database', $payload);

        if ((float) $event->time >= (float) config('cosmos-monitor.thresholds.slow_query_ms', 200)) {
            $this->telemetry->recordEvent('database', $payload);
            $this->telemetry->recordEvent('performance', array_merge($payload, ['category' => 'database']));
        }
    }

    /**
     * Created to let production deployments reduce query telemetry cost with a deterministic config knob.
     */
    protected function sample(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return random_int(1, 1000000) <= (int) floor($rate * 1000000);
    }
}
