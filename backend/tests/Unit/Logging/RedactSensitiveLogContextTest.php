<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Logging\RedactSensitiveLogContext;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\TestCase;

final class RedactSensitiveLogContextTest extends TestCase
{
    public function test_processor_redacts_identifiers_exception_objects_and_message_secrets(): void
    {
        $handler = new TestHandler;
        $monolog = new Logger('redaction-contract', [$handler]);
        $logger = new IlluminateLogger($monolog);
        app(RedactSensitiveLogContext::class)($logger);

        $logger->error('failed attempt_id=attempt-secret token=token-secret', [
            'attempt_id' => 'attempt-secret',
            'order_no' => 'order-secret',
            'user_id' => 'user-secret',
            'job_id' => 'job-secret',
            'exception' => new RuntimeException('Bearer bearer-secret'),
            'safe_count' => 2,
        ]);

        $record = $handler->getRecords()[0];
        $this->assertStringNotContainsString('attempt-secret', $record->message);
        $this->assertStringNotContainsString('token-secret', $record->message);
        $this->assertSame(SensitiveDiagnosticRedactor::REDACTED, $record->context['attempt_id']);
        $this->assertSame(SensitiveDiagnosticRedactor::REDACTED, $record->context['order_no']);
        $this->assertSame(SensitiveDiagnosticRedactor::REDACTED, $record->context['user_id']);
        $this->assertSame(SensitiveDiagnosticRedactor::REDACTED, $record->context['job_id']);
        $this->assertSame(SensitiveDiagnosticRedactor::REDACTED, $record->context['exception']);
        $this->assertSame(2, $record->context['safe_count']);
    }

    public function test_every_non_null_application_channel_installs_the_redaction_tap(): void
    {
        foreach (['single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            $this->assertContains(
                RedactSensitiveLogContext::class,
                (array) config("logging.channels.{$channel}.tap", []),
                "{$channel} must redact context before output"
            );
        }
    }
}
