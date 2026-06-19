<?php

namespace Cosmos\LaravelMonitor\Events;

/**
 * Created to let host applications attach custom channels when the package sends or tests monitor notifications.
 */
class CosmosMonitorNotificationTriggered
{
    /**
     * Created to expose notification type and payload to Laravel listeners.
     */
    public function __construct(
        public string $type,
        public array $payload
    ) {
    }
}
