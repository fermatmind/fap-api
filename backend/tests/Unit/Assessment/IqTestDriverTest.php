<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\IqTestDriver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_owner_original_runtime_binds_claim_eligible_norm_authority(): void
    {
        $this->migrateIqNormAuthority();
        $this->insertIqNormAuthority([
            'norm_table_version' => 'iq_owner30_norm_fixture_v1',
            'mean' => 2.0,
            'standard_deviation' => 1.0,
            'min_raw_score' => 0.0,
            'max_raw_score' => 3.0,
        ]);

        $driver = new IqTestDriver;
        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'D'],
            ],
            $this->ownerOriginalScoringSpec(),
            [
                'org_id' => 0,
                'locale' => 'zh-CN',
                'population_key' => 'general_adult_online',
                'duration_ms' => 45000,
                'pack_id' => 'default',
                'content_package_version' => 'iq_owner_original_30_v1',
                'scoring_spec_version' => 'iq_owner_original_30_runtime_scoring_v1',
            ]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertSame('iq_estimate', data_get($result->normedJson, 'score_claim_level'));
        $this->assertSame('iq_owner30_norm_fixture_v1', data_get($result->normedJson, 'norm_table_version'));
        $this->assertSame('production_normed', data_get($result->normedJson, 'norms.status'));
        $this->assertSame('iq_estimate', data_get($result->normedJson, 'norms.score_claim_level'));
        $this->assertTrue((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $this->assertSame('iq_owner30_norm_fixture_v1', data_get($result->normedJson, 'norms.norm_table_version'));
        $this->assertSame(100.0, data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertSame(50.0, data_get($result->normedJson, 'norms.percentile'));
        $this->assertSame([95.5, 104.5], data_get($result->normedJson, 'norms.confidence_interval'));
        $this->assertStringNotContainsString('correct_answer', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_owner_original_runtime_binds_active_norm_when_bank_id_is_top_level(): void
    {
        $this->migrateIqNormAuthority();
        $this->insertIqNormAuthority([
            'norm_table_version' => 'iq_owner30_norm_fixture_v1',
            'mean' => 2.0,
            'standard_deviation' => 1.0,
            'min_raw_score' => 0.0,
            'max_raw_score' => 3.0,
        ]);

        $spec = $this->ownerOriginalScoringSpec();
        $spec['bank_id'] = 'IQ_OWNER_ORIGINAL_30';
        unset($spec['item_bank']['bank_id']);

        $driver = new IqTestDriver;
        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'D'],
            ],
            $spec,
            [
                'org_id' => 0,
                'locale' => 'zh-CN',
                'population_key' => 'general_adult_online',
                'duration_ms' => 45000,
                'pack_id' => 'default',
                'content_package_version' => 'iq_owner_original_30_v1',
                'scoring_spec_version' => 'iq_owner_original_30_runtime_scoring_v1',
            ]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertSame('IQ_OWNER_ORIGINAL_30', data_get($result->normedJson, 'bank_id'));
        $this->assertSame('iq_estimate', data_get($result->normedJson, 'score_claim_level'));
        $this->assertSame('iq_owner30_norm_fixture_v1', data_get($result->normedJson, 'norm_table_version'));
        $this->assertTrue((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $this->assertSame(100.0, data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertStringNotContainsString('correct_answer', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_owner_original_runtime_without_claim_eligible_authority_stays_raw_score_only(): void
    {
        $this->migrateIqNormAuthority();
        $this->insertIqNormAuthority([
            'norm_table_version' => 'iq_owner30_draft_fixture_v1',
            'status' => 'draft',
            'license_verified' => false,
            'locked' => false,
        ]);

        $driver = new IqTestDriver;
        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'D'],
            ],
            $this->ownerOriginalScoringSpec(),
            [
                'org_id' => 0,
                'locale' => 'zh-CN',
                'population_key' => 'general_adult_online',
                'duration_ms' => 45000,
                'pack_id' => 'default',
                'content_package_version' => 'iq_owner_original_30_v1',
                'scoring_spec_version' => 'iq_owner_original_30_runtime_scoring_v1',
            ]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertSame('raw_score_only', data_get($result->normedJson, 'score_claim_level'));
        $this->assertSame('unavailable', data_get($result->normedJson, 'norm_table_version'));
        $this->assertSame('unavailable_without_norm_table', data_get($result->normedJson, 'norms.status'));
        $this->assertNull(data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertFalse((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $this->assertContains('no_norm_table', data_get($result->normedJson, 'norms.claim_warnings'));
        $this->assertStringNotContainsString('correct_answer', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
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

    private function ownerOriginalScoringSpec(): array
    {
        $spec = $this->completeScoringSpec();
        $spec['item_bank']['bank_id'] = 'IQ_OWNER_ORIGINAL_30';
        $spec['answer_key_version'] = 'iq_owner_original_30_answer_key_v1';
        $spec['scoring_engine_version'] = 'iq_scoring_v2';

        return $spec;
    }

    private function migrateIqNormAuthority(): void
    {
        Schema::dropIfExists('iq_norm_authorities');

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
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
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
