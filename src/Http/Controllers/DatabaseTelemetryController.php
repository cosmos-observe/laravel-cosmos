<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose database latency and slow-query telemetry.
 */
class DatabaseTelemetryController extends Controller
{
    /**
     * Created to list retained slow database queries and latency events.
     */
    public function latency(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('database', $this->filters($request)));
    }
}
