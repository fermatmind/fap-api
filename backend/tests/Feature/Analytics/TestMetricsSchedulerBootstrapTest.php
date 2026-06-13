<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class TestMetricsSchedulerBootstrapTest extends TestCase
{
    public function test_test_metrics_current_day_refresh_is_registered_conservatively(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'analytics:refresh-test-metrics-daily')
        );

        $this->assertNotNull($event, 'schedule:list did not include the test metrics daily refresh command.');
        $command = (string) $event['command'];

        $this->assertStringContainsString('analytics:refresh-test-metrics-daily', $command);
        $this->assertStringContainsString('--scheduled-current-day', $command);
        $this->assertStringNotContainsString('--dry-run', $command);
        $this->assertStringNotContainsString('--from=', $command);
        $this->assertStringNotContainsString('--to=', $command);
        $this->assertStringNotContainsString('--confirm-write', $command);
        $this->assertSame('*/15 * * * *', $event['expression']);
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
