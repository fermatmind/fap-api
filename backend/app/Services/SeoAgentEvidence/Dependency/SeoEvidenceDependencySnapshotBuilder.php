<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Dependency;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class SeoEvidenceDependencySnapshotBuilder
{
    private const DEPENDENCIES = ['seo-platform-06', 'seo-platform-07', 'seo-platform-08', 'seo-platform-09', 'seo-platform-10', 'seo-platform-11a'];

    public function __construct(
        private readonly SeoEvidenceCanonicalHasher $hasher,
        private readonly SeoEvidenceContractRegistry $contracts,
        private readonly SeoRoleCapabilityRegistry $registry,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly SeoDetectorRegistry $detectors,
    ) {}

    /** @param list<array<string, mixed>> $dependencies @param array<string, mixed> $dynamic @return array<string, mixed> */
    public function build(string $releaseSha, array $dependencies, array $dynamic = []): array
    {
        $dependencies = $this->normalizeDependencies($dependencies);
        $registry = $this->registry->registry();
        $inventory = json_decode((string) file_get_contents(base_path('docs/seo/generated/seo-platform-11a-inventory.v3.json')), true, 512, JSON_THROW_ON_ERROR);
        $manifest = $this->contracts->manifest();
        $blockers = [];
        foreach ($dependencies as $dependency) {
            if (($dependency['status'] ?? null) !== 'verified') {
                $blockers[] = (string) ($dependency['dependency_id'] ?? 'unknown_dependency');
            }
        }
        foreach (['url_truth_revision', 'url_truth_projection_hash'] as $field) {
            if (! is_string($dynamic[$field] ?? null) || trim((string) $dynamic[$field]) === '') {
                $blockers[] = $field;
            }
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        $snapshot = [
            'schema_version' => 'seo.evidence_dependency_snapshot.v1',
            'snapshot_id' => hash('sha256', $releaseSha.'|'.$manifest['manifest_hash']),
            'snapshot_version' => 1,
            'captured_at' => $dynamic['captured_at'] ?? now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'release_sha' => $releaseSha,
            'registry_id' => SeoRoleCapabilityRegistry::REGISTRY_ID,
            'registry_version' => SeoRoleCapabilityRegistry::REGISTRY_VERSION,
            'registry_hash' => $registry['registry_hash'],
            'inventory_v3_hash' => $inventory['inventory_self_hash'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'dependencies' => $dependencies,
            'page_family_policy_version' => PageFamilyPolicyRegistry::VERSION,
            'page_family_policy_hash' => $this->pageFamilies->policyHash(),
            'url_truth_revision' => $dynamic['url_truth_revision'] ?? 'unavailable',
            'url_truth_projection_hash' => $dynamic['url_truth_projection_hash'] ?? 'unavailable',
            'detector_registry_version' => SeoDetectorRegistry::VERSION,
            'detector_registry_hash' => $this->detectors->registryHash(),
            'status' => $blockers === [] ? 'READY' : 'DEPENDENCY_HOLD',
            'execution_allowed' => false,
            'blockers' => $blockers,
            'negative_guarantees' => [
                'raw_query_exposed' => false,
                'private_url_exposed' => false,
                'private_business_object_exposed' => false,
                'model_calls' => 0,
                'tool_calls' => 0,
                'external_calls' => 0,
                'business_writes' => 0,
            ],
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }

    /** @param list<array<string, mixed>> $dependencies @return list<array<string, mixed>> */
    private function normalizeDependencies(array $dependencies): array
    {
        $grouped = [];
        foreach ($dependencies as $dependency) {
            $id = $dependency['dependency_id'] ?? null;
            if (is_string($id) && in_array($id, self::DEPENDENCIES, true)) {
                $grouped[$id][] = $dependency;
            }
        }

        $normalized = [];
        foreach (self::DEPENDENCIES as $id) {
            $matches = $grouped[$id] ?? [];
            if (count($matches) !== 1) {
                $normalized[] = $this->heldDependency($id, $matches === [] ? 'DEPENDENCY_EVIDENCE_MISSING' : 'DEPENDENCY_EVIDENCE_DUPLICATE');

                continue;
            }
            $dependency = $matches[0];
            $verified = ($dependency['status'] ?? null) === 'verified'
                && ($dependency['source_state'] ?? null) === 'available'
                && ($dependency['private_boundary_proven'] ?? null) === true
                && is_string($dependency['evidence_code'] ?? null);
            if (! $verified && ($dependency['status'] ?? null) !== 'held') {
                $dependency = $this->heldDependency($id, 'DEPENDENCY_EVIDENCE_INVALID');
            }
            $normalized[] = $dependency;
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function heldDependency(string $id, string $code): array
    {
        return [
            'dependency_id' => $id,
            'status' => 'held',
            'source_state' => 'source_unavailable',
            'private_boundary_proven' => false,
            'evidence_code' => $code,
        ];
    }
}
