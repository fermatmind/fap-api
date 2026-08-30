<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Measurement\MeasurementContractRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementFixtureEvaluator;
use App\Services\SeoCouncil\Measurement\MeasurementModeRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementStateResolver;
use Tests\TestCase;

final class SeoPlatform11FStateContractsTest extends TestCase
{
    public function test_v3_is_current_authority_and_v1_v2_assets_remain_byte_stable(): void
    {
        $registry = app(MeasurementContractRegistry::class);
        $manifest = $registry->manifest();
        $generated = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-measurement-contract-manifest.v3.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('seo.measurement_contract_manifest.v3', $manifest['manifest_id']);
        $this->assertSame('3.0.0', $manifest['manifest_version']);
        $this->assertCount(9, $manifest['contracts']);
        $this->assertTrue($registry->verify($generated));
        foreach ($manifest['contracts'] as $contract) {
            $schema = $registry->schema($contract['id']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['hash']);
        }
        $this->assertSame('4d9629ba11917597d5400c14d206a8996fcb1af389186e41d5cd9d38c65da037', hash_file('sha256', base_path('docs/seo/generated/seo-measurement-contract-manifest.v1.json')));
        $this->assertSame('bcacaf18906553010e91dde49292759b447f1507741a1b5d39e0fbe4cdd6eeb7', hash_file('sha256', base_path('docs/seo/generated/seo-measurement-contract-manifest.v2.json')));
        $this->assertSame('2882f83d0631dca4df8a0f11fc2cfeb7fa3405bbedfc615ed71950c0f2356c2a', hash_file('sha256', resource_path('seo-agent/council/measurement/schemas/seo.measurement_closeout_receipt.v2.schema.json')));
        $this->assertSame('8093dcddc683716d0fd57ccd494c364909da2e565a83fe37b844b0801171bd3b', hash_file('sha256', resource_path('seo-agent/council/measurement/seo.search_measurement_mode.v1.json')));
        $runtime = app(MeasurementModeRegistry::class)->capabilitySnapshot();
        $this->assertSame('OFFLINE_EVAL_READY', $runtime['mode_state']);
        $this->assertFalse($runtime['production_execution_enabled']);
        $this->assertFalse($runtime['execution_allowed']);
    }

    public function test_source_conflict_priority_and_valid_zero_are_fail_closed(): void
    {
        $states = app(MeasurementStateResolver::class);
        $conflict = $states->sourceCapability([
            'unavailable_proven' => true, 'quality_gate_passed' => true, 'current_window_readable' => true,
        ]);
        $this->assertSame('unverified', $conflict['state']);
        $this->assertTrue($conflict['conflict_detected']);
        $this->assertSame('api_ready', $states->sourceCapability(['api_ready' => true, 'property_verified' => true, 'adapter_ready' => true])['state']);
        $this->assertSame('available', $states->sourceCapability(['quality_gate_passed' => true, 'current_window_readable' => true])['state']);
        $this->assertSame('manual_export_only', $states->sourceCapability(['manual_export_verified' => true])['state']);

        $valid = [
            'expected_evidence_present' => true, 'window_complete' => true, 'quality_gate_passed' => true,
            'freshness_state' => 'fresh', 'valid_measurement_present' => true,
        ];
        $this->assertSame('valid', $states->measurementState($valid)['state']);
        $this->assertSame('valid_zero', $states->measurementState($valid + ['explicit_zero_proof' => true, 'all_relevant_values_zero' => true])['state']);
        $this->assertSame('missing', $states->measurementState(['expected_evidence_present' => false, 'explicit_zero_proof' => true, 'all_relevant_values_zero' => true])['state']);
        $this->assertSame('hold', $states->measurementState([...$valid, 'freshness_state' => 'stale'])['state']);
        $this->assertSame('hold', $states->measurementState([...$valid, 'unavailable_proven' => true])['state']);

        $metrics = app(MeasurementFixtureEvaluator::class)->evaluate()['metrics'];
        $this->assertSame(15, $metrics['fixture_total']);
        $this->assertSame(0, $metrics['source_state_misclassification_count']);
        $this->assertSame(0, $metrics['measurement_state_misclassification_count']);
        $this->assertSame(0, $metrics['valid_zero_misclassification_count']);
    }
}
