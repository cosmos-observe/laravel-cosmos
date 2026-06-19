<?php

namespace Cosmos\LaravelMonitor\Http\Middleware;

use Closure;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Created to collect production-safe request metrics such as RPS, status counts, latency, and slow requests.
 */
class CaptureRequestMetrics
{
    /**
     * Created to record request telemetry through the shared Redis repository with payload redaction.
     */
    public function __construct(
        protected RedisTelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to time every sampled request, store status metrics, and rethrow exceptions without changing app behavior.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldCapture($request)) {
            return $next($request);
        }

        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->record($request, null, $this->elapsedMs($startedAt), $exception);

            throw $exception;
        }

        $this->record($request, $response, $this->elapsedMs($startedAt));

        return $response;
    }

    /**
     * Created to keep the monitoring API itself and unsampled traffic out of the request telemetry stream.
     */
    protected function shouldCapture(Request $request): bool
    {
        $prefix = trim((string) config('cosmos-monitor.route_prefix', 'api/cosmos-monitor/v1'), '/');

        if ($prefix !== '' && str_starts_with(trim($request->path(), '/'), $prefix)) {
            return false;
        }

        return $this->sample((float) config('cosmos-monitor.sampling.request_rate', 1.0));
    }

    /**
     * Created to record normal and exceptional request outcomes in a single consistent payload shape.
     */
    protected function record(Request $request, ?Response $response, float $durationMs, ?\Throwable $exception = null): void
    {
        $route = $request->route();
        $payload = $this->sanitizer->clean([
            'type' => 'request',
            'method' => $request->method(),
            'path' => '/' . trim($request->path(), '/'),
            'route' => $route?->getName() ?: $route?->uri() ?: $request->path(),
            'status' => $response?->getStatusCode() ?? 500,
            'duration_ms' => round($durationMs, 2),
            'user_id' => optional($request->user())->getAuthIdentifier(),
            'query' => $request->query(),
            'ip' => $request->ip(),
            'exception' => $exception ? $exception::class : null,
        ]);

        $this->telemetry->recordEvent('requests', $payload);

        if ($durationMs >= (float) config('cosmos-monitor.thresholds.slow_request_ms', 1000) || $exception !== null) {
            $this->telemetry->recordEvent('performance', array_merge($payload, ['category' => 'http']));
        }
    }

    /**
     * Created to apply configurable sampling without relying on external extensions.
     */
    protected function sample(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return random_int(1, 1000000) <= (int) floor($rate * 1000000);
    }

    /**
     * Created to convert high-resolution timing into milliseconds for API and rollup consistency.
     */
    protected function elapsedMs(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1000000;
    }
}
