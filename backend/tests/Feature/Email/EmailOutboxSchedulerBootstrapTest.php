<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class EmailOutboxSchedulerBootstrapTest extends TestCase
{
    public function test_email_outbox_send_is_registered_in_runtime_schedule(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'email:outbox-send')
        );

        $this->assertNotNull(
            $event,
            'schedule:list did not include the email outbox sender command. Commands: '.json_encode(
                array_values(array_map(fn (array $item): string => (string) ($item['command'] ?? ''), $events))
            )
        );
        $this->assertStringContainsString('email:outbox-send', (string) $event['command']);
        $this->assertStringContainsString('--limit=50', (string) $event['command']);
        $this->assertSame('* * * * *', $event['expression']);
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
