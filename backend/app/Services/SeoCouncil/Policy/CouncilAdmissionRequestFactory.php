<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Policy;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoCouncil\Contracts\MissionRequestData;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use Carbon\CarbonImmutable;

final class CouncilAdmissionRequestFactory
{
    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly RoleCapabilityBindingRegistry $binding,
    ) {}

    /** @return array<string, mixed> */
    public function make(MissionRequestData $request): array
    {
        $now = CarbonImmutable::now('UTC');
        $roleId = $this->binding->admissionRoleFor($request);
        $status = $this->evidenceStatus($request);
        $refs = array_map(static fn (array $ref): array => [
            'bundle_id' => $ref['bundle_id'],
            'bundle_version' => $ref['bundle_version'],
            'bundle_hash' => $ref['bundle_hash'],
        ], (array) $request->payload['evidence_bundle_refs']);
        $revisions = array_values(array_unique(array_column((array) $request->payload['evidence_bundle_refs'], 'authority_revision')));
        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => $this->hasher->hash([$request->requestHash, $roleId]),
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
        $context['context_hash'] = $this->hasher->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1',
            'caller_type' => $request->callerType,
            'mission_id' => $request->missionId(),
            'mission_type' => $request->payload['mission_type'],
            'requested_role_id' => $roleId,
            'family' => $request->payload['family'],
            'locale' => $request->payload['locale'],
            'claim_risk' => 'R1',
            'autonomy' => $request->payload['autonomy'],
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'execution_seconds' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'deadline_seconds' => 0,
            'tool_scope' => [],
            'egress_scope' => [],
            'evidence_context' => $context,
            'request_metadata' => ['source_label' => 'seo-council', 'correlation_hash' => $request->requestHash],
        ];
    }

    public function evidenceStatus(MissionRequestData $request): string
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
}
