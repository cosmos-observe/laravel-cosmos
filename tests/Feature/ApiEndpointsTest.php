<?php

namespace Cosmos\LaravelMonitor\Tests\Feature;

use Cosmos\LaravelMonitor\Events\CosmosMonitorNotificationTriggered;
use Cosmos\LaravelMonitor\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Created to verify the public monitoring API envelope and settings persistence.
 */
class ApiEndpointsTest extends TestCase
{
    /**
     * Created to ensure the health endpoint exposes ClickHouse and package identity in the standard envelope.
     */
    public function test_health_endpoint_returns_envelope(): void
    {
        $this->getJson('/api/cosmos-monitor/v1/health')
            ->assertOk()
            ->assertJsonPath('data.storage_driver', 'clickhouse')
            ->assertJsonPath('data.telemetry_storage', 'ok')
            ->assertJsonPath('data.clickhouse', 'ok')
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
     * Created to verify durable settings updates work through the API and remain separate from high-volume telemetry.
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
            'notifications' => [
                'fcm_enabled' => true,
                'fcm_tokens' => ['browser-token-1'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.retention.raw_seconds', 7200)
            ->assertJsonPath('data.thresholds.slow_request_ms', 500)
            ->assertJsonPath('data.notifications.fcm_enabled', true)
            ->assertJsonPath('data.notifications.fcm_tokens.0', 'browser-token-1');
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
     * Created to verify new storage and external service streams participate in summary metrics.
     */
    public function test_storage_and_external_service_streams_are_summarized(): void
    {
        $this->telemetry->recordEvent('storage', [
            'disk' => 'local',
            'status' => 'ok',
            'total_files' => 2,
        ]);

        $this->telemetry->recordEvent('external-services', [
            'service_id' => '1',
            'service_name' => 'Example API',
            'status' => 'up',
            'duration_ms' => 42,
        ]);

        $this->telemetry->recordEvent('external-requests', [
            'host' => 'api.example.test',
            'status' => 200,
            'status_family' => '2xx',
            'duration_ms' => 30,
        ]);

        $this->telemetry->recordEvent('mail', [
            'mailer' => 'smtp',
            'status' => 'sent',
            'duration_ms' => 22,
        ]);

        $this->getJson('/api/cosmos-monitor/v1/metrics/summary?streams=storage,external-services,external-requests,mail')
            ->assertOk()
            ->assertJsonPath('data.storage.count', 1)
            ->assertJsonPath('data.external-services.count', 1)
            ->assertJsonPath('data.external-requests.count', 1)
            ->assertJsonPath('data.mail.count', 1);
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

    /**
     * Created to verify dashboard browsers can register and remove Firebase Cloud Messaging tokens through the public API.
     */
    public function test_fcm_token_registration_updates_notification_settings(): void
    {
        $this->postJson('/api/cosmos-monitor/v1/notifications/fcm/register', [
            'token' => 'browser-token-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.registered', true)
            ->assertJsonPath('data.token_count', 1);

        $this->getJson('/api/cosmos-monitor/v1/settings')
            ->assertOk()
            ->assertJsonPath('data.notifications.fcm_enabled', true)
            ->assertJsonPath('data.notifications.fcm_tokens.0', 'browser-token-1');

        $this->deleteJson('/api/cosmos-monitor/v1/notifications/fcm/register', [
            'token' => 'browser-token-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.registered', false)
            ->assertJsonPath('data.token_count', 0);
    }

    /**
     * Created to verify FCM test dispatches use mocked Firebase HTTP v1 calls instead of reaching Google during tests.
     */
    public function test_notification_test_endpoint_dispatches_fcm_message(): void
    {
        $privateKey = $this->testingPrivateKey();
        config()->set('cosmos-monitor.firebase.enabled', true);
        config()->set('cosmos-monitor.firebase.project_id', 'cosmos-monitor-cc3da');
        config()->set('cosmos-monitor.firebase.client_email', 'firebase-adminsdk-fbsvc@cosmos-monitor-cc3da.iam.gserviceaccount.com');
        config()->set('cosmos-monitor.firebase.private_key', $privateKey);
        config()->set('cosmos-monitor.firebase.token_uri', 'https://oauth2.googleapis.com/token');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'https://fcm.googleapis.com/v1/projects/cosmos-monitor-cc3da/messages:send' => Http::response(['name' => 'projects/cosmos-monitor-cc3da/messages/test'], 200),
        ]);

        $this->putJson('/api/cosmos-monitor/v1/settings', [
            'notifications' => [
                'enabled' => true,
                'fcm_enabled' => true,
                'fcm_tokens' => ['browser-token-1'],
            ],
        ])->assertOk();

        $this->postJson('/api/cosmos-monitor/v1/notifications/test', [
            'message' => 'probe',
            'severity' => 'info',
        ])
            ->assertOk()
            ->assertJsonPath('data.fcm.sent', 1)
            ->assertJsonPath('data.fcm.failed', 0);

        Http::assertSentCount(2);
    }

    protected function testingPrivateKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        return $privateKey;
    }
}
