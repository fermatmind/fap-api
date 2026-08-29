<?php

declare(strict_types=1);

namespace App\Services\SeoAgentPolicyGateway;

use App\Services\ReviewGovernance\ReviewPolicyRegistry;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoPolicyRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Throwable;

final class PolicyGatewayRegistry
{
    public const REGISTRY_ID = 'fermatmind.seo.policy_gateway_registry';

    public const REGISTRY_VERSION = '1.0.0';

    public const ROLE_REGISTRY_HASH = 'b02b6edd816b75b42582468e5bc3aa2c9cd0060149825d1fdc6131cf71d73791';

    public const EVIDENCE_MANIFEST_HASH = 'c7b6d23cd70b789f8dad80fe4c187679211a1485dab47cb23ed2cb51980c59ab';

    public const PAGE_FAMILY_POLICY_HASH = '2e69428e1f4f589ceb392a6ee4d39e062b94e72101df0399cbed8e4140ef42dc';

    public const RELEASE_SEPARATION_POLICY_HASH = '6c1cf25f70ffc475a0c19f8c7cdf3451c9c35cb49482298e9d4485d365327be6';

    public const REVIEW_POLICY_REGISTRY_HASH = '1abc4f3b7a25aecf3b32662b340d337588b477bfa6393d4380b1d36bb1d6107d';

    public function __construct(
        private readonly PolicyGatewayAssetLoader $assets,
        private readonly SeoRegistryHasher $hasher,
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PageFamilyPolicyRegistry $families,
        private readonly SeoPolicyRegistry $policies,
    ) {}

    /** @return array<string, mixed> */
    public function registry(): array
    {
        $registry = $this->assets->load('seo.policy_gateway_registry.v1.json');
        $registry['registry_hash'] = $this->hasher->hash($registry);

        return $registry;
    }

    public function dependencyStatus(): string
    {
        try {
            $source = $this->registry();
            $dependencies = (array) ($source['dependencies'] ?? []);
            $role = $this->roles->registry();
            $evidence = $this->evidence->manifest();
            $release = $this->policies->definitions()['seo.release_separation'] ?? [];
            $actual = [
                'role_capability_registry' => [
                    'id' => $role['registry_id'] ?? null,
                    'version' => $role['registry_version'] ?? null,
                    'hash' => $role['registry_hash'] ?? null,
                ],
                'evidence_contract_manifest' => [
                    'id' => $evidence['schema_version'] ?? null,
                    'version' => $evidence['manifest_version'] ?? null,
                    'hash' => $evidence['manifest_hash'] ?? null,
                ],
                'page_family_policy' => [
                    'id' => PageFamilyPolicyRegistry::VERSION,
                    'version' => '1.0.0',
                    'hash' => $this->families->policyHash(),
                ],
                'release_separation_policy' => [
                    'id' => $release['id'] ?? null,
                    'version' => $release['version'] ?? null,
                    'hash' => $release['hash'] ?? null,
                ],
                'review_policy_registry' => [
                    'id' => ReviewPolicyRegistry::SCHEMA_VERSION,
                    'version' => '1.0.0',
                    'hash' => $this->hasher->hash(ReviewPolicyRegistry::inventory()),
                ],
            ];
            $frozen = [
                'role_capability_registry' => self::ROLE_REGISTRY_HASH,
                'evidence_contract_manifest' => self::EVIDENCE_MANIFEST_HASH,
                'page_family_policy' => self::PAGE_FAMILY_POLICY_HASH,
                'release_separation_policy' => self::RELEASE_SEPARATION_POLICY_HASH,
                'review_policy_registry' => self::REVIEW_POLICY_REGISTRY_HASH,
            ];
            foreach ($frozen as $name => $hash) {
                if (($actual[$name]['hash'] ?? null) !== $hash || ($dependencies[$name] ?? null) !== $actual[$name]) {
                    return 'DEPENDENCY_HOLD';
                }
            }

            $guards = (array) ($source['guards'] ?? []);
            if (($source['registry_id'] ?? null) !== self::REGISTRY_ID
                || ($source['registry_version'] ?? null) !== self::REGISTRY_VERSION
                || ($source['registry_state'] ?? null) !== 'frozen_deny_only'
                || ($source['runtime_state'] ?? null) !== 'dormant_not_authorized'
                || $guards !== $this->expectedGuards()) {
                return 'DEPENDENCY_HOLD';
            }

            return 'READY';
        } catch (Throwable) {
            return 'DEPENDENCY_HOLD';
        }
    }

    /** @return array<string, bool|int|string> */
    public function guards(): array
    {
        return (array) ($this->registry()['guards'] ?? []);
    }

    /** @return list<string> */
    public function callerTypes(): array
    {
        return array_values(array_filter(
            (array) ($this->registry()['caller_types'] ?? []),
            'is_string',
        ));
    }

    /** @return array<string, mixed> */
    public function trustRegistry(): array
    {
        return $this->assets->load('seo.manifest_trust_registry.v1.json');
    }

    /** @return array<string, mixed> */
    public function revocationRegistry(): array
    {
        return $this->assets->load('seo.manifest_revocation_registry.v1.json');
    }

    /** @return array<string, mixed> */
    public function runtimeControls(): array
    {
        return $this->assets->load('seo.policy_runtime_controls.v1.json');
    }

    /** @return array<string, mixed> */
    public function fieldCatalog(): array
    {
        return $this->assets->load('seo.logical_field_catalog.v1.json');
    }

    /** @return array<string, bool|int|string> */
    private function expectedGuards(): array
    {
        return [
            'read_only_gsc' => true,
            'search_submission_allowed' => false,
            'post12_agent_write_enabled' => false,
            'l4_state' => 'dormant_not_authorized',
            'agent_default_write_permission' => false,
            'deterministic_system_final_veto' => true,
            'global_write_gate' => false,
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'model_invocation_enabled' => false,
            'tool_invocation_enabled' => false,
            'external_egress_enabled' => false,
        ];
    }
}
