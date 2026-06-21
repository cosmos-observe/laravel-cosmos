<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;

/**
 * Created to group and retain production exceptions without requiring Telescope.
 */
class ExceptionRecorder
{
    /**
     * Created to share high-volume telemetry and sanitization with Laravel's exception hook.
     */
    public function __construct(
        protected TelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to persist a bounded exception summary and stack preview for API inspection and alerting.
     */
    public function record(\Throwable $exception): void
    {
        $maxFrames = (int) config('cosmos-monitor.limits.max_stack_frames', 20);
        $trace = array_slice($exception->getTrace(), 0, $maxFrames);

        $payload = $this->sanitizer->clean([
            'type' => 'exception',
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'hash' => $this->hash($exception),
            'trace' => $trace,
        ]);

        $this->telemetry->recordEvent('exceptions', $payload);
    }

    /**
     * Created to group repeated exceptions by class, location, and message without storing unbounded stack data.
     */
    protected function hash(\Throwable $exception): string
    {
        return sha1($exception::class . '|' . $exception->getFile() . '|' . $exception->getLine() . '|' . $exception->getMessage());
    }
}
