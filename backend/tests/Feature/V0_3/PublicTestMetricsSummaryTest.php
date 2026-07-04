<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicTestMetricsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_summary_returns_global_cumulative_successful_attempts(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $now = now();

        DB::table('analytics_test_metrics_daily')->insert([
            [
                'day' => '2026-07-03',
                'org_id' => 0,
                'scale_code' => 'MBTI',
                'scale_code_v2' => '',
                'scale_uid' => '',
                'form_code' => 'mbti_93',
                'locale' => 'zh-CN',
                'started_attempts' => 10,
                'successful_attempts' => 1000,
                'failed_attempts' => 12,
                'total_attempts' => 1012,
                'last_refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'day' => '2026-07-04',
                'org_id' => 0,
                'scale_code' => 'ENNEAGRAM',
                'scale_code_v2' => '',
                'scale_uid' => '',
                'form_code' => '',
                'locale' => 'zh-CN',
                'started_attempts' => 183,
                'successful_attempts' => 183,
                'failed_attempts' => 1,
                'total_attempts' => 184,
                'last_refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'day' => '2026-07-04',
                'org_id' => 2,
                'scale_code' => 'MBTI',
                'scale_code_v2' => '',
                'scale_uid' => '',
                'form_code' => 'mbti_93',
                'locale' => 'zh-CN',
                'started_attempts' => 999,
                'successful_attempts' => 999,
                'failed_attempts' => 999,
                'total_attempts' => 1998,
                'last_refreshed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->getJson('/api/v0.3/public-gateways/test-metrics-summary')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('test_metrics_summary.available', true)
            ->assertJsonPath('test_metrics_summary.org_id', 0)
            ->assertJsonPath('test_metrics_summary.row_count', 2)
            ->assertJsonPath('test_metrics_summary.cumulative_successful_attempts', 1183)
            ->assertJsonPath('test_metrics_summary.cumulative_failed_attempts', 13)
            ->assertJsonPath('test_metrics_summary.source', 'analytics_test_metrics_daily');
    }
}
