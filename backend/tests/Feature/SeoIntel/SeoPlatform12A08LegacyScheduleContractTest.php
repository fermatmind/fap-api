<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoPlatform12A08LegacyScheduleContractTest extends TestCase
{
    public function test_existing_schedules_remain_serial_and_unchanged_outside_the_council_background_job(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));
        foreach ([
            ['seo:weekly-decisions --trigger=scheduled --json', "weeklyOn(4, '13:45')"],
            ['analytics:refresh-seo-conversion-daily --trigger=scheduled --json', "dailyAt('05:50')"],
            ['seo:runtime-probe-scheduled --trigger=scheduled --json', 'everyTenMinutes()'],
            ['seo-intel:url-truth-controlled-reconcile --execute --no-http --max-records=5000 --batch-size=250', "dailyAt('02:40')"],
        ] as [$command, $cadence]) {
            preg_match('/command\(\''.preg_quote($command, '/').'\'\)([^;]+);/', $source, $matches);
            $this->assertNotEmpty($matches);
            $this->assertStringContainsString($cadence, $matches[1]);
            $this->assertStringContainsString('withoutOverlapping(', $matches[1]);
            $this->assertStringContainsString('onOneServer()', $matches[1]);
            $this->assertStringNotContainsString('runInBackground()', $matches[1]);
        }
        $this->assertMatchesRegularExpression('/command\("seo-intel:gsc-sync[^;]+dailyAt\(\'05:20\'\)[^;]+withoutOverlapping\(120\)[^;]+onOneServer\(\)/', $source);
    }

    public function test_existing_deploy_dependency_guard_still_precedes_platform10_backfill(): void
    {
        $source = file_get_contents(base_path('../deploy.php'));
        $this->assertStringContainsString("after('guard:no-pending-seo-intel-migrations', 'seo:council-runtime-db-access')", $source);
        $this->assertStringContainsString("after('seo:council-runtime-db-access', 'seo:platform-10-material-backfill')", $source);
        $this->assertStringContainsString("after('seo:platform-10-material-backfill', 'seo:detector-foundation-receipt')", $source);
    }
}
