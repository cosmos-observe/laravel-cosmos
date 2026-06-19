<?php

namespace Cosmos\LaravelMonitor\Tests;

use Cosmos\LaravelMonitor\CosmosMonitorServiceProvider;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Tests\Fakes\FakeRedisFactory;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Created to boot the package inside Orchestra Testbench with fake Redis and an in-memory database.
 */
abstract class TestCase extends Orchestra
{
    protected RedisTelemetryRepository $telemetry;

    /**
     * Created to bind fake Redis telemetry after the Testbench application is ready.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->telemetry = new RedisTelemetryRepository(new FakeRedisFactory(), (array) config('cosmos-monitor'));
        $this->app->instance(RedisTelemetryRepository::class, $this->telemetry);
    }

    /**
     * Created to register the package service provider in Testbench.
     */
    protected function getPackageProviders($app): array
    {
        return [
            CosmosMonitorServiceProvider::class,
        ];
    }

    /**
     * Created to configure testing database and disable request middleware capture noise.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cosmos-monitor.capture.requests', false);
        $app['config']->set('cosmos-monitor.capture.database', false);
        $app['config']->set('cosmos-monitor.capture.queues', false);
        $app['config']->set('cosmos-monitor.capture.schedules', false);
        $app['config']->set('cosmos-monitor.capture.exceptions', false);
    }

    /**
     * Created to load the package settings migration for settings API tests.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
