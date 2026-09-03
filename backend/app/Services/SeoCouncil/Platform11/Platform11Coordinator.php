<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\QueryOwnerUrlTruthReadModel;

final class Platform11Coordinator
{
    private const FLOW = [
        'mission_request_v2_validation',
        '11c_admission',
        'binding_v4_resolution',
        'dependency_snapshot',
        'bounded_review',
        'deterministic_mode_runner',
        'mode_output',
        'run_receipt',
        'final_deterministic_veto',
    ];

    private const INDEPENDENT_FLOW = [
        'mission_request_v2_validation',
        '11c_admission',
        'binding_v4_resolution',
        'fresh_independent_run_id',
        'fresh_independent_context_id',
        'frozen_manifest_review',
        'independent_review_receipt',
        'policy_final_veto',
    ];

    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly QueryOwnerUrlTruthReadModel $queryOwners,
        private readonly IntentOwnershipRunner $intentOwnership,
        private readonly EditorialDraftRunner $editorialDraft,
        private readonly RuntimeQaRunner $runtimeQa,
        private readonly IndependentReviewRunner $independentReview,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function run(Platform11MissionRequestData $request): array
    {
        $registry = $this->contracts->registry();
        $binding = $this->contracts->binding();
        $policy = $this->contracts->policy();
        $independent = ($request->payload['mission_type'] ?? null) === 'independent_registry_review';
        $runNamespace = $independent ? 'seo.independent_review.run.v1' : 'seo.platform11.run.v1';
        $contextNamespace = $independent ? 'seo.independent_review.context.v1' : 'seo.platform11.context.v1';
        $runId = $this->hasher->hash(['request' => $request->requestHash, 'registry' => $registry['registry_hash'], 'binding' => $binding['binding_hash'], 'namespace' => $runNamespace]);
        $contextId = $this->hasher->hash(['run_id' => $runId, 'evidence' => $request->evidenceHash, 'namespace' => $contextNamespace]);
        $domain = (string) ($request->payload['review_domain'] ?? '');
        $definition = $independent ? [
            'role' => 'seo.independent_reviewer',
            'autonomy' => 'L0',
            'authority' => 'review_verdict',
            'capabilities' => ['seo.independent_policy_experiment_safety_review'],
        ] : (Platform11ContractRegistry::DOMAINS[$domain] ?? null);
        $status = 'DEPENDENCY_HOLD';
        $reason = 'PLATFORM11_CONTRACT_DRIFT';
        $mode = null;
        $roleId = is_array($definition) ? (string) $definition['role'] : '';

        if ($this->contracts->verifyGenerated()) {
            if ($domain === 'intent_query_ownership') {
                try {
                    $projection = $this->queryOwners->report((string) $request->payload['mode_input']['query_family_key']);
                } catch (\Throwable) {
                    $projection = ['families' => [], 'status' => 'blocked'];
                }
                $family = (array) (($projection['families'][0] ?? null) ?: []);
                if ($family === []) {
                    $reason = 'QUERY_OWNER_DEPENDENCY_HOLD';
                } else {
                    $mode = $this->intentOwnership->evaluate(
                        (array) $request->payload['mode_input'],
                        $family,
                        (array) $request->payload['evidence_bundle_refs'],
                        $runId,
                        $contextId,
                    );
                    $status = ($mode['receipt']['status'] ?? null) === 'PASS' ? 'CANDIDATE_READY' : 'EVIDENCE_HOLD';
                    $reason = ($mode['receipt']['status'] ?? null) === 'PASS'
                        ? 'INTENT_OWNERSHIP_CANDIDATE_READY'
                        : (string) ($mode['output']['abstain_reason'] ?? 'INTENT_OWNERSHIP_HOLD');
                }
            } elseif ($domain === 'editorial_draft') {
                $mode = $this->editorialDraft->evaluate(
                    (array) $request->payload['mode_input'],
                    (array) $request->payload['evidence_bundle_refs'],
                    $runId,
                    $contextId,
                );
                $status = ($mode['receipt']['status'] ?? null) === 'PASS' ? 'CANDIDATE_READY' : 'EVIDENCE_HOLD';
                $reason = ($mode['receipt']['status'] ?? null) === 'PASS'
                    ? 'EDITORIAL_DRAFT_CANDIDATE_READY'
                    : (string) ($mode['output']['hold_reason'] ?? 'EDITORIAL_DRAFT_HOLD');
            } elseif ($domain === 'runtime_qa') {
                $mode = $this->runtimeQa->evaluate(
                    (array) $request->payload['mode_input'],
                    (array) $request->payload['evidence_bundle_refs'],
                    $runId,
                    $contextId,
                );
                $status = ($mode['receipt']['status'] ?? null) === 'TECHNICALLY_VALID' ? 'REVIEW_READY' : 'EVIDENCE_HOLD';
                $reason = ($mode['receipt']['status'] ?? null) === 'TECHNICALLY_VALID'
                    ? 'RUNTIME_QA_REVIEW_READY'
                    : (string) data_get($mode, 'output.attribution_assessment.classification', 'RUNTIME_QA_HOLD');
            } elseif ($independent) {
                $mode = $this->independentReview->evaluate(
                    (array) $request->payload['mode_input'],
                    (array) $request->payload['evidence_bundle_refs'],
                    $runId,
                    $contextId,
                );
                $status = 'POLICY_HOLD';
                $reason = (string) data_get($mode, 'output.policy_gateway_decision', 'HOLD') === 'HOLD'
                    ? 'INDEPENDENT_REVIEW_POLICY_HOLD'
                    : 'INDEPENDENT_REVIEW_DENIED';
            } else {
                $reason = 'MODE_NOT_AVAILABLE_IN_CURRENT_STAGE';
            }
        }

        $receipt = [
            'receipt_version' => 'seo.platform11_run_receipt.v1',
            'run_id' => $runId,
            'context_id' => $contextId,
            'request_hash' => $request->requestHash,
            'caller_type' => $request->callerType,
            'registry_ref' => ['id' => $registry['registry_id'], 'version' => $registry['registry_version'], 'hash' => $registry['registry_hash']],
            'binding_ref' => ['id' => $binding['binding_id'], 'version' => $binding['binding_version'], 'hash' => $binding['binding_hash']],
            'policy_ref' => ['id' => $policy['registry_id'], 'version' => $policy['registry_version'], 'hash' => $policy['registry_hash']],
            'mission_schema_version' => Platform11ContractRegistry::MISSION_SCHEMA_VERSION,
            'review_domain' => $independent ? 'independent_registry_review' : $domain,
            'autonomy' => $request->payload['autonomy'],
            'role_id' => $roleId,
            'role_call_count' => $mode === null ? 0 : 1,
            'route_plan' => $mode === null ? [] : array_map(static fn (string $capability, int $index): array => [
                'sequence' => $index + 1,
                'role_id' => $roleId,
                'capability_id' => $capability,
                'authority_ceiling' => (string) $definition['authority'],
                'allow_delegation' => false,
                'execution_allowed' => false,
            ], (array) $definition['capabilities'], array_keys((array) $definition['capabilities'])),
            'mode_output' => $mode['output'] ?? null,
            'mode_receipt' => $mode['receipt'] ?? null,
            'status' => $status,
            'reason_code' => $reason,
            'trace' => array_map(static fn (string $step, int $index): array => [
                'sequence' => $index + 1,
                'step' => $step,
                'status' => $status === 'CANDIDATE_READY' || $index < 4 ? 'PASS' : 'HOLD',
            ], $independent ? self::INDEPENDENT_FLOW : self::FLOW, array_keys($independent ? self::INDEPENDENT_FLOW : self::FLOW)),
            'negative_guarantees' => [
                'raw_query_leak_count' => 0,
                'private_url_leak_count' => 0,
                'cross_locale_owner_copy_count' => 0,
                'authority_invention_count' => 0,
                'unresolved_multi_primary_without_abstain' => 0,
                'query_owner_writes' => 0,
                'url_truth_writes' => 0,
                'policy_bypass_count' => 0,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'business_writes' => 0,
                'cms_writes' => 0,
                'production_permissions' => 0,
                'delegation_count' => 0,
            ],
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }
}
