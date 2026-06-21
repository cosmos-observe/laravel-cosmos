<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose metadata-only mail send telemetry without retaining message body, attachments, or full recipients.
 */
class MailTelemetryController extends Controller
{
    /**
     * Created to list mail telemetry with shared filters such as mailer, transport, status, and duration.
     */
    public function index(Request $request, TelemetryRepository $telemetry): JsonResponse
    {
        return $this->telemetryEnvelope($telemetry->listEvents('mail', $this->filters($request)));
    }
}
