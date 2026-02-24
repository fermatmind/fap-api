<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use App\Services\Report\Eq60ReportComposer;
use App\Services\Report\ReportAccess;
use Tests\TestCase;

final class Eq60GoldenCasesTest extends TestCase
{
    public function test_compiled_golden_cases_match_scorer_output_and_section_contract(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);

        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);
        /** @var Eq60ReportComposer $composer */
        $composer = app(Eq60ReportComposer::class);

        $questionIndex = $loader->loadQuestionIndex('v1');
        $options = $loader->loadOptions('v1');
        $policy = $loader->loadPolicy('v1');
        $cases = $loader->loadGoldenCases('v1');
        $manifestHash = $loader->resolveManifestHash('v1');

        $this->assertNotEmpty($cases);
        $this->assertNotEmpty($questionIndex);
        $this->assertNotEmpty((array) ($options['score_map'] ?? []));

        foreach ($cases as $case) {
            $caseId = trim((string) ($case['case_id'] ?? ''));
            $answers = $this->resolveAnswersMap($case);

            $payload = $scorer->score(
                $answers,
                $questionIndex,
                $policy,
                [
                    'score_map' => (array) ($options['score_map'] ?? []),
                    'server_duration_seconds' => (int) ($case['time_seconds_total'] ?? 420),
                    'locale' => (string) ($case['locale'] ?? 'zh-CN'),
                    'region' => (string) ($case['country'] ?? 'CN_MAINLAND'),
                    'pack_id' => Eq60PackLoader::PACK_ID,
                    'dir_version' => 'v1',
                    'content_manifest_hash' => $manifestHash,
                ]
            );

            $this->assertSame(
                strtoupper((string) ($case['expected_quality_level'] ?? '')),
                strtoupper((string) data_get($payload, 'quality.level', '')),
                'golden case quality level mismatch: ' . $caseId
            );

            $expectedFlags = array_values(array_unique(array_map(
                static fn ($flag): string => strtoupper(trim((string) $flag)),
                (array) ($case['expected_quality_flags'] ?? [])
            )));
            sort($expectedFlags);

            $actualFlags = array_values(array_unique(array_map(
                static fn ($flag): string => strtoupper(trim((string) $flag)),
                (array) data_get($payload, 'quality.flags', [])
            )));
            sort($actualFlags);

            $this->assertSame(
                $expectedFlags,
                $actualFlags,
                'golden case quality flags mismatch: ' . $caseId
            );

            $expectedPrimaryProfile = trim((string) ($case['expected_primary_profile'] ?? ''));
            $actualPrimaryProfile = trim((string) data_get($payload, 'report.primary_profile', ''));
            $this->assertSame(
                $expectedPrimaryProfile,
                $actualPrimaryProfile,
                'golden case primary profile mismatch: ' . $caseId
            );

            $actualTags = array_values(array_filter(
                array_map('strval', (array) data_get($payload, 'report_tags', [])),
                static fn (string $tag): bool => $tag !== ''
            ));
            foreach ((array) ($case['expected_report_tags'] ?? []) as $expectedTag) {
                $tag = trim((string) $expectedTag);
                if ($tag === '') {
                    continue;
                }
                $this->assertContains($tag, $actualTags, 'golden case missing expected report tag: ' . $caseId . ' -> ' . $tag);
            }

            $expectedDimLevels = is_array($case['expected_dim_levels'] ?? null) ? $case['expected_dim_levels'] : [];
            foreach (['SA', 'ER', 'EM', 'RM'] as $dimension) {
                $expectedLevel = strtolower(trim((string) ($expectedDimLevels[$dimension] ?? '')));
                if ($expectedLevel === '') {
                    continue;
                }

                $this->assertSame(
                    $expectedLevel,
                    strtolower((string) data_get($payload, 'scores.' . $dimension . '.level', '')),
                    'golden case dimension level mismatch: ' . $caseId . ' -> ' . $dimension
                );
            }

            $expectedGlobalLevel = strtolower(trim((string) ($case['expected_global_level'] ?? '')));
            if ($expectedGlobalLevel !== '') {
                $this->assertSame(
                    $expectedGlobalLevel,
                    strtolower((string) data_get($payload, 'scores.global.level', '')),
                    'golden case global level mismatch: ' . $caseId
                );
            }

            $attempt = new Attempt([
                'scale_code' => 'EQ_60',
                'locale' => (string) ($case['locale'] ?? 'zh-CN'),
                'region' => (string) ($case['country'] ?? 'CN_MAINLAND'),
                'dir_version' => 'v1',
            ]);
            $result = new Result([
                'scale_code' => 'EQ_60',
                'result_json' => [
                    'scale_code' => 'EQ_60',
                    'normed_json' => $payload,
                    'breakdown_json' => ['score_result' => $payload],
                    'axis_scores_json' => ['score_result' => $payload],
                ],
            ]);

            $freeReport = $composer->composeVariant(
                $attempt,
                $result,
                ReportAccess::VARIANT_FREE,
                ['modules_allowed' => [ReportAccess::MODULE_EQ_CORE]]
            );
            $this->assertTrue((bool) ($freeReport['ok'] ?? false), 'golden case free report compose failed: ' . $caseId);

            $freeKeys = array_values(array_map(
                static fn (array $section): string => (string) ($section['key'] ?? ''),
                array_filter((array) data_get($freeReport, 'report.sections', []), 'is_array')
            ));

            $this->assertSame(
                array_values(array_map('strval', (array) ($case['expected_free_sections'] ?? []))),
                $freeKeys,
                'golden case free sections mismatch: ' . $caseId
            );

            $fullReport = $composer->composeVariant(
                $attempt,
                $result,
                ReportAccess::VARIANT_FULL,
                [
                    'modules_allowed' => [
                        ReportAccess::MODULE_EQ_CORE,
                        ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
                        ReportAccess::MODULE_EQ_GROWTH_PLAN,
                    ],
                ]
            );
            $this->assertTrue((bool) ($fullReport['ok'] ?? false), 'golden case full report compose failed: ' . $caseId);

            $fullKeys = array_values(array_map(
                static fn (array $section): string => (string) ($section['key'] ?? ''),
                array_filter((array) data_get($fullReport, 'report.sections', []), 'is_array')
            ));

            $this->assertSame(
                array_values(array_map('strval', (array) ($case['expected_full_sections'] ?? []))),
                $fullKeys,
                'golden case full sections mismatch: ' . $caseId
            );
        }
    }

    /**
     * @param array<string,mixed> $case
     * @return array<int,string>
     */
    private function resolveAnswersMap(array $case): array
    {
        $answersByQid = is_array($case['answers_by_qid'] ?? null) ? $case['answers_by_qid'] : [];
        if ($answersByQid !== []) {
            $normalized = [];
            foreach ($answersByQid as $qidRaw => $codeRaw) {
                $qid = (int) $qidRaw;
                $code = strtoupper(trim((string) $codeRaw));
                if ($qid < 1 || $qid > 60 || !in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
                    continue;
                }
                $normalized[$qid] = $code;
            }

            ksort($normalized, SORT_NUMERIC);
            if (count($normalized) === 60) {
                return $normalized;
            }
        }

        $answerString = strtoupper(trim((string) ($case['answers'] ?? '')));
        $answers = [];
        for ($qid = 1; $qid <= 60; $qid++) {
            $answers[$qid] = substr($answerString, $qid - 1, 1);
        }

        return $answers;
    }
}
