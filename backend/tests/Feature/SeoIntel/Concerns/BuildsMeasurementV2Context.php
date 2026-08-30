<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel\Concerns;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleFactory;
use App\Services\SeoCouncil\Measurement\MeasurementContractValidator;
use App\Services\SeoCouncil\Measurement\MeasurementEvidenceContextBuilder;

trait BuildsMeasurementV2Context
{
    /** @param array<string, mixed> $payload @param array<string, mixed> $bundleOverrides @return array<string, mixed> */
    private function measurementContext(
        string $modeId = 'search_measurement',
        array $payload = [],
        array $bundleOverrides = [],
    ): array {
        [$bundle, $request] = $this->measurementBundleAndRequest($modeId, $payload, $bundleOverrides);

        return app(MeasurementEvidenceContextBuilder::class)->build($request, [$bundle]);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $bundleOverrides @return array{0:array<string, mixed>,1:array<string, mixed>} */
    private function measurementBundleAndRequest(
        string $modeId = 'search_measurement',
        array $payload = [],
        array $bundleOverrides = [],
    ): array {
        $roleId = $modeId === 'search_measurement'
            ? 'seo.expert.search_analytics_measurement'
            : 'seo.expert.commercial_funnel_cro';
        $sourceType = $modeId === 'search_measurement' ? 'gsc_aggregate' : 'public_funnel_aggregate';
        $missionId = 'mission:measurement-v2';
        $revision = str_repeat('a', 64);
        $defaults = $modeId === 'search_measurement' ? $this->searchPayload() : $this->croPayload();
        $bundle = app(SeoEvidenceBundleFactory::class)->create([
            'bundle_id' => 'bundle:measurement-v2', 'bundle_version' => 2, 'mission_id' => $missionId,
            'source_type' => $sourceType, 'source_ref' => 'measurement-readmodel:'.$revision,
            'authority_type' => 'measurement_readmodel', 'captured_at' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'evidence_state' => 'verified', 'freshness_state' => 'fresh', 'source_capability_state' => 'available',
            'retention_class' => 'first_party_aggregate', 'page_family' => 'tests', 'locale' => 'en',
            'authority_revision' => $revision, 'source_license_class' => 'first_party',
            'data_usage_purpose' => 'measurement_review', 'egress_decision' => 'not_required',
            'lineage_refs' => [], 'payload' => [...$defaults, ...$payload], ...$bundleOverrides,
        ]);
        $request = app(MeasurementContractValidator::class)->sealRequest([
            'version' => 'seo.measurement_request.v2', 'mission_id' => $missionId, 'run_id' => 'run:measurement-v2',
            'role_id' => $roleId, 'mode_id' => $modeId, 'page_family' => 'tests', 'locale' => 'en',
            'windows' => [7, 28, 90], 'evidence_bundle_refs' => [[
                'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                'bundle_hash' => $bundle['bundle_hash'], 'source_type' => $bundle['source_type'],
                'authority_type' => $bundle['authority_type'],
            ]],
            'authority_revision' => $revision, 'execution_allowed' => false,
        ]);

        return [$bundle, $request];
    }

    /** @return array<string, mixed> */
    private function searchPayload(): array
    {
        return [
            'windows' => [
                ['window_days' => 7, 'metrics' => ['clicks' => 8, 'impressions' => 100, 'ctr_ppm' => 80000, 'average_position_milli' => 9500]],
                ['window_days' => 28, 'metrics' => ['clicks' => 32, 'impressions' => 400, 'ctr_ppm' => 80000, 'average_position_milli' => 9800]],
                ['window_days' => 90, 'metrics' => ['clicks' => 100, 'impressions' => 1200, 'ctr_ppm' => 83333, 'average_position_milli' => 10000]],
            ],
            'branded_non_branded' => [
                'branded' => ['clicks' => 20, 'impressions' => 100, 'ctr_ppm' => 200000, 'average_position_milli' => 3000],
                'non_branded' => ['clicks' => 80, 'impressions' => 1100, 'ctr_ppm' => 72727, 'average_position_milli' => 11000],
            ],
            'detector_findings' => ['high_impressions_low_ctr'],
            'freshness' => ['lag_days_required' => 3, 'max_source_age_days' => 10, 'min_source_date' => now()->subDays(92)->toDateString(), 'max_source_date' => now()->subDays(3)->toDateString()],
            'mapping_state' => 'mapped', 'quality_gate_status' => 'pass', 'window_complete' => true,
            'current_window_readable' => true, 'valid_measurement_present' => true,
            'explicit_zero_proof' => false, 'all_relevant_values_zero' => false,
            'verified_facts' => ['The 28-day aggregate contains 400 impressions.'],
            'associations' => ['A release annotation overlaps the aggregate window.'],
            'hypotheses' => ['Snippet alignment may affect click-through rate.'],
            'unknowns' => ['Causal contribution remains unverified.'],
        ];
    }

    /** @return array<string, mixed> */
    private function croPayload(): array
    {
        $metrics = [
            'landing_pv_count' => 1500, 'article_to_test_click_count' => 180,
            'start_test_count' => 140, 'complete_test_count' => 100,
            'aggregate_outcome_view_count' => 96, 'return_public_content_count' => 20,
        ];

        return [
            'windows' => [
                ['window_days' => 7, 'metrics' => $metrics],
                ['window_days' => 28, 'metrics' => $metrics],
                ['window_days' => 90, 'metrics' => $metrics],
            ],
            'stage_coverage' => [
                'landing' => true, 'start' => true, 'completion' => true,
                'aggregate_outcome_view' => true, 'return_public_content' => true, 'cta' => true,
            ],
            'freshness' => ['age_hours' => 4, 'max_age_hours' => 48, 'latest_refresh_status' => 'success'],
            'revision_hash' => str_repeat('b', 64), 'mapping_state' => 'mapped',
            'quality_gate_status' => 'pass', 'window_complete' => true,
            'current_window_readable' => true, 'valid_measurement_present' => true,
            'explicit_zero_proof' => false, 'all_relevant_values_zero' => false,
            'verified_facts' => [], 'associations' => [],
            'hypotheses' => ['Promise parity may affect qualified starts.'], 'unknowns' => [],
        ];
    }
}
