<?php

namespace Cosmos\LaravelMonitor\Services;

use Illuminate\Support\Facades\Http;

/**
 * Created to send browser push notifications through Firebase Cloud Messaging HTTP v1 without storing service credentials in public settings.
 */
class FirebaseCloudMessagingService
{
    /**
     * Created to send one notification payload to every registered browser token and return per-token delivery results.
     */
    public function send(array $tokens, array $payload, array $notifications = []): array
    {
        $tokens = $this->cleanTokens($tokens);

        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'configured' => false,
                'sent' => 0,
                'failed' => count($tokens),
                'results' => [],
            ];
        }

        if (! ($notifications['fcm_enabled'] ?? config('cosmos-monitor.firebase.enabled', false))) {
            return [
                'ok' => true,
                'configured' => true,
                'enabled' => false,
                'sent' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        if ($tokens === []) {
            return [
                'ok' => true,
                'configured' => true,
                'enabled' => true,
                'sent' => 0,
                'failed' => 0,
                'results' => [],
            ];
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'configured' => true,
                'sent' => 0,
                'failed' => count($tokens),
                'error' => $exception->getMessage(),
                'results' => [],
            ];
        }

        $results = [];

        foreach ($tokens as $token) {
            $results[] = $this->sendToToken($accessToken, $token, $payload, $notifications);
        }

        $sent = count(array_filter($results, fn (array $result): bool => (bool) ($result['ok'] ?? false)));

        return [
            'ok' => $sent === count($tokens),
            'configured' => true,
            'enabled' => true,
            'sent' => $sent,
            'failed' => count($tokens) - $sent,
            'results' => $results,
        ];
    }

    /**
     * Created to let the registration endpoint persist a browser token in the durable notification settings.
     */
    public function registerToken(string $token, SettingsService $settings, ?string $updatedBy = null): array
    {
        $current = $settings->all();
        $notifications = (array) ($current['notifications'] ?? []);
        $tokens = $this->cleanTokens($notifications['fcm_tokens'] ?? []);
        $tokens[] = trim($token);
        $notifications['fcm_tokens'] = $this->cleanTokens($tokens);
        $notifications['fcm_enabled'] = true;

        return $settings->update(['notifications' => $notifications], $updatedBy)['notifications'] ?? $notifications;
    }

    /**
     * Created to remove stale or user-disabled browser push tokens from durable settings.
     */
    public function unregisterToken(string $token, SettingsService $settings, ?string $updatedBy = null): array
    {
        $current = $settings->all();
        $notifications = (array) ($current['notifications'] ?? []);
        $target = trim($token);
        $notifications['fcm_tokens'] = array_values(array_filter(
            $this->cleanTokens($notifications['fcm_tokens'] ?? []),
            fn (string $value): bool => $value !== $target
        ));

        return $settings->update(['notifications' => $notifications], $updatedBy)['notifications'] ?? $notifications;
    }

    protected function sendToToken(string $accessToken, string $token, array $payload, array $notifications): array
    {
        try {
            $message = [
                'token' => $token,
                'notification' => [
                    'title' => $this->titleFor($payload),
                    'body' => $this->bodyFor($payload),
                ],
                'data' => $this->dataPayload($payload),
            ];
            $link = (string) ($notifications['fcm_link'] ?? config('cosmos-monitor.notifications.fcm_link') ?? '');

            if ($link !== '') {
                $message['webpush'] = [
                    'fcm_options' => [
                        'link' => $link,
                    ],
                ];
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->messagesUrl(), [
                    'message' => $message,
                ]);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'name' => $response->json('name'),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }

    protected function accessToken(): string
    {
        $response = Http::asForm()->post((string) config('cosmos-monitor.firebase.token_uri'), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->signedJwt(),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException('Firebase OAuth token request failed with status ' . $response->status());
        }

        return (string) $response->json('access_token');
    }

    protected function signedJwt(): string
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]) ?: '{}');
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => config('cosmos-monitor.firebase.client_email'),
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => config('cosmos-monitor.firebase.token_uri'),
            'iat' => $now,
            'exp' => $now + 3600,
        ]) ?: '{}');
        $input = $header . '.' . $claims;
        $key = $this->normalizedPrivateKey();

        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Firebase service account JWT signing failed.');
        }

        return $input . '.' . $this->base64UrlEncode($signature);
    }

    protected function isConfigured(): bool
    {
        return (bool) config('cosmos-monitor.firebase.enabled')
            && filled(config('cosmos-monitor.firebase.project_id'))
            && filled(config('cosmos-monitor.firebase.client_email'))
            && filled(config('cosmos-monitor.firebase.private_key'))
            && filled(config('cosmos-monitor.firebase.token_uri'));
    }

    protected function messagesUrl(): string
    {
        return sprintf(
            'https://fcm.googleapis.com/v1/projects/%s/messages:send',
            rawurlencode((string) config('cosmos-monitor.firebase.project_id'))
        );
    }

    protected function normalizedPrivateKey(): string
    {
        return str_replace('\\n', "\n", (string) config('cosmos-monitor.firebase.private_key'));
    }

    protected function cleanTokens(array $tokens): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($token): string => is_scalar($token) ? trim((string) $token) : '',
            $tokens
        ))));
    }

    protected function titleFor(array $payload): string
    {
        $severity = strtoupper((string) ($payload['severity'] ?? 'info'));

        return sprintf('Cosmos Monitor %s', $severity);
    }

    protected function bodyFor(array $payload): string
    {
        return (string) ($payload['message'] ?? $payload['event'] ?? $payload['type'] ?? 'Monitor notification');
    }

    protected function dataPayload(array $payload): array
    {
        return collect($payload)
            ->map(fn ($value): string => is_scalar($value) || $value === null ? (string) $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: ''))
            ->filter(fn (string $value): bool => $value !== '')
            ->all();
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
