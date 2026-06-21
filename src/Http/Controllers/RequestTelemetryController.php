<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose captured HTTP request telemetry with filtering and sorting.
 */
class RequestTelemetryController extends Controller
{
    /**
     * Created to list recent request metrics for the monitoring panel.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('requests', $this->filters($request)));
    }
}
