<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Services\Attempts\AnswerRowWriter;
use App\Services\Attempts\AnswerSetStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AnswerStorageScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_dual_mode_writes_scale_identity_columns_to_answer_set_and_rows(): void
    {
        config()->set('scale_identity.write_mode', 'dual');

        $attempt = $this->createSdsAttempt('anon_answer_storage_dual');
        $answers = $this->answersFixture();

        $stored = app(AnswerSetStore::class)->storeFinalAnswers($attempt, $answers, 120000, 'v2.0_Factor_Logic');
        $this->assertTrue((bool) ($stored['ok'] ?? false));

        $rowsWritten = app(AnswerRowWriter::class)->writeRows($attempt, $answers, 120000);
        $this->assertTrue((bool) ($rowsWritten['ok'] ?? false));
        $this->assertSame(2, (int) ($rowsWritten['rows'] ?? 0));

        $setRow = DB::table('attempt_answer_sets')->where('attempt_id', (string) $attempt->id)->first();
        $this->assertNotNull($setRow);
        $this->assertSame('DEPRESSION_SCREENING_STANDARD', (string) ($setRow->scale_code_v2 ?? ''));
        $this->assertSame('44444444-4444-4444-8444-444444444444', (string) ($setRow->scale_uid ?? ''));

        $row = DB::table('attempt_answer_rows')
            ->where('attempt_id', (string) $attempt->id)
            ->where('question_id', 'SDS-001')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('DEPRESSION_SCREENING_STANDARD', (string) ($row->scale_code_v2 ?? ''));
        $this->assertSame('44444444-4444-4444-8444-444444444444', (string) ($row->scale_uid ?? ''));
    }

    public function test_legacy_mode_keeps_scale_identity_columns_nullable_in_answer_storage(): void
    {
        config()->set('scale_identity.write_mode', 'legacy');

        $attempt = $this->createSdsAttempt('anon_answer_storage_legacy');
        $answers = $this->answersFixture();

        $stored = app(AnswerSetStore::class)->storeFinalAnswers($attempt, $answers, 90000, 'v2.0_Factor_Logic');
        $this->assertTrue((bool) ($stored['ok'] ?? false));

        $rowsWritten = app(AnswerRowWriter::class)->writeRows($attempt, $answers, 90000);
        $this->assertTrue((bool) ($rowsWritten['ok'] ?? false));
        $this->assertSame(2, (int) ($rowsWritten['rows'] ?? 0));

        $setRow = DB::table('attempt_answer_sets')->where('attempt_id', (string) $attempt->id)->first();
        $this->assertNotNull($setRow);
        $this->assertNull($setRow->scale_code_v2);
        $this->assertNull($setRow->scale_uid);

        $row = DB::table('attempt_answer_rows')
            ->where('attempt_id', (string) $attempt->id)
            ->where('question_id', 'SDS-001')
            ->first();
        $this->assertNotNull($row);
        $this->assertNull($row->scale_code_v2);
        $this->assertNull($row->scale_uid);
    }

    private function createSdsAttempt(string $anonId): Attempt
    {
        return Attempt::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'SDS_20',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 2,
            'answers_summary_json' => [
                'stage' => 'seed',
            ],
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(2),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'SDS_20',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v2.0_Factor_Logic',
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function answersFixture(): array
    {
        return [
            [
                'question_id' => 'SDS-001',
                'question_index' => 1,
                'question_type' => 'likert',
                'code' => '1',
                'answer' => '1',
            ],
            [
                'question_id' => 'SDS-002',
                'question_index' => 2,
                'question_type' => 'likert',
                'code' => '2',
                'answer' => '2',
            ],
        ];
    }
}

