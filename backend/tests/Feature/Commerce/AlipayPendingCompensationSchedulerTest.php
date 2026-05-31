<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AlipayPendingCompensationSchedulerTest extends TestCase
{
    public function test_alipay_pending_compensation_is_registered_conservatively(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'commerce:compensate-pending-orders')
        );

        $this->assertNotNull($event, 'schedule:list did not include the Alipay pending compensation command.');
        $this->assertStringContainsString('--provider=alipay', (string) $event['command']);
        $this->assertStringContainsString('--include-created', (string) $event['command']);
        $this->assertStringContainsString('--limit=50', (string) $event['command']);
        $this->assertStringContainsString('--older-than-minutes=15', (string) $event['command']);
        $this->assertStringNotContainsString('--close-expired', (string) $event['command']);
        $this->assertSame('*/5 * * * *', $event['expression']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scheduleListEvents(): array
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list', '--json', '--no-ansi'], base_path());
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
