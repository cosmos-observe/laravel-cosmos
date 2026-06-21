<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;

/**
 * Created to record outbound HTTP dependency calls without storing headers, bodies, or sensitive query strings.
 */
class ExternalHttpRequestRecorder
{
    protected array $pending = [];

    public function __construct(
        protected TelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to remember Laravel HTTP client start times when the framework emits RequestSending.
     */
    public function sending(object $event): void
    {
        if (! isset($event->request)) {
            return;
        }

        if ($this->isClickHouseRequest($this->requestUrl($event->request))) {
            return;
        }

        $this->pending[$this->requestKey($event->request)] = hrtime(true);
    }

    /**
     * Created to record successful Laravel HTTP client responses.
     */
    public function responseReceived(object $event): void
    {
        if (! isset($event->request, $event->response)) {
            return;
        }

        if ($this->isClickHouseRequest($this->requestUrl($event->request))) {
            return;
        }

        $durationMs = $this->responseDurationMs($event->response) ?? $this->elapsedMs($event->request);
        $this->recordRequest($event->request, $event->response, $durationMs, null, $this->sourceFromLaravelRequest($event->request));
    }

    /**
     * Created to record Laravel HTTP client connection failures and timeouts.
     */
    public function connectionFailed(object $event): void
    {
        if (! isset($event->request)) {
            return;
        }

        if ($this->isClickHouseRequest($this->requestUrl($event->request))) {
            return;
        }

        $this->recordRequest(
            $event->request,
            null,
            $this->elapsedMs($event->request),
            $event->exception ?? null,
            $this->sourceFromLaravelRequest($event->request)
        );
    }

    /**
     * Created to let an opt-in Guzzle middleware feed direct Guzzle traffic into the same telemetry stream.
     */
    public function recordPsrRequest(object $request, ?object $response, float $durationMs, ?\Throwable $exception = null, string $source = 'guzzle_middleware'): void
    {
        $this->recordRequest($request, $response, $durationMs, $exception, $source);
    }

    /**
     * Created to normalize Laravel and PSR request/response objects into safe outbound HTTP telemetry.
     */
    protected function recordRequest(object $request, ?object $response, float $durationMs, ?\Throwable $exception, string $source): void
    {
        if (! $this->sample((float) config('cosmos-monitor.sampling.external_request_rate', 1.0))) {
            return;
        }

        $url = $this->requestUrl($request);

        if ($this->isClickHouseRequest($url)) {
            return;
        }

        $status = $this->responseStatus($response);
        $payload = $this->sanitizer->clean([
            'type' => 'external_request',
            'method' => $this->requestMethod($request),
            'url' => $this->sanitizedUrl($url),
            'host' => $this->hostFromUrl($url),
            'path' => $this->pathFromUrl($url),
            'query_present' => parse_url($url, PHP_URL_QUERY) !== null,
            'status' => $status,
            'status_family' => $this->statusFamily($status, $exception),
            'duration_ms' => round(max($durationMs, 0), 2),
            'source' => $source,
            'error' => $exception ? $exception->getMessage() : null,
            'exception' => $exception ? $exception::class : null,
        ]);

        $this->telemetry->recordEvent('external-requests', $payload);

        if ($this->shouldRecordPerformance($payload)) {
            $this->telemetry->recordEvent('performance', array_merge($payload, ['category' => 'external_http']));
        }
    }

    protected function shouldRecordPerformance(array $payload): bool
    {
        if (($payload['status_family'] ?? null) === '5xx' || ($payload['status_family'] ?? null) === 'failed') {
            return true;
        }

        return (float) ($payload['duration_ms'] ?? 0) >= (float) config('cosmos-monitor.thresholds.slow_external_request_ms', 1000);
    }

    protected function responseDurationMs(object $response): ?float
    {
        if (! method_exists($response, 'handlerStats')) {
            return null;
        }

        $stats = $response->handlerStats();
        $totalTime = is_array($stats) ? ($stats['total_time'] ?? null) : null;

        return is_numeric($totalTime) && (float) $totalTime > 0 ? (float) $totalTime * 1000 : null;
    }

    protected function elapsedMs(object $request): float
    {
        $key = $this->requestKey($request);
        $startedAt = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return is_int($startedAt) ? (hrtime(true) - $startedAt) / 1000000 : 0.0;
    }

    protected function requestKey(object $request): string
    {
        return spl_object_id($request) . ':' . $this->requestMethod($request) . ':' . $this->requestUrl($request);
    }

    protected function requestMethod(object $request): string
    {
        return strtoupper((string) (method_exists($request, 'method') ? $request->method() : (method_exists($request, 'getMethod') ? $request->getMethod() : 'GET')));
    }

    protected function requestUrl(object $request): string
    {
        return (string) (method_exists($request, 'url') ? $request->url() : (method_exists($request, 'getUri') ? $request->getUri() : ''));
    }

    /**
     * Created to prevent the package's own ClickHouse storage calls from recursively becoming external-request telemetry.
     */
    protected function isClickHouseRequest(string $url): bool
    {
        $clickhouseUrl = (string) config('cosmos-monitor.clickhouse.url', '');

        if ($clickhouseUrl === '' || $url === '') {
            return false;
        }

        $target = parse_url($clickhouseUrl);
        $actual = parse_url($url);

        if (! is_array($target) || ! is_array($actual)) {
            return false;
        }

        $targetScheme = strtolower((string) ($target['scheme'] ?? 'http'));
        $actualScheme = strtolower((string) ($actual['scheme'] ?? 'http'));
        $targetHost = strtolower((string) ($target['host'] ?? ''));
        $actualHost = strtolower((string) ($actual['host'] ?? ''));
        $targetPort = (int) ($target['port'] ?? ($targetScheme === 'https' ? 443 : 80));
        $actualPort = (int) ($actual['port'] ?? ($actualScheme === 'https' ? 443 : 80));

        return $targetScheme === $actualScheme && $targetHost === $actualHost && $targetPort === $actualPort;
    }

    protected function responseStatus(?object $response): ?int
    {
        if (! $response) {
            return null;
        }

        if (method_exists($response, 'status')) {
            return (int) $response->status();
        }

        return method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null;
    }

    protected function sanitizedUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['host'])) {
            return $url === '' ? 'unknown' : strtok($url, '?');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return $scheme . '://' . $parts['host'] . $port . $path;
    }

    protected function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : 'unknown';
    }

    protected function pathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    protected function sourceFromLaravelRequest(object $request): string
    {
        if (! method_exists($request, 'attributes')) {
            return 'laravel_http';
        }

        $attributes = $request->attributes();

        return is_array($attributes) && isset($attributes['cosmos_monitor_source'])
            ? (string) $attributes['cosmos_monitor_source']
            : 'laravel_http';
    }

    protected function statusFamily(?int $status, ?\Throwable $exception): string
    {
        if ($exception !== null || $status === null) {
            return 'failed';
        }

        return ((int) floor($status / 100)) . 'xx';
    }

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
}
