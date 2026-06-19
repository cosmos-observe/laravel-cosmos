<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Events\CosmosMonitorNotificationTriggered;
use Cosmos\LaravelMonitor\Storage\RedisTelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Created to send monitor notifications through webhook, mail, and Laravel events without hard-coding a panel.
 */
class NotificationService
{
    /**
     * Created to combine durable settings, Redis audit telemetry, and payload sanitization for notifications.
     */
    public function __construct(
        protected SettingsService $settings,
        protected RedisTelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to verify configured notification channels through the public API.
     */
    public function sendTest(array $payload = []): array
    {
        return $this->dispatch('test', array_merge([
            'message' => 'Cosmos monitor test notification',
            'severity' => 'info',
        ], $payload));
    }

    /**
     * Created to send one sanitized notification payload through enabled package channels and record the attempt.
     */
    public function dispatch(string $type, array $payload): array
    {
        $settings = $this->settings->all();
        $notifications = $settings['notifications'] ?? [];
        $payload = $this->sanitizer->clean(array_merge($payload, [
            'type' => $type,
            'app_id' => config('cosmos-monitor.app_id'),
            'environment' => config('cosmos-monitor.environment'),
        ]));

        Event::dispatch(new CosmosMonitorNotificationTriggered($type, $payload));

        $results = ['event' => 'dispatched'];

        if (($notifications['enabled'] ?? true) && ! empty($notifications['webhook_url'])) {
            $results['webhook'] = $this->sendWebhook((string) $notifications['webhook_url'], $payload);
        }

        if (($notifications['enabled'] ?? true) && ! empty($notifications['mail_to'])) {
            $results['mail'] = $this->sendMail((array) $notifications['mail_to'], $payload);
        }

        $this->telemetry->recordEvent('notifications', [
            'event' => $type,
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * Created to post notification payloads to external dashboards or incident tooling.
     */
    protected function sendWebhook(string $url, array $payload): array
    {
        try {
            $response = Http::timeout(5)->post($url, $payload);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Created to provide a built-in mail channel for teams that do not have webhook infrastructure yet.
     */
    protected function sendMail(array $recipients, array $payload): array
    {
        try {
            /**
             * Created to address Laravel's raw mail message to every configured monitor recipient.
             */
            Mail::raw(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Cosmos monitor notification', function ($message) use ($recipients): void {
                $message->to($recipients)->subject('Cosmos Monitor Notification');
            });

            return ['ok' => true];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
