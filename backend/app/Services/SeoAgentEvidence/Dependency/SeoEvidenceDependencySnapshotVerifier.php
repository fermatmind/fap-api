<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Dependency;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class SeoEvidenceDependencySnapshotVerifier
{
    private const FIELDS = ['schema_version', 'snapshot_id', 'snapshot_version', 'captured_at', 'release_sha', 'registry_id', 'registry_version', 'registry_hash', 'inventory_v3_hash', 'contract_manifest_hash', 'dependencies', 'page_family_policy_version', 'page_family_policy_hash', 'url_truth_revision', 'url_truth_projection_hash', 'detector_registry_version', 'detector_registry_hash', 'status', 'execution_allowed', 'blockers', 'negative_guarantees', 'snapshot_hash'];

    private const DEPENDENCIES = ['seo-platform-06', 'seo-platform-07', 'seo-platform-08', 'seo-platform-09', 'seo-platform-10', 'seo-platform-11a'];

    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoEvidenceContractRegistry $contracts,
        private readonly SeoRoleCapabilityRegistry $registry,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoDetectorRegistry $detectors,
    ) {}

    /** @param array<string, mixed> $snapshot @return array{valid:bool,status:string} */
    public function verify(array $snapshot, string $expectedSha): array
    {
        try {
            $keys = array_keys($snapshot);
            $fields = self::FIELDS;
            sort($keys, SORT_STRING);
            sort($fields, SORT_STRING);
            $inventory = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-platform-11a-inventory.v3.json')), true, 512, JSON_THROW_ON_ERROR);
            $registry = $this->registry->registry();
            $dependencies = array_values(array_filter((array) ($snapshot['dependencies'] ?? []), 'is_array'));
            $dependencyIds = array_column($dependencies, 'dependency_id');
            sort($dependencyIds, SORT_STRING);
            $requiredIds = self::DEPENDENCIES;
            sort($requiredIds, SORT_STRING);
            $dependencyStatesValid = $dependencyIds === $requiredIds;
            $held = false;
            foreach ($dependencies as $dependency) {
                $verified = ($dependency['status'] ?? null) === 'verified';
                $dependencyStatesValid = $dependencyStatesValid
                    && in_array($dependency['status'] ?? null, ['verified', 'held'], true)
                    && is_string($dependency['evidence_code'] ?? null)
                    && ($verified
                        ? ($dependency['source_state'] ?? null) === 'available' && ($dependency['private_boundary_proven'] ?? null) === true
                        : ($dependency['private_boundary_proven'] ?? null) === false);
                $held = $held || ! $verified;
            }
            $dynamicHeld = ($snapshot['url_truth_revision'] ?? null) === 'unavailable'
                || ($snapshot['url_truth_projection_hash'] ?? null) === 'unavailable';
            $expectedStatus = $held || $dynamicHeld ? 'DEPENDENCY_HOLD' : 'READY';
            $negative = (array) ($snapshot['negative_guarantees'] ?? []);
            $valid = $keys === $fields
                && preg_match('/^[a-f0-9]{40}$/', $expectedSha) === 1
                && ($snapshot['schema_version'] ?? null) === 'seo.evidence_dependency_snapshot.v1'
                && ($snapshot['snapshot_version'] ?? null) === 1
                && CarbonImmutable::parse((string) ($snapshot['captured_at'] ?? ''))->utc()->format('Y-m-d\TH:i:s\Z') === $snapshot['captured_at']
                && ($snapshot['release_sha'] ?? null) === $expectedSha
                && ($snapshot['registry_id'] ?? null) === SeoRoleCapabilityRegistry::REGISTRY_ID
                && ($snapshot['registry_version'] ?? null) === SeoRoleCapabilityRegistry::REGISTRY_VERSION
                && ($snapshot['registry_hash'] ?? null) === $registry['registry_hash']
                && ($snapshot['inventory_v3_hash'] ?? null) === $inventory['inventory_self_hash']
                && ($snapshot['contract_manifest_hash'] ?? null) === $this->contracts->manifest()['manifest_hash']
                && ($snapshot['page_family_policy_version'] ?? null) === PageFamilyPolicyRegistry::VERSION
                && ($snapshot['page_family_policy_hash'] ?? null) === $this->pageFamilies->policyHash()
                && ($snapshot['detector_registry_version'] ?? null) === SeoDetectorRegistry::VERSION
                && ($snapshot['detector_registry_hash'] ?? null) === $this->detectors->registryHash()
                && $dependencyStatesValid
                && ($snapshot['status'] ?? null) === $expectedStatus
                && ($snapshot['execution_allowed'] ?? null) === false
                && ($negative['raw_query_exposed'] ?? null) === false
                && ($negative['private_url_exposed'] ?? null) === false
                && ($negative['private_business_object_exposed'] ?? null) === false
                && ($negative['model_calls'] ?? null) === 0
                && ($negative['tool_calls'] ?? null) === 0
                && ($negative['external_calls'] ?? null) === 0
                && ($negative['business_writes'] ?? null) === 0
                && is_string($snapshot['snapshot_hash'] ?? null)
                && hash_equals($this->hasher->hashWithout($snapshot, 'snapshot_hash'), (string) $snapshot['snapshot_hash']);
        } catch (Throwable) {
            $valid = false;
        }

        return ['valid' => $valid, 'status' => $valid ? (string) $snapshot['status'] : 'INVALID'];
    }
}
