<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayRegistry;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use Carbon\CarbonImmutable;
use Throwable;

final class TechnicalDiagnosisDependencySnapshotBuilder
{
    public const SNAPSHOT_VERSION = 'seo.technical_diagnosis_dependency_snapshot.v1';

    private const BINDING_V2_FILE_SHA256 = '655d25e227e33f08dc8e8589a414a6a755572450bb9f7da740f7b5d47df40a73';

    /** @var list<string> */
    private const STATE_FIELDS = [
        'production_closeout_sha', 'url_truth_revision', 'url_truth_projection_hash',
        'runtime_evidence_revision', 'runtime_evidence_hash', 'deployment_revision',
        'evidence_deployment_revision', 'authority_revision', 'evidence_authority_revision',
        'page_family', 'locale', 'evidence_captured_at', 'evidence_expires_at',
        'source_capability_state', 'evidence_freshness_state',
    ];

    public function __construct(
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoDetectorRegistry $detectors,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function build(string $productionSha, array $state): array
    {
        $registry = $this->roles->registry();
        $evidence = $this->evidence->manifest();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $blockers = [];
        if (array_diff(self::STATE_FIELDS, array_keys($state)) !== [] || array_diff(array_keys($state), self::STATE_FIELDS) !== []) {
            $blockers[] = 'dependency_state_fields_invalid';
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $productionSha) !== 1
            || ! hash_equals($productionSha, (string) ($state['production_closeout_sha'] ?? ''))) {
            $blockers[] = 'production_exact_sha_closeout_missing';
        }
        foreach (['url_truth_revision', 'url_truth_projection_hash', 'runtime_evidence_revision', 'runtime_evidence_hash', 'deployment_revision', 'authority_revision'] as $field) {
            if (! is_string($state[$field] ?? null) || trim((string) $state[$field]) === '' || $state[$field] === 'unavailable') {
                $blockers[] = $field.'_unavailable';
            }
        }
        if (($state['deployment_revision'] ?? null) !== ($state['evidence_deployment_revision'] ?? null)
            || ($state['deployment_revision'] ?? null) !== $productionSha) {
            $blockers[] = 'deployment_revision_conflict';
        }
        if (($state['authority_revision'] ?? null) !== ($state['evidence_authority_revision'] ?? null)) {
            $blockers[] = 'authority_revision_conflict';
        }
        if (! in_array($state['page_family'] ?? null, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)) {
            $blockers[] = 'page_family_invalid';
        }
        if (! in_array($state['locale'] ?? null, ['zh-CN', 'en'], true)) {
            $blockers[] = 'locale_invalid';
        }
        if (($state['source_capability_state'] ?? null) !== 'available') {
            $blockers[] = 'source_capability_unavailable';
        }
        if (($state['evidence_freshness_state'] ?? null) !== 'fresh') {
            $blockers[] = 'evidence_not_fresh';
        }
        try {
            $captured = CarbonImmutable::parse((string) ($state['evidence_captured_at'] ?? ''))->utc();
            $expires = CarbonImmutable::parse((string) ($state['evidence_expires_at'] ?? ''))->utc();
            if ($expires->lessThanOrEqualTo($captured) || $expires->isPast()) {
                $blockers[] = 'evidence_expired';
            }
        } catch (Throwable) {
            $blockers[] = 'evidence_time_invalid';
        }
        if (($registry['registry_hash'] ?? null) !== PolicyGatewayRegistry::ROLE_REGISTRY_HASH
            || ($evidence['manifest_hash'] ?? null) !== PolicyGatewayRegistry::EVIDENCE_MANIFEST_HASH
            || $this->policy->dependencyStatus() !== 'READY'
            || $this->binding->status() !== 'READY'
            || ! hash_equals(RoleCapabilityBindingRegistry::V1_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json')) ?: '')
            || ! hash_equals(self::BINDING_V2_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')) ?: '')) {
            $blockers[] = 'frozen_dependency_hash_drift';
        }
        $detectorRef = ['version' => SeoDetectorRegistry::VERSION, 'hash' => 'unavailable'];
        try {
            $this->detectors->assertValid();
            $detectorRef['hash'] = $this->detectors->registryHash();
        } catch (Throwable) {
            $blockers[] = 'detector_registry_missing_or_drifted';
        }
        $pageFamilyRef = ['version' => PageFamilyPolicyRegistry::VERSION, 'hash' => 'unavailable'];
        try {
            $this->pageFamilies->assertValid();
            $pageFamilyRef['hash'] = $this->pageFamilies->policyHash();
        } catch (Throwable) {
            $blockers[] = 'page_family_policy_missing_or_drifted';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        $snapshot = [
            'snapshot_id' => $this->hasher->hash([$productionSha, $state, $this->contracts->manifest()['manifest_hash']]),
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'production_sha' => $productionSha,
            'role_registry_ref' => ['id' => $registry['registry_id'], 'version' => $registry['registry_version'], 'hash' => $registry['registry_hash']],
            'evidence_bundle_contract_ref' => ['id' => $evidence['schema_version'], 'version' => $evidence['manifest_version'], 'hash' => $evidence['manifest_hash']],
            'privacy_gateway_policy_ref' => $this->privacyGatewayRef(),
            'admission_policy_ref' => ['id' => $policy['registry_id'], 'version' => $policy['registry_version'], 'hash' => $policy['registry_hash']],
            'binding_ref' => $binding,
            'orchestrator_ref' => ['id' => 'fap_api.seo_council_orchestrator', 'version' => '1.0.0'],
            'detector_registry_ref' => $detectorRef,
            'page_family_policy_ref' => $pageFamilyRef,
            'url_truth_revision' => $state['url_truth_revision'] ?? null,
            'url_truth_projection_hash' => $state['url_truth_projection_hash'] ?? null,
            'runtime_evidence_revision' => $state['runtime_evidence_revision'] ?? null,
            'runtime_evidence_hash' => $state['runtime_evidence_hash'] ?? null,
            'deployment_revision' => $state['deployment_revision'] ?? null,
            'authority_revision' => $state['authority_revision'] ?? null,
            'page_family' => $state['page_family'] ?? null,
            'locale' => $state['locale'] ?? null,
            'evidence_captured_at' => $state['evidence_captured_at'] ?? null,
            'evidence_expires_at' => $state['evidence_expires_at'] ?? null,
            'source_capability_state' => $state['source_capability_state'] ?? null,
            'evidence_freshness_state' => $state['evidence_freshness_state'] ?? null,
            'historical_authority_immutable' => $blockers === [] || ! in_array('frozen_dependency_hash_drift', $blockers, true),
            'status' => $blockers === [] ? 'READY' : 'DEPENDENCY_HOLD',
            'blockers' => $blockers,
            'execution_allowed' => false,
            'ready_for_diagnosis' => $blockers === [],
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function verify(array $snapshot, string $expectedSha): bool
    {
        try {
            return ($snapshot['snapshot_version'] ?? null) === self::SNAPSHOT_VERSION
                && ($snapshot['production_sha'] ?? null) === $expectedSha
                && ($snapshot['execution_allowed'] ?? null) === false
                && (($snapshot['status'] ?? null) === 'READY') === (($snapshot['ready_for_diagnosis'] ?? null) === true)
                && ($snapshot['role_registry_ref']['hash'] ?? null) === $this->roles->registry()['registry_hash']
                && ($snapshot['evidence_bundle_contract_ref']['hash'] ?? null) === $this->evidence->manifest()['manifest_hash']
                && ($snapshot['admission_policy_ref']['hash'] ?? null) === $this->policy->registry()['registry_hash']
                && ($snapshot['binding_ref'] ?? null) === $this->binding->reference()
                && ($snapshot['detector_registry_ref']['hash'] ?? null) === $this->detectors->registryHash()
                && ($snapshot['page_family_policy_ref']['hash'] ?? null) === $this->pageFamilies->policyHash()
                && is_string($snapshot['snapshot_hash'] ?? null)
                && hash_equals($this->hasher->hashWithout($snapshot, 'snapshot_hash'), (string) $snapshot['snapshot_hash']);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{id:string,version:string,hash:string} */
    private function privacyGatewayRef(): array
    {
        $assets = [];
        foreach ([
            'seo-query-privacy.v2.json', 'seo-private-negative-set.v2.json',
            'seo-external-content-gateway.v2.json', 'seo-context-minimization.v2.json',
            'seo-evidence-retention.v2.json',
        ] as $file) {
            $assets[$file] = json_decode((string) file_get_contents(resource_path('seo-agent/evidence/policies/'.$file)), true, 512, JSON_THROW_ON_ERROR);
        }

        return ['id' => 'seo.evidence_privacy_gateway_policy_set.v2', 'version' => '2.0.0', 'hash' => $this->hasher->hash($assets)];
    }
}
