<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\ExceptionStateService;
use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose grouped and bounded exception telemetry for production incident review.
 */
class ExceptionTelemetryController extends Controller
{
    /**
     * Created to list recent exceptions with class, message, hash, and stack preview fields.
     */
    public function index(Request $request, TelemetryRepository $telemetry, ExceptionStateService $states): JsonResponse
    {
        $result = $telemetry->listEvents('exceptions', $this->filters($request));
        $result['data'] = $states->apply($result['data'] ?? []);

        return $this->telemetryEnvelope($result);
    }

    /**
     * Created to update exception workflow state by hash while leaving immutable immutable exception events untouched.
     */
    public function updateStatus(string $hash, Request $request, ExceptionStateService $states): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,resolved,snoozed'],
            'snoozed_until' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->envelope($states->update($hash, $validated, optional($request->user())->getAuthIdentifier()));
    }
}
