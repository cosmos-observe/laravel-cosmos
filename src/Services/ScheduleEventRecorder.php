<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;

/**
 * Created to track Laravel scheduler health, task runtime, skipped tasks, and failures through scheduler events.
 */
class ScheduleEventRecorder
{
    protected array $startedAt = [];

    /**
     * Created to share high-volume telemetry and sanitization across scheduler event callbacks.
     */
    public function __construct(
        protected TelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to record scheduler task starts and enable duration calculation for later finish/fail events.
     */
    public function starting(object $event): void
    {
        $key = $this->taskKey($event);
        $this->startedAt[$key] = hrtime(true);

        $this->telemetry->recordEvent('schedules', $this->sanitizer->clean(array_merge(
            $this->basePayload($event),
            ['event' => 'starting']
        )));
    }

    /**
     * Created to record successfully completed scheduler tasks and their runtime.
     */
    public function finished(object $event): void
    {
        $this->recordTerminalEvent($event, 'finished');
    }

    /**
     * Created to record background scheduler completions separately from foreground task completions.
     */
    public function backgroundFinished(object $event): void
    {
        $this->recordTerminalEvent($event, 'background_finished');
    }

    /**
     * Created to record scheduler failures with exception summaries for health checks and alerts.
     */
    public function failed(object $event): void
    {
        $key = $this->taskKey($event);
        $payload = array_merge($this->basePayload($event), [
            'event' => 'failed',
            'duration_ms' => $this->durationMs($key),
            'exception_class' => isset($event->exception) ? $event->exception::class : null,
            'exception_message' => isset($event->exception) ? $event->exception->getMessage() : null,
        ]);

        unset($this->startedAt[$key]);

        $this->telemetry->recordEvent('schedules', $this->sanitizer->clean($payload));
    }

    /**
     * Created to record skipped scheduler tasks because skips can indicate lock or environment health problems.
     */
    public function skipped(object $event): void
    {
        $this->telemetry->recordEvent('schedules', $this->sanitizer->clean(array_merge(
            $this->basePayload($event),
            ['event' => 'skipped']
        )));
    }

    /**
     * Created to share terminal task recording logic between foreground and background scheduler events.
     */
    protected function recordTerminalEvent(object $event, string $eventName): void
    {
        $key = $this->taskKey($event);
        $payload = array_merge($this->basePayload($event), [
            'event' => $eventName,
            'duration_ms' => $this->durationMs($key) ?? $this->eventRuntimeMs($event),
        ]);

        unset($this->startedAt[$key]);

        $this->telemetry->recordEvent('schedules', $this->sanitizer->clean($payload));
    }

    /**
     * Created to normalize task fields across Laravel scheduler event versions.
     */
    protected function basePayload(object $event): array
    {
        $task = $event->task ?? null;

        return [
            'type' => 'schedule_task',
            'description' => $task?->description ?? null,
            'command' => $task?->command ?? null,
            'expression' => is_object($task) && method_exists($task, 'getExpression') ? $task->getExpression() : null,
            'timezone' => $task?->timezone ?? null,
        ];
    }

    /**
     * Created to derive a stable key for task duration tracking.
     */
    protected function taskKey(object $event): string
    {
        $task = $event->task ?? null;

        if ($task !== null && method_exists($task, 'mutexName')) {
            return $task->mutexName();
        }

        return sha1(json_encode($this->basePayload($event)) ?: spl_object_hash($event));
    }

    /**
     * Created to convert captured task start time into scheduler task duration.
     */
    protected function durationMs(string $key): ?float
    {
        if (! isset($this->startedAt[$key])) {
            return null;
        }

        return round((hrtime(true) - $this->startedAt[$key]) / 1000000, 2);
    }

    /**
     * Created to use Laravel's scheduler runtime value when start and finish events do not share process memory.
     */
    protected function eventRuntimeMs(object $event): ?float
    {
        if (! isset($event->runtime) || ! is_numeric($event->runtime)) {
            return null;
        }

        return round(((float) $event->runtime) * 1000, 2);
    }
}
