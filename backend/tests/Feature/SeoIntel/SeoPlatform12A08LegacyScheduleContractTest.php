<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class SeoPlatform12A08LegacyScheduleContractTest extends TestCase
{
    public function test_production_natural_tick_uses_the_application_identity_without_forwarding_acceptance(): void
    {
        $this->app->instance('env', 'production');
        config()->set('seo_council.scheduler_enabled', true);
        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(output: '{"status":"IDLE","execution_allowed":false,"business_write_enabled":false}'),
        ]);
        $this->artisan('seo:council-scheduled', ['--json' => true])
            ->expectsOutput('{"status":"IDLE","execution_allowed":false,"business_write_enabled":false}')->assertSuccessful();
        \Illuminate\Support\Facades\Process::assertRan(function ($process): bool {
            return $process->timeout === 120 && $process->command === [
                '/usr/bin/sudo', '-n', '-u', 'www-data', '--', PHP_BINARY,
                base_path('artisan'), 'seo:council-scheduled', '--json',
            ];
        });
    }

    public function test_controlled_acceptance_is_never_delegated_by_the_natural_entrypoint(): void
    {
        $this->app->instance('env', 'production');
        config()->set('seo_council.scheduler_enabled', true);
        \Illuminate\Support\Facades\Process::fake();
        $this->artisan('seo:council-scheduled', ['--acceptance' => 'seo.platform12.daily_private_policy_evidence_drift'])
            ->expectsOutput('{"status":"SCHEDULED_IDENTITY_HOLD","execution_allowed":false}')->assertFailed();
        \Illuminate\Support\Facades\Process::assertNothingRan();
    }

    public function test_application_identity_failure_is_closed_and_does_not_expose_process_stderr(): void
    {
        $this->app->instance('env', 'production');
        config()->set('seo_council.scheduler_enabled', true);
        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(errorOutput: 'private diagnostic', exitCode: 1),
        ]);
        $this->artisan('seo:council-scheduled', ['--json' => true])
            ->expectsOutput('{"status":"SCHEDULED_IDENTITY_HOLD","execution_allowed":false}')->assertFailed();
    }

    public function test_existing_schedules_keep_cadence_and_locks_with_bounded_background_truth_maintenance(): void
    {
        $source = file_get_contents(base_path('bootstrap/app.php'));
        foreach ([
            ['seo:weekly-decisions --trigger=scheduled --json', "weeklyOn(4, '13:45')", false],
            ['analytics:refresh-seo-conversion-daily --trigger=scheduled --json', "dailyAt('05:50')", false],
            ['seo-intel:url-truth-controlled-reconcile --execute --no-http --max-records=5000 --batch-size=250', "timezone('Asia/Shanghai')\n            ->dailyAt('06:10')", true],
        ] as [$command, $cadence, $background]) {
            preg_match('/command\(\''.preg_quote($command, '/').'\'\)([^;]+);/', $source, $matches);
            $this->assertNotEmpty($matches);
            $this->assertStringContainsString($cadence, $matches[1]);
            $this->assertStringContainsString('withoutOverlapping(', $matches[1]);
            $this->assertStringContainsString('onOneServer()', $matches[1]);
            if ($background) {
                $this->assertStringContainsString('runInBackground()', $matches[1]);
            } else {
                $this->assertStringNotContainsString('runInBackground()', $matches[1]);
            }
        }
        $m2 = collect(app(\App\Services\SeoCouncil\Platform12\Platform12DailyMissionSet::class)->missions())
            ->firstWhere('mission_id', 'seo.platform12.daily_url_truth_reconciliation');
        $this->assertSame('daily:ALL:06:25', $m2['natural_slot']);
        $this->assertSame('Asia/Shanghai', $m2['timezone']);
        $this->assertSame(0, array_sum($m2['budgets']));
        $this->assertSame('HOLD', $m2['failure_policy']['terminal_state']);
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
