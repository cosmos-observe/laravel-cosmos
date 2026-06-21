<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose outbound HTTP request telemetry captured from Laravel and opt-in Guzzle clients.
 */
class ExternalRequestTelemetryController extends Controller
{
    /**
     * Created to list outbound request telemetry with shared filters such as host, status family, source, and duration.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('external-requests', $this->filters($request)));
    }
}
