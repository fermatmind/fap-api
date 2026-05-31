<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class RefreshAnalyticsFunnelDailyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_command_supports_dry_run_and_upserts_refresh_scope(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(91);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=2')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=91:scale=all',
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_rows=2')
            ->expectsOutputToContain('write_guard=passed')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('analytics_funnel_daily')->count());

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [91],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=91:scale=all',
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('analytics_funnel_daily')->count());
        $this->assertSame(
            3898,
            (int) DB::table('analytics_funnel_daily')->where('org_id', 91)->sum('paid_revenue_cents')
        );
    }
}
