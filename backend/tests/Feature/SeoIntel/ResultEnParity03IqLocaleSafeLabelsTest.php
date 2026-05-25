<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\IqReportBuilder;
use App\Services\Report\ReportAccess;
use Tests\TestCase;

final class ResultEnParity03IqLocaleSafeLabelsTest extends TestCase
{
    public function test_iq_english_report_uses_backend_label_catalog_without_chinese_labels(): void
    {
        $payload = app(IqReportBuilder::class)->composeVariant(
            $this->attempt('en-US'),
            $this->scoredResult(),
            ReportAccess::VARIANT_FULL,
            [
                'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
                'locale' => 'en-US',
            ]
        );

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $report = (array) ($payload['report'] ?? []);

        $this->assertSame('en', $report['locale'] ?? null);
        $this->assertSame('iq.locale_labels.v1', data_get($report, 'label_catalog.schema_version'));
        $this->assertSame('missing_label_returns_null_no_zh_fallback', data_get($report, 'label_catalog.fallback_policy'));
        $this->assertSame('Visual-spatial insight', data_get($report, 'dimensions.visual_spatial_insight.dimension_name'));
        $this->assertSame('Visual-spatial pattern reasoning', data_get($report, 'dimensions.visual_spatial_pattern_reasoning.dimension_name'));
        $this->assertSame('Numeric pattern reasoning', data_get($report, 'dimensions.numerical_pattern_reasoning.dimension_name'));
        $this->assertSame('IQ report PDF', data_get($report, 'iq_pro.pdf_payload.label'));
        $this->assertSame('IQ result certificate', data_get($report, 'iq_pro.certificate_payload.label'));

        $serialized = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $serialized);
    }

    public function test_iq_default_chinese_labels_remain_available(): void
    {
        $payload = app(IqReportBuilder::class)->composeVariant(
            $this->attempt('zh-CN'),
            $this->scoredResult(),
            ReportAccess::VARIANT_FULL,
            [
                'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
            ]
        );

        $report = (array) ($payload['report'] ?? []);

        $this->assertSame('zh-CN', $report['locale'] ?? null);
        $this->assertSame('backend_catalog_authority', data_get($report, 'label_catalog.fallback_policy'));
        $this->assertSame('视觉空间洞察', data_get($report, 'dimensions.visual_spatial_insight.dimension_name'));
        $this->assertSame('视觉空间模式推理', data_get($report, 'dimensions.visual_spatial_pattern_reasoning.dimension_name'));
        $this->assertSame('数字规律推理', data_get($report, 'dimensions.numerical_pattern_reasoning.dimension_name'));
    }

    public function test_iq_label_catalog_keeps_online_estimate_claim_boundary(): void
    {
        $path = base_path('content_packs/IQ_INTELLIGENCE_QUOTIENT/locale_labels.v1.json');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('online_estimate_confidence_bound_not_clinical_iq_diagnosis', data_get($decoded, 'claim_boundary.measurement'));
        $this->assertSame('missing_label_returns_null_no_zh_fallback', data_get($decoded, 'fallback_policy.en'));

        $visibleEnglish = implode(' ', [
            data_get($decoded, 'iq_pro.pdf_payload.description.en'),
            data_get($decoded, 'iq_pro.certificate_payload.description.en'),
        ]);

        foreach ([
            'clinical diagnosis',
            'definitive intelligence measurement',
            'certified IQ',
            'globally most accurate',
            'treatment',
        ] as $claim) {
            $this->assertStringNotContainsString(strtolower($claim), strtolower($visibleEnglish), $claim);
        }

        $this->assertStringContainsString('Online IQ estimate', $visibleEnglish);
    }

    public function test_generated_iq_inventory_json_parses(): void
    {
        $path = base_path('docs/seo/generated/result-en-parity-03-iq-locale-safe-labels.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RESULT-EN-PARITY-03', $decoded['pr_id'] ?? null);
        $this->assertSame('iq', $decoded['family'] ?? null);
        $this->assertContains('iq.dimensions.visual_spatial_insight.en', $decoded['fixed_keys'] ?? []);
        $this->assertContains('iq_pro.pdf_payload.en', $decoded['fixed_keys'] ?? []);
        $this->assertSame('fail_closed_no_zh_label_fallback', $decoded['english_runtime_policy'] ?? null);
    }

    private function attempt(string $locale): Attempt
    {
        return new Attempt([
            'id' => 'attempt-iq-locale-labels',
            'scale_code' => 'IQ_RAVEN',
            'locale' => $locale,
        ]);
    }

    private function scoredResult(): Result
    {
        return new Result([
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
    }
}
