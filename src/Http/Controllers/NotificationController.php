<?php

namespace Cosmos\LaravelMonitor\Http\Controllers;

use Cosmos\LaravelMonitor\Services\NotificationService;
use Cosmos\LaravelMonitor\Services\FirebaseCloudMessagingService;
use Cosmos\LaravelMonitor\Services\SettingsService;
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

    /**
     * Created to persist the current browser's Firebase registration token for monitor push tests.
     */
    public function registerFcmToken(Request $request, FirebaseCloudMessagingService $firebase, SettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $notifications = $firebase->registerToken($validated['token'], $settings, optional($request->user())->getAuthIdentifier());

        return $this->envelope([
            'registered' => true,
            'token_count' => count($notifications['fcm_tokens'] ?? []),
        ]);
    }

    /**
     * Created to remove a browser Firebase registration token when notification testing is disabled.
     */
    public function unregisterFcmToken(Request $request, FirebaseCloudMessagingService $firebase, SettingsService $settings): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $notifications = $firebase->unregisterToken($validated['token'], $settings, optional($request->user())->getAuthIdentifier());

        return $this->envelope([
            'registered' => false,
            'token_count' => count($notifications['fcm_tokens'] ?? []),
        ]);
    }
}
