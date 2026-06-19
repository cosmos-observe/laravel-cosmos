<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;

/**
 * Created to monitor Laravel cache activity so dashboards can show cache hit, miss, write, and forget patterns.
 */
class CacheEventRecorder
{
    /**
     * Created to share Redis telemetry and sanitization across cache event callbacks.
     */
    public function __construct(
        protected RedisTelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to record cache events across Laravel versions whose event property names vary slightly.
     */
    public function record(object $event, string $eventName): void
    {
        $payload = $this->sanitizer->clean([
            'type' => 'cache_event',
            'event' => $eventName,
            'key' => $this->property($event, 'key'),
            'store' => $this->property($event, 'storeName') ?? $this->property($event, 'store'),
            'tags' => $this->property($event, 'tags'),
        ]);

        $this->telemetry->recordEvent('cache', $payload);
    }

    /**
     * Created to read public properties safely from framework cache event objects.
     */
    protected function property(object $event, string $name): mixed
    {
        return property_exists($event, $name) ? $event->{$name} : null;
    }
}
