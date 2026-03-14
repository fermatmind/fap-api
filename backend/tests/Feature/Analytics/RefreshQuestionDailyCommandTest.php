<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsQuestionAnalyticsScenario;
use Tests\TestCase;

final class RefreshQuestionDailyCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQuestionAnalyticsScenario;

    public function test_command_supports_dry_run_and_upserts_two_question_daily_tables(): void
    {
        $scenario = $this->seedQuestionAnalyticsScenario(901);

        $this->artisan('analytics:refresh-question-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('effective_scale_scope=BIG5_OCEAN')
            ->expectsOutputToContain('attempted_option_rows=240')
            ->expectsOutputToContain('attempted_progress_rows=245')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('analytics_question_option_daily')->count());
        $this->assertSame(0, DB::table('analytics_question_progress_daily')->count());

        $this->artisan('analytics:refresh-question-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
        ])
            ->expectsOutputToContain('dry_run=0')
            ->expectsOutputToContain('upserted_option_rows=240')
            ->expectsOutputToContain('upserted_progress_rows=245')
            ->assertExitCode(0);

        $this->assertSame(240, DB::table('analytics_question_option_daily')->count());
        $this->assertSame(245, DB::table('analytics_question_progress_daily')->count());

        $this->artisan('analytics:refresh-question-daily', [
            '--from' => $scenario['from'],
            '--to' => $scenario['to'],
            '--org' => [901],
        ])->assertExitCode(0);

        $this->assertSame(240, DB::table('analytics_question_option_daily')->count());
        $this->assertSame(245, DB::table('analytics_question_progress_daily')->count());
    }
}
