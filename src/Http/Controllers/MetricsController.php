<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose dashboard summary and rollup time-series data over API.
 */
class MetricsController extends Controller
{
    /**
     * Created to return compact counts and latest records for all requested telemetry streams.
     */
    public function summary(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        $streams = $request->query('streams')
            ? array_filter(explode(',', (string) $request->query('streams')))
            : TelemetryRepository::STREAMS;

        return $this->envelope($telemetry->summary($streams, $this->filters($request)));
    }

    /**
     * Created to return minute or hour rollups for charting metrics in an external panel.
     */
    public function timeseries(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        $stream = (string) $request->query('stream', 'requests');
        $filters = $this->filters($request, [
            'interval' => $request->query('interval', 'minute'),
        ]);

        return $this->envelope($telemetry->timeseries($stream, $filters), [
            'stream' => $stream,
            'interval' => $filters['interval'] ?? 'minute',
        ]);
    }
}
