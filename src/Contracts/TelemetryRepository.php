<?php

namespace Cosmos\LaravelMonitor\Contracts;

/**
 * Created to define the storage boundary so collectors and API controllers stay independent from the Redis implementation.
 */
interface TelemetryRepository
{
    /**
     * Created to store one recent telemetry event and update its aggregate rollups in the same call.
     */
    public function recordEvent(string $stream, array $payload, ?int $timestampMs = null): string;

    /**
     * Created to update rollup counters for high-volume signals without always storing a raw event.
     */
    public function recordAggregate(string $stream, array $payload, ?int $timestampMs = null): void;

    /**
     * Created to expose sorted, filtered, paginated telemetry for the monitoring API.
     */
    public function listEvents(string $stream, array $filters = []): array;

    /**
     * Created to provide a compact dashboard summary without scanning every Redis payload.
     */
    public function summary(array $streams, array $filters = []): array;

    /**
     * Created to provide chart-ready rollup points for the external monitoring panel.
     */
    public function timeseries(string $stream, array $filters = []): array;

    /**
     * Created to remove stale raw events and rollups according to bounded production retention.
     */
    public function prune(?int $nowMs = null): array;

    /**
     * Created to allow health checks to verify Redis availability through the same storage boundary.
     */
    public function ping(): bool;
}
