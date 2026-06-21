<?php

namespace Cosmos\LaravelMonitor\Tests\Feature;

use Cosmos\LaravelMonitor\Events\CosmosMonitorNotificationTriggered;
use Cosmos\LaravelMonitor\Models\ExternalService;
use Cosmos\LaravelMonitor\Services\ExternalServiceChecker;
use Cosmos\LaravelMonitor\Services\StorageSnapshotService;
use Cosmos\LaravelMonitor\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Created to verify storage snapshots and external service health checks through the public package surface.
 */
class StorageAndExternalServicesTest extends TestCase
{
    /**
     * Created to ensure local disk sampling records file totals and exposes them over the storage endpoint.
     */
    public function test_storage_sampler_records_local_file_counts(): void
    {
        $root = sys_get_temp_dir() . '/cosmos-storage-test-' . bin2hex(random_bytes(4));
        mkdir($root . '/nested', 0777, true);
        file_put_contents($root . '/alpha.txt', 'abc');
        file_put_contents($root . '/nested/beta.txt', 'defgh');

        config()->set('filesystems.disks.cosmos_test', [
            'driver' => 'local',
            'root' => $root,
        ]);
        config()->set('cosmos-monitor.storage_monitor.disks', ['cosmos_test']);
        config()->set('cosmos-monitor.storage_monitor.warning_used_percent', 100);
        config()->set('cosmos-monitor.storage_monitor.critical_used_percent', 100);

        app(StorageSnapshotService::class)->sampleConfiguredDisks();

        $this->getJson('/api/cosmos-monitor/v1/storage?disk=cosmos_test&per_page=5')
            ->assertOk()
            ->assertJsonPath('data.0.disk', 'cosmos_test')
            ->assertJsonPath('data.0.total_files', 2)
            ->assertJsonPath('data.0.total_bytes', 8)
            ->assertJsonPath('data.0.status', 'ok');

        @unlink($root . '/nested/beta.txt');
        @unlink($root . '/alpha.txt');
        @rmdir($root . '/nested');
        @rmdir($root);
    }

    /**
     * Created to verify service CRUD and manual checks update latest status fields.
     */
    public function test_external_service_crud_and_manual_check(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $created = $this->postJson('/api/cosmos-monitor/v1/external-services', [
            'name' => 'Example API',
            'url' => 'https://api.example.com/health',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Example API')
            ->json('data');

        $this->postJson('/api/cosmos-monitor/v1/external-services/' . $created['id'] . '/check')
            ->assertOk()
            ->assertJsonPath('data.check.status', 'up')
            ->assertJsonPath('data.check.http_status', 200);

        $this->putJson('/api/cosmos-monitor/v1/external-services/' . $created['id'], [
            'enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->deleteJson('/api/cosmos-monitor/v1/external-services/' . $created['id'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    /**
     * Created to verify HTTP status families map to up, reachable warning, and down.
     */
    public function test_external_service_checker_maps_statuses_and_records_transitions(): void
    {
        Event::fake([CosmosMonitorNotificationTriggered::class]);

        $service = ExternalService::query()->create([
            'name' => 'OpenAI',
            'url' => 'https://api.openai.test/health',
            'enabled' => true,
            'latest_status' => 'up',
        ]);

        Http::fakeSequence()
            ->push('not found', 404)
            ->push('server error', 503);

        $warning = app(ExternalServiceChecker::class)->check($service->fresh());
        $this->assertSame('reachable_warning', $warning['status']);

        $down = app(ExternalServiceChecker::class)->check($service->fresh());
        $this->assertSame('down', $down['status']);

        $this->getJson('/api/cosmos-monitor/v1/metrics/summary?streams=external-services')
            ->assertOk()
            ->assertJsonPath('data.external-services.count', 2);

        Event::assertDispatched(CosmosMonitorNotificationTriggered::class);
    }
}
