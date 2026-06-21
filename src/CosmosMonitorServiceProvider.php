<?php

namespace Cosmos\LaravelMonitor;

use Cosmos\LaravelMonitor\Commands\PruneTelemetryCommand;
use Cosmos\LaravelMonitor\Commands\CheckExternalServicesCommand;
use Cosmos\LaravelMonitor\Commands\InstallClickHouseSchemaCommand;
use Cosmos\LaravelMonitor\Commands\SampleStorageCommand;
use Cosmos\LaravelMonitor\Commands\SampleQueuesCommand;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Http\Middleware\CaptureRequestMetrics;
use Cosmos\LaravelMonitor\Http\Middleware\EnsureMonitorApiToken;
use Cosmos\LaravelMonitor\Services\CacheEventRecorder;
use Cosmos\LaravelMonitor\Services\DatabaseQueryRecorder;
use Cosmos\LaravelMonitor\Services\ExceptionRecorder;
use Cosmos\LaravelMonitor\Services\ExceptionStateService;
use Cosmos\LaravelMonitor\Services\ExternalHttpRequestRecorder;
use Cosmos\LaravelMonitor\Services\QueueEventRecorder;
use Cosmos\LaravelMonitor\Services\ScheduleEventRecorder;
use Cosmos\LaravelMonitor\Services\SettingsService;
use Cosmos\LaravelMonitor\Services\ExternalServiceChecker;
use Cosmos\LaravelMonitor\Services\MailEventRecorder;
use Cosmos\LaravelMonitor\Services\StorageSnapshotService;
use Cosmos\LaravelMonitor\Storage\ClickHouse\ClickHouseClient;
use Cosmos\LaravelMonitor\Storage\ClickHouse\ClickHouseTelemetryRepository;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mailer\Event\FailedMessageEvent;

/**
 * Created to wire the package into Laravel applications without requiring Blade, Telescope, or database-backed telemetry.
 */
class CosmosMonitorServiceProvider extends ServiceProvider
{
    /**
     * Created to register shared package services that need the host application's configuration bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cosmos-monitor.php', 'cosmos-monitor');

        /**
         * Created to build the sanitizer from current package config when the container first resolves it.
         */
        $this->app->singleton(PayloadSanitizer::class, function (): PayloadSanitizer {
            return new PayloadSanitizer((array) config('cosmos-monitor.redaction'), (array) config('cosmos-monitor.limits'));
        });

        /**
         * Created to bind the ClickHouse HTTP client lazily so tests and host apps can override it.
         */
        $this->app->singleton(ClickHouseClient::class, function ($app): ClickHouseClient {
            return new ClickHouseClient($app->make(HttpFactory::class), (array) config('cosmos-monitor.clickhouse'));
        });

        /**
         * Created to bind the ClickHouse repository as the runtime telemetry backend.
         */
        $this->app->singleton(ClickHouseTelemetryRepository::class, function ($app): ClickHouseTelemetryRepository {
            return new ClickHouseTelemetryRepository($app->make(ClickHouseClient::class), (array) config('cosmos-monitor'));
        });

        /**
         * Created to let host applications depend on the telemetry storage contract instead of a concrete backend.
         */
        $this->app->singleton(TelemetryRepository::class, function ($app): TelemetryRepository {
            return $app->make(ClickHouseTelemetryRepository::class);
        });

        $this->app->singleton(SettingsService::class);
        $this->app->singleton(QueueEventRecorder::class);
        $this->app->singleton(ScheduleEventRecorder::class);
        $this->app->singleton(DatabaseQueryRecorder::class);
        $this->app->singleton(ExceptionRecorder::class);
        $this->app->singleton(ExceptionStateService::class);
        $this->app->singleton(CacheEventRecorder::class);
        $this->app->singleton(StorageSnapshotService::class);
        $this->app->singleton(ExternalServiceChecker::class);
        $this->app->singleton(ExternalHttpRequestRecorder::class);
        $this->app->singleton(MailEventRecorder::class);
    }

    /**
     * Created to expose routes, publishable assets, commands, and production-safe collectors when the package boots.
     */
    public function boot(): void
    {
        $this->publishPackageFiles();
        $this->loadPackageRoutes();
        $this->registerCommands();
        $this->registerCollectors();
    }

    /**
     * Created to let host applications publish config, migrations, and API documentation as first-class package artifacts.
     */
    protected function publishPackageFiles(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cosmos-monitor.php' => config_path('cosmos-monitor.php'),
        ], 'cosmos-monitor-config');

        $this->publishes([
            __DIR__ . '/../database/migrations/2026_01_01_000000_create_cosmos_monitor_settings_table.php' => database_path('migrations/2026_01_01_000000_create_cosmos_monitor_settings_table.php'),
            __DIR__ . '/../database/migrations/2026_01_01_000001_create_cosmos_monitor_exception_states_table.php' => database_path('migrations/2026_01_01_000001_create_cosmos_monitor_exception_states_table.php'),
            __DIR__ . '/../database/migrations/2026_01_01_000002_create_cosmos_monitor_external_services_table.php' => database_path('migrations/2026_01_01_000002_create_cosmos_monitor_external_services_table.php'),
        ], 'cosmos-monitor-migrations');

        $this->publishes([
            __DIR__ . '/../docs' => base_path('docs/cosmos-monitor'),
        ], 'cosmos-monitor-docs');
    }

    /**
     * Created to load versioned JSON API routes behind configurable middleware and prefix settings.
     */
    protected function loadPackageRoutes(): void
    {
        if (! config('cosmos-monitor.enabled')) {
            return;
        }

        $middleware = array_values(array_filter((array) config('cosmos-monitor.middleware', ['api'])));

        if (config('cosmos-monitor.api_token')) {
            $middleware[] = EnsureMonitorApiToken::class;
        }

        $this->app['router']
            ->middleware($middleware)
            ->prefix(trim((string) config('cosmos-monitor.route_prefix', 'api/cosmos-monitor/v1'), '/'))
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Created to register operational commands for queue sampling, ClickHouse schema install, and telemetry retention reporting.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneTelemetryCommand::class,
                InstallClickHouseSchemaCommand::class,
                SampleQueuesCommand::class,
                SampleStorageCommand::class,
                CheckExternalServicesCommand::class,
            ]);
        }
    }

    /**
     * Created to attach collectors only when monitoring is enabled so production hosts can disable all instrumentation quickly.
     */
    protected function registerCollectors(): void
    {
        if (! config('cosmos-monitor.enabled')) {
            return;
        }

        $this->registerHttpCollector();
        $this->registerDatabaseCollector();
        $this->registerQueueCollectors();
        $this->registerScheduleCollectors();
        $this->registerExceptionCollector();
        $this->registerCacheCollectors();
        $this->registerExternalRequestCollectors();
        $this->registerMailCollectors();
    }

    /**
     * Created to capture request latency and RPS automatically through Laravel's HTTP kernel.
     */
    protected function registerHttpCollector(): void
    {
        if (! config('cosmos-monitor.capture.requests')) {
            return;
        }

        if ($this->app->bound(Kernel::class)) {
            $this->app->make(Kernel::class)->pushMiddleware(CaptureRequestMetrics::class);
        }
    }

    /**
     * Created to monitor database latency through Laravel's query listener without storing full result data.
     */
    protected function registerDatabaseCollector(): void
    {
        if (! config('cosmos-monitor.capture.database')) {
            return;
        }

        /**
         * Created to forward query execution events to the database telemetry recorder.
         */
        DB::listen(function (QueryExecuted $event): void {
            $this->app->make(DatabaseQueryRecorder::class)->record($event);
        });
    }

    /**
     * Created to record queue job lifecycle events and keep queue monitoring independent from Horizon.
     */
    protected function registerQueueCollectors(): void
    {
        if (! config('cosmos-monitor.capture.queues')) {
            return;
        }

        /**
         * Created to forward job start events to the queue recorder.
         */
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(QueueEventRecorder::class)->processing($event);
        });

        /**
         * Created to forward successful job events to the queue recorder.
         */
        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->app->make(QueueEventRecorder::class)->processed($event);
        });

        /**
         * Created to forward failed job events to the queue recorder.
         */
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->app->make(QueueEventRecorder::class)->failed($event);
        });
    }

    /**
     * Created to monitor Laravel scheduler health through framework events when the host Laravel version provides them.
     */
    protected function registerScheduleCollectors(): void
    {
        if (! config('cosmos-monitor.capture.schedules')) {
            return;
        }

        $listeners = [
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFinished::class => 'finished',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskSkipped::class => 'skipped',
        ];

        foreach ($listeners as $eventClass => $method) {
            if (! class_exists($eventClass)) {
                continue;
            }

            /**
             * Created to route version-specific scheduler events to the matching recorder method.
             */
            Event::listen($eventClass, function (object $event) use ($method): void {
                $this->app->make(ScheduleEventRecorder::class)->{$method}($event);
            });
        }
    }

    /**
     * Created to hook Laravel's exception handler so production exceptions are grouped and stored in telemetry.
     */
    protected function registerExceptionCollector(): void
    {
        if (! config('cosmos-monitor.capture.exceptions')) {
            return;
        }

        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'reportable')) {
            /**
             * Created to forward reportable exceptions to telemetry without replacing Laravel's handler.
             */
            $handler->reportable(function (\Throwable $exception): void {
                $this->app->make(ExceptionRecorder::class)->record($exception);
            });
        }
    }

    /**
     * Created to record cache events that Laravel emits so dashboard cache health is available without Telescope.
     */
    protected function registerCacheCollectors(): void
    {
        if (! config('cosmos-monitor.capture.cache')) {
            return;
        }

        $listeners = [
            CacheHit::class => 'hit',
            CacheMissed::class => 'missed',
            KeyWritten::class => 'written',
            KeyForgotten::class => 'forgotten',
        ];

        foreach ($listeners as $eventClass => $eventName) {
            if (! class_exists($eventClass)) {
                continue;
            }

            /**
             * Created to route framework cache events to the package cache recorder.
             */
            Event::listen($eventClass, function (object $event) use ($eventName): void {
                $this->app->make(CacheEventRecorder::class)->record($event, $eventName);
            });
        }
    }

    /**
     * Created to monitor outbound Laravel HTTP client traffic while allowing direct Guzzle users to opt into middleware.
     */
    protected function registerExternalRequestCollectors(): void
    {
        if (! config('cosmos-monitor.capture.external_requests')) {
            return;
        }

        $listeners = [
            RequestSending::class => 'sending',
            ResponseReceived::class => 'responseReceived',
            ConnectionFailed::class => 'connectionFailed',
        ];

        foreach ($listeners as $eventClass => $method) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, function (object $event) use ($method): void {
                $this->app->make(ExternalHttpRequestRecorder::class)->{$method}($event);
            });
        }
    }

    /**
     * Created to monitor mail sending metadata without storing message bodies, attachments, or full recipient addresses.
     */
    protected function registerMailCollectors(): void
    {
        if (! config('cosmos-monitor.capture.mail')) {
            return;
        }

        $listeners = [
            MessageSending::class => 'sending',
            MessageSent::class => 'sent',
            FailedMessageEvent::class => 'failed',
        ];

        foreach ($listeners as $eventClass => $method) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, function (object $event) use ($method): void {
                $this->app->make(MailEventRecorder::class)->{$method}($event);
            });
        }
    }
}
