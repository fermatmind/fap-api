<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsQualityResearchScenario;
use Tests\TestCase;

final class RefreshQualityDailyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQualityResearchScenario;

    public function test_command_supports_dry_run_and_upserts_quality_daily_rows(): void
    {
        $scenario = $this->seedQualityResearchScenario(901);

        $this->artisan('analytics:refresh-quality-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('attempted_rows=4')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_scale_quality_daily')->count());

        $this->artisan('analytics:refresh-quality-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_rows=4')
            ->assertExitCode(0);

        $this->assertSame(4, DB::table('analytics_scale_quality_daily')->count());

        $this->artisan('analytics:refresh-quality-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
            '--scale' => ['BIG5_OCEAN'],
        ])
            ->expectsOutputToContain('scale_scope=BIG5_OCEAN')
            ->assertExitCode(0);

        $this->assertSame(4, DB::table('analytics_scale_quality_daily')->count());
    }
}
