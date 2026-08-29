<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Governance;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;

final class CouncilDependencySnapshotBuilder
{
    public function __construct(
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(string $releaseSha): array
    {
        $roleRegistry = $this->roles->registry();
        $evidence = $this->evidence->manifest();
        $policy = $this->policy->registry();
        $dependencies = [
            '11a_role_registry' => [
                'version' => $roleRegistry['registry_version'],
                'hash' => $roleRegistry['registry_hash'],
            ],
            '11b_evidence_contract' => [
                'version' => $evidence['manifest_version'],
                'hash' => $evidence['manifest_hash'],
            ],
            '11c_policy_gateway' => [
                'version' => $policy['registry_version'],
                'hash' => $policy['registry_hash'],
            ],
            '11d_role_capability_binding' => [
                'version' => $this->binding->reference()['version'],
                'hash' => $this->binding->reference()['hash'],
            ],
        ];
        $status = $this->policy->dependencyStatus() === 'READY' && $this->binding->status() === 'READY'
            ? 'READY'
            : 'DEPENDENCY_HOLD';
        $snapshot = [
            'snapshot_version' => 'seo.council_dependency_snapshot.v1',
            'release_sha' => $releaseSha,
            'baseline_sha' => (string) config('seo_council.baseline_sha'),
            'dependencies' => $dependencies,
            'status' => $status,
            'execution_allowed' => false,
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }
}
