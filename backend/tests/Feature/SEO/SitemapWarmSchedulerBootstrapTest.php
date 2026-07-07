<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SitemapWarmSchedulerBootstrapTest extends TestCase
{
    public function test_sitemap_warm_command_is_registered_in_scheduler(): void
    {
        $events = $this->scheduleListEvents();
        $event = collect($events)->first(
            fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'seo:warm-sitemap-source-cache')
        );

        $this->assertNotNull($event, 'schedule:list did not include seo:warm-sitemap-source-cache.');
        $command = (string) $event['command'];

        $this->assertStringContainsString('seo:warm-sitemap-source-cache', $command);
        $this->assertStringContainsString('--json', $command);
        $this->assertSame('*/10 * * * *', $event['expression']);
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
