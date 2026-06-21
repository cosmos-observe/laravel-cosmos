<?php

namespace Cosmos\LaravelMonitor\Services;

use Cosmos\LaravelMonitor\Contracts\TelemetryRepository;
use Cosmos\LaravelMonitor\Support\PayloadSanitizer;

/**
 * Created to record metadata-only mail telemetry without retaining bodies, attachments, or full recipient addresses.
 */
class MailEventRecorder
{
    protected array $pending = [];

    public function __construct(
        protected TelemetryRepository $telemetry,
        protected PayloadSanitizer $sanitizer
    ) {
    }

    /**
     * Created to time Laravel mail sends before the transport is invoked.
     */
    public function sending(object $event): void
    {
        if (! isset($event->message) || ! is_object($event->message)) {
            return;
        }

        $this->pending[$this->messageKey($event->message)] = hrtime(true);
    }

    /**
     * Created to record successful Laravel mail sends.
     */
    public function sent(object $event): void
    {
        $message = $this->messageFromSentEvent($event);

        if (! $message) {
            return;
        }

        $this->recordMail($message, 'sent', $this->elapsedMs($message), null, $this->messageId($event));
    }

    /**
     * Created to record Symfony mail transport failures when the host version dispatches them through Laravel's event system.
     */
    public function failed(object $event): void
    {
        if (! method_exists($event, 'getMessage')) {
            return;
        }

        $message = $event->getMessage();
        $exception = method_exists($event, 'getError') ? $event->getError() : null;
        $this->recordMail($message, 'failed', $this->elapsedMs($message), $exception instanceof \Throwable ? $exception : null, null);
    }

    /**
     * Created to store safe mail metadata and optional performance flags.
     */
    protected function recordMail(object $message, string $status, float $durationMs, ?\Throwable $exception, ?string $messageId): void
    {
        if (! $this->sample((float) config('cosmos-monitor.sampling.mail_rate', 1.0))) {
            return;
        }

        $recipientMetadata = $this->recipientMetadata($message);
        $payload = $this->sanitizer->clean([
            'type' => 'mail',
            'event' => $status,
            'status' => $status,
            'mailer' => $this->mailerName(),
            'transport' => $this->transportName(),
            'duration_ms' => round(max($durationMs, 0), 2),
            'message_id' => $messageId,
            'recipient_count' => $recipientMetadata['count'],
            'recipient_domains' => $recipientMetadata['domains'],
            'recipient_domain' => $recipientMetadata['domains'][0] ?? null,
            'recipient_hashes' => $recipientMetadata['hashes'],
            'has_attachments' => $this->hasAttachments($message),
            'error' => $exception ? $exception->getMessage() : null,
            'exception' => $exception ? $exception::class : null,
        ]);

        $this->telemetry->recordEvent('mail', $payload);

        if ($status === 'failed' || (float) $payload['duration_ms'] >= (float) config('cosmos-monitor.thresholds.slow_mail_ms', 1000)) {
            $this->telemetry->recordEvent('performance', array_merge($payload, ['category' => 'mail']));
        }
    }

    protected function messageFromSentEvent(object $event): ?object
    {
        if (isset($event->message) && is_object($event->message)) {
            return $event->message;
        }

        if (isset($event->sent) && is_object($event->sent)) {
            $sent = method_exists($event->sent, 'getSymfonySentMessage')
                ? $event->sent->getSymfonySentMessage()
                : $event->sent;
            $message = method_exists($sent, 'getOriginalMessage') ? $sent->getOriginalMessage() : null;

            return is_object($message) ? $message : null;
        }

        return null;
    }

    protected function messageId(object $event): ?string
    {
        if (isset($event->sent) && is_object($event->sent)) {
            $sent = method_exists($event->sent, 'getSymfonySentMessage')
                ? $event->sent->getSymfonySentMessage()
                : $event->sent;

            return method_exists($sent, 'getMessageId') ? (string) $sent->getMessageId() : null;
        }

        return null;
    }

    protected function recipientMetadata(object $message): array
    {
        $addresses = [];

        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            if (! method_exists($message, $method)) {
                continue;
            }

            foreach ((array) $message->{$method}() as $address) {
                $email = is_object($address) && method_exists($address, 'getAddress') ? $address->getAddress() : (string) $address;

                if ($email !== '') {
                    $addresses[] = strtolower($email);
                }
            }
        }

        $domains = [];

        foreach ($addresses as $email) {
            $domain = substr(strrchr($email, '@') ?: '', 1);

            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return [
            'count' => count($addresses),
            'domains' => array_values(array_unique($domains)),
            'hashes' => array_map(fn (string $email): string => substr(hash_hmac('sha256', $email, $this->hashSalt()), 0, 24), $addresses),
        ];
    }

    protected function hasAttachments(object $message): bool
    {
        return method_exists($message, 'getAttachments') && count((array) $message->getAttachments()) > 0;
    }

    protected function elapsedMs(object $message): float
    {
        $key = $this->messageKey($message);
        $startedAt = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return is_int($startedAt) ? (hrtime(true) - $startedAt) / 1000000 : 0.0;
    }

    protected function messageKey(object $message): string
    {
        return spl_object_id($message);
    }

    protected function mailerName(): string
    {
        return (string) config('mail.default', 'default');
    }

    protected function transportName(): string
    {
        $mailer = $this->mailerName();

        return (string) config('mail.mailers.' . $mailer . '.transport', $mailer);
    }

    protected function hashSalt(): string
    {
        return (string) (config('app.key') ?: config('cosmos-monitor.key_prefix', 'cosmos-monitor'));
    }

    protected function sample(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return random_int(1, 1000000) <= (int) floor($rate * 1000000);
    }
}
