<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Measurement\Platform12MetricContract;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use Tests\TestCase;

final class SeoPlatform12H01MetricContractTest extends TestCase
{
    public function test_frozen_contract_hash_schema_and_generated_artifacts_are_verifiable(): void
    {
        $service = app(Platform12MetricContract::class);
        $contract = $service->contract();
        $schema = $service->schema();

        $this->assertSame('FROZEN_PRE_DAY1', $contract['contract_state']);
        $this->assertSame('1.0.0', $contract['contract_version']);
        $this->assertSame(
            app(SeoRegistryHasher::class)->hashWithout($contract, 'contract_hash'),
            $contract['contract_hash'],
        );
        $this->assertTrue($service->verify($contract));
        $this->assertTrue(app(Platform12ContractRegistry::class)->verifyGenerated());

        $this->assertSame([], array_diff($schema['required'], array_keys($contract)));
        $this->assertSame([], array_diff(array_keys($contract), array_keys($schema['properties'])));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['contract_hash']);
        $this->assertCount($schema['properties']['metrics']['minItems'], $contract['metrics']);
        foreach ($contract['metrics'] as $metric) {
            $metricSchema = $schema['$defs']['metric'];
            $this->assertSame([], array_diff($metricSchema['required'], array_keys($metric)));
            $this->assertSame([], array_diff(array_keys($metric), array_keys($metricSchema['properties'])));
        }
    }

    public function test_all_fixed_metrics_have_explicit_measurement_and_decision_semantics(): void
    {
        $contract = app(Platform12MetricContract::class)->contract();
        $metrics = collect($contract['metrics'])->keyBy('metric_id');

        $this->assertCount(19, $metrics);
        $this->assertSame(28, $metrics['gsc_daily_slot_success_rate']['minimum_sample']);
        $this->assertStringContainsString('all 28 planned daily slots', $metrics['gsc_daily_slot_success_rate']['denominator']);
        $this->assertSame('>=95%', $metrics['gsc_daily_slot_success_rate']['threshold']);
        $this->assertSame('100% with lag <=3 days', $metrics['gsc_data_lag_compliance_rate']['threshold']);
        $this->assertSame('=100%', $metrics['url_truth_reconciliation_rate']['threshold']);
        $this->assertSame('>=90%', $metrics['cluster_valid_coverage_rate']['threshold']);
        $this->assertSame(100, $metrics['dedupe_precision']['minimum_sample']);
        $this->assertSame('>=90%', $metrics['dedupe_precision']['threshold']);
        $this->assertSame(50, $metrics['false_positive_rate']['minimum_sample']);
        $this->assertSame('<10%', $metrics['false_positive_rate']['threshold']);

        foreach ([
            'routing_required_mode_safety_recall' => '=100%',
            'routing_authority_recall' => '=100%',
            'routing_overall_recall' => '>=95%',
            'routing_precision' => '>=90%',
            'routing_unnecessary_mode_rate' => '<=10%',
            'routing_all_team_invocation_rate' => '=0%',
            'routing_human_correction_rate' => '<=10%',
        ] as $id => $threshold) {
            $this->assertSame(96, $metrics[$id]['minimum_sample'], $id);
            $this->assertContains('minimum 8 per family×locale', $metrics[$id]['strata'], $id);
            $this->assertSame($threshold, $metrics[$id]['threshold'], $id);
            $this->assertTrue($metrics[$id]['confidence_interval_95']['required'], $id);
        }

        $this->assertSame('=100%', $metrics['trace_completeness_rate']['threshold']);
        $this->assertSame('=0', $metrics['policy_incident_count']['threshold']);
        $this->assertSame('=0', $metrics['private_data_incident_count']['threshold']);
        $this->assertSame('=0', $metrics['unauthorized_execution_incident_count']['threshold']);
        $this->assertSame('<=30 minutes/week', $metrics['routine_maintenance_median_minutes_per_week']['threshold']);
        $this->assertSame(4, $metrics['routine_maintenance_relative_reduction']['minimum_sample']);
        $this->assertSame('>=80%', $metrics['routine_maintenance_relative_reduction']['threshold']);

        foreach ($metrics as $metric) {
            $this->assertNotEmpty($metric['numerator']);
            $this->assertNotEmpty($metric['denominator']);
            $this->assertNotEmpty($metric['data_sources']);
            $this->assertNotEmpty($metric['sample_method']);
            $this->assertSame(0.95, $metric['confidence_interval_95']['confidence_level']);
            $this->assertContains('HOLD', $metric['allowed_terminal_states']);
            $this->assertContains('FAIL', $metric['allowed_terminal_states']);
            $this->assertContains('INCONCLUSIVE', $metric['allowed_terminal_states']);
            $this->assertContains('RESTART_REQUIRED', $metric['allowed_terminal_states']);
        }
    }

    public function test_external_failure_baseline_shortfall_and_safety_breaches_fail_closed(): void
    {
        $service = app(Platform12MetricContract::class);

        $this->assertSame(
            ['state' => 'MEASUREMENT_HOLD', 'reason' => 'EXTERNAL_DATA_FAILURE'],
            $service->classifyGuardrails(['external_data_available' => false]),
        );
        $this->assertSame(
            ['state' => 'INCONCLUSIVE', 'reason' => 'MEASUREMENT_BASELINE_HOLD'],
            $service->classifyGuardrails([
                'external_data_available' => true,
                'complete_real_baseline_weeks' => 3,
            ]),
        );
        $this->assertSame(
            ['state' => 'NOT_STARTED', 'reason' => 'DAY1_CLOCK_NOT_AUTHORIZED'],
            $service->classifyGuardrails([
                'external_data_available' => true,
                'complete_real_baseline_weeks' => 4,
            ]),
        );

        foreach ([
            'private_data_leak_count',
            'authority_violation_count',
            'unauthorized_execution_count',
            'wrong_canonical_or_noindex_count',
            'fabricated_evidence_count',
        ] as $signal) {
            $result = $service->classifyGuardrails([$signal => 1]);
            $this->assertSame('RESTART_REQUIRED', $result['state'], $signal);
        }
        $this->assertSame(
            'RESTART_REQUIRED',
            $service->classifyGuardrails(['scaled_after_guardrail_failure' => true])['state'],
        );
    }

    public function test_contract_tampering_requires_a_new_version_even_if_hash_is_recomputed(): void
    {
        $service = app(Platform12MetricContract::class);
        $tampered = $service->contract();
        $tampered['metrics'][0]['threshold'] = '>=90%';

        $this->assertFalse($service->verify($tampered));
        $tampered['contract_hash'] = app(SeoRegistryHasher::class)->hashWithout($tampered, 'contract_hash');
        $this->assertFalse($service->verify($tampered));

        $contract = $service->contract();
        $this->assertFalse($contract['runtime_controls']['starts_28_day_clock']);
        $this->assertFalse($contract['runtime_controls']['modifies_runtime_flags']);
        $this->assertFalse($contract['runtime_controls']['activates_runtime_writes']);
        $this->assertFalse($contract['evaluation_window']['clock_start_authorized']);
        $this->assertFalse($contract['decision_rules']['external_data_failure']['silent_exclusion_allowed']);
        $this->assertFalse($contract['decision_rules']['insufficient_baseline']['fabricated_or_proxy_baseline_allowed']);
        $this->assertTrue($contract['decision_rules']['contract_change']['new_version_required']);
    }
}
