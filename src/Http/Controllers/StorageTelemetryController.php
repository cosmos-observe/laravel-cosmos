<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose storage disk snapshots collected by the scheduled sampler.
 */
class StorageTelemetryController extends Controller
{
    /**
     * Created to list storage telemetry with shared filters, including disk and status.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('storage', $this->filters($request)));
    }
}
