<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AccessTestStatisticsSchedulerTest extends TestCase
{
    public function test_incremental_and_reconciliation_refreshes_are_server_scheduled(): void
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list', '--json', '--no-ansi'], base_path());
        $process->mustRun();
        $events = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));

        $recent = $events->first(fn (array $event): bool => str_contains((string) $event['command'], 'analytics:refresh-access-test-statistics --scheduled-recent'));
        $history = $events->first(fn (array $event): bool => str_contains((string) $event['command'], 'analytics:refresh-access-test-statistics --scheduled-history'));

        $this->assertNotNull($recent);
        $this->assertSame('*/5 * * * *', $recent['expression']);
        $this->assertNotNull($history);
        $this->assertSame('15 18 * * *', $history['expression']);
    }
}
