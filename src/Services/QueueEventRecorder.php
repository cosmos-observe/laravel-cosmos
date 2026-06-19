<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Created to monitor queue job lifecycle events without depending on Laravel Horizon.
 */
class QueueEventRecorder
{
    protected array $startedAt = [];

    /**
     * Created to share Redis telemetry and sanitization across queue lifecycle callbacks.
     */
    public function __construct(
        protected RedisTelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to record when a worker starts a job so processed and failed events can include runtime.
     */
    public function processing(JobProcessing $event): void
    {
        $key = $this->jobKey($event);
        $this->startedAt[$key] = hrtime(true);

        $this->telemetry->recordEvent('jobs', $this->sanitizer->clean(array_merge(
            $this->basePayload($event),
            ['event' => 'processing']
        )));
    }

    /**
     * Created to record successful job completions with queue, job name, attempts, and duration.
     */
    public function processed(JobProcessed $event): void
    {
        $key = $this->jobKey($event);
        $payload = array_merge($this->basePayload($event), [
            'event' => 'processed',
            'duration_ms' => $this->durationMs($key),
        ]);

        unset($this->startedAt[$key]);

        $this->telemetry->recordEvent('jobs', $this->sanitizer->clean($payload));
    }

    /**
     * Created to record failed jobs and their exception summary for queue reliability monitoring.
     */
    public function failed(JobFailed $event): void
    {
        $key = $this->jobKey($event);
        $payload = array_merge($this->basePayload($event), [
            'event' => 'failed',
            'duration_ms' => $this->durationMs($key),
            'exception_class' => $event->exception::class,
            'exception_message' => $event->exception->getMessage(),
        ]);

        unset($this->startedAt[$key]);

        $this->telemetry->recordEvent('jobs', $this->sanitizer->clean($payload));
        $this->telemetry->recordEvent('exceptions', $this->sanitizer->clean(array_merge($payload, [
            'type' => 'failed_job_exception',
            'hash' => sha1($event->exception::class . '|' . $event->exception->getMessage()),
        ])));
    }

    /**
     * Created to normalize queue event fields across Laravel versions and queue drivers.
     */
    protected function basePayload(object $event): array
    {
        $job = $event->job;

        return [
            'type' => 'queue_job',
            'connection' => $event->connectionName ?? null,
            'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
            'job' => $this->jobName($job),
            'job_id' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
            'attempts' => method_exists($job, 'attempts') ? $job->attempts() : null,
        ];
    }

    /**
     * Created to resolve queue job names safely across drivers that expose different job APIs.
     */
    protected function jobName(object $job): string
    {
        if (method_exists($job, 'resolveName')) {
            return (string) $job->resolveName();
        }

        if (method_exists($job, 'getName')) {
            return (string) $job->getName();
        }

        return $job::class;
    }

    /**
     * Created to derive a stable in-memory key for duration tracking during a worker process lifetime.
     */
    protected function jobKey(object $event): string
    {
        $job = $event->job;
        $id = method_exists($job, 'uuid') ? $job->uuid() : null;
        $id ??= method_exists($job, 'getJobId') ? $job->getJobId() : null;

        return (string) ($id ?: spl_object_id($job));
    }

    /**
     * Created to convert a previously captured start time into queue job duration.
     */
    protected function durationMs(string $key): ?float
    {
        if (! isset($this->startedAt[$key])) {
            return null;
        }

        return round((hrtime(true) - $this->startedAt[$key]) / 1000000, 2);
    }
}
