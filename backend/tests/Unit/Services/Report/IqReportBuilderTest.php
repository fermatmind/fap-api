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
                        'iq_estimate' => null,
                        'percentile' => null,
                        'confidence_interval' => null,
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
        $this->assertSame('stable', data_get($payload, 'report.stability.status'));
        $this->assertSame('A', data_get($payload, 'report.quality.level'));
        $this->assertSame(75.0, data_get($payload, 'report.dimensions.visual_spatial_insight.percent_correct'));
        $this->assertSame(4.0, data_get($payload, 'report.dimensions.visual_spatial_pattern_reasoning.raw_score'));
        $this->assertSame(2.0, data_get($payload, 'report.dimensions.numerical_pattern_reasoning.raw_score'));
        $this->assertSame('contract_defined_not_implemented', data_get($payload, 'report.iq_pro.pdf_payload.status'));
        $this->assertSame('full', data_get($payload, 'report.access.report_access_level'));
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
}
