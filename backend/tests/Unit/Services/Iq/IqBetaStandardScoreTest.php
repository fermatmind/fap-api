<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Iq;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Drivers\IqTestDriver;
use App\Services\Assessment\IqBetaStandardScore;
use App\Services\Iq\IqResultPayloadRedactor;
use App\Services\Report\IqReportBuilder;
use App\Services\Report\ReportAccess;
use Tests\TestCase;

final class IqBetaStandardScoreTest extends TestCase
{
    public function test_random_baseline_beta_standard_score_mapping_is_stable(): void
    {
        $calculator = new IqBetaStandardScore;

        $expected = [
            0 => 62,
            3 => 85,
            5 => 99,
            6 => 107,
            7 => 114,
            8 => 121,
            9 => 129,
            10 => 136,
            11 => 144,
            12 => 145,
            30 => 145,
        ];

        foreach ($expected as $rawScore => $betaStandardScore) {
            $payload = $calculator->fromRawScore($rawScore);

            $this->assertSame($betaStandardScore, $payload['beta_standard_score'], 'raw '.$rawScore);
            $this->assertSame(IqBetaStandardScore::STATUS_SIMULATION_CALIBRATED_BETA, $payload['beta_standard_score_status']);
            $this->assertSame(IqBetaStandardScore::SOURCE, $payload['beta_standard_score_source']);
            $this->assertSame(IqBetaStandardScore::RANDOM_BASELINE_MEAN, $payload['random_baseline_mean']);
            $this->assertSame(IqBetaStandardScore::RANDOM_BASELINE_SD, $payload['random_baseline_sd']);
            $this->assertFalse($payload['production_normed']);
            $this->assertFalse($payload['claim_eligible']);
            $this->assertFalse($payload['population_percentile_eligible']);
            $this->assertNull($payload['percentile']);
            $this->assertSame(IqBetaStandardScore::SOURCE_KIND, $payload['source_kind']);
            $this->assertSame(IqBetaStandardScore::SOURCE_REF, $payload['source_ref']);
        }
    }

    public function test_invalid_raw_score_does_not_emit_beta_standard_score(): void
    {
        $calculator = new IqBetaStandardScore;

        foreach ([-1, 31] as $rawScore) {
            $payload = $calculator->fromRawScore($rawScore);

            $this->assertNull($payload['beta_standard_score']);
            $this->assertSame(IqBetaStandardScore::STATUS_INVALID_RAW_SCORE, $payload['beta_standard_score_status']);
            $this->assertNull($payload['random_baseline_z']);
            $this->assertNull($payload['above_random_baseline']);
            $this->assertFalse($payload['production_normed']);
            $this->assertFalse($payload['claim_eligible']);
            $this->assertFalse($payload['population_percentile_eligible']);
            $this->assertNull($payload['percentile']);
        }
    }

    public function test_owner_original_driver_adds_beta_fields_without_changing_scoring_or_iq_claims(): void
    {
        $driver = new IqTestDriver;
        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'B'],
            ],
            $this->ownerOriginalSpec(),
            [
                'duration_ms' => 45000,
                'pack_id' => 'default',
                'content_package_version' => 'iq_owner_original_30_v1',
                'scoring_spec_version' => 'iq_owner_original_30_runtime_scoring_v1',
            ]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertSame(2.0, $result->finalScore);
        $this->assertSame(77, data_get($result->normedJson, 'beta_standard_score'));
        $this->assertSame(IqBetaStandardScore::STATUS_SIMULATION_CALIBRATED_BETA, data_get($result->normedJson, 'beta_standard_score_status'));
        $this->assertSame(IqBetaStandardScore::SOURCE, data_get($result->normedJson, 'beta_standard_score_source'));
        $this->assertSame(IqBetaStandardScore::RANDOM_BASELINE_MEAN, data_get($result->normedJson, 'random_baseline_mean'));
        $this->assertSame(IqBetaStandardScore::RANDOM_BASELINE_SD, data_get($result->normedJson, 'random_baseline_sd'));
        $this->assertFalse((bool) data_get($result->normedJson, 'above_random_baseline'));
        $this->assertFalse((bool) data_get($result->normedJson, 'production_normed'));
        $this->assertFalse((bool) data_get($result->normedJson, 'claim_eligible'));
        $this->assertFalse((bool) data_get($result->normedJson, 'population_percentile_eligible'));
        $this->assertNull(data_get($result->normedJson, 'percentile'));
        $this->assertNull(data_get($result->normedJson, 'iq_estimate'));
        $this->assertNull(data_get($result->normedJson, 'norms.iq_estimate'));
        $this->assertSame('raw_score_only', data_get($result->normedJson, 'score_claim_level'));
        $this->assertFalse((bool) data_get($result->normedJson, 'norms.claim_policy.claim_eligible'));
        $redacted = IqResultPayloadRedactor::redactAnswerKeys($result->toArray());
        $this->assertStringNotContainsString('correct_answer', json_encode($redacted, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('answer_key', json_encode($redacted, JSON_THROW_ON_ERROR));
    }

    public function test_non_owner_iq_driver_does_not_emit_owner_beta_fields(): void
    {
        $driver = new IqTestDriver;
        $spec = $this->ownerOriginalSpec();
        $spec['item_bank']['bank_id'] = 'IQ_SHOWCASE_3_BETA';

        $result = $driver->score(
            [
                ['question_id' => 'IQ001', 'code' => 'A'],
                ['question_id' => 'IQ002', 'code' => 'C'],
                ['question_id' => 'IQ003', 'code' => 'B'],
            ],
            $spec,
            ['duration_ms' => 45000]
        );

        $this->assertSame(2.0, $result->rawScore);
        $this->assertArrayNotHasKey('beta_standard_score', $result->normedJson);
        $this->assertArrayNotHasKey('beta_standard_score_status', $result->normedJson);
        $this->assertArrayNotHasKey('random_baseline_mean', $result->normedJson);
    }

    public function test_report_builder_passes_beta_fields_without_exposing_population_or_iq_claims(): void
    {
        $builder = app(IqReportBuilder::class);
        $attempt = new Attempt([
            'id' => 'attempt-iq-beta-standard-score',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'locale' => 'zh-CN',
        ]);
        $result = new Result([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'result_json' => [
                'normed_json' => array_merge($this->scoredPayload(), app(IqBetaStandardScore::class)->fromRawScore(9)),
            ],
        ]);

        $payload = $builder->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
        ]);

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame(9.0, data_get($payload, 'report.summary.raw_score'));
        $this->assertSame(129, data_get($payload, 'report.summary.beta_standard_score'));
        $this->assertSame(129, data_get($payload, 'report.scoring.beta_standard_score'));
        $this->assertSame(IqBetaStandardScore::STATUS_SIMULATION_CALIBRATED_BETA, data_get($payload, 'report.summary.beta_standard_score_status'));
        $this->assertSame(IqBetaStandardScore::SOURCE, data_get($payload, 'report.summary.beta_standard_score_source'));
        $this->assertSame(IqBetaStandardScore::SOURCE_KIND, data_get($payload, 'report.summary.source_kind'));
        $this->assertSame(IqBetaStandardScore::SOURCE_REF, data_get($payload, 'report.summary.source_ref'));
        $this->assertFalse((bool) data_get($payload, 'report.summary.production_normed'));
        $this->assertFalse((bool) data_get($payload, 'report.summary.claim_eligible'));
        $this->assertFalse((bool) data_get($payload, 'report.summary.population_percentile_eligible'));
        $this->assertNull(data_get($payload, 'report.summary.iq_estimate'));
        $this->assertNull(data_get($payload, 'report.summary.percentile'));
        $this->assertNull(data_get($payload, 'report.summary.confidence_interval'));
        $this->assertSame('raw_score_only', data_get($payload, 'report.summary.score_claim_level'));
        $this->assertStringNotContainsString('correct_answer', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('answer_key', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function ownerOriginalSpec(): array
    {
        return [
            'version' => 'iq_owner_original_30_runtime_scoring_v1',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scoring_mode' => 'scored',
            'answer_key_version' => 'iq_owner_original_30_answer_key_v1',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'item_bank' => [
                'bank_id' => 'IQ_OWNER_ORIGINAL_30',
                'item_count' => 3,
            ],
            'quality_rules' => [
                'speeding_seconds_lt' => 30,
                'straightlining_run_len_gte' => 8,
            ],
            'items' => [
                $this->itemDefinition('FM-IQ-VSPR-MX-L2-0001', 'IQ001', 'VSPR', 'A'),
                $this->itemDefinition('FM-IQ-VSI-HS-L2-0002', 'IQ002', 'VSI', 'C'),
                $this->itemDefinition('FM-IQ-NPR-NS-L3-0003', 'IQ003', 'NPR', 'D'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function scoredPayload(): array
    {
        return [
            'status' => 'scored',
            'scoring_mode' => 'scored',
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'norm_table_version' => 'unavailable',
            'scoring_engine_version' => 'iq_scoring_v2',
            'raw_score' => 9.0,
            'score_claim_level' => 'raw_score_only',
            'claim_policy' => [
                'claim_eligible' => false,
                'score_claim_level' => 'raw_score_only',
                'claim_warnings' => ['no_norm_table'],
                'iq_estimate_allowed' => false,
                'source' => 'iq_norm_authority',
            ],
            'claim_warnings' => ['no_norm_table'],
            'norms' => [
                'status' => 'unavailable_without_norm_table',
                'iq_estimate' => null,
                'percentile' => null,
                'confidence_interval' => null,
                'norm_table_version' => null,
                'score_claim_level' => 'raw_score_only',
                'claim_warnings' => ['no_norm_table'],
                'claim_policy' => [
                    'claim_eligible' => false,
                    'score_claim_level' => 'raw_score_only',
                    'claim_warnings' => ['no_norm_table'],
                    'iq_estimate_allowed' => false,
                    'source' => 'iq_norm_authority',
                ],
            ],
            'quality' => ['level' => 'A', 'flags' => []],
            'result_stability' => ['status' => 'stable', 'reason' => 'quality_clear'],
            'dimension_scores' => [
                'VSI' => ['raw_score' => 4.0, 'percent_correct' => 40.0, 'item_count' => 10, 'answered_count' => 10, 'correct_count' => 4],
                'VSPR' => ['raw_score' => 5.0, 'percent_correct' => 50.0, 'item_count' => 10, 'answered_count' => 10, 'correct_count' => 5],
                'NPR' => ['raw_score' => 0.0, 'percent_correct' => 0.0, 'item_count' => 10, 'answered_count' => 10, 'correct_count' => 0],
            ],
        ];
    }

    private function itemDefinition(string $itemId, string $questionId, string $dimension, string $correctAnswer): array
    {
        return [
            'item_id' => $itemId,
            'question_id' => $questionId,
            'dimension' => $dimension,
            'item_family' => 'fixture_family',
            'difficulty_level' => 'L2',
            'correct_answer' => $correctAnswer,
            'solution_rule' => 'fixture solution rule',
            'distractor_logic' => 'fixture distractor logic',
            'raw_points' => 1,
            'assets' => [
                'stem' => 'fixtures/iq/'.$questionId.'/stem.svg',
                'options' => [
                    'A' => 'fixtures/iq/'.$questionId.'/A.svg',
                    'B' => 'fixtures/iq/'.$questionId.'/B.svg',
                    'C' => 'fixtures/iq/'.$questionId.'/C.svg',
                    'D' => 'fixtures/iq/'.$questionId.'/D.svg',
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
