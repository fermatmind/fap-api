<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsFunnelAnalyticsScenario;
use Tests\TestCase;

final class AnalyticsFunnelControlledRefreshWriteGuard01Test extends TestCase
{
    use RefreshDatabase;
    use SeedsFunnelAnalyticsScenario;

    public function test_non_dry_run_refresh_is_blocked_without_exact_confirmation_token(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(610);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [610],
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('before_count=0')
            ->expectsOutputToContain('after_count=0')
            ->expectsOutputToContain('write_guard=blocked')
            ->expectsOutputToContain('write_guard_reason=confirm_write_token_mismatch')
            ->expectsOutputToContain('expected_confirm_write=analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=610:scale=all')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_non_dry_run_refresh_requires_explicit_org_scope(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(611);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=*:scale=all',
        ])
            ->expectsOutputToContain('write_guard=blocked')
            ->expectsOutputToContain('write_guard_reason=explicit_org_scope_required')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_non_dry_run_refresh_requires_explicit_date_range(): void
    {
        $this->artisan('analytics:refresh-funnel-daily', [
            '--org' => [612],
        ])
            ->expectsOutputToContain('write_guard=blocked')
            ->expectsOutputToContain('write_guard_reason=explicit_from_to_required')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_non_dry_run_refresh_rejects_overwide_date_range(): void
    {
        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => '2026-01-01',
            '--to' => '2026-02-05',
            '--org' => [613],
            '--confirm-write' => 'analytics_funnel_daily:write:2026-01-01:2026-02-05:org=613:scale=all',
        ])
            ->expectsOutputToContain('write_guard=blocked')
            ->expectsOutputToContain('write_guard_reason=date_range_exceeds_31_days')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_dry_run_outputs_audit_counts_and_never_writes(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(614);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [614],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('before_count=0')
            ->expectsOutputToContain('after_count=0')
            ->expectsOutputToContain('attempted_rows=2')
            ->expectsOutputToContain('deleted_rows=0')
            ->expectsOutputToContain('upserted_rows=0')
            ->expectsOutputToContain('write_guard=dry_run_no_write')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_funnel_daily')->count());
    }

    public function test_non_dry_run_refresh_writes_only_with_exact_bounded_confirmation_token(): void
    {
        $scenario = $this->seedFunnelAnalyticsScenario(615);

        $this->artisan('analytics:refresh-funnel-daily', [
            '--from' => $scenario['day'],
            '--to' => $scenario['day'],
            '--org' => [615],
            '--confirm-write' => 'analytics_funnel_daily:write:'.$scenario['day'].':'.$scenario['day'].':org=615:scale=all',
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('before_count=0')
            ->expectsOutputToContain('after_count=2')
            ->expectsOutputToContain('attempted_rows=2')
            ->expectsOutputToContain('upserted_rows=2')
            ->expectsOutputToContain('write_guard=passed')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('analytics_funnel_daily')->where('org_id', 615)->count());
    }
}
