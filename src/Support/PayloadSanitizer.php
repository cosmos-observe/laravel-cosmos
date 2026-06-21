<?php

namespace Cosmos\LaravelMonitor\Support;

/**
 * Created to keep production telemetry safe by redacting sensitive keys and bounding payload size before telemetry writes.
 */
class PayloadSanitizer
{
    /**
     * Created to keep redaction and size limits configurable from the host application.
     */
    public function __construct(
        protected array $redaction,
        protected array $limits
    ) {
    }

    /**
     * Created to recursively clean arrays before they are stored as monitor telemetry.
     */
    public function clean(array $payload): array
    {
        $clean = $this->cleanValue($payload);

        if (! is_array($clean)) {
            return [];
        }

        return $this->boundArrayPayload($clean);
    }

    /**
     * Created to redact sensitive keys and normalize non-scalar values recursively.
     */
    protected function cleanValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->shouldRedact($key)) {
            return $this->redaction['replacement'] ?? '[redacted]';
        }

        if (is_array($value)) {
            $clean = [];

            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $this->cleanValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $clean;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : $value::class;
        }

        if (is_resource($value)) {
            return 'resource';
        }

        if (is_string($value)) {
            return $this->truncateString($value);
        }

        return $value;
    }

    /**
     * Created to detect sensitive names consistently across headers, context arrays, and request payloads.
     */
    protected function shouldRedact(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ((array) ($this->redaction['keys'] ?? []) as $sensitiveKey) {
            if ($normalized === strtolower((string) $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Created to prevent a single string from exceeding the telemetry payload budget.
     */
    protected function truncateString(string $value): string
    {
        $maxBytes = max(512, (int) ($this->limits['max_payload_bytes'] ?? 8192));

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . '...[truncated]';
    }

    /**
     * Created to shrink oversized arrays while preserving the most important top-level telemetry fields.
     */
    protected function boundArrayPayload(array $payload): array
    {
        $maxBytes = max(1024, (int) ($this->limits['max_payload_bytes'] ?? 8192));
        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) <= $maxBytes) {
            return $payload;
        }

        $bounded = $payload;

        foreach (array_keys($bounded) as $key) {
            if (in_array($key, ['id', 'type', 'timestamp', 'duration_ms', 'status', 'level', 'message', 'hash', 'exception_class', 'file', 'line', 'event', 'queue', 'job', 'route', 'method', 'connection', 'category', 'command'], true)) {
                continue;
            }

            $bounded[$key] = '[truncated]';

            $encoded = json_encode($bounded);

            if ($encoded !== false && strlen($encoded) <= $maxBytes) {
                return $bounded;
            }
        }

        return [
            'type' => $payload['type'] ?? 'telemetry',
            'message' => '[payload truncated]',
        ];
    }
}
