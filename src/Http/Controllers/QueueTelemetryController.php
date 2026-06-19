<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose queue depth samples and job lifecycle telemetry without requiring Horizon.
 */
class QueueTelemetryController extends Controller
{
    /**
     * Created to list queue depth samples and queue-level health events.
     */
    public function index(Request $request, RedisTelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('queues', $this->filters($request)));
    }

    /**
     * Created to list job lifecycle events for one queue while preserving the shared filter contract.
     */
    public function jobs(string $queue, Request $request, RedisTelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('jobs', $this->filters($request, [
            'queue' => $queue,
        ])));
    }
}
