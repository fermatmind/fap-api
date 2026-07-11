<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PaymentRepairSchedulerBootstrapTest extends TestCase
{
    public function test_both_payment_repair_commands_are_in_the_effective_schedule(): void
    {
        $events = $this->scheduleListEvents();

        foreach ([
            'commerce:repair-paid-orders --limit=50',
            'commerce:repair-post-commit-failed --limit=50',
        ] as $command) {
            $matchingEvents = collect($events)->filter(
                fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), $command)
            );

            $this->assertCount(1, $matchingEvents, "schedule:list must include {$command} exactly once.");
            $this->assertSame('*/5 * * * *', $matchingEvents->first()['expression'] ?? null);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function scheduleListEvents(): array
    {
        $process = new Process(
            [PHP_BINARY, base_path('artisan'), 'schedule:list', '--json', '--no-ansi'],
            base_path(),
        );
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
