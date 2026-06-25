<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\IqReportBuilder;
use App\Services\Report\ReportAccess;
use Tests\TestCase;

final class IqReportBuilderTest extends TestCase
{
    public function test_builder_emits_three_dimension_report_payload_for_scored_runtime(): void
    {
        $builder = app(IqReportBuilder::class);
        $attempt = new Attempt([
            'id' => 'attempt-iq-report-scored',
            'scale_code' => 'IQ_RAVEN',
        ]);
        $result = new Result([
            'scale_code' => 'IQ_RAVEN',
            'result_json' => [
                'normed_json' => [
                    'status' => 'scored',
                    'scoring_mode' => 'scored',
                    'bank_id' => 'IQ_SHOWCASE_12_BETA',
                    'answer_key_version' => 'showcase12.v1',
                    'norm_table_version' => 'unavailable',
                    'scoring_engine_version' => 'iq_scoring_v2',
                    'raw_score' => 18.0,
                    'quality' => [
                        'level' => 'A',
                        'flags' => [],
                    ],
                    'result_stability' => [
                        'status' => 'stable',
                        'reason' => 'quality_clear',
                    ],
                    'norms' => [
                        'status' => 'unavailable_without_norm_table',
                        'iq_estimate' => 132.0,
                        'percentile' => 98.0,
                        'confidence_interval' => [128.0, 136.0],
                    ],
                    'dimension_scores' => [
                        'VSI' => [
                            'dimension_name' => '视觉空间洞察',
                            'item_count' => 4,
                            'answered_count' => 4,
                            'correct_count' => 3,
                            'raw_score' => 3.0,
                            'percent_correct' => 75.0,
                        ],
                        'VSPR' => [
                            'dimension_name' => '视觉空间模式推理',
                            'item_count' => 4,
                            'answered_count' => 4,
                            'correct_count' => 4,
                            'raw_score' => 4.0,
                            'percent_correct' => 100.0,
                        ],
                        'NPR' => [
                            'dimension_name' => '数字规律推理',
                            'item_count' => 4,
                            'answered_count' => 4,
                            'correct_count' => 2,
                            'raw_score' => 2.0,
                            'percent_correct' => 50.0,
                        ],
                    ],
                ],
            ],
        ]);

        $payload = $builder->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
        ]);

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('iq.report.v1', data_get($payload, 'report.schema_version'));
        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', data_get($payload, 'report.scale_code'));
        $this->assertSame('IQ_RAVEN', data_get($payload, 'report.scale_code_legacy'));
        $this->assertSame(18.0, data_get($payload, 'report.summary.raw_score'));
        $this->assertNull(data_get($payload, 'report.summary.iq_estimate'));
        $this->assertNull(data_get($payload, 'report.summary.percentile'));
        $this->assertNull(data_get($payload, 'report.summary.confidence_interval'));
        $this->assertSame('unavailable_without_norm_table', data_get($payload, 'report.summary.norms_status'));
        $this->assertSame('raw_score_only', data_get($payload, 'report.summary.score_claim_level'));
        $this->assertSame('raw_score_only', data_get($payload, 'report.scoring.score_claim_level'));
        $this->assertFalse((bool) data_get($payload, 'report.summary.claim_policy.claim_eligible'));
        $this->assertSame('stable', data_get($payload, 'report.stability.status'));
        $this->assertSame('A', data_get($payload, 'report.quality.level'));
        $this->assertSame(75.0, data_get($payload, 'report.dimensions.visual_spatial_insight.percent_correct'));
        $this->assertSame(4.0, data_get($payload, 'report.dimensions.visual_spatial_pattern_reasoning.raw_score'));
        $this->assertSame(2.0, data_get($payload, 'report.dimensions.numerical_pattern_reasoning.raw_score'));
        $this->assertSame('contract_defined_not_implemented', data_get($payload, 'report.iq_pro.pdf_payload.status'));
        $this->assertSame('full', data_get($payload, 'report.access.report_access_level'));
    }

    public function test_builder_emits_iq_claim_fields_only_when_norm_claim_policy_is_eligible(): void
    {
        $builder = app(IqReportBuilder::class);
        $attempt = new Attempt([
            'id' => 'attempt-iq-report-norm-claim',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        ]);
        $result = new Result([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'result_json' => [
                'normed_json' => $this->scoredPayloadWithNormClaimPolicy(true),
            ],
        ]);

        $payload = $builder->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
        ]);

        $this->assertSame(109.0, data_get($payload, 'report.summary.iq_estimate'));
        $this->assertSame(72.57, data_get($payload, 'report.summary.percentile'));
        $this->assertSame([104.5, 113.5], data_get($payload, 'report.summary.confidence_interval'));
        $this->assertSame('iq_estimate', data_get($payload, 'report.summary.score_claim_level'));
        $this->assertTrue((bool) data_get($payload, 'report.summary.claim_policy.claim_eligible'));

        $lockedResult = new Result([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'result_json' => [
                'normed_json' => $this->scoredPayloadWithNormClaimPolicy(false),
            ],
        ]);
        $lockedPayload = $builder->composeVariant($attempt, $lockedResult, ReportAccess::VARIANT_FULL, [
            'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
        ]);

        $this->assertNull(data_get($lockedPayload, 'report.summary.iq_estimate'));
        $this->assertNull(data_get($lockedPayload, 'report.summary.percentile'));
        $this->assertNull(data_get($lockedPayload, 'report.summary.confidence_interval'));
        $this->assertSame('raw_score_only', data_get($lockedPayload, 'report.summary.score_claim_level'));
        $this->assertSame('raw_score_only', data_get($lockedPayload, 'report.scoring.score_claim_level'));
        $this->assertFalse((bool) data_get($lockedPayload, 'report.summary.claim_policy.claim_eligible'));
    }

    public function test_builder_keeps_blocked_unscored_legacy_demo_report_explicit(): void
    {
        $builder = app(IqReportBuilder::class);
        $attempt = new Attempt([
            'id' => 'attempt-iq-report-blocked',
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
        ]);
        $result = new Result([
            'scale_code' => 'IQ_INTELLIGENCE_QUOTIENT',
            'result_json' => [
                'normed_json' => [
                    'status' => 'blocked_unscored',
                    'reason_code' => 'ANSWER_KEY_MISSING',
                    'scoring_mode' => 'scored',
                    'quality' => [
                        'level' => 'C',
                        'flags' => ['PARTIAL_COMPLETION'],
                    ],
                ],
            ],
        ]);

        $payload = $builder->build($attempt, $result, ReportAccess::VARIANT_FREE, [
            'report_access_level' => ReportAccess::REPORT_ACCESS_FREE,
        ]);

        $this->assertSame('IQ_INTELLIGENCE_QUOTIENT', data_get($payload, 'scale_code'));
        $this->assertNull(data_get($payload, 'summary.raw_score'));
        $this->assertSame('free', data_get($payload, 'access.report_access_level'));
        $this->assertSame('free', data_get($payload, 'access.variant'));
        $this->assertSame([], data_get($payload, 'sections'));
        $this->assertTrue((bool) data_get($payload, '_meta.redacted'));
        $this->assertNull(data_get($payload, 'scoring'));
        $this->assertNull(data_get($payload, 'dimensions'));
        $this->assertNull(data_get($payload, 'quality'));
        $this->assertNull(data_get($payload, 'stability'));
        $this->assertNull(data_get($payload, 'iq_pro'));
    }

    /**
     * @return array<string,mixed>
     */
    private function scoredPayloadWithNormClaimPolicy(bool $claimEligible): array
    {
        return [
            'status' => 'scored',
            'scoring_mode' => 'scored',
            'bank_id' => 'IQ_OWNER_ORIGINAL_30',
            'answer_key_version' => 'iq_owner_original_30_answer_key_v1',
            'norm_table_version' => 'iq_norm_prod_v1',
            'scoring_engine_version' => 'iq_scoring_v2',
            'raw_score' => 18.0,
            'quality' => [
                'level' => 'A',
                'flags' => [],
            ],
            'result_stability' => [
                'status' => 'stable',
                'reason' => 'quality_clear',
            ],
            'norms' => [
                'status' => 'production_normed',
                'iq_estimate' => 109.0,
                'percentile' => 72.57,
                'confidence_interval' => [104.5, 113.5],
                'norm_table_version' => $claimEligible ? 'iq_norm_prod_v1' : null,
                'score_claim_level' => $claimEligible ? 'iq_estimate' : 'raw_score_only',
                'claim_warnings' => $claimEligible ? [] : ['license_verification_required'],
                'claim_policy' => [
                    'claim_eligible' => $claimEligible,
                    'score_claim_level' => $claimEligible ? 'iq_estimate' : 'raw_score_only',
                    'reason_code' => $claimEligible ? null : 'license_verification_required',
                    'claim_warnings' => $claimEligible ? [] : ['license_verification_required'],
                    'iq_estimate_allowed' => $claimEligible,
                    'source' => 'iq_norm_authority',
                ],
            ],
            'dimension_scores' => [
                'VSI' => ['raw_score' => 4.0, 'percent_correct' => 40.0, 'item_count' => 10, 'answered_count' => 10, 'correct_count' => 4],
                'VSPR' => ['raw_score' => 14.0, 'percent_correct' => 100.0, 'item_count' => 14, 'answered_count' => 14, 'correct_count' => 14],
                'NPR' => ['raw_score' => 0.0, 'percent_correct' => 0.0, 'item_count' => 6, 'answered_count' => 6, 'correct_count' => 0],
            ],
        ];
    }
}
