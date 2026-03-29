<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillAttemptAnswerSetsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_attempt_answer_sets(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempt_answer_sets')->insert([
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'seed',
            'answers_json' => json_encode([['question_id' => 'Q1', 'answer' => 1]], JSON_UNESCAPED_UNICODE),
            'answers_hash' => hash('sha256', 'seed'),
            'question_count' => 1,
            'duration_ms' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('ops:backfill-attempt-answer-sets-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempt_answer_sets_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_attempt_answer_sets_scale_code(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempt_answer_sets')->insert([
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'pack_id' => 'seed',
            'dir_version' => 'seed',
            'scoring_spec_version' => 'seed',
            'answers_json' => json_encode([['question_id' => 'Q1', 'answer' => 1]], JSON_UNESCAPED_UNICODE),
            'answers_hash' => hash('sha256', 'seed_unknown'),
            'question_count' => 1,
            'duration_ms' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('ops:backfill-attempt-answer-sets-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempt_answer_sets_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempt_answer_sets')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
