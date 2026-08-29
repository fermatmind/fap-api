<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class AdmissionPolicy
{
    public function __construct(
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly PolicyGatewayContractValidator $contracts,
        private readonly PolicyGatewayRegistry $gatewayRegistry,
        private readonly SeoRoleCapabilityRegistry $roleRegistry,
        private readonly PageFamilyPolicyRegistry $families,
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly PolicyDecisionFactory $decisions,
    ) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function decide(array $request, ?string $boundCallerType = null): array
    {
        if ($this->privacy->containsPrivateData($request)) {
            return $this->deny(['PRIVATE_DATA_DENIED']);
        }
        if (! $this->contracts->admission($request)) {
            return $this->deny(['REQUEST_CONTRACT_INVALID']);
        }

        $roleId = (string) $request['requested_role_id'];
        $scope = [
            'family' => (string) $request['family'],
            'locale' => (string) $request['locale'],
            'action' => null,
        ];
        if ($boundCallerType !== null && $request['caller_type'] !== $boundCallerType) {
            return $this->deny(['CALLER_BINDING_MISMATCH'], $roleId, $scope);
        }
        if ($this->gatewayRegistry->dependencyStatus() !== 'READY') {
            return $this->hold(['DEPENDENCY_HOLD'], $roleId, $scope);
        }

        $registry = $this->roleRegistry->registry();
        $role = collect((array) ($registry['roles'] ?? []))->firstWhere('role_id', $roleId);
        if (! is_array($role)) {
            return $this->deny(['ROLE_UNKNOWN'], 'role:withheld', $scope);
        }
        if (! in_array($request['mission_type'], (array) ($role['allowed_missions'] ?? []), true)
            || ! in_array($request['family'], (array) ($role['page_family_scope'] ?? []), true)
            || ! in_array($request['locale'], (array) ($role['locale_scope'] ?? []), true)) {
            return $this->deny(['ROLE_SCOPE_EXPANSION_DENIED'], $roleId, $scope);
        }
        if ($request['autonomy'] === 'L4') {
            return $this->deny(['L4_DORMANT_NOT_AUTHORIZED'], $roleId, $scope);
        }
        if ($request['autonomy'] !== 'L0') {
            return $this->deny(['AUTONOMY_SCOPE_EXPANSION_DENIED'], $roleId, $scope);
        }
        if ($request['tool_scope'] !== []) {
            return $this->deny(['TOOL_SCOPE_DENIED'], $roleId, $scope);
        }
        if ($request['egress_scope'] !== []) {
            return $this->deny(['EGRESS_SCOPE_DENIED'], $roleId, $scope);
        }

        $budget = (array) $request['budget'];
        if ($budget['model_calls'] > (int) ($role['max_model_calls'] ?? 0)
            || $budget['tool_calls'] > (int) ($role['max_tool_calls'] ?? 0)
            || $budget['execution_seconds'] > (int) ($role['max_execution_seconds'] ?? 0)
            || (float) $budget['cost_amount'] > (float) ($role['max_cost']['amount'] ?? 0)
            || $request['deadline_seconds'] > (int) ($role['max_execution_seconds'] ?? 0)) {
            return $this->deny(['BUDGET_OR_DEADLINE_EXPANSION_DENIED'], $roleId, $scope);
        }

        $family = $this->families->families()[$request['family']] ?? null;
        if (! is_array($family) || ! $this->riskAllowed((string) $request['claim_risk'], (string) ($family['agent_risk_cap'] ?? 'forbidden'))) {
            return $this->deny(['CLAIM_RISK_SCOPE_DENIED'], $roleId, $scope);
        }

        $context = (array) $request['evidence_context'];
        $contextHash = (string) $context['context_hash'];
        if (! hash_equals($this->hasher->hashWithout($context, 'context_hash'), $contextHash)
            || $context['mission_id'] !== $request['mission_id']
            || $context['mission_type'] !== $request['mission_type']
            || $context['role_id'] !== $roleId
            || $context['page_family'] !== $request['family']
            || $context['locale'] !== $request['locale']) {
            return $this->deny(['EVIDENCE_CONTEXT_FORGED_OR_MISMATCHED'], $roleId, $scope);
        }
        try {
            $now = CarbonImmutable::now('UTC');
            $builtAt = CarbonImmutable::parse((string) $context['built_at'])->utc();
            $expiresAt = CarbonImmutable::parse((string) $context['expires_at'])->utc();
            if ($builtAt->isFuture() || ! $expiresAt->isAfter($now)) {
                return $this->deny(['EVIDENCE_CONTEXT_EXPIRED'], $roleId, $scope);
            }
        } catch (Throwable) {
            return $this->deny(['EVIDENCE_CONTEXT_TIME_INVALID'], $roleId, $scope);
        }

        if (($context['status'] ?? null) !== 'READY') {
            return $this->hold([(string) $context['status']], $roleId, $scope);
        }
        if ((int) ($context['evidence_summary']['bundle_count'] ?? 0) < 1) {
            return $this->hold(['EVIDENCE_INSUFFICIENT'], $roleId, $scope);
        }
        $states = (array) ($context['source_capability_states'] ?? []);
        if ($states === [] || array_diff($states, ['available', 'degraded']) !== []) {
            return $this->hold(['SOURCE_CAPABILITY_UNAVAILABLE'], $roleId, $scope);
        }

        return $this->hold(['ROLE_CAPABILITY_BINDING_UNAVAILABLE'], $roleId, $scope);
    }

    private function riskAllowed(string $requested, string $cap): bool
    {
        $levels = ['R1' => 1, 'R2' => 2, 'R3' => 3, 'R4' => 4];
        $capLevel = match ($cap) {
            'L1' => 1,
            'L2' => 2,
            'L3' => 3,
            'L4' => 4,
            default => 0,
        };

        return isset($levels[$requested]) && $levels[$requested] <= $capLevel;
    }

    /** @param list<string> $reasons @param array{family:string,locale:string,action:?string} $scope @return array<string, mixed> */
    private function deny(array $reasons, string $roleId = 'role:withheld', array $scope = ['family' => 'family:withheld', 'locale' => 'und', 'action' => null]): array
    {
        return $this->decisions->make('admission', 'DENY', $reasons, $roleId, $scope);
    }

    /** @param list<string> $reasons @param array{family:string,locale:string,action:?string} $scope @return array<string, mixed> */
    private function hold(array $reasons, string $roleId, array $scope): array
    {
        return $this->decisions->make('admission', 'HOLD', $reasons, $roleId, $scope);
    }
}
