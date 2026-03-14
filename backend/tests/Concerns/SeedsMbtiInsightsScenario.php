<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsMbtiInsightsScenario
{
    /**
     * @return array{from:string,to:string}
     */
    private function seedMbtiInsightsAuthorityScenario(int $orgId): array
    {
        $dayOne = CarbonImmutable::parse('2026-01-03 09:00:00');
        $dayTwo = CarbonImmutable::parse('2026-01-04 10:00:00');

        $attemptA = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
            'norm_version' => 'norm_2026_01',
            'created_at' => $dayOne,
            'submitted_at' => $dayOne->addMinutes(5),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptA,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'INTJ-A',
            'scores_pct' => ['EI' => 45, 'SN' => 40, 'TF' => 80, 'JP' => 70, 'AT' => 60],
            'computed_at' => $dayOne->addMinutes(6),
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
        ]);

        $attemptB = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
            'norm_version' => 'norm_2026_01',
            'created_at' => $dayOne->addHours(1),
            'submitted_at' => $dayOne->addHours(1)->addMinutes(5),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptB,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'ENFP-T',
            'scores_pct' => ['EI' => 65, 'SN' => 30, 'TF' => 40, 'JP' => 35, 'AT' => 40],
            'computed_at' => $dayOne->addHours(1)->addMinutes(6),
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_01',
        ]);

        $attemptC = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayOne->addHours(2),
            'submitted_at' => $dayOne->addHours(2)->addMinutes(5),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptC,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'INFJ-A',
            'scores_pct' => ['EI' => 30, 'SN' => 35, 'TF' => 35, 'JP' => 75, 'AT' => 55],
            'computed_at' => $dayOne->addHours(2)->addMinutes(6),
            'content_package_version' => 'content_2026_01',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $attemptD = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo,
            'submitted_at' => $dayTwo->addMinutes(5),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptD,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'INTJ',
            'scores_pct' => ['EI' => 40, 'SN' => 35, 'TF' => 78, 'JP' => 68, 'AT' => 62],
            'computed_at' => $dayTwo->addMinutes(6),
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $attemptE = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'fr-FR',
            'region' => 'FR',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo->addHours(1),
            'submitted_at' => $dayTwo->addHours(1)->addMinutes(5),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $attemptE,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'ESTP-T',
            'scores_pct' => ['EI' => 80, 'SN' => 55, 'TF' => 78, 'JP' => 20, 'AT' => 30],
            'computed_at' => $dayTwo->addHours(1)->addMinutes(6),
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $nonMbtiAttempt = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_big5_2026_01',
            'scoring_spec_version' => 'scoring_big5_2026_01',
            'norm_version' => 'norm_big5_2026_01',
            'created_at' => $dayOne->addHours(3),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $nonMbtiAttempt,
            'org_id' => $orgId,
            'scale_code' => 'BIG5_OCEAN',
            'type_code' => 'ENTJ-A',
            'scores_pct' => ['EI' => 60, 'SN' => 55, 'TF' => 51, 'JP' => 54, 'AT' => 57],
            'computed_at' => $dayOne->addHours(3)->addMinutes(6),
            'content_package_version' => 'content_big5_2026_01',
            'scoring_spec_version' => 'scoring_big5_2026_01',
        ]);

        $invalidAttempt = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo->addHours(2),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $invalidAttempt,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'ENTP-T',
            'scores_pct' => ['EI' => 62, 'SN' => 64, 'TF' => 71, 'JP' => 28, 'AT' => 35],
            'computed_at' => $dayTwo->addHours(2)->addMinutes(6),
            'is_valid' => false,
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $fallbackAttempt = $this->insertAnalyticsAttempt([
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'locale' => 'en',
            'region' => 'US',
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
            'norm_version' => 'norm_2026_02',
            'created_at' => $dayTwo->addHours(3),
        ]);
        $this->insertAnalyticsResult([
            'attempt_id' => $fallbackAttempt,
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => '',
            'scores_pct' => ['EI' => 44, 'SN' => 66, 'TF' => 56, 'JP' => 60, 'AT' => 58],
            'computed_at' => $dayTwo->addHours(3)->addMinutes(6),
            'result_json' => ['type_code' => 'ISTJ-A'],
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        $this->insertAnalyticsResult([
            'attempt_id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'scale_code' => 'MBTI',
            'type_code' => 'ENTJ-A',
            'scores_pct' => ['EI' => 65, 'SN' => 55, 'TF' => 80, 'JP' => 75, 'AT' => 60],
            'computed_at' => $dayTwo->addHours(4)->addMinutes(6),
            'content_package_version' => 'content_2026_02',
            'scoring_spec_version' => 'scoring_2026_02',
        ]);

        return [
            'from' => $dayOne->toDateString(),
            'to' => $dayTwo->toDateString(),
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertAnalyticsAttempt(array $overrides = []): string
    {
        $attemptId = (string) ($overrides['id'] ?? Str::uuid());
        $createdAt = $overrides['created_at'] ?? CarbonImmutable::parse('2026-01-03 00:00:00');
        $submittedAt = $overrides['submitted_at'] ?? null;
        $scaleCode = (string) ($overrides['scale_code'] ?? 'MBTI');

        $row = [
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'anon_id' => 'anon_'.substr(str_replace('-', '', $attemptId), 0, 10),
            'user_id' => null,
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => $scaleCode,
            'scale_version' => (string) ($overrides['scale_version'] ?? 'v0.3'),
            'question_count' => (int) ($overrides['question_count'] ?? 93),
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'web',
            'client_version' => 'test',
            'channel' => 'web',
            'referrer' => '/tests/mbti-insights',
            'region' => (string) ($overrides['region'] ?? 'US'),
            'locale' => (string) ($overrides['locale'] ?? 'en'),
            'started_at' => $createdAt,
            'submitted_at' => $submittedAt,
            'created_at' => $createdAt,
            'updated_at' => $submittedAt ?? $createdAt,
            'pack_id' => (string) ($overrides['pack_id'] ?? $scaleCode),
            'dir_version' => (string) ($overrides['dir_version'] ?? 'mbti_dir_2026_01'),
            'content_package_version' => (string) ($overrides['content_package_version'] ?? 'content_2026_01'),
            'scoring_spec_version' => (string) ($overrides['scoring_spec_version'] ?? 'scoring_2026_01'),
            'norm_version' => (string) ($overrides['norm_version'] ?? 'norm_2026_01'),
            'result_json' => json_encode($overrides['result_json'] ?? ['type_code' => 'INTJ-A'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (Schema::hasColumn('attempts', 'scale_code_v2')) {
            $row['scale_code_v2'] = $scaleCode === 'MBTI' ? 'MBTI_PERSONALITY_TEST_16_TYPES' : $scaleCode;
        }

        if (Schema::hasColumn('attempts', 'scale_uid')) {
            $row['scale_uid'] = $scaleCode === 'MBTI'
                ? '11111111-1111-4111-8111-111111111111'
                : '22222222-2222-4222-8222-222222222222';
        }

        DB::table('attempts')->insert($row);

        return $attemptId;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertAnalyticsResult(array $overrides = []): string
    {
        $resultId = (string) ($overrides['id'] ?? Str::uuid());
        $computedAt = $overrides['computed_at'] ?? CarbonImmutable::parse('2026-01-03 00:00:00');
        $scaleCode = (string) ($overrides['scale_code'] ?? 'MBTI');
        $typeCode = (string) ($overrides['type_code'] ?? 'INTJ-A');
        $scoresPct = (array) ($overrides['scores_pct'] ?? ['EI' => 45, 'SN' => 40, 'TF' => 80, 'JP' => 70, 'AT' => 60]);
        $resultJson = $overrides['result_json'] ?? ['type_code' => $typeCode];

        $row = [
            'id' => $resultId,
            'attempt_id' => (string) ($overrides['attempt_id'] ?? Str::uuid()),
            'org_id' => (int) ($overrides['org_id'] ?? 0),
            'scale_code' => $scaleCode,
            'scale_version' => (string) ($overrides['scale_version'] ?? 'v0.3'),
            'type_code' => $typeCode,
            'scores_json' => json_encode($overrides['scores_json'] ?? ['EI' => ['sum' => 2]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'profile_version' => (string) ($overrides['profile_version'] ?? 'profile_2026_01'),
            'is_valid' => array_key_exists('is_valid', $overrides) ? (bool) $overrides['is_valid'] : true,
            'computed_at' => $computedAt,
            'created_at' => $computedAt,
            'updated_at' => $computedAt,
        ];

        if (Schema::hasColumn('results', 'scores_pct')) {
            $row['scores_pct'] = json_encode($scoresPct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'axis_states')) {
            $row['axis_states'] = json_encode($overrides['axis_states'] ?? [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'scale_code_v2')) {
            $row['scale_code_v2'] = $scaleCode === 'MBTI' ? 'MBTI_PERSONALITY_TEST_16_TYPES' : $scaleCode;
        }

        if (Schema::hasColumn('results', 'scale_uid')) {
            $row['scale_uid'] = $scaleCode === 'MBTI'
                ? '11111111-1111-4111-8111-111111111111'
                : '22222222-2222-4222-8222-222222222222';
        }

        if (Schema::hasColumn('results', 'result_json')) {
            $row['result_json'] = json_encode($resultJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (Schema::hasColumn('results', 'pack_id')) {
            $row['pack_id'] = (string) ($overrides['pack_id'] ?? $scaleCode);
        }

        if (Schema::hasColumn('results', 'content_package_version')) {
            $row['content_package_version'] = (string) ($overrides['content_package_version'] ?? 'content_2026_01');
        }

        if (Schema::hasColumn('results', 'dir_version')) {
            $row['dir_version'] = (string) ($overrides['dir_version'] ?? 'mbti_dir_2026_01');
        }

        if (Schema::hasColumn('results', 'scoring_spec_version')) {
            $row['scoring_spec_version'] = (string) ($overrides['scoring_spec_version'] ?? 'scoring_2026_01');
        }

        if (Schema::hasColumn('results', 'report_engine_version')) {
            $row['report_engine_version'] = (string) ($overrides['report_engine_version'] ?? 'v1.2');
        }

        DB::table('results')->insert($row);

        return $resultId;
    }
}
