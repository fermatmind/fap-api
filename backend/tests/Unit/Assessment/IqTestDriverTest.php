<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\IqTestDriver;
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
