<?php

namespace Cosmos\LaravelMonitor\Tests\Feature;

use Cosmos\LaravelMonitor\Http\Client\ExternalRequestGuzzleMiddleware;
use Cosmos\LaravelMonitor\Services\ExternalHttpRequestRecorder;
use Cosmos\LaravelMonitor\Services\MailEventRecorder;
use Cosmos\LaravelMonitor\Tests\TestCase;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

/**
 * Created to verify outbound HTTP and mail telemetry capture without storing sensitive payload data.
 */
class ExternalRequestsAndMailTest extends TestCase
{
    /**
     * Created to verify outbound HTTP metadata, status families, sanitized URLs, and latency buckets.
     */
    public function test_external_request_recorder_captures_statuses_and_sanitizes_urls(): void
    {
        $recorder = app(ExternalHttpRequestRecorder::class);

        $recorder->recordPsrRequest(
            new PsrRequest('GET', 'https://api.example.test/v1/users?api_key=secret'),
            new PsrResponse(200),
            42,
            null,
            'unit'
        );

        $recorder->recordPsrRequest(
            new PsrRequest('POST', 'https://api.example.test/v1/payments'),
            new PsrResponse(503),
            1300,
            null,
            'unit'
        );

        $recorder->recordPsrRequest(
            new PsrRequest('GET', 'https://timeout.example.test/health'),
            null,
            5000,
            new \RuntimeException('DNS lookup failed'),
            'unit'
        );

        $this->getJson('/api/cosmos-monitor/v1/external-requests?host=api.example.test&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.stream', 'external-requests')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.host', 'api.example.test');

        $payload = $this->getJson('/api/cosmos-monitor/v1/external-requests?source=unit&per_page=10')
            ->assertOk()
            ->json('data');

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('api_key=secret', $encoded);
        $this->assertStringContainsString('https://api.example.test/v1/users', $encoded);

        $this->getJson('/api/cosmos-monitor/v1/metrics/timeseries?stream=external-requests&interval=minute&breakdown=status_family&from=0')
            ->assertOk()
            ->assertJsonStructure(['data' => [['timestamp_ms', 'count', 'avg_duration_ms', 'breakdown', 'duration_buckets']]]);
    }

    /**
     * Created to verify the opt-in Guzzle middleware records direct Guzzle responses.
     */
    public function test_guzzle_middleware_records_direct_guzzle_requests(): void
    {
        $middleware = app(ExternalRequestGuzzleMiddleware::class);
        $wrapped = $middleware(function () {
            return new FulfilledPromise(new PsrResponse(201));
        });

        $wrapped(new PsrRequest('POST', 'https://guzzle.example.test/webhook'), [])->wait();

        $this->getJson('/api/cosmos-monitor/v1/external-requests?host=guzzle.example.test&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source', 'guzzle_middleware')
            ->assertJsonPath('data.0.status', 201);
    }

    /**
     * Created to prevent ClickHouse storage HTTP calls from recursively becoming external-request telemetry.
     */
    public function test_clickhouse_storage_requests_are_not_recorded_as_external_requests(): void
    {
        config()->set('cosmos-monitor.clickhouse.url', 'http://127.0.0.1:8123');

        app(ExternalHttpRequestRecorder::class)->recordPsrRequest(
            new PsrRequest('POST', 'http://127.0.0.1:8123/?query=SELECT%201'),
            new PsrResponse(200),
            12,
            null,
            'unit'
        );

        $this->getJson('/api/cosmos-monitor/v1/external-requests?host=127.0.0.1&per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Created to verify mail telemetry stores metadata only and avoids body, subject, attachments, and full recipients.
     */
    public function test_mail_recorder_stores_metadata_only(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.transport', 'smtp');

        $recorder = app(MailEventRecorder::class);
        $email = (new Email())
            ->from('app@example.test')
            ->to('alice@example.com')
            ->cc('bob@client.test')
            ->subject('Sensitive invoice subject')
            ->text('Sensitive body payload');

        $recorder->sending(new MessageSending($email));
        $sent = new LaravelSentMessage(new SymfonySentMessage($email, Envelope::create($email)));
        $recorder->sent(new MessageSent($sent));

        $failedEmail = (new Email())
            ->from('app@example.test')
            ->to('carol@example.com')
            ->subject('Another sensitive subject')
            ->text('Another sensitive body');
        $recorder->sending(new MessageSending($failedEmail));
        $recorder->failed(new FailedMessageEvent($failedEmail, new \RuntimeException('SMTP rejected message')));

        $this->getJson('/api/cosmos-monitor/v1/mail?mailer=smtp&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.stream', 'mail');

        $this->getJson('/api/cosmos-monitor/v1/mail?status=sent&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/cosmos-monitor/v1/mail?status=failed&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $payload = $this->getJson('/api/cosmos-monitor/v1/mail?per_page=10')
            ->assertOk()
            ->json('data');

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('example.com', $encoded);
        $this->assertStringContainsString('client.test', $encoded);
        $this->assertStringNotContainsString('alice@example.com', $encoded);
        $this->assertStringNotContainsString('Sensitive invoice subject', $encoded);
        $this->assertStringNotContainsString('Sensitive body payload', $encoded);
    }

    /**
     * Created to verify new streams participate in summary metrics and settings validation.
     */
    public function test_new_streams_are_summarized_and_settings_accept_new_keys(): void
    {
        $this->telemetry->recordEvent('external-requests', [
            'host' => 'summary.example.test',
            'status' => 200,
            'status_family' => '2xx',
            'duration_ms' => 10,
        ]);

        $this->telemetry->recordEvent('mail', [
            'mailer' => 'smtp',
            'transport' => 'smtp',
            'status' => 'sent',
            'duration_ms' => 20,
        ]);

        $this->getJson('/api/cosmos-monitor/v1/metrics/summary?streams=external-requests,mail')
            ->assertOk()
            ->assertJsonPath('data.external-requests.count', 1)
            ->assertJsonPath('data.mail.count', 1);

        $this->putJson('/api/cosmos-monitor/v1/settings', [
            'capture' => [
                'external_requests' => true,
                'mail' => true,
            ],
            'sampling' => [
                'external_request_rate' => 0.5,
                'mail_rate' => 0.75,
            ],
            'thresholds' => [
                'slow_external_request_ms' => 1500,
                'slow_mail_ms' => 1200,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.capture.external_requests', true)
            ->assertJsonPath('data.sampling.external_request_rate', 0.5)
            ->assertJsonPath('data.thresholds.slow_mail_ms', 1200);
    }
}
