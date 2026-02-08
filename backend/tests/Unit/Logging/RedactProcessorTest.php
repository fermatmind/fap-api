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
        $processor = new RedactProcessor();

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
                    'trace_id' => 'trace-1',
                ],
            ],
        ];

        $actual = $processor($record);

        $this->assertSame('[REDACTED]', $actual['context']['password']);
        $this->assertSame('[REDACTED]', $actual['context']['nested']['authorization']);
        $this->assertSame('[REDACTED]', $actual['context']['nested']['credit_card']);
        $this->assertSame('[REDACTED]', $actual['extra']['token']);
        $this->assertSame('[REDACTED]', $actual['extra']['payload']['secret']);
        $this->assertSame('ok', $actual['context']['nested']['keep']);
        $this->assertSame('trace-1', $actual['extra']['payload']['trace_id']);
    }

    public function test_key_matching_is_case_insensitive(): void
    {
        $processor = new RedactProcessor();

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

    public function test_non_sensitive_fields_remain_unchanged(): void
    {
        $processor = new RedactProcessor();

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
        $processor = new RedactProcessor();

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
}
