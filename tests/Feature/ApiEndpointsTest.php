<?php

namespace Cosmos\LaravelMonitor\Tests\Feature;

use Cosmos\LaravelMonitor\Events\CosmosMonitorNotificationTriggered;
use Cosmos\LaravelMonitor\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * Created to verify the public monitoring API envelope and settings persistence.
 */
class ApiEndpointsTest extends TestCase
{
    /**
     * Created to ensure the health endpoint exposes Redis and package identity in the standard envelope.
     */
    public function test_health_endpoint_returns_envelope(): void
    {
        $this->getJson('/api/cosmos-monitor/v1/health')
            ->assertOk()
            ->assertJsonPath('data.redis', 'ok')
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    /**
     * Created to ensure telemetry list endpoints expose repository data with filters and pagination metadata.
     */
    public function test_requests_endpoint_returns_paginated_telemetry(): void
    {
        $this->telemetry->recordEvent('requests', [
            'method' => 'GET',
            'route' => 'health',
            'status' => 200,
            'duration_ms' => 10,
        ]);

        $this->getJson('/api/cosmos-monitor/v1/requests?status=200&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.stream', 'requests')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.route', 'health');
    }

    /**
     * Created to verify durable settings updates work through the API and remain separate from Redis telemetry.
     */
    public function test_settings_can_be_updated(): void
    {
        $this->putJson('/api/cosmos-monitor/v1/settings', [
            'retention' => [
                'raw_seconds' => 7200,
                'rollup_seconds' => 86400,
            ],
            'thresholds' => [
                'slow_request_ms' => 500,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.retention.raw_seconds', 7200)
            ->assertJsonPath('data.thresholds.slow_request_ms', 500);
    }

    /**
     * Created to verify rollup API responses can feed an external metrics chart.
     */
    public function test_timeseries_endpoint_returns_rollup_points(): void
    {
        $this->telemetry->recordEvent('requests', [
            'method' => 'GET',
            'route' => 'metrics',
            'status' => 200,
            'duration_ms' => 25,
        ]);

        $this->getJson('/api/cosmos-monitor/v1/metrics/timeseries?stream=requests&interval=minute')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links'])
            ->assertJsonPath('meta.stream', 'requests');
    }

    /**
     * Created to verify notification testing dispatches the package event for host-defined channels.
     */
    public function test_notification_test_endpoint_dispatches_event(): void
    {
        Event::fake([CosmosMonitorNotificationTriggered::class]);

        $this->postJson('/api/cosmos-monitor/v1/notifications/test', [
            'message' => 'probe',
            'severity' => 'info',
        ])
            ->assertOk()
            ->assertJsonPath('data.event', 'dispatched');

        Event::assertDispatched(CosmosMonitorNotificationTriggered::class);
    }
}
