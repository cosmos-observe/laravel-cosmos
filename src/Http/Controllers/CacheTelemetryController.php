<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose cache hit, miss, write, and forget telemetry for dashboard cache-health cards.
 */
class CacheTelemetryController extends Controller
{
    /**
     * Created to list recent cache events with shared filtering, cursor, and pagination support.
     */
    public function index(Request $request, RedisTelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('cache', $this->filters($request)));
    }
}
