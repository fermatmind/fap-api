<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportMbtiAttributionDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_command_outputs_grouped_rows_and_invite_unlock_contribution_rate(): void
    {
        DB::table('analytics_mbti_attribution_daily')->insert([
            'day' => '2026-04-06',
            'org_id' => 7,
            'locale' => 'zh',
            'entry_surface' => 'mbti_topic_detail',
            'source_page_type' => 'topic_detail',
            'test_slug' => 'mbti-personality-test-16-personality-types',
            'form_code' => 'mbti_144',
            'entry_views' => 10,
            'start_clicks' => 5,
            'start_attempts' => 4,
            'result_views' => 3,
            'unlock_clicks' => 2,
            'orders_created' => 2,
            'payments_confirmed' => 1,
            'unlock_successes' => 2,
            'payment_unlock_successes' => 1,
            'invite_creates' => 2,
            'invite_shares' => 1,
            'invite_completions' => 1,
            'invite_unlock_successes' => 1,
            'last_refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('analytics:report-mbti-attribution-daily', [
            '--from' => '2026-04-06',
            '--to' => '2026-04-06',
            '--org' => [7],
            '--json' => true,
        ])->expectsOutputToContain('"entry_surface":"mbti_topic_detail"')
            ->assertSuccessful();
    }
}
