<?php

namespace Cosmos\LaravelMonitor\Support;

use Illuminate\Http\Request;

/**
 * Created to centralize query parsing so every telemetry endpoint supports the same filtering and pagination contract.
 */
class TelemetryQuery
{
    /**
     * Created to convert request query parameters into repository filters with safe pagination defaults.
     */
    public static function fromRequest(Request $request, array $overrides = []): array
    {
        $maxPageSize = (int) config('cosmos-monitor.limits.max_page_size', 100);
        $perPage = min(max((int) $request->query('per_page', 25), 1), $maxPageSize);
        $page = max((int) $request->query('page', 1), 1);

        $filters = array_merge([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'level' => $request->query('level'),
            'status' => $request->query('status'),
            'queue' => $request->query('queue'),
            'job' => $request->query('job'),
            'route' => $request->query('route'),
            'method' => $request->query('method'),
            'hash' => $request->query('hash'),
            'event' => $request->query('event'),
            'connection' => $request->query('connection'),
            'category' => $request->query('category'),
            'min_duration' => $request->query('min_duration'),
            'search' => $request->query('search'),
            'sort' => $request->query('sort', 'timestamp_ms'),
            'order' => strtolower((string) $request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc',
            'page' => $page,
            'per_page' => $perPage,
            'cursor' => $request->query('cursor'),
            'scan_limit' => $request->query('scan_limit'),
        ], $overrides);

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    /**
     * Created to normalize timestamps from Unix milliseconds, Unix seconds, or date strings.
     */
    public static function timestampMs(mixed $value, ?int $fallback = null): ?int
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;

            return $numeric > 9999999999 ? (int) $numeric : (int) ($numeric * 1000);
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? $fallback : $timestamp * 1000;
    }
}
