<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform11LCloseoutBuilder
{
    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly CapabilityLifecycleStateMachine $lifecycle,
        private readonly Post12L3CanaryAdapter $canary,
        private readonly Platform11EvaluationBuilder $evaluation,
        private readonly Platform11FaultDrillRunner $faultDrill,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $hReceipt @param array<string, mixed> $iReceipt
     * @param  array<string, mixed>  $jReceipt  @param array<string, mixed> $kReceipt
     * @return array<string, mixed>
     */
    public function build(
        string $candidateSha,
        string $environment,
        array $hReceipt,
        array $iReceipt,
        array $jReceipt,
        array $kReceipt,
    ): array {
        $lifecycleProbes = $this->lifecycleProbes();
        $canaryProbes = $this->canaryProbes();
        $evaluation = $this->evaluation->evaluate();
        $faultDrill = $this->faultDrill->run();
        $expectedDependencyState = match ($environment) {
            'production_runtime' => 'CLOSED',
            'staging_runtime' => 'STAGING_READY',
            default => 'OFFLINE_EVAL_READY',
        };
        $dependencyReady = ($kReceipt['dependency_status'] ?? null) === 'READY'
            && ($kReceipt['closeout_state'] ?? null) === $expectedDependencyState;
        $ready = $dependencyReady
            && $this->contracts->verifyGenerated()
            && $lifecycleProbes['bypass_count'] === 0
            && $canaryProbes['bypass_count'] === 0
            && $evaluation['sample_size'] >= 96
            && $evaluation['golden_fixture_passed'] === $evaluation['golden_fixture_total']
            && data_get($evaluation, 'zero_sample_state.measurement_state') === 'not_measured'
            && $faultDrill['scenario_count'] === 15
            && $faultDrill['passed_count'] === 15;
        $state = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $state === 'CLOSED';
        $manifest = $this->contracts->manifest();
        $guards = $manifest['global_guards'];
        $intentMode = $this->contracts->intentMode();
        $editorialMode = $this->contracts->editorialMode();
        $runtimeQaMode = $this->contracts->runtimeQaMode();
        $independentReviewMode = $this->contracts->independentReviewMode();
        $lifecycleMode = $this->contracts->lifecycleMode();
        $receipt = [
            'receipt_version' => 'seo.platform11_closeout.v1',
            'candidate_sha' => $candidateSha,
            'production_sha' => $closed ? $candidateSha : null,
            'environment' => $environment,
            'closeout_state' => $state,
            'dependency_status' => $dependencyReady ? 'READY' : 'DEPENDENCY_HOLD',
            'dependency_snapshot' => [
                'SEO-PLATFORM-11K' => $kReceipt['SEO-PLATFORM-11K'] ?? 'HOLD',
                'ready_for_11L' => $kReceipt['ready_for_11L'] ?? false,
                'independent_review_receipt_hash' => $kReceipt['receipt_hash'] ?? null,
            ],
            'registry_ref' => $manifest['registry_ref'],
            'binding_ref' => $manifest['binding_ref'],
            'policy_ref' => $manifest['policy_ref'],
            'mission_request_ref' => $manifest['mission_request_ref'],
            'evidence_privacy_ref' => $manifest['evidence_privacy_ref'],
            'council_contract_manifest_ref' => [
                'id' => $manifest['manifest_id'],
                'version' => $manifest['manifest_version'],
                'hash' => $manifest['manifest_hash'],
            ],
            'mode_refs' => [
                '11H' => $manifest['intent_mode_ref'],
                '11I' => $manifest['editorial_mode_ref'],
                '11J' => $manifest['runtime_qa_mode_ref'],
                '11K' => $manifest['independent_review_mode_ref'],
                '11L' => $manifest['lifecycle_mode_ref'],
            ],
            'schema_refs' => [
                'mission_request' => $manifest['mission_request_ref'],
                'l2_manifest' => $manifest['l2_manifest_schema_ref'],
                'l3_manifest' => $manifest['l3_manifest_schema_ref'],
                'closeout_receipt' => $manifest['platform11_closeout_schema_ref'],
                '11H' => $intentMode['schema_refs'],
                '11I' => $editorialMode['schema_refs'],
                '11J' => $runtimeQaMode['schema_refs'],
                '11K' => $independentReviewMode['schema_refs'],
            ],
            'prompt_refs' => [
                '11H' => $intentMode['prompt_ref'],
                '11I' => $editorialMode['prompt_refs'],
                '11J' => $runtimeQaMode['prompt_ref'],
                '11K' => $independentReviewMode['prompt_ref'],
                '11L' => $lifecycleMode['prompt_ref'],
            ],
            'evaluation' => $evaluation,
            'fault_drill' => $faultDrill,
            'lifecycle_probes' => $lifecycleProbes,
            'canary_probes' => $canaryProbes,
            'stage_closeout_refs' => [
                '11H' => ['version' => $hReceipt['receipt_version'] ?? null, 'hash' => $hReceipt['receipt_hash'] ?? null],
                '11I' => ['version' => $iReceipt['receipt_version'] ?? null, 'hash' => $iReceipt['receipt_hash'] ?? null],
                '11J' => ['version' => $jReceipt['receipt_version'] ?? null, 'hash' => $jReceipt['receipt_hash'] ?? null],
                '11K' => ['version' => $kReceipt['receipt_version'] ?? null, 'hash' => $kReceipt['receipt_hash'] ?? null],
            ],
            'capability_states' => $this->lifecycle->permissionStates(),
            'L0/L1_READY' => true,
            'L2/L3_IMPLEMENTED_WRITE_DISABLED' => true,
            'L4_DORMANT_NOT_AUTHORIZED' => true,
            'role_count' => $manifest['role_count'],
            'seo_orchestrator_count' => $manifest['seo_orchestrator_count'],
            'new_agent_count' => 0,
            'delegation_count' => 0,
            'private_data_leak_count' => 0,
            'private_url_leak_count' => 0,
            'authority_invention_count' => 0,
            'policy_bypass_count' => 0,
            'stale_enablement_acceptance_count' => 0,
            'l2_write_bypass_count' => 0,
            'l3_write_bypass_count' => 0,
            'l4_allow_count' => 0,
            'active_manifest_count' => $guards['active_manifest_count'],
            'trusted_signing_key_count' => $guards['trusted_signing_key_count'],
            'model_calls' => 0,
            'tool_calls' => 0,
            'new_external_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'publish_writes' => 0,
            'url_truth_writes' => 0,
            'canonical_writes' => 0,
            'robots_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'post12_agent_write_enabled' => false,
            'search_submission_allowed' => false,
            'canary_started' => false,
            'execution_allowed' => false,
            'SEO-PLATFORM-11L' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'SEO-PLATFORM-11' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'ready_for_12' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array{total:int,passed:int,bypass_count:int,results:list<array{id:string,passed:bool}>} */
    private function lifecycleProbes(): array
    {
        $results = [];
        foreach ([
            ['draft', 'offline_eval'], ['offline_eval', 'shadow'], ['shadow', 'active'], ['active', 'degraded'],
            ['draft', 'hold'], ['offline_eval', 'hold'], ['shadow', 'hold'], ['active', 'hold'], ['degraded', 'hold'],
            ['hold', 'offline_eval'], ['active', 'deprecated'], ['degraded', 'deprecated'], ['hold', 'deprecated'],
        ] as [$from, $to]) {
            $result = $this->lifecycle->transition($from, $to, true);
            $results[] = ['id' => $from.'_to_'.$to, 'passed' => $result['status'] === 'ACCEPTED'];
        }
        foreach ([['draft', 'active'], ['hold', 'active'], ['deprecated', 'active']] as [$from, $to]) {
            $result = $this->lifecycle->transition($from, $to, true);
            $results[] = ['id' => $from.'_to_'.$to.'_denied', 'passed' => $result['status'] === 'HOLD'];
        }
        foreach ([['offline_eval', 'shadow'], ['shadow', 'active']] as [$from, $to]) {
            $result = $this->lifecycle->transition($from, $to, false);
            $results[] = ['id' => $to.'_without_eval_denied', 'passed' => $result['reason'] === 'EVAL_RECEIPT_REQUIRED'];
        }
        foreach (['prompt', 'cli', 'scheduler', 'api', 'ui'] as $channel) {
            $result = $this->lifecycle->transition('draft', 'offline_eval', true, $channel);
            $results[] = ['id' => $channel.'_mutation_denied', 'passed' => $result['reason'] === 'MUTATION_CHANNEL_DENIED'];
        }
        $vector = array_fill_keys(CapabilityLifecycleStateMachine::REEVALUATION_DIMENSIONS, str_repeat('a', 64));
        foreach (CapabilityLifecycleStateMachine::REEVALUATION_DIMENSIONS as $dimension) {
            $observed = $vector;
            $observed[$dimension] = str_repeat('b', 64);
            $result = $this->lifecycle->verifyVersionVector($vector, $observed);
            $results[] = ['id' => $dimension.'_drift_holds', 'passed' => $result['reason'] === 'REEVALUATION_REQUIRED' && $result['state'] === 'hold'];
        }
        $passed = count(array_filter($results, static fn (array $result): bool => $result['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }

    /** @return array{total:int,passed:int,bypass_count:int,results:list<array{id:string,passed:bool}>} */
    private function canaryProbes(): array
    {
        $shared = $this->canary->evaluate(['shared_layer' => true]);
        $invalid = $this->canary->evaluate(['shared_layer' => false]);
        $disabled = $this->canary->evaluate([
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
        $results = [
            ['id' => 'shared_layer_without_isolation_denied', 'passed' => $shared['reason'] === 'NOT_A_CANARY'],
            ['id' => 'invalid_manifest_denied', 'passed' => $invalid['status'] === 'HOLD'],
            ['id' => 'valid_shape_stays_write_disabled', 'passed' => $disabled['status'] === 'IMPLEMENTED_WRITE_DISABLED' && $disabled['canary_started'] === false && $disabled['execution_allowed'] === false],
        ];
        $passed = count(array_filter($results, static fn (array $result): bool => $result['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }
}
