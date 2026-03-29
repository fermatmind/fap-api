<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillResultsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_results(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'ops_backfill_results_known',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'client_version' => null,
            'channel' => 'test',
            'referrer' => null,
            'started_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultId = (string) Str::uuid();
        DB::table('results')->insert([
            'id' => $resultId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => json_encode(['EI' => 10], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode(['EI' => 60], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode(['EI' => 'I'], JSON_UNESCAPED_UNICODE),
            'profile_version' => 'seed',
            'content_package_version' => 'v1',
            'is_valid' => 1,
            'computed_at' => now(),
            'result_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'seed',
            'report_engine_version' => 'v1.2',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-results-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_results_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('results')->where('id', $resultId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_scale_code_results(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => 'ops_backfill_results_unknown',
            'user_id' => null,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_version' => 'v0.3',
            'question_count' => 1,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'web',
            'client_version' => null,
            'channel' => 'test',
            'referrer' => null,
            'started_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultId = (string) Str::uuid();
        DB::table('results')->insert([
            'id' => $resultId,
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_version' => 'v0.3',
            'type_code' => 'UNKNOWN',
            'scores_json' => json_encode(['raw' => 0], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => 'seed',
            'content_package_version' => 'v1',
            'is_valid' => 1,
            'computed_at' => now(),
            'result_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'seed',
            'dir_version' => 'seed',
            'scoring_spec_version' => 'seed',
            'report_engine_version' => 'v1.2',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('ops:backfill-results-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_results_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('results')->where('id', $resultId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
