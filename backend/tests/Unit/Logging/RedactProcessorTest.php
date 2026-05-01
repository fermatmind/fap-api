<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Support\Logging\RedactProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class RedactProcessorTest extends TestCase
{
    public function test_redacts_sensitive_keys_recursively_in_context_and_extra(): void
    {
        $processor = new RedactProcessor;

        $record = [
            'message' => 'demo',
            'context' => [
                'password' => 'secret-1',
                'nested' => [
                    'authorization' => 'Bearer abc',
                    'credit_card' => '4111111111111111',
                    'keep' => 'ok',
                ],
            ],
            'extra' => [
                'token' => 'tok_123',
                'payload' => [
                    'secret' => 'sec_123',
                    'to_email' => 'user@example.com',
                    'phone_e164' => '+8613900001111',
                    'invite_unlock_code' => 'unlock-plain-1',
                    'invite_token' => 'invite-token-1',
                    'webhook_secret' => 'whsec_live_secret',
                    'x_api_key' => 'api-key-raw-1',
                    'request_cookie' => 'cookie-raw-1',
                    'anon_id' => 'anon-raw-1',
                    'target_attempt_id' => 'attempt-raw-1',
                    'trace_id' => 'trace-1',
                    'diagnostic_status' => 'ready',
                    'duration_ms' => 12,
                ],
            ],
        ];

        $actual = $processor($record);

        $this->assertSame('[REDACTED]', $actual['context']['password']);
        $this->assertSame('[REDACTED]', $actual['context']['nested']['authorization']);
        $this->assertSame('[REDACTED]', $actual['context']['nested']['credit_card']);
        $this->assertSame('[REDACTED]', $actual['extra']['token']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['secret']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['to_email']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['phone_e164']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['invite_unlock_code']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['invite_token']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['webhook_secret']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['x_api_key']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['request_cookie']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['anon_id']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['target_attempt_id']);
        $this->assertSame('ok', $actual['context']['nested']['keep']);
        $this->assertSame('trace-1', $actual['extra']['payload']['trace_id']);
        $this->assertSame('ready', $actual['extra']['payload']['diagnostic_status']);
        $this->assertSame(12, $actual['extra']['payload']['duration_ms']);
    }

    public function test_key_matching_is_case_insensitive(): void
    {
        $processor = new RedactProcessor;

        $record = [
            'context' => [
                'Password' => 'a',
                'Authorization' => 'b',
            ],
            'extra' => [
                'TOKEN' => 'c',
            ],
        ];

        $actual = $processor($record);

        $this->assertSame('[REDACTED]', $actual['context']['Password']);
        $this->assertSame('[REDACTED]', $actual['context']['Authorization']);
        $this->assertSame('[REDACTED]', $actual['extra']['TOKEN']);
    }

    public function test_custom_redaction_keys_are_preserved(): void
    {
        $processor = new RedactProcessor(['session']);

        $actual = $processor([
            'context' => [
                'session_hint' => 'raw-session',
                'trace_id' => 'trace-1',
            ],
        ]);

        $this->assertSame('[REDACTED]', $actual['context']['session_hint']);
        $this->assertSame('trace-1', $actual['context']['trace_id']);
    }

    public function test_non_sensitive_fields_remain_unchanged(): void
    {
        $processor = new RedactProcessor;

        $record = [
            'context' => [
                'user_id' => 1001,
                'meta' => [
                    'score' => 95,
                    'enabled' => true,
                ],
            ],
            'extra' => [
                'request_id' => 'req-1',
            ],
        ];

        $actual = $processor($record);

        $this->assertSame($record, $actual);
    }

    public function test_redacts_sensitive_keys_for_monolog_log_record(): void
    {
        $processor = new RedactProcessor;

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-02-08T00:00:00+00:00'),
            channel: 'testing',
            level: Level::Info,
            message: 'demo',
            context: [
                'token' => 'tok_123',
                'nested' => [
                    'secret' => 'sec_123',
                ],
            ],
            extra: [
                'authorization' => 'Bearer abc',
                'request_id' => 'req-1',
            ],
        );

        $actual = $processor($record);

        $this->assertInstanceOf(LogRecord::class, $actual);
        $this->assertSame('[REDACTED]', $actual->context['token']);
        $this->assertSame('[REDACTED]', $actual->context['nested']['secret']);
        $this->assertSame('[REDACTED]', $actual->extra['authorization']);
        $this->assertSame('req-1', $actual->extra['request_id']);
    }

    public function test_redacts_sensitive_values_inside_diagnostic_strings(): void
    {
        $processor = new RedactProcessor;

        $record = [
            'context' => [
                'endpoint' => 'https://ops.example.test/hook?webhook_secret=whsec_live_123&attempt_id=attempt-raw-2',
                'message' => '{"invite_token":"invite-token-2","anon_id":"anon-raw-2","safe":"kept"}',
                'auth_header' => 'Bearer token-raw-3',
            ],
        ];

        $actual = $processor($record);

        $this->assertStringNotContainsString('whsec_live_123', $actual['context']['endpoint']);
        $this->assertStringNotContainsString('attempt-raw-2', $actual['context']['endpoint']);
        $this->assertStringNotContainsString('invite-token-2', $actual['context']['message']);
        $this->assertStringNotContainsString('anon-raw-2', $actual['context']['message']);
        $this->assertStringNotContainsString('token-raw-3', $actual['context']['auth_header']);
        $this->assertStringContainsString('safe', $actual['context']['message']);
    }
}
