<?php

namespace Cosmos\LaravelMonitor\Tests\Unit;

use Cosmos\LaravelMonitor\Support\PayloadSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Created to verify sensitive telemetry fields are redacted before Redis storage.
 */
class PayloadSanitizerTest extends TestCase
{
    /**
     * Created to ensure configured sensitive keys are replaced recursively.
     */
    public function test_it_redacts_sensitive_keys(): void
    {
        $sanitizer = new PayloadSanitizer([
            'keys' => ['password', 'authorization'],
            'replacement' => '[hidden]',
        ], ['max_payload_bytes' => 8192]);

        $payload = $sanitizer->clean([
            'password' => 'secret',
            'headers' => [
                'authorization' => 'Bearer token',
            ],
            'safe' => 'value',
        ]);

        $this->assertSame('[hidden]', $payload['password']);
        $this->assertSame('[hidden]', $payload['headers']['authorization']);
        $this->assertSame('value', $payload['safe']);
    }

    /**
     * Created to ensure exception grouping hashes survive payload truncation so resolve and snooze state can still attach to heavy exceptions.
     */
    public function test_it_preserves_exception_hash_when_payload_is_truncated(): void
    {
        $sanitizer = new PayloadSanitizer([
            'keys' => ['token'],
            'replacement' => '[hidden]',
        ], ['max_payload_bytes' => 1024]);

        $payload = $sanitizer->clean([
            'type' => 'exception',
            'message' => 'Heavy exception',
            'hash' => 'abc123',
            'exception_class' => \RuntimeException::class,
            'trace' => str_repeat('large-frame', 1000),
            'token' => 'secret',
        ]);

        $this->assertSame('abc123', $payload['hash']);
        $this->assertSame(\RuntimeException::class, $payload['exception_class']);
        $this->assertNotSame('secret', json_encode($payload));
    }
}
