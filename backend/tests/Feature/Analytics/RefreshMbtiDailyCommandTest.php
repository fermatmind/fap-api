<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsMbtiInsightsScenario;
use Tests\TestCase;

final class RefreshMbtiDailyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMbtiInsightsScenario;

    public function test_command_supports_dry_run_and_upserts_two_mbti_daily_tables(): void
    {
        $scenario = $this->seedMbtiInsightsAuthorityScenario(701);

        $this->artisan('analytics:refresh-mbti-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [701],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('source_results=5')
            ->expectsOutputToContain('attempted_type_rows=5')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_mbti_type_daily')->count());
        $this->assertSame(0, DB::table('analytics_axis_daily')->count());

        $this->artisan('analytics:refresh-mbti-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [701],
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_type_rows=5')
            ->expectsOutputToContain('upserted_axis_rows=24')
            ->assertExitCode(0);

        $this->assertSame(5, DB::table('analytics_mbti_type_daily')->count());
        $this->assertSame(24, DB::table('analytics_axis_daily')->count());
        $this->assertSame(5, (int) DB::table('analytics_mbti_type_daily')->sum('results_count'));

        $this->artisan('analytics:refresh-mbti-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [701],
        ])->assertExitCode(0);

        $this->assertSame(5, DB::table('analytics_mbti_type_daily')->count());
        $this->assertSame(24, DB::table('analytics_axis_daily')->count());
    }
}
