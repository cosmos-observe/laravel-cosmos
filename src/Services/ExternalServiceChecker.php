<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Models\ExternalService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Support\Facades\Http;

/**
 * Created to probe user-defined external HTTP dependencies and record bounded health telemetry.
 */
class ExternalServiceChecker
{
    public function __construct(
        protected TelemetryRepository $telemetry,
        protected NotificationService $notifications
    ) {
    }

    /**
     * Created to check all enabled registered services.
     */
    public function checkEnabledServices(): array
    {
        $results = [];

        foreach (ExternalService::query()->where('enabled', true)->orderBy('name')->get() as $service) {
            $results[] = $this->check($service);
        }

        return $results;
    }

    /**
     * Created to check one service, persist its latest status, record telemetry, and notify on transitions.
     */
    public function check(ExternalService $service): array
    {
        $previousStatus = $service->latest_status;
        $started = microtime(true);
        $httpStatus = null;
        $error = null;

        try {
            $request = Http::timeout((float) config('cosmos-monitor.external_services.timeout_seconds', 5))
                ->connectTimeout((float) config('cosmos-monitor.external_services.connect_timeout_seconds', 3))
                ->withHeaders(['User-Agent' => (string) config('cosmos-monitor.external_services.user_agent', 'Cosmos Laravel Monitor')]);

            if (method_exists($request, 'withAttributes')) {
                $request->withAttributes(['cosmos_monitor_source' => 'external_service_check']);
            }

            $response = $request->get($service->url);

            $httpStatus = $response->status();
            $status = $this->statusForHttpStatus($httpStatus);
        } catch (\Throwable $exception) {
            $status = 'down';
            $error = $exception->getMessage();
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $checkedAt = now();

        $service->forceFill([
            'latest_status' => $status,
            'latest_http_status' => $httpStatus,
            'latest_latency_ms' => $latencyMs,
            'latest_checked_at' => $checkedAt,
            'latest_error' => $error,
        ])->save();

        $payload = [
            'type' => 'external_service',
            'event' => 'checked',
            'service_id' => (string) $service->getKey(),
            'service_name' => $service->name,
            'url' => $service->url,
            'status' => $status,
            'http_status' => $httpStatus,
            'duration_ms' => $latencyMs,
            'checked_at' => $checkedAt->toISOString(),
            'error' => $error,
        ];

        $this->telemetry->recordEvent('external-services', $payload);
        $this->dispatchTransitionNotification($service, $previousStatus, $payload);

        return $payload;
    }

    /**
     * Created to map HTTP status families to the requested monitor states.
     */
    protected function statusForHttpStatus(int $status): string
    {
        if ($status >= 200 && $status < 400) {
            return 'up';
        }

        if ($status >= 400 && $status < 500) {
            return 'reachable_warning';
        }

        return 'down';
    }

    /**
     * Created to avoid noisy repeated alerts by only notifying when latest status changes.
     */
    protected function dispatchTransitionNotification(ExternalService $service, ?string $previousStatus, array $payload): void
    {
        $currentStatus = (string) $payload['status'];

        if ($previousStatus === null || $previousStatus === $currentStatus) {
            return;
        }

        $this->notifications->dispatch('external_service_status_transition', [
            'severity' => $currentStatus === 'down' ? 'critical' : ($currentStatus === 'reachable_warning' ? 'warning' : 'info'),
            'service_id' => (string) $service->getKey(),
            'service_name' => $service->name,
            'url' => $service->url,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus,
            'http_status' => $payload['http_status'] ?? null,
            'duration_ms' => $payload['duration_ms'] ?? null,
        ]);
    }
}
