<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Platform11\CapabilityLifecycleStateMachine;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11EvaluationBuilder;
use App\Services\SeoCouncil\Platform11\Platform11FaultDrillRunner;
use App\Services\SeoCouncil\Platform11\Platform11HCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11ICloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11JCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11KCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Platform11LCloseoutBuilder;
use App\Services\SeoCouncil\Platform11\Post12L3CanaryAdapter;
use Tests\TestCase;

final class SeoPlatform11LLifecycleEvaluationTest extends TestCase
{
    public function test_permission_state_machine_is_deny_only_at_platform_11_close(): void
    {
        $machine = $this->app->make(CapabilityLifecycleStateMachine::class);
        $this->assertSame([
            'L0' => 'READY',
            'L1' => 'READY',
            'L2' => 'IMPLEMENTED_WRITE_DISABLED',
            'L3' => 'IMPLEMENTED_WRITE_DISABLED',
            'L4' => 'DORMANT_NOT_AUTHORIZED',
        ], $machine->permissionStates());
        foreach ([['draft', 'active'], ['hold', 'active'], ['deprecated', 'active']] as [$from, $to]) {
            $result = $machine->transition($from, $to, true);
            $this->assertSame('HOLD', $result['status']);
            $this->assertFalse($result['execution_allowed']);
        }
    }

    public function test_legal_lifecycle_requires_evaluation_and_denies_external_mutation_channels(): void
    {
        $machine = $this->app->make(CapabilityLifecycleStateMachine::class);
        foreach ([
            ['draft', 'offline_eval'], ['offline_eval', 'shadow'], ['shadow', 'active'], ['active', 'degraded'],
            ['active', 'hold'], ['hold', 'offline_eval'], ['active', 'deprecated'],
        ] as [$from, $to]) {
            $this->assertSame('ACCEPTED', $machine->transition($from, $to, true)['status']);
        }
        $this->assertSame('EVAL_RECEIPT_REQUIRED', $machine->transition('offline_eval', 'shadow', false)['reason']);
        $this->assertSame('EVAL_RECEIPT_REQUIRED', $machine->transition('shadow', 'active', false)['reason']);
        foreach (['prompt', 'cli', 'scheduler', 'api', 'ui'] as $channel) {
            $this->assertSame('MUTATION_CHANNEL_DENIED', $machine->transition('draft', 'offline_eval', true, $channel)['reason']);
        }
    }

    public function test_every_governance_hash_dimension_forces_reevaluation_hold(): void
    {
        $machine = $this->app->make(CapabilityLifecycleStateMachine::class);
        $expected = array_fill_keys(CapabilityLifecycleStateMachine::REEVALUATION_DIMENSIONS, str_repeat('a', 64));
        $this->assertSame('READY', $machine->verifyVersionVector($expected, $expected)['status']);
        foreach (CapabilityLifecycleStateMachine::REEVALUATION_DIMENSIONS as $dimension) {
            $observed = $expected;
            $observed[$dimension] = str_repeat('b', 64);
            $result = $machine->verifyVersionVector($expected, $observed);
            $this->assertSame('hold', $result['state']);
            $this->assertSame('REEVALUATION_REQUIRED', $result['reason']);
            $this->assertFalse($result['execution_allowed']);
        }
    }

    public function test_l3_canary_contract_never_starts_and_rejects_shared_unisolated_layers(): void
    {
        $adapter = $this->app->make(Post12L3CanaryAdapter::class);
        $notCanary = $adapter->evaluate(['shared_layer' => true]);
        $this->assertSame('HOLD', $notCanary['status']);
        $this->assertSame('NOT_A_CANARY', $notCanary['reason']);
        $this->assertFalse($notCanary['canary_started']);

        $disabled = $adapter->evaluate([
            'shared_layer' => false,
            'signed_manifest_valid' => true,
            'exact_url_allowlist' => [str_repeat('a', 64)],
            'page_family' => 'career',
            'locale' => 'en',
            'feature_flag' => 'seo_l3_canary',
            'rollback_unit' => str_repeat('a', 64),
            'current_evidence' => true,
            'prior_stage_readback' => true,
            'independent_review' => true,
            'policy_gateway_approved' => true,
        ]);
        $this->assertSame('IMPLEMENTED_WRITE_DISABLED', $disabled['status']);
        $this->assertSame(['1-3', '10', '50', 'approved_cohort'], $disabled['cohort_sequence']);
        $this->assertSame(0, $disabled['write_count']);
        $this->assertSame(0, $disabled['production_permissions']);
        $this->assertFalse($disabled['canary_started']);
        $this->assertFalse($disabled['execution_allowed']);
    }

    public function test_evaluation_has_96_stratified_golden_fixtures_and_wilson_intervals(): void
    {
        $builder = $this->app->make(Platform11EvaluationBuilder::class);
        $manifest = $builder->fixtureManifest();
        $evaluation = $builder->evaluate();
        $this->assertCount(96, $manifest['fixtures']);
        $this->assertSame(96, $evaluation['sample_size']);
        $this->assertSame(48, $evaluation['positive_sample_count']);
        $this->assertSame(48, $evaluation['hold_sample_count']);
        $this->assertSame(48, $evaluation['stratum_count']);
        $this->assertCount(84, $evaluation['metric_strata']);
        $this->assertSame(1.0, $evaluation['golden_fixture_pass_rate']);
        $this->assertLessThan(1.0, $evaluation['golden_fixture_confidence_interval_95']['lower']);
        $this->assertSame(1.0, $evaluation['golden_fixture_confidence_interval_95']['upper']);
        $this->assertSame('not_measured', $evaluation['zero_sample_state']['measurement_state']);
        $this->assertNull($evaluation['zero_sample_state']['observed_rate']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['fixture_manifest_hash']);
        foreach ($evaluation['metric_strata'] as $metric) {
            $this->assertSame(8, $metric['sample_size']);
            $this->assertSame(8, $metric['numerator']);
            $this->assertSame(8, $metric['denominator']);
            $this->assertSame(1.0, $metric['observed_rate']);
            $this->assertSame('observed', $metric['measurement_state']);
        }
    }

    public function test_fault_drill_contains_all_15_isolated_hold_or_stop_cases_without_activity(): void
    {
        $receipt = $this->app->make(Platform11FaultDrillRunner::class)->run();
        $this->assertSame(15, $receipt['scenario_count']);
        $this->assertSame(15, $receipt['passed_count']);
        $this->assertSame([
            'model_failure', 'tool_timeout', 'evidence_expired', 'policy_update', 'cms_failure',
            'readback_failure', 'rollback_failure', 'duplicate_mission', 'scheduler_duplicate_delivery',
            'stale_enablement', 'private_attempt', 'egress_failure', 'prompt_injection',
            'tool_metadata_injection', 'trace_failure',
        ], array_column($receipt['results'], 'scenario'));
        foreach ($receipt['results'] as $result) {
            $this->assertContains($result['status'], ['HOLD', 'STOP']);
            $this->assertTrue($result['isolated_test_double']);
            $this->assertFalse($result['stale_enablement_accepted']);
            $this->assertFalse($result['private_context_ingested']);
            $this->assertFalse($result['permission_expansion']);
            $this->assertFalse($result['execution_allowed']);
            foreach (['model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes'] as $field) {
                $this->assertSame(0, $result[$field]);
            }
        }
        foreach (['duplicate_delivery_bypass_count', 'stale_enablement_acceptance_count', 'private_data_leak_count', 'trace_permission_expansion_count', 'model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions'] as $field) {
            $this->assertSame(0, $receipt[$field]);
        }
    }

    public function test_closeout_binds_all_stages_and_closes_only_in_production(): void
    {
        $sha = str_repeat('c', 40);
        $h = $this->app->make(Platform11HCloseoutBuilder::class)->build($sha, 'ci_candidate');
        $i = $this->app->make(Platform11ICloseoutBuilder::class)->build($sha, 'ci_candidate', $h);
        $j = $this->app->make(Platform11JCloseoutBuilder::class)->build($sha, 'ci_candidate', $i);
        $k = $this->app->make(Platform11KCloseoutBuilder::class)->build($sha, 'ci_candidate', $j);
        $builder = $this->app->make(Platform11LCloseoutBuilder::class);
        $offline = $builder->build($sha, 'ci_candidate', $h, $i, $j, $k);
        $this->assertSame('OFFLINE_EVAL_READY', $offline['closeout_state']);
        $this->assertSame('OFFLINE_EVAL_READY', $offline['SEO-PLATFORM-11L']);
        $this->assertSame('OFFLINE_EVAL_READY', $offline['SEO-PLATFORM-11']);
        $this->assertFalse($offline['ready_for_12']);
        $this->assertSame(['11H', '11I', '11J', '11K'], array_keys($offline['stage_closeout_refs']));
        $this->assertSame(0, $offline['lifecycle_probes']['bypass_count']);
        $this->assertSame(0, $offline['canary_probes']['bypass_count']);

        $k['environment'] = 'production_runtime';
        $k['closeout_state'] = 'CLOSED';
        $k['dependency_status'] = 'READY';
        $k['SEO-PLATFORM-11K'] = 'CLOSED';
        $k['ready_for_11L'] = true;
        $production = $builder->build($sha, 'production_runtime', $h, $i, $j, $k);
        $this->assertSame('CLOSED', $production['SEO-PLATFORM-11L']);
        $this->assertSame('CLOSED', $production['SEO-PLATFORM-11']);
        $this->assertTrue($production['ready_for_12']);
        $this->assertSame($sha, $production['production_sha']);
        foreach ([
            'new_agent_count', 'delegation_count', 'private_data_leak_count', 'private_url_leak_count',
            'authority_invention_count', 'policy_bypass_count', 'stale_enablement_acceptance_count',
            'l2_write_bypass_count', 'l3_write_bypass_count', 'l4_allow_count', 'active_manifest_count',
            'trusted_signing_key_count', 'model_calls', 'tool_calls', 'new_external_calls', 'external_calls',
            'cms_writes', 'publish_writes', 'url_truth_writes', 'canonical_writes', 'robots_writes',
            'search_writes', 'business_writes', 'production_permissions',
        ] as $field) {
            $this->assertSame(0, $production[$field], $field);
        }
        $this->assertFalse($production['post12_agent_write_enabled']);
        $this->assertFalse($production['execution_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $production['receipt_hash']);
        $this->assertTrue($this->app->make(Platform11ContractRegistry::class)->verifyGenerated());
    }
}
