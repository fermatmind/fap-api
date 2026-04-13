<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RefreshCareerAttributionDailyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_command_outputs_scope_and_writes_daily_rows(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $occurredAt = now()->startOfDay()->setDate(2026, 4, 10)->setTime(9, 15);
        $row = [
            'id' => (string) Str::uuid(),
            'event_code' => 'career_job_index_result_click',
            'event_name' => 'career_job_index_result_click',
            'org_id' => 5,
            'user_id' => null,
            'anon_id' => 'anon_cmd_001',
            'session_id' => 'session_cmd_001',
            'request_id' => 'request_cmd_001',
            'attempt_id' => null,
            'meta_json' => json_encode([
                'entry_surface' => 'career_job_index',
                'source_page_type' => 'job_index',
                'route_family' => 'jobs',
                'subject_kind' => 'job_slug',
                'subject_key' => 'data-scientists',
                'query_mode' => 'non_query',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
            'scale_code' => 'CAREER',
            'channel' => 'web',
            'locale' => 'zh',
        ];

        if (Schema::hasColumn('events', 'scale_code_v2')) {
            $row['scale_code_v2'] = 'CAREER';
        }

        DB::table('events')->insert($row);

        $this->artisan('analytics:refresh-career-attribution-daily', [
            '--from' => '2026-04-10',
            '--to' => '2026-04-10',
            '--org' => [5],
        ])->expectsOutputToContain('attempted_rows=1')
            ->expectsOutputToContain('upserted_rows=1')
            ->assertSuccessful();

        $row = DB::table('analytics_career_attribution_daily')
            ->where('day', '2026-04-10')
            ->where('org_id', 5)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('publish_ready', $row->readiness_class);
        $this->assertSame('job_index', $row->source_page_type);
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
