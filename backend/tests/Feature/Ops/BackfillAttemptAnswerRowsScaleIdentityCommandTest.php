<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillAttemptAnswerRowsScaleIdentityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_scale_identity_columns_for_known_attempt_answer_rows(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempt_answer_rows')->insert([
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'question_id' => 'Q1',
            'question_index' => 1,
            'question_type' => 'likert',
            'answer_json' => json_encode(['answer' => 4], JSON_UNESCAPED_UNICODE),
            'duration_ms' => 10,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('ops:backfill-attempt-answer-rows-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempt_answer_rows_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempt_answer_rows')
            ->where('attempt_id', $attemptId)
            ->where('question_id', 'Q1')
            ->first();
        $this->assertNotNull($after);
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($after->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($after->scale_uid ?? ''));
    }

    public function test_backfill_skips_unknown_attempt_answer_rows_scale_code(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');

        $attemptId = (string) Str::uuid();
        DB::table('attempt_answer_rows')->insert([
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'UNKNOWN_SCALE',
            'scale_code_v2' => null,
            'scale_uid' => null,
            'question_id' => 'Q1',
            'question_index' => 1,
            'question_type' => 'likert',
            'answer_json' => json_encode(['answer' => 4], JSON_UNESCAPED_UNICODE),
            'duration_ms' => 10,
            'submitted_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('ops:backfill-attempt-answer-rows-scale-identity --chunk=100')
            ->expectsOutputToContain('backfill_attempt_answer_rows_scale_identity')
            ->assertExitCode(0);

        $after = DB::table('attempt_answer_rows')
            ->where('attempt_id', $attemptId)
            ->where('question_id', 'Q1')
            ->first();
        $this->assertNotNull($after);
        $this->assertNull($after->scale_code_v2);
        $this->assertNull($after->scale_uid);
    }
}
