<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class ExecutionPolicy
{
    public function __construct(
        private readonly PolicyGatewayPrivacyGuard $privacy,
        private readonly PolicyGatewayContractValidator $contracts,
        private readonly AdmissionPolicy $admission,
        private readonly ActionManifestVerifier $manifests,
        private readonly PolicyGatewayRegistry $registry,
        private readonly PageFamilyPolicyRegistry $families,
        private readonly PolicyDecisionFactory $decisions,
    ) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function decide(array $request, ?string $boundCallerType = null): array
    {
        if ($this->privacy->containsPrivateData($request)) {
            return $this->deny(['PRIVATE_DATA_DENIED']);
        }
        if (! $this->contracts->execution($request)) {
            return $this->deny(['EXECUTION_REQUEST_CONTRACT_INVALID']);
        }

        $scopeInput = (array) $request['action_scope'];
        $scope = [
            'family' => (string) $scopeInput['family'],
            'locale' => (string) $scopeInput['locale'],
            'action' => (string) $scopeInput['action'],
        ];
        $roleId = is_string($request['admission_request']['requested_role_id'] ?? null)
            ? (string) $request['admission_request']['requested_role_id']
            : 'role:withheld';
        if ($boundCallerType !== null && $request['caller_type'] !== $boundCallerType) {
            return $this->deny(['CALLER_BINDING_MISMATCH'], $roleId, $scope);
        }

        $admission = $this->admission->decide((array) $request['admission_request'], (string) $request['caller_type']);
        if (($admission['decision'] ?? null) === 'DENY') {
            return $this->deny((array) ($admission['reason_codes'] ?? ['ADMISSION_DENIED']), $roleId, $scope);
        }

        $manifest = (array) $request['manifest'];
        $verification = $this->manifests->verify($manifest);
        if (! $verification['valid']) {
            return $verification['code'] === 'DEPENDENCY_HOLD'
                ? $this->hold(['DEPENDENCY_HOLD'], $roleId, $scope)
                : $this->deny([$verification['code']], $roleId, $scope);
        }

        $denials = [];
        $holds = array_values((array) ($admission['reason_codes'] ?? []));
        if ($manifest['role_id'] !== ($request['admission_request']['requested_role_id'] ?? null)) {
            $denials[] = 'MANIFEST_ROLE_BINDING_MISMATCH';
        }
        if ($manifest['mission_type'] !== ($request['admission_request']['mission_type'] ?? null)) {
            $denials[] = 'MANIFEST_MISSION_BINDING_MISMATCH';
        }
        if ($manifest['autonomy'] !== ($request['admission_request']['autonomy'] ?? null)) {
            $denials[] = 'MANIFEST_AUTONOMY_BINDING_MISMATCH';
        }
        if ($manifest['family'] !== $scopeInput['family']
            || $manifest['locale'] !== $scopeInput['locale']
            || $manifest['action'] !== $scopeInput['action']) {
            $denials[] = 'ACTION_SCOPE_MISMATCH';
        }
        $catalog = $this->registry->fieldCatalog();
        $globalForbidden = (array) ($catalog['global_forbidden_fields'] ?? []);
        $actualFields = (array) $scopeInput['fields'];
        if (array_diff($actualFields, (array) $manifest['allowed_fields']) !== []
            || array_intersect($actualFields, [...(array) $manifest['forbidden_fields'], ...$globalForbidden]) !== []) {
            $denials[] = 'ACTION_FIELD_SCOPE_DENIED';
        }
        $urlCount = (int) $scopeInput['url_count'];
        if ($urlCount <= 0 || $urlCount > (int) $manifest['max_urls'] || $urlCount > $this->canaryMaximum((string) $scopeInput['family'])) {
            $denials[] = 'URL_COUNT_DENIED';
        }
        if ($scopeInput['claim_risk'] !== ($request['admission_request']['claim_risk'] ?? null)) {
            $denials[] = 'CLAIM_RISK_MISMATCH';
        }
        if (! $scopeInput['rollback_ready'] || $scopeInput['rollback_unit'] !== $manifest['rollback_unit']) {
            $denials[] = 'ROLLBACK_NOT_READY';
        }
        if ($scopeInput['authority_revision'] !== $manifest['authority_revision']
            || ($request['admission_request']['evidence_context']['payload']['revision_hash'] ?? null) !== $manifest['authority_revision']) {
            $denials[] = 'AUTHORITY_REVISION_MISMATCH';
        }
        if ($scopeInput['measurement_state'] !== 'READY'
            || ($request['admission_request']['evidence_context']['status'] ?? null) !== 'READY') {
            $holds[] = 'MEASUREMENT_HOLD';
        }
        $minimumBundles = (int) $manifest['evidence_threshold']['minimum_bundle_count'];
        $context = (array) $request['admission_request']['evidence_context'];
        if (count((array) ($context['bundle_refs'] ?? [])) < $minimumBundles
            || (int) ($context['evidence_summary']['bundle_count'] ?? 0) < $minimumBundles) {
            $holds[] = 'EVIDENCE_THRESHOLD_UNMET';
        }
        if ($manifest['canary_stage'] !== $scopeInput['canary_stage']) {
            $denials[] = 'CANARY_STAGE_MISMATCH';
        }
        if (! $this->blastRadiusMatches(
            (string) $scopeInput['blast_radius'],
            (bool) $scopeInput['shared_layer'],
            $urlCount,
        )) {
            $denials[] = 'BLAST_RADIUS_SCOPE_MISMATCH';
        }
        match ($manifest['approval']['review_state']) {
            'pending' => $holds[] = 'APPROVAL_PENDING',
            'rejected' => $denials[] = 'APPROVAL_REJECTED',
            'unknown' => $denials[] = 'APPROVAL_UNKNOWN',
            default => null,
        };
        if ($scopeInput['shared_layer']) {
            $controls = $this->registry->runtimeControls();
            if (! $manifest['shared_layer_allowed']
                || $controls['shared_layer_feature_flags'] === []
                || $controls['shared_layer_cohort_allowlist'] === []) {
                $denials[] = 'SHARED_LAYER_NOT_AUTHORIZED';
            }
        }

        $controls = $this->registry->runtimeControls();
        if (! in_array($manifest['manifest_id'], (array) ($this->registry->trustRegistry()['active_manifest_ids'] ?? []), true)) {
            $holds[] = 'ACTIVE_MANIFEST_UNAVAILABLE';
        }
        if (! in_array($manifest['authority_revision'], (array) ($controls['current_authority_revisions'] ?? []), true)) {
            $holds[] = 'AUTHORITY_REVISION_UNAVAILABLE';
        }
        if (($controls['canary_state_registry_available'] ?? true) !== true) {
            $holds[] = 'CANARY_STATE_UNAVAILABLE';
        }
        if (($controls['global_write_gate'] ?? true) !== true) {
            $holds[] = 'GLOBAL_WRITE_GATE_DISABLED';
        }
        if (($controls['post12_agent_write_enabled'] ?? true) !== true) {
            $holds[] = 'POST12_AGENT_WRITE_DISABLED';
        }

        if ($denials !== []) {
            return $this->deny($denials, $roleId, $scope);
        }

        return $this->hold($holds, $roleId, $scope);
    }

    private function canaryMaximum(string $family): int
    {
        $policy = $this->families->families()[$family]['canary_policy'] ?? [];
        $explicit = $policy['initial_canary']['maximum_urls'] ?? null;

        return is_int($explicit) && $explicit > 0 ? $explicit : 1;
    }

    private function blastRadiusMatches(string $blastRadius, bool $sharedLayer, int $urlCount): bool
    {
        return match ($blastRadius) {
            'single_url' => ! $sharedLayer && $urlCount === 1,
            'bounded_cohort' => ! $sharedLayer && $urlCount > 1,
            'shared_layer' => $sharedLayer,
            default => false,
        };
    }

    /** @param list<string> $reasons @param array{family:string,locale:string,action:?string} $scope @return array<string, mixed> */
    private function deny(array $reasons, string $roleId = 'role:withheld', array $scope = ['family' => 'family:withheld', 'locale' => 'und', 'action' => null]): array
    {
        return $this->decisions->make('execution', 'DENY', $reasons, $roleId, $scope);
    }

    /** @param list<string> $reasons @param array{family:string,locale:string,action:?string} $scope @return array<string, mixed> */
    private function hold(array $reasons, string $roleId, array $scope): array
    {
        return $this->decisions->make('execution', 'HOLD', $reasons, $roleId, $scope);
    }
}
