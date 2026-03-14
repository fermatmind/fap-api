<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Analytics\QuestionAnalyticsSupport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsQuestionAnalyticsScenario
{
    /**
     * @return array{from:string,to:string}
     */
    private function seedQuestionAnalyticsScenario(int $orgId): array
    {
        $definition = app(QuestionAnalyticsSupport::class)->big5Definition();
        /** @var array<int,string> $questionIdsByOrder */
        $questionIdsByOrder = $definition['question_ids_by_order'] ?? [];

        $dayOne = CarbonImmutable::parse('2026-01-03 09:00:00');
        $dayTwo = CarbonImmutable::parse('2026-01-04 10:00:00');

        $attemptA = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
            'norm_version' => 'norm_2026_01',
            'created_at' => $dayOne,
            'submitted_at' => $dayOne->addMinutes(8),
        ]);
        $this->insertQuestionAnalyticsAnswerRows($attemptA, $orgId, $questionIdsByOrder, '1', $dayOne->addMinutes(8));

        $attemptB = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayOne->addHours(1),
            'submitted_at' => $dayOne->addHours(1)->addMinutes(8),
        ]);
        $this->insertQuestionAnalyticsAnswerRows($attemptB, $orgId, $questionIdsByOrder, '5', $dayOne->addHours(1)->addMinutes(8));

        $attemptC = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo,
            'updated_at' => $dayTwo->addMinutes(12),
        ]);
        $this->insertQuestionAnalyticsDraft($attemptC, $orgId, [
            'cursor' => 'page-3',
            'updated_at' => $dayTwo->addMinutes(12),
            'answers' => [
                $this->draftAnswer($questionIdsByOrder[1] ?? '1', 0, '2'),
                $this->draftAnswer($questionIdsByOrder[2] ?? '2', 1, '3'),
            ],
        ]);

        $attemptD = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'fr-FR',
            'region' => 'FR',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo->addHours(1),
            'updated_at' => $dayTwo->addHours(1)->addMinutes(6),
        ]);
        $this->insertQuestionAnalyticsDraft($attemptD, $orgId, [
            'cursor' => 'page-2',
            'updated_at' => $dayTwo->addHours(1)->addMinutes(6),
            'answers' => [
                $this->draftAnswer($questionIdsByOrder[1] ?? '1', 0, '4'),
            ],
        ]);

        $clinicalAttempt = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'CLINICAL_COMBO_68',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_clinical_2026_01',
            'scoring_spec_version' => 'scoring_clinical_2026_01',
            'norm_version' => 'norm_clinical_2026_01',
            'created_at' => $dayOne->addHours(2),
            'submitted_at' => $dayOne->addHours(2)->addMinutes(5),
        ]);
        $this->insertSingleAnswerRow($clinicalAttempt, $orgId, 'CLINICAL_COMBO_68', 'CLIN-1', 0, '1', $dayOne->addHours(2)->addMinutes(5));

        $sdsAttempt = $this->insertQuestionAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'SDS_20',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_sds_2026_01',
            'scoring_spec_version' => 'scoring_sds_2026_01',
            'norm_version' => 'norm_sds_2026_01',
            'created_at' => $dayOne->addHours(3),
            'submitted_at' => $dayOne->addHours(3)->addMinutes(5),
        ]);
        $this->insertSingleAnswerRow($sdsAttempt, $orgId, 'SDS_20', 'SDS-1', 0, null, $dayOne->addHours(3)->addMinutes(5), true);

        return [
            'from' => $dayOne->toDateString(),
            'to' => $dayTwo->toDateString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertQuestionAnalyticsAttempt(array $overrides = []): string
    {
        $attemptId = (string) ($overrides['id'] ?? Str::uuid());
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-01-03 00:00:00');
        $submittedAt = $overrides['submitted_at'] ?? null;
        $updatedAt = $overrides['updated_at'] ?? ($submittedAt ?? $createdAt);
        $scaleCode = (string) ($overrides['scale_code'] ?? 'BIG5_OCEAN');

        $row = [
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => $scaleCode,
            'scale_version' => (string) ($overrides['scale_version'] ?? 'v1'),
            'question_count' => (int) ($overrides['question_count'] ?? 120),
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/question-analytics',
            'region' => (string) ($overrides['region'] ?? 'US'),
            'locale' => (string) ($overrides['locale'] ?? 'en'),
            'started_at' => $createdAt,
            'submitted_at' => $submittedAt,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'pack_id' => (string) ($overrides['pack_id'] ?? $scaleCode),
            'dir_version' => (string) ($overrides['dir_version'] ?? 'v1'),
            'content_package_version' => (string) ($overrides['content_package_version'] ?? 'content_2026_01'),
            'scoring_spec_version' => (string) ($overrides['scoring_spec_version'] ?? 'scoring_2026_01'),
            'norm_version' => (string) ($overrides['norm_version'] ?? 'norm_2026_01'),
            'result_json' => json_encode($overrides['result_json'] ?? ['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $row['scale_code_v2'] = match ($scaleCode) {
                'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
                'CLINICAL_COMBO_68' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
                'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
                default => $scaleCode,
            };
        }

        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $row['scale_uid'] = match ($scaleCode) {
                'BIG5_OCEAN' => '22222222-2222-4222-8222-222222222222',
                'CLINICAL_COMBO_68' => '33333333-3333-4333-8333-333333333333',
                'SDS_20' => '44444444-4444-4444-8444-444444444444',
                default => null,
            };
        }

        DB::table('attempts')->insert($row);

        return $attemptId;
    }

    /**
     * @param  array<int,string>  $questionIdsByOrder
     */
    private function insertQuestionAnalyticsAnswerRows(
        string $attemptId,
        int $orgId,
        array $questionIdsByOrder,
        string $optionCode,
        \DateTimeInterface $submittedAt,
    ): void {
        $rows = [];
        $createdAt = CarbonImmutable::parse($submittedAt);

        foreach ($questionIdsByOrder as $order => $questionId) {
            $rows[] = $this->answerRowPayload(
                $attemptId,
                $orgId,
                'BIG5_OCEAN',
                (string) $questionId,
                max(0, $order - 1),
                $optionCode,
                $createdAt
            );
        }

        DB::table('attempt_answer_rows')->insert($rows);
    }

    private function insertSingleAnswerRow(
        string $attemptId,
        int $orgId,
        string $scaleCode,
        string $questionId,
        int $questionIndex,
        ?string $optionCode,
        \DateTimeInterface $submittedAt,
        bool $redacted = false,
    ): void {
        DB::table('attempt_answer_rows')->insert([
            $this->answerRowPayload($attemptId, $orgId, $scaleCode, $questionId, $questionIndex, $optionCode, $submittedAt, $redacted),
        ]);
    }

    /**
     * @param  array{cursor:string,updated_at:\DateTimeInterface,answers:list<array<string,mixed>>}  $overrides
     */
    private function insertQuestionAnalyticsDraft(string $attemptId, int $orgId, array $overrides): void
    {
        DB::table('attempt_drafts')->insert([
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'resume_token_hash' => hash('sha256', 'resume-'.$attemptId),
            'last_seq' => 2,
            'cursor' => $overrides['cursor'],
            'duration_ms' => 2400,
            'answers_json' => json_encode($overrides['answers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'answered_count' => count($overrides['answers']),
            'created_at' => CarbonImmutable::parse($overrides['updated_at'])->subMinutes(5),
            'updated_at' => $overrides['updated_at'],
            'expires_at' => CarbonImmutable::parse($overrides['updated_at'])->addDays(7),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function draftAnswer(string $questionId, int $questionIndex, string $code): array
    {
        return [
            'question_id' => $questionId,
            'question_type' => 'likert',
            'question_index' => $questionIndex,
            'code' => $code,
            'answer' => ['value' => (int) $code],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function answerRowPayload(
        string $attemptId,
        int $orgId,
        string $scaleCode,
        string $questionId,
        int $questionIndex,
        ?string $optionCode,
        \DateTimeInterface $submittedAt,
        bool $redacted = false,
    ): array {
        $payload = $redacted
            ? ['question_id' => $questionId, 'redacted' => true]
            : [
                'question_id' => $questionId,
                'question_index' => $questionIndex,
                'question_type' => 'likert',
                'code' => (string) $optionCode,
                'answer' => ['value' => $optionCode !== null ? (int) $optionCode : null],
            ];

        $row = [
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'question_id' => $questionId,
            'question_index' => $questionIndex,
            'question_type' => 'likert',
            'answer_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'duration_ms' => 5000,
            'submitted_at' => $submittedAt,
            'created_at' => $submittedAt,
        ];

        if (Schema::hasColumn('attempt_answer_rows', 'scale_code_v2')) {
            $row['scale_code_v2'] = match ($scaleCode) {
                'BIG5_OCEAN' => 'BIG_FIVE_OCEAN_MODEL',
                'CLINICAL_COMBO_68' => 'CLINICAL_DEPRESSION_ANXIETY_PRO',
                'SDS_20' => 'DEPRESSION_SCREENING_STANDARD',
                default => $scaleCode,
            };
        }

        if (Schema::hasColumn('attempt_answer_rows', 'scale_uid')) {
            $row['scale_uid'] = match ($scaleCode) {
                'BIG5_OCEAN' => '22222222-2222-4222-8222-222222222222',
                'CLINICAL_COMBO_68' => '33333333-3333-4333-8333-333333333333',
                'SDS_20' => '44444444-4444-4444-8444-444444444444',
                default => null,
            };
        }

        return $row;
    }
}
