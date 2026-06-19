<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose cross-cutting slow request and slow database performance telemetry.
 */
class PerformanceController extends Controller
{
    /**
     * Created to list retained performance events across HTTP and database categories.
     */
    public function index(Request $request, RedisTelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('performance', $this->filters($request)));
    }
}
