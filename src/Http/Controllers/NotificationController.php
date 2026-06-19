<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Created to expose notification test actions for API-driven monitor setup.
 */
class NotificationController extends Controller
{
    /**
     * Created to verify webhook, mail, and event notification channels from the external panel.
     */
    public function test(Request $request, NotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['sometimes', 'string', 'max:500'],
            'severity' => ['sometimes', 'string', 'in:info,warning,error,critical'],
        ]);

        return $this->envelope($notifications->sendTest($validated));
    }
}
