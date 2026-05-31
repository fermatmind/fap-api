<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Services\Report\EqIntegratedReportComposer;
use PHPUnit\Framework\TestCase;

final class EqIntegratedReportComposerTest extends TestCase
{
    public function test_it_composes_integrated_report_without_merging_self_report_and_sjt_scores(): void
    {
        $report = (new EqIntegratedReportComposer())->compose($this->eq60Report(), $this->sjtScore());

        $this->assertSame('eq.integrated_report.v1', $report['schema_version']);
        $this->assertSame('integrated', $report['eq_report_mode']);
        $this->assertSame('integrated_self_report_and_scenario_judgment', $report['measurement_type']);
        $this->assertTrue($report['access']['all_results_free']);
        $this->assertFalse($report['access']['locked']);
        $this->assertFalse($report['access']['paywall']);

        $this->assertSame('EQ_60', $report['component_reports']['self_report']['scale_code']);
        $this->assertSame('EQ_SJT_16', $report['component_reports']['scenario_judgment']['scale_code']);
        $this->assertSame('high_empathy_low_recovery', $report['component_reports']['self_report']['core_formulation_id']);
        $this->assertSame('scenario_based_emotional_judgment', $report['component_reports']['scenario_judgment']['measurement_type']);

        $this->assertArrayHasKey('self_report', $report['scores']);
        $this->assertArrayHasKey('scenario_judgment', $report['scores']);
        $this->assertSame(70, $report['scores']['self_report']['dimensions']['EM']['percentile']);
        $this->assertSame(25.0, $report['scores']['scenario_judgment']['strategy_scores']['BND']['score_pct']);
        $this->assertArrayNotHasKey('true_eq_score', $report['scores']);

        $this->assertSame('draft_not_yet_validated', $report['methodology']['validation_status']);
        $this->assertFalse($report['visibility']['public_runtime_enabled']);
        $this->assertFalse($report['visibility']['frontend_integrated_report_visible']);
    }

    public function test_it_builds_gap_map_pressure_pattern_scripts_and_action_path(): void
    {
        $report = (new EqIntegratedReportComposer())->compose($this->eq60Report(), $this->sjtScore());
        $gapMap = $report['interpretation']['gap_map'];

        $this->assertCount(6, $gapMap);
        $boundaryGap = $this->findGap($gapMap, 'empathy_boundary_alignment');
        $this->assertSame('EM', $boundaryGap['self_report_dimension']);
        $this->assertSame('BND', $boundaryGap['scenario_strategy']);
        $this->assertSame('boundary_gap', $boundaryGap['gap_type']);
        $this->assertSame('eq.integrated.gap.boundary_gap.EM_BND', $boundaryGap['asset_id']);

        $this->assertSame('boundary_under_pressure', $report['interpretation']['pressure_pattern']['pattern_id']);
        $this->assertSame('BND', $report['interpretation']['pressure_pattern']['lowest_strategy']);
        $this->assertContains('eq.integrated.script.BND.boundary_gap', $report['interpretation']['scenario_script_ids']);
        $this->assertSame(14, $report['interpretation']['integrated_action_path']['duration_days']);
        $this->assertSame('BND', $report['interpretation']['integrated_action_path']['focus_strategy']);
        $this->assertSame('boundary_gap', $report['interpretation']['integrated_action_path']['priority_gap_type']);
    }

    public function test_claim_boundary_prevents_ability_msceit_hiring_and_clinical_claims(): void
    {
        $report = (new EqIntegratedReportComposer())->compose($this->eq60Report(), $this->sjtScore());

        $this->assertTrue($report['claim_boundary']['not_clinical']);
        $this->assertTrue($report['claim_boundary']['not_hiring']);
        $this->assertTrue($report['claim_boundary']['not_certified_capability_evaluation']);
        $this->assertTrue($report['claim_boundary']['not_msceit_equivalent']);
        $this->assertTrue($report['claim_boundary']['not_true_emotional_ability_score']);
        $this->assertTrue($report['claim_boundary']['does_not_predict_job_performance']);
    }

    /**
     * @param  list<array<string,mixed>>  $gapMap
     * @return array<string,mixed>
     */
    private function findGap(array $gapMap, string $id): array
    {
        foreach ($gapMap as $gap) {
            if (($gap['id'] ?? null) === $id) {
                return $gap;
            }
        }

        $this->fail("Missing gap {$id}.");
    }

    /**
     * @return array<string,mixed>
     */
    private function eq60Report(): array
    {
        return [
            'locale' => 'en',
            'eq_report_mode' => 'self_report',
            'measurement_type' => 'self_report_trait_mixed_ei',
            'quality' => [
                'level' => 'A',
                'confidence_label' => 'high',
            ],
            'scores' => [
                'global' => [
                    'standard_score' => 104,
                    'percentile' => 61,
                    'band' => 'stable',
                ],
                'dimensions' => [
                    'SA' => ['standard_score' => 106, 'percentile' => 65, 'band' => 'stable'],
                    'ER' => ['standard_score' => 92, 'percentile' => 30, 'band' => 'developing'],
                    'EM' => ['standard_score' => 110, 'percentile' => 70, 'band' => 'proficient'],
                    'RM' => ['standard_score' => 101, 'percentile' => 52, 'band' => 'stable'],
                ],
            ],
            'interpretation' => [
                'core_formulation_id' => 'high_empathy_low_recovery',
                'development_lever' => 'ER',
            ],
            'methodology' => [
                'content_version' => 'EQ_60/v1',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sjtScore(): array
    {
        return [
            'scale_code' => 'EQ_SJT_16',
            'score_method' => 'eq_sjt_16_likely_response_partial_credit_v1',
            'measurement_type' => 'scenario_based_emotional_judgment',
            'answer_mode' => 'likely_response',
            'raw_score' => 34.0,
            'max_score' => 48.0,
            'score_pct' => 70.83,
            'band' => 'effective',
            'top_strategy' => 'EMP',
            'lowest_strategy' => 'BND',
            'strategy_scores' => [
                'CUE' => ['raw_score' => 8.0, 'max_score' => 12.0, 'score_pct' => 66.67, 'band' => 'effective'],
                'PAUSE' => ['raw_score' => 4.0, 'max_score' => 9.0, 'score_pct' => 44.44, 'band' => 'mixed_effectiveness'],
                'EMP' => ['raw_score' => 9.0, 'max_score' => 9.0, 'score_pct' => 100.0, 'band' => 'strong'],
                'BND' => ['raw_score' => 3.0, 'max_score' => 12.0, 'score_pct' => 25.0, 'band' => 'low_effectiveness'],
                'REPAIR' => ['raw_score' => 7.0, 'max_score' => 9.0, 'score_pct' => 77.78, 'band' => 'effective'],
                'INFL' => ['raw_score' => 6.0, 'max_score' => 9.0, 'score_pct' => 66.67, 'band' => 'effective'],
            ],
            'domain_scores' => [
                'boundary_setting' => ['raw_score' => 3.0, 'max_score' => 6.0, 'score_pct' => 50.0],
            ],
            'quality' => [
                'level' => 'A',
                'flags' => [],
            ],
            'version_snapshot' => [
                'content_version' => 'EQ_SJT_16/v1',
                'rubric_version' => 'eq_sjt_16.rubric.v1_draft',
            ],
        ];
    }
}
