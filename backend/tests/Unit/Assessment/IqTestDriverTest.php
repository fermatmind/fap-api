<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\IqTestDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IqTestDriverTest extends TestCase
{
    public function test_complete_fixture_bank_scores_raw_and_dimension_results(): void
    {
        $driver = new IqTestDriver;

        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'D'],
            ],
            $this->completeScoringSpec(),
            [
                'duration_ms' => 45000,
                'pack_id' => 'default',
                'content_package_version' => 'iq_showcase_3_beta',
                'scoring_spec_version' => 'iq_contract_fixture_v1',
            ]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertSame(2.0, $result->finalScore);
        $this->assertSame('scored', data_get($result->breakdownJson, 'status'));
        $this->assertNull(data_get($result->breakdownJson, 'reason_code'));
        $this->assertSame('stable', data_get($result->breakdownJson, 'result_stability.status'));
        $this->assertSame('unavailable_without_norm_table', data_get($result->normedJson, 'norms.status'));
        $this->assertSame(1, data_get($result->normedJson, 'dimension_scores.VSPR.correct_count'));
        $this->assertSame(1, data_get($result->normedJson, 'dimension_scores.VSI.correct_count'));
        $this->assertSame(0, data_get($result->normedJson, 'dimension_scores.NPR.correct_count'));
        $this->assertSame(100.0, data_get($result->axisScoresJson, 'scores_pct.VSPR'));
        $this->assertSame(100.0, data_get($result->axisScoresJson, 'scores_pct.VSI'));
        $this->assertSame(0.0, data_get($result->axisScoresJson, 'scores_pct.NPR'));
        $this->assertCount(3, data_get($result->normedJson, 'items', []));
        $this->assertArrayNotHasKey('correct_answer', data_get($result->normedJson, 'items.0', []));
        $this->assertStringNotContainsString('correct_answer', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_missing_answer_key_returns_blocked_unscored_state(): void
    {
        $driver = new IqTestDriver;

        $result = $driver->score(
            [
                ['question_id' => 'MATRIX_Q01', 'code' => 'A'],
                ['question_id' => 'ODD_Q01', 'code' => 'B'],
            ],
            [
                'version' => '2026.03',
                'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
                'scoring_mode' => 'scored',
                'answer_key_version' => 'legacy_demo_answer_key_not_available',
                'norm_table_version' => 'unavailable',
                'scoring_engine_version' => 'iq_scoring_v2',
                'item_bank' => [
                    'bank_id' => 'IQ_INTELLIGENCE_QUOTIENT_LEGACY_DEMO_30',
                    'item_count' => 30,
                ],
                'quality_rules' => [
                    'speeding_seconds_lt' => 30,
                    'straightlining_run_len_gte' => 8,
                ],
                'items' => [],
            ],
            [
                'duration_ms' => 20000,
                'pack_id' => 'default',
                'content_package_version' => 'v0.3.0-DEMO',
                'scoring_spec_version' => '2026.03',
            ]
        );

        $this->assertSame(0.0, $result->rawScore);
        $this->assertSame(0.0, $result->finalScore);
        $this->assertSame('blocked_unscored', data_get($result->breakdownJson, 'status'));
        $this->assertSame('ANSWER_KEY_MISSING', data_get($result->breakdownJson, 'reason_code'));
        $this->assertSame('scored', data_get($result->breakdownJson, 'scoring_mode'));
        $this->assertSame('C', data_get($result->breakdownJson, 'quality.level'));
        $this->assertContains('SPEEDING', data_get($result->breakdownJson, 'quality.flags', []));
        $this->assertContains('PARTIAL_COMPLETION', data_get($result->breakdownJson, 'quality.flags', []));
        $this->assertSame('blocked_unscored', data_get($result->normedJson, 'status'));
    }

    public function test_beta30_original_bank_scores_raw_dimensions_quality_and_nullable_norms(): void
    {
        $driver = new IqTestDriver;

        $result = $driver->score(
            $this->beta30Answers(18),
            $this->beta30ScoringSpec(),
            [
                'duration_ms' => 20 * 60 * 1000,
                'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'content_package_version' => 'v0.3.0-demo',
                'scoring_spec_version' => 'iq_beta30_original_v1',
            ]
        );

        $this->assertSame(18.0, $result->rawScore);
        $this->assertSame(18.0, $result->finalScore);
        $this->assertSame('scored', data_get($result->normedJson, 'status'));
        $this->assertSame('IQ_BETA_30_ORIGINAL', data_get($result->normedJson, 'bank_id'));
        $this->assertSame(30, data_get($result->normedJson, 'expected_item_count'));
        $this->assertSame(30, data_get($result->normedJson, 'answer_count'));
        $this->assertSame('A', data_get($result->normedJson, 'quality.level'));
        $this->assertSame([], data_get($result->normedJson, 'quality.flags'));
        $this->assertSame('stable', data_get($result->normedJson, 'result_stability.status'));
        $this->assertSame('quality_clear', data_get($result->normedJson, 'result_stability.reason'));
        $this->assertSame('unavailable_without_norm_table', data_get($result->normedJson, 'norms.status'));
        $this->assertNull(data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertNull(data_get($result->normedJson, 'norms.percentile'));
        $this->assertNull(data_get($result->normedJson, 'norms.confidence_interval'));
        $this->assertSame(14, data_get($result->normedJson, 'dimension_scores.VSPR.item_count'));
        $this->assertSame(14, data_get($result->normedJson, 'dimension_scores.VSPR.correct_count'));
        $this->assertSame(100.0, data_get($result->normedJson, 'dimension_scores.VSPR.percent_correct'));
        $this->assertSame(10, data_get($result->normedJson, 'dimension_scores.VSI.item_count'));
        $this->assertSame(4, data_get($result->normedJson, 'dimension_scores.VSI.correct_count'));
        $this->assertSame(40.0, data_get($result->normedJson, 'dimension_scores.VSI.percent_correct'));
        $this->assertSame(6, data_get($result->normedJson, 'dimension_scores.NPR.item_count'));
        $this->assertSame(0, data_get($result->normedJson, 'dimension_scores.NPR.correct_count'));
        $this->assertSame(0.0, data_get($result->normedJson, 'dimension_scores.NPR.percent_correct'));
        $this->assertStringNotContainsString('correct_answer', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_beta30_original_bank_marks_partial_speeding_and_straightline_as_caution(): void
    {
        $driver = new IqTestDriver;

        $result = $driver->score(
            $this->beta30Answers(12, 'A'),
            $this->beta30ScoringSpec(),
            [
                'duration_ms' => 10 * 1000,
                'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'content_package_version' => 'v0.3.0-demo',
                'scoring_spec_version' => 'iq_beta30_original_v1',
            ]
        );

        $flags = data_get($result->normedJson, 'quality.flags', []);

        $this->assertSame('C', data_get($result->normedJson, 'quality.level'));
        $this->assertContains('SPEEDING', $flags);
        $this->assertContains('STRAIGHTLINING', $flags);
        $this->assertContains('PARTIAL_COMPLETION', $flags);
        $this->assertSame('review_with_caution', data_get($result->normedJson, 'result_stability.status'));
        $this->assertSame('partial_completion', data_get($result->normedJson, 'result_stability.reason'));
        $this->assertSame(12, data_get($result->normedJson, 'answer_count'));
        $this->assertSame(30, data_get($result->normedJson, 'expected_item_count'));
    }

    public function test_beta30_norm_authority_unlocks_iq_claim_fields_only_after_public_claim_gate_passes(): void
    {
        $this->migrateIqNormAuthority();
        $this->insertIqNormAuthority([
            'norm_table_version' => 'iq_norm_prod_v1',
            'status' => 'production_normed',
            'sample_size' => 1200,
            'mean' => 15.0,
            'standard_deviation' => 5.0,
            'license_verified' => true,
            'locked' => true,
        ]);

        $spec = $this->beta30ScoringSpec();
        $spec['norm_table_version'] = 'iq_norm_prod_v1';

        $result = (new IqTestDriver)->score(
            $this->beta30Answers(18),
            $spec,
            [
                'duration_ms' => 20 * 60 * 1000,
                'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'content_package_version' => 'v0.3.0-demo',
                'scoring_spec_version' => 'iq_beta30_original_v1',
                'locale' => 'zh-CN',
            ]
        );

        $this->assertSame('production_normed', data_get($result->normedJson, 'norms.status'));
        $this->assertTrue((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $this->assertSame('iq_norm_prod_v1', data_get($result->normedJson, 'norms.norm_table_version'));
        $this->assertSame(109.0, data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertSame(72.57, data_get($result->normedJson, 'norms.percentile'));
        $this->assertSame([104.5, 113.5], data_get($result->normedJson, 'norms.confidence_interval'));
    }

    public function test_beta30_norm_authority_keeps_iq_claim_fields_locked_when_public_claim_gate_fails(): void
    {
        $this->migrateIqNormAuthority();
        $this->insertIqNormAuthority([
            'norm_table_version' => 'iq_norm_not_claim_ready_v1',
            'status' => 'production_normed',
            'sample_size' => 120,
            'mean' => 15.0,
            'standard_deviation' => 5.0,
            'license_verified' => false,
            'locked' => false,
        ]);

        $spec = $this->beta30ScoringSpec();
        $spec['norm_table_version'] = 'iq_norm_not_claim_ready_v1';

        $result = (new IqTestDriver)->score(
            $this->beta30Answers(18),
            $spec,
            [
                'duration_ms' => 20 * 60 * 1000,
                'pack_id' => 'IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO',
                'content_package_version' => 'v0.3.0-demo',
                'scoring_spec_version' => 'iq_beta30_original_v1',
                'locale' => 'zh-CN',
            ]
        );

        $this->assertSame('unavailable_without_claim_eligible_norm_authority', data_get($result->normedJson, 'norms.status'));
        $this->assertFalse((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $this->assertContains('sample_size_below_public_claim_minimum', data_get($result->normedJson, 'norms.claim_policy.errors', []));
        $this->assertNull(data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertNull(data_get($result->normedJson, 'norms.percentile'));
        $this->assertNull(data_get($result->normedJson, 'norms.confidence_interval'));
    }

    private function completeScoringSpec(): array
    {
        return [
            'version' => 'iq_contract_fixture_v1',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scoring_mode' => 'scored',
            'answer_key_version' => 'showcase_fixture_answer_key_v1',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'item_bank' => [
                'bank_id' => 'IQ_SHOWCASE_3_BETA',
                'item_count' => 3,
            ],
            'quality_rules' => [
                'speeding_seconds_lt' => 30,
                'straightlining_run_len_gte' => 8,
            ],
            'items' => [
                $this->itemDefinition(
                    'FM-IQ-VSPR-MX-L2-0001',
                    'IQ001',
                    'VSPR',
                    'A',
                    'matrix_reasoning',
                    'L2'
                ),
                $this->itemDefinition(
                    'FM-IQ-VSI-HS-L2-0002',
                    'IQ002',
                    'VSI',
                    'C',
                    'hidden_shape',
                    'L2'
                ),
                $this->itemDefinition(
                    'FM-IQ-NPR-NS-L3-0003',
                    'IQ003',
                    'NPR',
                    'B',
                    'number_sequence',
                    'L3'
                ),
            ],
        ];
    }

    private function beta30ScoringSpec(): array
    {
        $itemsPayload = $this->readBeta30Json('items.json');
        $answerKey = $this->readBeta30Json('answer_key.json');
        $items = [];

        foreach (($itemsPayload['items'] ?? []) as $item) {
            $itemId = (string) ($item['item_id'] ?? '');
            $items[] = [
                'item_id' => $itemId,
                'question_id' => (string) ($item['question_id'] ?? ''),
                'dimension' => (string) ($item['dimension'] ?? ''),
                'item_family' => (string) ($item['item_family'] ?? ''),
                'difficulty_level' => (string) ($item['difficulty_level'] ?? ''),
                'correct_answer' => (string) data_get($answerKey, "answers.{$itemId}.correct_answer"),
                'solution_rule' => (string) ($item['solution_rule'] ?? ''),
                'distractor_logic' => (string) ($item['distractor_logic'] ?? ''),
                'raw_points' => 1,
                'assets' => $item['assets'] ?? [],
                'asset_hashes' => $item['asset_hashes'] ?? [],
                'generator_metadata' => $item['generator_metadata'] ?? [],
            ];
        }

        return [
            'version' => 'iq_beta30_original_v1',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scoring_mode' => 'scored',
            'answer_key_version' => 'iq_beta30_original_answer_key_v1',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'item_bank' => [
                'bank_id' => 'IQ_BETA_30_ORIGINAL',
                'item_count' => 30,
            ],
            'quality_rules' => [
                'speeding_seconds_lt' => 30,
                'straightlining_run_len_gte' => 8,
            ],
            'items' => $items,
        ];
    }

    private function beta30Answers(int $correctCount, ?string $forceCode = null): array
    {
        $itemsPayload = $this->readBeta30Json('items.json');
        $answerKey = $this->readBeta30Json('answer_key.json');
        $answers = [];
        $optionCodes = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach (array_values($itemsPayload['items'] ?? []) as $index => $item) {
            if ($forceCode !== null && $index >= $correctCount) {
                break;
            }

            $itemId = (string) ($item['item_id'] ?? '');
            $correct = (string) data_get($answerKey, "answers.{$itemId}.correct_answer");
            $code = $forceCode ?? ($index < $correctCount
                ? $correct
                : $optionCodes[(array_search($correct, $optionCodes, true) + 1) % count($optionCodes)]);

            $answers[] = [
                'question_id' => (string) ($item['question_id'] ?? ''),
                'code' => $code,
            ];
        }

        return $answers;
    }

    private function readBeta30Json(string $file): array
    {
        $path = base_path('../content_packages/default/CN_MAINLAND/zh-CN/IQ_INTELLIGENCE_QUOTIENT-CN-v0.3.0-DEMO/banks/IQ_BETA_30_ORIGINAL/'.$file);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function migrateIqNormAuthority(): void
    {
        $migration = require base_path('database/migrations/2026_05_31_090000_create_iq_norm_authorities_table.php');
        $migration->up();
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertIqNormAuthority(array $overrides = []): void
    {
        DB::table('iq_norm_authorities')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'bank_id' => 'IQ_BETA_30_ORIGINAL',
            'norm_table_version' => 'iq_norm_prod_v1',
            'status' => 'production_normed',
            'population_key' => 'general_adult_online',
            'locale' => 'zh-CN',
            'sample_size' => 1200,
            'mean' => 15.0,
            'standard_deviation' => 5.0,
            'min_raw_score' => 0.0,
            'max_raw_score' => 30.0,
            'source_kind' => 'internal_calibration',
            'source_ref' => 'iq-norm-03-test-fixture',
            'license_verified' => true,
            'locked' => true,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function itemDefinition(
        string $itemId,
        string $questionId,
        string $dimension,
        string $correctAnswer,
        string $itemFamily,
        string $difficultyLevel
    ): array {
        return [
            'item_id' => $itemId,
            'question_id' => $questionId,
            'dimension' => $dimension,
            'item_family' => $itemFamily,
            'difficulty_level' => $difficultyLevel,
            'correct_answer' => $correctAnswer,
            'solution_rule' => 'fixture solution rule',
            'distractor_logic' => 'fixture distractor logic',
            'raw_points' => 1,
            'assets' => [
                'stem' => 'fixtures/iq/'.$questionId.'/stem.svg',
                'options' => [
                    'fixtures/iq/'.$questionId.'/A.svg',
                    'fixtures/iq/'.$questionId.'/B.svg',
                    'fixtures/iq/'.$questionId.'/C.svg',
                    'fixtures/iq/'.$questionId.'/D.svg',
                ],
            ],
            'asset_hashes' => [
                'stem' => 'sha256:'.$questionId.'_stem',
                'options' => [
                    'A' => 'sha256:'.$questionId.'_A',
                    'B' => 'sha256:'.$questionId.'_B',
                    'C' => 'sha256:'.$questionId.'_C',
                    'D' => 'sha256:'.$questionId.'_D',
                ],
            ],
            'generator_metadata' => [
                'generator_version' => 'fixture_v1',
                'theme_version' => 'fixture_theme_v1',
                'seed' => $questionId.'_seed',
                'params_hash' => 'sha256:'.$questionId.'_params',
            ],
        ];
    }
}
