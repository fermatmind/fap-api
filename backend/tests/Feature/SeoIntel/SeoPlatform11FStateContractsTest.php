<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\MeasurementContractRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementDependencySnapshotBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementModeRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementStateResolver;
use Tests\TestCase;

final class SeoPlatform11FStateContractsTest extends TestCase
{
    public function test_manifest_is_canonical_and_binds_all_state_search_and_cro_contracts(): void
    {
        $registry = app(MeasurementContractRegistry::class);
        $manifest = $registry->manifest();
        $artifact = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-measurement-contract-manifest.v1.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo.measurement_contract_manifest.v1', $manifest['manifest_id']);
        $this->assertCount(12, $manifest['contracts']);
        $this->assertTrue($registry->verify($artifact));
        $this->assertSame([
            'seo.measurement_evidence_context.v1', 'seo.source_capability_decision.v1',
            'seo.measurement_state_decision.v1', 'seo.measurement_evidence_gap.v1',
            'seo.search_measurement_request.v1', 'seo.search_measurement_finding.v1',
            'seo.search_measurement_output.v1', 'seo.commercial_funnel_cro_request.v1',
            'seo.commercial_funnel_cro_finding.v1', 'seo.cro_experiment_candidate.v1',
            'seo.commercial_funnel_cro_output.v1', 'seo.measurement_closeout_receipt.v1',
        ], array_column($manifest['contracts'], 'id'));
        foreach ($manifest['contracts'] as $contract) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['hash']);
        }
        $runtime = app(MeasurementModeRegistry::class)->capabilitySnapshot();
        $this->assertSame('OFFLINE_EVAL_READY', $runtime['mode_state']);
        foreach (['production_model_enabled', 'production_tool_enabled', 'production_execution_enabled', 'production_write_enabled', 'allow_delegation', 'external_egress_enabled', 'execution_allowed'] as $field) {
            $this->assertFalse($runtime[$field], $field);
        }
    }

    public function test_source_capability_and_measurement_state_follow_fail_closed_priority(): void
    {
        $states = app(MeasurementStateResolver::class);
        $this->assertSame('available', $states->sourceCapability(['quality_gate_passed' => true, 'current_window_readable' => true])['state']);
        $this->assertSame('api_ready', $states->sourceCapability(['api_ready' => true, 'property_verified' => true, 'adapter_ready' => true])['state']);
        $this->assertSame('manual_export_only', $states->sourceCapability(['manual_export_verified' => true])['state']);
        $this->assertSame('unverified', $states->sourceCapability([])['state']);
        $this->assertSame('unavailable', $states->sourceCapability(['unavailable_proven' => true])['state']);

        $base = ['expected_evidence_present' => true, 'window_complete' => true, 'quality_gate_passed' => true, 'valid_measurement_present' => true];
        $this->assertSame('hold', $states->measurementState($base + ['policy_hold' => true, 'mapping_failed' => true])['state']);
        $this->assertSame('mapping_failed', $states->measurementState($base + ['mapping_failed' => true])['state']);
        $this->assertSame('missing', $states->measurementState(['expected_evidence_present' => false])['state']);
        $this->assertSame('delayed', $states->measurementState(['expected_evidence_present' => true, 'within_normal_lag' => true])['state']);
        $this->assertSame('window_incomplete', $states->measurementState(['expected_evidence_present' => true, 'window_complete' => false])['state']);
        $this->assertSame('valid', $states->measurementState($base)['state']);
        $this->assertSame('valid_zero', $states->measurementState($base + ['explicit_zero_proof' => true, 'all_relevant_values_zero' => true])['state']);
        $this->assertNotSame('valid_zero', $states->measurementState(['expected_evidence_present' => false, 'window_complete' => true, 'quality_gate_passed' => true])['state']);
    }

    public function test_dependency_snapshot_binds_every_authority_and_invalid_context_holds(): void
    {
        $sha = str_repeat('a', 40);
        $snapshot = app(MeasurementDependencySnapshotBuilder::class)->build($sha, 'ci_candidate', $sha, 'private_results', 'en');

        $this->assertSame('DEPENDENCY_HOLD', $snapshot['status']);
        $this->assertContains('page_family_invalid', $snapshot['blockers']);
        $this->assertSame([7, 28, 90], $snapshot['analysis_windows_days']);
        $this->assertSame('immediately_preceding_equal_length_window', $snapshot['comparison_window_rule']);
        foreach ([
            '11a_registry', '11b_evidence_privacy_retention', '11c_policy_gateway', '11d_binding_v2',
            '11e_closeout', 'gsc_data_quality_gate', 'gsc_readmodel', 'search_to_result_funnel',
            'funnel_taxonomy_mapping', 'funnel_aggregate_readmodel', 'detector_registry',
            'page_family_policy', 'url_truth',
        ] as $dependency) {
            $this->assertArrayHasKey($dependency, $snapshot['dependencies']);
        }
        $this->assertFalse($snapshot['execution_allowed']);
    }
}
