<?php

declare(strict_types=1);

namespace Tests\Unit\Eq;

use App\Services\Assessment\Scorers\EqSjt16Scorer;
use App\Services\Eq\EqSjtValidationTelemetryContract;
use App\Services\Report\EqIntegratedReportComposer;
use Tests\TestCase;

final class EqSjtValidationTelemetryContractTest extends TestCase
{
    public function test_sjt_scored_telemetry_is_safe_and_does_not_include_answers(): void
    {
        $score = $this->scoreGoldenCase('boundary_gap');
        $event = (new EqSjtValidationTelemetryContract())->scoredEvent($score, [
            'attempt_id' => 'attempt-eq-sjt-telemetry',
            'anon_id' => 'anon-eq-sjt-telemetry',
            'locale' => 'en',
            'region' => 'GLOBAL',
        ]);

        $this->assertSame('eq_sjt16_scored', $event['event_code']);
        $this->assertSame('EQ_SJT_16', $event['meta']['scale_code']);
        $this->assertSame('scenario_based_emotional_judgment', $event['meta']['measurement_type']);
        $this->assertSame('likely_response', $event['meta']['answer_mode']);
        $this->assertSame('draft_not_yet_validated', $event['meta']['validation_status']);
        $this->assertFalse($event['meta']['stable_validation_claim_allowed']);
        $this->assertSame('BND', $event['meta']['lowest_strategy']);
        $this->assertSame([], $event['meta']['quality_flags']);
        $this->assertTrue($event['meta']['claim_boundary']['not_msceit_equivalent']);

        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('selected_options', $encoded);
        $this->assertStringNotContainsString('answers', $encoded);
        $this->assertStringNotContainsString('response_options', $encoded);
    }

    public function test_low_quality_sjt_path_is_telemetry_visible_but_not_validation_claimed(): void
    {
        $score = $this->scoreGoldenCase('low_effectiveness');
        $event = (new EqSjtValidationTelemetryContract())->scoredEvent($score);

        $this->assertSame('B', $event['meta']['quality_level']);
        $this->assertSame(['low_effectiveness_pattern'], $event['meta']['quality_flags']);
        $this->assertSame('low_effectiveness', $event['meta']['band']);
        $this->assertFalse($event['meta']['stable_validation_claim_allowed']);
    }

    public function test_integrated_report_telemetry_and_qa_gate_keep_public_release_closed(): void
    {
        $score = $this->scoreGoldenCase('boundary_gap');
        $report = (new EqIntegratedReportComposer())->compose($this->eq60Report('en'), $score, ['locale' => 'en']);
        $contract = new EqSjtValidationTelemetryContract();
        $event = $contract->integratedReportComposedEvent($report, [
            'attempt_id' => 'attempt-integrated-eq',
            'locale' => 'en',
        ]);
        $gate = $contract->qaGate($score, $report);

        $this->assertSame('eq_integrated_report_composed', $event['event_code']);
        $this->assertSame('integrated', $event['meta']['eq_report_mode']);
        $this->assertSame('draft_not_yet_validated', $event['meta']['validation_status']);
        $this->assertFalse($event['meta']['public_runtime_enabled']);
        $this->assertFalse($event['meta']['frontend_integrated_report_visible']);
        $this->assertSame('boundary_under_pressure', $event['meta']['pressure_pattern_id']);

        $this->assertSame('pass_for_internal_qa_only', $gate['status']);
        $this->assertFalse($gate['public_release_allowed']);
        $this->assertFalse($gate['stable_validation_claim_allowed']);
        $this->assertSame([], $gate['issues']);
        $this->assertContains('expert_rubric_calibration', $gate['required_next_evidence']);
        $this->assertContains('locale_bias_review', $gate['required_next_evidence']);
    }

    public function test_zh_cn_and_en_integrated_contracts_share_boundaries_without_paid_runtime(): void
    {
        $score = $this->scoreGoldenCase('balanced_effective');
        foreach (['zh-CN', 'en'] as $locale) {
            $report = (new EqIntegratedReportComposer())->compose($this->eq60Report($locale), $score, ['locale' => $locale]);

            $this->assertSame($locale, $report['locale']);
            $this->assertTrue($report['access']['all_results_free']);
            $this->assertFalse($report['access']['locked']);
            $this->assertFalse($report['access']['blur']);
            $this->assertFalse($report['access']['paywall']);
            $this->assertFalse($report['visibility']['public_runtime_enabled']);
            $this->assertFalse($report['visibility']['frontend_integrated_report_visible']);
            $this->assertSame('draft_not_yet_validated', $report['methodology']['validation_status']);

            $encoded = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertIsString($encoded);
            foreach (['SKU_EQ_60_FULL_299', 'EQ_60_FULL', 'premium', 'unlock', 'MSCEIT-like', 'certified emotional intelligence'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $encoded);
            }
        }
    }

    public function test_claim_boundary_gate_blocks_overclaiming_payloads(): void
    {
        $contract = new EqSjtValidationTelemetryContract();
        $score = $this->scoreGoldenCase('balanced_effective');
        $report = (new EqIntegratedReportComposer())->compose($this->eq60Report('en'), $score, ['locale' => 'en']);
        $report['claim_copy'] = 'This is a MSCEIT-like certified emotional intelligence clinical assessment.';
        $report['visibility']['frontend_integrated_report_visible'] = true;

        $gate = $contract->qaGate($score, $report);

        $this->assertSame('blocked', $gate['status']);
        $this->assertContains('integrated_report_frontend_visible_before_release', $gate['issues']);
        $this->assertContains('integrated_report_forbidden_claim_msceit_like', $gate['issues']);
        $this->assertContains('integrated_report_forbidden_claim_certified_emotional_intelligence', $gate['issues']);
        $this->assertContains('integrated_report_forbidden_claim_clinical_assessment', $gate['issues']);
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreGoldenCase(string $caseId): array
    {
        $cases = $this->jsonFile(base_path('content_packs/EQ_SJT_16/v1/raw/golden_cases.json'));
        $case = collect((array) ($cases['cases'] ?? []))->firstWhere('case_id', $caseId);
        $this->assertIsArray($case, $caseId);

        return (new EqSjt16Scorer())->score((array) ($case['answers'] ?? []), $this->items(), [
            'scoring_spec_version' => 'eq_sjt_16_partial_credit_v1',
            'content_version' => 'EQ_SJT_16/v1',
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function items(): array
    {
        $payload = $this->jsonFile(base_path('content_packs/EQ_SJT_16/v1/raw/items.json'));
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            $this->assertIsArray($item);
            $itemId = (string) ($item['item_id'] ?? '');
            $this->assertNotSame('', $itemId);
            $items[$itemId] = $item;
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function eq60Report(string $locale): array
    {
        return [
            'locale' => $locale,
            'eq_report_mode' => 'self_report',
            'measurement_type' => 'self_report_trait_mixed_ei',
            'quality' => ['level' => 'A', 'confidence_label' => 'high'],
            'scores' => [
                'global' => ['standard_score' => 104, 'percentile' => 61, 'band' => 'stable'],
                'dimensions' => [
                    'SA' => ['standard_score' => 106, 'percentile' => 65, 'band' => 'stable'],
                    'ER' => ['standard_score' => 92, 'percentile' => 30, 'band' => 'developing'],
                    'EM' => ['standard_score' => 110, 'percentile' => 70, 'band' => 'proficient'],
                    'RM' => ['standard_score' => 101, 'percentile' => 52, 'band' => 'stable'],
                ],
            ],
            'interpretation' => ['core_formulation_id' => 'high_empathy_low_recovery'],
            'methodology' => ['content_version' => 'EQ_60/v1'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path);

        return $decoded;
    }
}
