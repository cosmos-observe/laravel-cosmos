<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose scheduler health, skipped tasks, failures, and run durations.
 */
class ScheduleTelemetryController extends Controller
{
    /**
     * Created to list scheduler events for API consumers building schedule health views.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('schedules', $this->filters($request)));
    }
}
