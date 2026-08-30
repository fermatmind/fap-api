<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Contracts\CouncilContractValidator;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\CouncilDependencySnapshotBuilder;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;
use App\Services\SeoCouncil\Measurement\MeasurementModeRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementRunner;
use App\Services\SeoCouncil\Measurement\MeasurementRuntimeGate;
use App\Services\SeoCouncil\Persistence\CouncilRunRepository;
use App\Services\SeoCouncil\Policy\CouncilAdmissionGateway;
use App\Services\SeoCouncil\Policy\CouncilAdmissionRequestFactory;
use App\Services\SeoCouncil\Routing\CouncilConflictResolver;
use App\Services\SeoCouncil\Routing\DeterministicMissionRouter;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisModeRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRunner;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisRuntimeGate;

final class SeoCouncilOrchestrator
{
    private const FLOW = [
        'validate_privacy_scan',
        'mission_request_validation',
        'admission_request_binding',
        '11c_admission',
        'dependency_snapshot',
        'runtime_capability_snapshot',
        'role_capability_binding',
        'evidence_context_verification',
        'technical_mode_selection',
        'handoff_generation',
        'deterministic_diagnosis',
        'independent_review_requirement',
        'final_policy_veto',
        'run_receipt',
    ];

    public function __construct(
        private readonly SeoRegistryHasher $hasher,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly CouncilDependencySnapshotBuilder $dependencies,
        private readonly RuntimeCapabilitySnapshotBuilder $runtime,
        private readonly MeasurementModeRegistry $measurementMode,
        private readonly MeasurementRunner $measurementRunner,
        private readonly MeasurementRuntimeGate $measurementGate,
        private readonly TechnicalDiagnosisModeRegistry $technicalMode,
        private readonly TechnicalDiagnosisRunner $technicalRunner,
        private readonly TechnicalDiagnosisRuntimeGate $technicalGate,
        private readonly PolicyGatewayRegistry $policy,
        private readonly CouncilAdmissionGateway $admissionGateway,
        private readonly CouncilAdmissionRequestFactory $admissionRequests,
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

        $registry = $this->roles->registry();
        $binding = $this->binding->reference();
        $runId = $this->runId($request, $registry, $binding);
        $releaseSha = $this->releaseSha();
        $runtime = $this->runtime->snapshot();
        $technicalRuntime = $this->technicalMode->capabilitySnapshot();
        $measurementRuntime = $this->measurementMode->capabilitySnapshot();
        $baseContext = [
            'registry' => $registry,
            'binding' => $binding,
            'runtime' => $runtime,
            'technical_runtime' => $technicalRuntime,
            'measurement_runtime' => $measurementRuntime,
        ];

        $admission = $this->admissionGateway->admission(
            $request->callerType,
            $this->admissionRequests->make($request),
        );
        $baseContext['admission'] = $admission;
        $decision = (string) ($admission['decision'] ?? 'DENY');
        if ($decision === 'DENY' || $decision === 'HOLD') {
            $reasonCodes = array_values(array_filter((array) ($admission['reason_codes'] ?? []), 'is_string'));

            return $this->terminalReceipt(
                $request,
                'POLICY_HOLD',
                $reasonCodes[0] ?? 'POLICY_DECISION_INVALID',
                [],
                [],
                $baseContext,
            );
        }
        if ($decision !== 'ALLOW' || ($admission['execution_allowed'] ?? null) !== false) {
            return $this->terminalReceipt($request, 'POLICY_HOLD', 'POLICY_DECISION_INVALID', [], [], $baseContext);
        }

        $dependency = $this->dependencies->snapshot($releaseSha);
        $baseContext['dependency'] = $dependency;
        if ($dependency['status'] !== 'READY') {
            return $this->terminalReceipt($request, 'DEPENDENCY_HOLD', 'dependency_hash_or_version_hold', [], [], $baseContext);
        }

        $resume = $request->payload['resume_from'];
        if (is_array($resume) && ! $this->repository->resumeValid((string) $resume['receipt_hash'], (string) $resume['step_hash'])) {
            return $this->terminalReceipt($request, 'STALE_RESUME_HOLD', 'resume_hash_verification_failed', [], [], $baseContext);
        }

        $route = $this->router->route($request);
        $routePlan = $this->routePlan($request, $route['roles'], $runId);
        $conflicts = $this->evidenceConflicts($request, $runId);

        $technicalMission = $request->payload['mission_type'] === 'bounded_review'
            && $request->payload['review_domain'] === 'technical';
        $measurementMission = $request->payload['mission_type'] === 'bounded_review'
            && in_array($request->payload['review_domain'], ['analytics', 'cro'], true);
        $status = $measurementMission ? 'MEASUREMENT_HOLD' : 'SOURCE_CAPABILITY_UNAVAILABLE';
        $stopReason = match (true) {
            $technicalMission => 'technical_diagnosis_production_disabled',
            $measurementMission => 'measurement_mode_offline_eval_only',
            default => '11g_to_11j_modes_unavailable',
        };
        if (in_array($route['status'], ['ROUTING_SCOPE_HOLD', 'MISSION_SCOPE_HOLD', 'REQUESTED_ROLE_EXPANSION_HOLD'], true)) {
            $status = $route['status'];
            $stopReason = 'deterministic_route_scope_hold';
        } elseif ($route['status'] === 'EVIDENCE_HOLD') {
            $status = 'EVIDENCE_HOLD';
            $stopReason = 'required_binding_evidence_missing';
        } elseif ($this->admissionRequests->evidenceStatus($request) !== 'READY') {
            $status = $this->admissionRequests->evidenceStatus($request);
            $stopReason = 'evidence_context_not_ready';
        } elseif ($conflicts !== []) {
            $status = 'unresolved_conflict';
            $stopReason = 'authority_or_evidence_conflict';
        } elseif ($request->payload['mission_type'] === 'career_candidate_generation'
            && $runtime['career_runtime'] !== 'available') {
            $status = 'SOURCE_CAPABILITY_UNAVAILABLE';
            $stopReason = 'career_manifest_validator_risk_open';
        } elseif ($technicalMission && $this->technicalGate->allows($technicalRuntime)) {
            $handoff = $routePlan[0] ?? null;
            if (! is_array($handoff) || ($handoff['kind'] ?? null) !== 'role_handoff') {
                $status = 'EVIDENCE_HOLD';
                $stopReason = 'technical_handoff_missing';
            } else {
                $output = $this->technicalRunner->run($request, $handoff, $releaseSha, 'production_runtime');
                if (! $this->acceptModeOutput(
                    $output,
                    (string) ($handoff['handoff_hash'] ?? ''),
                    (string) ($handoff['target_role_id'] ?? ''),
                )) {
                    $status = 'EVIDENCE_HOLD';
                    $stopReason = 'technical_mode_output_contract_hold';
                } else {
                    $routePlan[] = ['kind' => 'mode_output', ...$output];
                    $status = ($output['status'] ?? null) === 'PASS' ? 'TECHNICAL_DIAGNOSIS_READY' : 'EVIDENCE_HOLD';
                    $stopReason = ($output['status'] ?? null) === 'PASS'
                        ? 'technical_diagnosis_completed'
                        : (string) ($output['summary_code'] ?? 'technical_diagnosis_hold');
                }
            }
        } elseif ($measurementMission && $this->measurementGate->allows($measurementRuntime)) {
            $handoff = $routePlan[0] ?? null;
            if (! is_array($handoff) || ($handoff['kind'] ?? null) !== 'role_handoff') {
                $status = 'MEASUREMENT_HOLD';
                $stopReason = 'measurement_handoff_missing';
            } else {
                $output = $this->measurementRunner->run($request, $handoff, $releaseSha, 'production_runtime');
                if (! $this->acceptModeOutput(
                    $output,
                    (string) ($handoff['handoff_hash'] ?? ''),
                    (string) ($handoff['target_role_id'] ?? ''),
                )) {
                    $status = 'MEASUREMENT_HOLD';
                    $stopReason = 'measurement_mode_output_contract_hold';
                } else {
                    $routePlan[] = ['kind' => 'mode_output', ...$output];
                    $status = ($output['status'] ?? null) === 'PASS' ? 'MEASUREMENT_READY' : 'MEASUREMENT_HOLD';
                    $stopReason = ($output['status'] ?? null) === 'PASS'
                        ? 'measurement_completed'
                        : (string) ($output['summary_code'] ?? 'measurement_hold');
                }
            }
        }

        return $this->terminalReceipt(
            $request,
            $status,
            $stopReason,
            $routePlan,
            $conflicts,
            [
                ...$baseContext,
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
            'human_decision_required' => $status === 'unresolved_conflict'
                || ($status === 'POLICY_HOLD'
                    && (($context['admission']['human_decision_required'] ?? null)
                        ?? (($context['admission']['decision'] ?? null) === 'HOLD'))),
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
            'ROUTING_SCOPE_HOLD', 'MISSION_SCOPE_HOLD', 'REQUESTED_ROLE_EXPANSION_HOLD' => 'role_capability_binding',
            'EVIDENCE_HOLD', 'MEASUREMENT_HOLD' => 'evidence_context_verification',
            'unresolved_conflict' => 'evidence_context_verification',
            'STALE_RESUME_HOLD' => 'validate_privacy_scan',
            'TECHNICAL_DIAGNOSIS_READY', 'MEASUREMENT_READY' => 'run_receipt',
            default => 'technical_mode_selection',
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
                'status' => $stopped ? 'NOT_RUN' : ($isStop ? $this->stopStepStatus($status, $context) : 'PASS'),
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

    /** @param array<string, mixed>|null $context */
    private function stopStepStatus(string $status, ?array $context): string
    {
        if ($status === 'POLICY_HOLD') {
            $decision = (string) ($context['admission']['decision'] ?? 'HOLD');

            return in_array($decision, ['DENY', 'HOLD'], true) ? $decision : 'HOLD';
        }

        if (in_array($status, ['TECHNICAL_DIAGNOSIS_READY', 'MEASUREMENT_READY'], true)) {
            return 'PASS';
        }

        return $status;
    }

    /** @param list<string> $roles @return list<array<string, mixed>> */
    private function routePlan(MissionRequestData $request, array $roles, string $runId): array
    {
        $mission = $this->binding->mission((string) $request->payload['mission_type']);
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
                'stop_conditions' => $mission['stop_conditions'],
                'previous_handoff_hash' => $previousHandoff,
                'previous_output_hash' => null,
                'previous_output_required' => $previousHandoff !== null,
            ];
            $handoff['handoff_hash'] = $this->hasher->hash($handoff);
            $plan[] = ['kind' => 'role_handoff', ...$handoff];
            $previousHandoff = $handoff['handoff_hash'];
        }
        if (is_array($mission['deterministic_compile'] ?? null) && $roles !== []) {
            $compile = $mission['deterministic_compile'];
            $plan[] = [
                'kind' => 'deterministic_dry_compile',
                'operation' => $compile['operation'],
                'previous_handoff_hash' => $previousHandoff,
                'consumes_previous_output_hash' => $compile['consumes_previous_output_hash'],
                'write_current' => $compile['write_current'],
                'gates' => $compile['gates'],
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
