<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\SearchMeasurementMode;
use Tests\TestCase;

final class SeoPlatform11FSearchMeasurementTest extends TestCase
{
    public function test_valid_measurement_keeps_fact_boundaries_and_all_required_windows(): void
    {
        $output = app(SearchMeasurementMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true, 'mapping_state' => 'mapped',
            'verified_facts' => ['28d impressions increased'],
            'associations' => ['deployment annotation overlaps the window'],
            'hypotheses' => ['snippet mismatch may contribute'],
            'unknowns' => ['causal contribution'],
            'trend_metrics' => ['7' => ['impressions' => 10], '28' => ['impressions' => 40], '90' => ['impressions' => 100]],
            'branded_non_branded' => ['branded' => ['impressions' => 3], 'non_branded' => ['impressions' => 37]],
            'detector_results' => ['high_impressions_low_ctr', 'position_4_15_opportunity', 'content_decay_candidate'],
            'gai_capability' => ['state' => 'manual_export_only', 'official_capability_evidence_ref' => 'controlled-export:sha256'],
        ]);

        $this->assertSame('READY', $output['status']);
        $finding = $output['findings'][0];
        $this->assertSame([7, 28, 90], $finding['windows']);
        $this->assertSame(['28d impressions increased'], $finding['verified_facts']);
        $this->assertSame(['deployment annotation overlaps the window'], $finding['associations']);
        $this->assertSame(['high_impressions_low_ctr', 'position_4_15_opportunity', 'content_decay_candidate'], $finding['detector_results']);
        $this->assertSame([], $finding['holds']);
        $this->assertStringContainsString('average_position_is_not_exact_rank', $finding['attribution_caveat']);
        $this->assertSame('manual_export_only', $output['gai_capability']['state']);
        $this->assertFalse($output['gai_capability']['ordinary_web_search_metrics_relabelled']);
        $this->assertFalse($output['execution_allowed']);
    }

    public function test_raw_queries_action_intents_and_causal_claims_fail_closed_or_are_demoted(): void
    {
        $unsafe = app(SearchMeasurementMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true, 'raw_query' => 'private phrase',
            'cms_write' => true,
        ]);
        $this->assertSame('HOLD', $unsafe['status']);
        $this->assertSame([], $unsafe['findings'][0]['trend_metrics']);

        $overclaim = app(SearchMeasurementMode::class)->review($this->request(), [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true,
            'verified_facts' => ['Google update caused the decline'],
        ]);
        $this->assertSame([], $overclaim['findings'][0]['verified_facts']);
        $this->assertContains('causal_or_attribution_claim_not_supported', $overclaim['findings'][0]['unknowns']);
    }

    public function test_missing_or_unproved_gai_evidence_holds_without_fabricating_valid_or_visibility(): void
    {
        $output = app(SearchMeasurementMode::class)->review($this->request(), [
            'expected_evidence_present' => false,
            'window_complete' => false,
            'verified_facts' => ['must not escape'],
            'gai_capability' => ['state' => 'api_ready'],
        ]);

        $this->assertSame('HOLD', $output['status']);
        $this->assertSame('missing', $output['findings'][0]['measurement_state']['state']);
        $this->assertSame([], $output['findings'][0]['verified_facts']);
        $this->assertSame(['missing'], $output['findings'][0]['holds']);
        $this->assertSame('unverified', $output['gai_capability']['state']);
        $this->assertSame(0, $output['model_calls'] + $output['tool_calls'] + $output['external_calls'] + $output['write_count']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'version' => 'seo.search_measurement_request.v1',
            'role_id' => 'seo.expert.search_analytics_measurement',
            'surface' => 'public_search', 'page_family' => 'tests', 'locale' => 'en',
            'query_cohort' => 'non_branded', 'windows' => [7, 28, 90],
            'comparison_window' => ['rule' => 'immediately_preceding_equal_length_window'],
            'execution_allowed' => false,
        ];
    }
}
