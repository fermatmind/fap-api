<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\CouncilDependencySnapshotBuilder;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Persistence\CouncilRunRepository;
use App\Services\SeoCouncil\Routing\CouncilConflictResolver;
use App\Services\SeoCouncil\Routing\DeterministicMissionRouter;
use Carbon\CarbonImmutable;

final class SeoCouncilOrchestrator
{
    private const FLOW = [
        'validate_privacy_scan',
        'dependency_snapshot',
        'runtime_capability_snapshot',
        '11c_admission',
        'deterministic_mission_classification',
        'role_capability_binding',
        'evidence_context_verification',
        'fixed_flow_planning',
        'handoff_generation',
        'mode_availability_check',
        'conflict_resolution',
        'independent_review_requirement',
        'final_policy_veto',
        'run_receipt',
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly SeoEvidenceCanonicalHasher $evidenceHasher,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly CouncilDependencySnapshotBuilder $dependencies,
        private readonly RuntimeCapabilitySnapshotBuilder $runtime,
        private readonly PolicyGatewayRegistry $policy,
        private readonly PolicyGatewayCallerGuard $callers,
        private readonly DeterministicMissionRouter $router,
        private readonly CouncilConflictResolver $conflicts,
        private readonly CouncilContractValidator $contracts,
        private readonly CouncilRunRepository $repository,
    ) {}

    /** @return array<string, mixed> */
    public function run(MissionRequestData $request): array
    {
        $existing = $this->repository->findByIdempotencyKey($request->idempotencyKey());
        if (is_array($existing)) {
            return hash_equals((string) $existing['request_hash'], $request->requestHash)
                ? $existing
                : $this->idempotencyConflict($request, $existing);
        }

        $resume = $request->payload['resume_from'];
        if (is_array($resume) && ! $this->repository->resumeValid((string) $resume['receipt_hash'], (string) $resume['step_hash'])) {
            return $this->terminalReceipt($request, 'STALE_RESUME_HOLD', 'resume_hash_verification_failed', [], [], null);
        }

        $registry = $this->roles->registry();
        $binding = $this->binding->reference();
        $runId = $this->runId($request, $registry, $binding);
        $releaseSha = $this->releaseSha();
        $dependency = $this->dependencies->snapshot($releaseSha);
        $runtime = $this->runtime->snapshot();
        $route = $this->router->route($request);
        $admission = $this->callers->admission(
            $request->callerType,
            $this->admissionRequest($request),
        );
        $routePlan = $this->routePlan($request, $route['roles'], $runId);
        $conflicts = $this->evidenceConflicts($request, $runId);

        $status = 'SOURCE_CAPABILITY_UNAVAILABLE';
        $stopReason = '11e_to_11j_modes_unavailable';
        if (($admission['decision'] ?? null) === 'DENY') {
            $status = 'POLICY_HOLD';
            $stopReason = '11c_admission_denied';
        } elseif ($dependency['status'] !== 'READY') {
            $status = 'DEPENDENCY_HOLD';
            $stopReason = 'dependency_hash_or_version_hold';
        } elseif (in_array($route['status'], ['ROUTING_SCOPE_HOLD', 'MISSION_SCOPE_HOLD'], true)) {
            $status = $route['status'];
            $stopReason = 'deterministic_route_scope_hold';
        } elseif ($this->evidenceStatus($request) !== 'READY') {
            $status = $this->evidenceStatus($request);
            $stopReason = 'evidence_context_not_ready';
        } elseif ($conflicts !== []) {
            $status = 'unresolved_conflict';
            $stopReason = 'authority_or_evidence_conflict';
        } elseif ($request->payload['mission_type'] === 'career_candidate_generation'
            && $runtime['career_runtime'] !== 'available') {
            $status = 'SOURCE_CAPABILITY_UNAVAILABLE';
            $stopReason = 'career_manifest_validator_risk_open';
        }

        return $this->terminalReceipt(
            $request,
            $status,
            $stopReason,
            $routePlan,
            $conflicts,
            [
                'registry' => $registry,
                'binding' => $binding,
                'dependency' => $dependency,
                'runtime' => $runtime,
                'admission' => $admission,
                'route' => $route,
            ],
        );
    }

    /** @param array<string, mixed> $output */
    public function acceptModeOutput(array $output, string $handoffHash, string $roleId): bool
    {
        return $this->contracts->modeOutput($output, $handoffHash, $roleId);
    }

    /** @param list<array<string, mixed>> $routePlan @param list<array<string, mixed>> $conflicts @param null|array<string, mixed> $context @return array<string, mixed> */
    private function terminalReceipt(
        MissionRequestData $request,
        string $status,
        string $stopReason,
        array $routePlan,
        array $conflicts,
        ?array $context,
    ): array {
        $registry = $context['registry'] ?? $this->roles->registry();
        $binding = $context['binding'] ?? $this->binding->reference();
        $policy = $this->policy->registry();
        $runId = $this->runId($request, $registry, $binding);
        $steps = $this->steps($runId, $request, $status, $stopReason, $context);
        $resume = $request->payload['resume_from'];
        $receiptVersion = is_array($resume) ? 2 : 1;
        $receipt = [
            'receipt_id' => $this->hasher->hash([$runId, $receiptVersion]),
            'receipt_version' => $receiptVersion,
            'run_id' => $runId,
            'request_hash' => $request->requestHash,
            'caller_provenance' => [
                'caller_type' => $request->callerType,
                'provenance_hash' => $this->hasher->hash([$request->callerType, $request->requestHash]),
            ],
            'registry_ref' => [
                'id' => $registry['registry_id'],
                'version' => $registry['registry_version'],
                'hash' => $registry['registry_hash'],
            ],
            'binding_ref' => $binding,
            'evidence_hash' => $request->evidenceHash,
            'policy_ref' => [
                'id' => $policy['registry_id'],
                'version' => $policy['registry_version'],
                'hash' => $policy['registry_hash'],
            ],
            'status' => $status,
            'stop_reason' => $stopReason,
            'route_plan' => $routePlan,
            'steps' => $steps,
            'conflicts' => $conflicts,
            'trace' => array_map(static fn (array $step): array => [
                'sequence' => $step['sequence'],
                'step_hash' => $step['step_hash'],
                'status' => $step['status'],
                'summary_code' => $step['summary_code'],
                'count' => $step['count'],
            ], $steps),
            'negative_guarantees' => [
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'agent_write_permissions' => 0,
                'business_writes' => 0,
                'cms_writes' => 0,
                'url_truth_writes' => 0,
                'search_submissions' => 0,
                'career_current_writes' => 0,
                'external_trace_export' => false,
                'shared_agent_memory' => false,
                'peer_delegation' => false,
                'active_manifests' => 0,
                'trusted_signing_keys' => 0,
            ],
            'human_decision_required' => $status === 'unresolved_conflict',
            'execution_allowed' => false,
            'supersedes_receipt_hash' => is_array($resume) ? $resume['receipt_hash'] : null,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);
        if ($status !== 'IDEMPOTENCY_CONFLICT') {
            $this->repository->persist($receipt, $request->idempotencyKey());
        }

        return $receipt;
    }

    /** @param array<string, mixed> $context @return list<array<string, mixed>> */
    private function steps(string $runId, MissionRequestData $request, string $status, string $stopReason, ?array $context): array
    {
        $registryHash = (string) (($context['registry']['registry_hash'] ?? null) ?: $this->roles->registry()['registry_hash']);
        $bindingHash = (string) (($context['binding']['hash'] ?? null) ?: $this->binding->reference()['hash']);
        $policy = $this->policy->registry();
        $stopStep = match ($status) {
            'DEPENDENCY_HOLD' => 'dependency_snapshot',
            'POLICY_HOLD' => '11c_admission',
            'ROUTING_SCOPE_HOLD', 'MISSION_SCOPE_HOLD' => 'deterministic_mission_classification',
            'EVIDENCE_HOLD', 'MEASUREMENT_HOLD' => 'evidence_context_verification',
            'unresolved_conflict' => 'conflict_resolution',
            'STALE_RESUME_HOLD' => 'validate_privacy_scan',
            default => 'mode_availability_check',
        };
        $stopped = false;
        $steps = [];
        foreach (self::FLOW as $index => $type) {
            $isStop = $type === $stopStep;
            $step = [
                'step_id' => $this->hasher->hash([$runId, $index + 1, $type]),
                'run_id' => $runId,
                'sequence' => $index + 1,
                'step_type' => $type,
                'request_hash' => $request->requestHash,
                'registry_hash' => $registryHash,
                'binding_hash' => $bindingHash,
                'evidence_hash' => $request->evidenceHash,
                'policy_revision' => ['version' => $policy['registry_version'], 'hash' => $policy['registry_hash']],
                'status' => $stopped ? 'NOT_RUN' : ($isStop ? $status : 'PASS'),
                'stop_reason' => $isStop ? $stopReason : null,
                'summary_code' => $stopped ? 'terminal_stop' : ($isStop ? $stopReason : 'deterministic_pass'),
                'count' => 0,
            ];
            $step['step_hash'] = $this->hasher->hash($step);
            $steps[] = $step;
            $stopped = $stopped || $isStop;
        }

        return $steps;
    }

    /** @param list<string> $roles @return list<array<string, mixed>> */
    private function routePlan(MissionRequestData $request, array $roles, string $runId): array
    {
        $plan = [];
        $previousHandoff = null;
        foreach ($roles as $index => $roleId) {
            $handoff = [
                'handoff_id' => $this->hasher->hash([$runId, $index + 1, $roleId]),
                'run_id' => $runId,
                'sequence' => $index + 1,
                'target_role_id' => $roleId,
                'target_role_version' => $this->binding->roleVersion($roleId),
                'scope' => [
                    'mission_type' => $request->payload['mission_type'],
                    'family' => $request->payload['family'],
                    'locale' => $request->payload['locale'],
                    'capabilities' => $this->binding->capabilitiesFor($roleId),
                ],
                'evidence_context_hash' => $request->evidenceHash,
                'budget' => $request->payload['budget'],
                'stop_conditions' => ['WARN', 'BLOCKED', 'HOLD', 'EVIDENCE_MISSING', 'AUTHORITY_DRIFT'],
                'previous_handoff_hash' => $previousHandoff,
                'previous_output_hash' => null,
                'previous_output_required' => $previousHandoff !== null,
            ];
            $handoff['handoff_hash'] = $this->hasher->hash($handoff);
            $plan[] = ['kind' => 'role_handoff', ...$handoff];
            $previousHandoff = $handoff['handoff_hash'];
        }
        if ($request->payload['mission_type'] === 'career_candidate_generation' && $roles !== []) {
            $plan[] = [
                'kind' => 'deterministic_dry_compile',
                'operation' => 'career_ten_block_current_package_dry_compile',
                'previous_handoff_hash' => $previousHandoff,
                'consumes_previous_output_hash' => true,
                'write_current' => false,
                'gates' => ['claim', 'locale', 'seo', 'duplicate', 'material'],
                'execution_allowed' => false,
            ];
        }

        return $plan;
    }

    /** @return list<array<string, mixed>> */
    private function evidenceConflicts(MissionRequestData $request, string $runId): array
    {
        $revisions = array_values(array_unique(array_column((array) $request->payload['evidence_bundle_refs'], 'authority_revision')));
        if (count($revisions) <= 1) {
            return [];
        }

        return [$this->conflicts->resolve($runId, 'claim_evidence', 'claim_evidence', false, true)];
    }

    private function evidenceStatus(MissionRequestData $request): string
    {
        $refs = (array) $request->payload['evidence_bundle_refs'];
        if ($refs === []) {
            return 'EVIDENCE_HOLD';
        }
        foreach ($refs as $ref) {
            if (($ref['status'] ?? null) !== 'READY') {
                return (string) $ref['status'];
            }
        }

        return 'READY';
    }

    /** @return array<string, mixed> */
    private function admissionRequest(MissionRequestData $request): array
    {
        $now = CarbonImmutable::now('UTC');
        $roleId = $this->policyAdmissionRole($request);
        $status = $this->evidenceStatus($request);
        $refs = array_map(static fn (array $ref): array => [
            'bundle_id' => $ref['bundle_id'],
            'bundle_version' => $ref['bundle_version'],
            'bundle_hash' => $ref['bundle_hash'],
        ], (array) $request->payload['evidence_bundle_refs']);
        $revisions = array_values(array_unique(array_column((array) $request->payload['evidence_bundle_refs'], 'authority_revision')));
        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => $this->evidenceHasher->hash([$request->requestHash, $roleId]),
            'context_version' => 1,
            'mission_id' => $request->missionId(),
            'mission_type' => $request->payload['mission_type'],
            'role_id' => $roleId,
            'page_family' => $request->payload['family'],
            'locale' => $request->payload['locale'],
            'built_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
            'bundle_refs' => $refs,
            'source_capability_states' => $refs === [] ? ['unavailable'] : ['available'],
            'evidence_summary' => ['bundle_count' => count($refs), 'private_data_present' => false],
            'payload' => ['revision_hash' => $revisions[0] ?? str_repeat('0', 64)],
            'status' => in_array($status, ['READY', 'EVIDENCE_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE', 'MEASUREMENT_HOLD'], true) ? $status : 'EVIDENCE_HOLD',
            'execution_allowed' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'write_permissions' => [],
            'tool_allowlist' => [],
            'egress_allowlist' => [],
        ];
        $context['context_hash'] = $this->evidenceHasher->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1',
            'caller_type' => $request->callerType,
            'mission_id' => $request->missionId(),
            'mission_type' => $request->payload['mission_type'],
            'requested_role_id' => $roleId,
            'family' => $request->payload['family'],
            'locale' => $request->payload['locale'],
            'claim_risk' => 'R1',
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'execution_seconds' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'deadline_seconds' => 0,
            'tool_scope' => [],
            'egress_scope' => [],
            'evidence_context' => $context,
            'request_metadata' => ['source_label' => 'seo-council', 'correlation_hash' => $request->requestHash],
        ];
    }

    private function policyAdmissionRole(MissionRequestData $request): string
    {
        return match ($request->payload['mission_type']) {
            'bounded_review' => match ($request->payload['review_domain']) {
                'technical' => 'seo.expert.technical_search_authority',
                'analytics' => 'seo.expert.search_analytics_measurement',
                'content' => 'seo.expert.content_entity_quality',
                'competitor' => 'seo.expert.competitor_research',
                'stability' => 'seo.expert.public_content_stability',
                'cro' => 'seo.expert.commercial_funnel_cro',
            },
            'independent_registry_review' => 'seo.independent_reviewer',
            'career_candidate_generation' => 'career.content_agent',
            default => 'seo.orchestrator',
        };
    }

    /** @param array<string, mixed> $existing @return array<string, mixed> */
    private function idempotencyConflict(MissionRequestData $request, array $existing): array
    {
        return $this->terminalReceipt(
            $request,
            'IDEMPOTENCY_CONFLICT',
            'idempotency_key_payload_mismatch',
            [],
            [],
            null,
        );
    }

    /** @param array<string, mixed> $registry @param array<string, mixed> $binding */
    private function runId(MissionRequestData $request, array $registry, array $binding): string
    {
        return $this->hasher->hash([
            'request_hash' => $request->requestHash,
            'registry_hash' => $registry['registry_hash'],
            'binding_hash' => $binding['hash'],
        ]);
    }

    private function releaseSha(): string
    {
        $revision = dirname(base_path()).'/REVISION';
        if (is_file($revision)) {
            $sha = strtolower(trim((string) file_get_contents($revision)));
            if (preg_match('/^[a-f0-9]{40}$/D', $sha) === 1) {
                return $sha;
            }
        }

        return (string) config('seo_council.baseline_sha', str_repeat('0', 40));
    }
}
