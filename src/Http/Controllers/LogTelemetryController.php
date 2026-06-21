<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose sanitized log telemetry captured by the package Monolog handler.
 */
class LogTelemetryController extends Controller
{
    /**
     * Created to list recent logs with level, search, and time filters for production debugging.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('logs', $this->filters($request)));
    }
}
