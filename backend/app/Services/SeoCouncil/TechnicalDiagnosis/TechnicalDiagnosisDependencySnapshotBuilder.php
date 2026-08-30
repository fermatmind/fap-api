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
    public const SNAPSHOT_VERSION = 'seo.technical_diagnosis_dependency_snapshot.v2';

    private const BINDING_V2_FILE_SHA256 = '655d25e227e33f08dc8e8589a414a6a755572450bb9f7da740f7b5d47df40a73';

    private const ENVIRONMENTS = ['ci_candidate', 'staging_runtime', 'production_runtime'];

    /** @var list<string> */
    private const STATE_FIELDS = [
        'dependency_mode', 'observed_active_sha', 'url_truth_revision', 'url_truth_projection_hash',
        'runtime_evidence_revision', 'runtime_evidence_hash', 'deployment_revision', 'authority_revision',
        'page_family', 'locale', 'source_capability_state',
    ];

    public function __construct(
        private readonly SeoRoleCapabilityRegistry $roles,
        private readonly SeoEvidenceContractRegistry $evidence,
        private readonly PolicyGatewayRegistry $policy,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly SeoDetectorRegistry $detectors,
        private readonly PageFamilyPolicyRegistry $pageFamilies,
        private readonly TechnicalDiagnosisContractRegistry $contracts,
        private readonly TechnicalDiagnosisSourceFieldOwnership $ownership,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function build(string $expectedReleaseSha, string $expectedEnvironment, array $state): array
    {
        $registry = $this->roles->registry();
        $evidence = $this->evidence->manifest();
        $policy = $this->policy->registry();
        $binding = $this->binding->reference();
        $blockers = [];
        $now = CarbonImmutable::now('UTC');
        if (! in_array($expectedEnvironment, self::ENVIRONMENTS, true)) {
            $blockers[] = 'environment_invalid';
        }
        if (array_diff(self::STATE_FIELDS, array_keys($state)) !== [] || array_diff(array_keys($state), self::STATE_FIELDS) !== []) {
            $blockers[] = 'dependency_state_fields_invalid';
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $expectedReleaseSha) !== 1) {
            $blockers[] = 'expected_release_sha_invalid';
        }
        $mode = $state['dependency_mode'] ?? null;
        if (($expectedEnvironment === 'ci_candidate' && $mode !== 'OFFLINE_FIXTURE')
            || ($expectedEnvironment !== 'ci_candidate' && $mode !== 'RUNTIME_READ_ONLY')) {
            $blockers[] = 'dependency_mode_environment_mismatch';
        }
        $observed = $state['observed_active_sha'] ?? null;
        if ($expectedEnvironment === 'ci_candidate') {
            if ($observed !== null) {
                $blockers[] = 'ci_candidate_cannot_claim_active_release';
            }
        } elseif (! is_string($observed) || ! hash_equals($expectedReleaseSha, $observed)) {
            $blockers[] = 'observed_active_sha_mismatch';
        }
        if (($state['deployment_revision'] ?? null) !== $expectedReleaseSha) {
            $blockers[] = 'deployment_revision_conflict';
        }
        foreach (['url_truth_revision', 'url_truth_projection_hash', 'runtime_evidence_revision', 'runtime_evidence_hash', 'authority_revision'] as $field) {
            if (! is_string($state[$field] ?? null) || trim((string) $state[$field]) === '' || $state[$field] === 'unavailable') {
                $blockers[] = $field.'_unavailable';
            }
        }
        foreach (['url_truth_projection_hash', 'runtime_evidence_hash'] as $field) {
            if (preg_match('/^[a-f0-9]{64}$/D', (string) ($state[$field] ?? '')) !== 1) {
                $blockers[] = $field.'_invalid';
            }
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
        if (($registry['registry_hash'] ?? null) !== PolicyGatewayRegistry::ROLE_REGISTRY_HASH
            || ($evidence['manifest_hash'] ?? null) !== PolicyGatewayRegistry::EVIDENCE_MANIFEST_HASH
            || $this->policy->dependencyStatus() !== 'READY'
            || $this->binding->status() !== 'READY'
            || ! hash_equals(RoleCapabilityBindingRegistry::V1_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v1.json')) ?: '')
            || ! hash_equals(self::BINDING_V2_FILE_SHA256, hash_file('sha256', resource_path('seo-agent/council/bindings/seo.role_capability_binding.v2.json')) ?: '')) {
            $blockers[] = 'frozen_dependency_hash_drift';
        }
        if (! $this->ownership->valid()) {
            $blockers[] = 'source_field_ownership_invalid';
        }
        $detectorRef = ['registry_version' => SeoDetectorRegistry::VERSION, 'registry_hash' => 'unavailable'];
        $pageFamilyRef = ['version' => PageFamilyPolicyRegistry::VERSION, 'hash' => 'unavailable'];
        try {
            $this->detectors->assertValid();
            $detectorRef['registry_hash'] = $this->detectors->registryHash();
        } catch (Throwable) {
            $blockers[] = 'detector_registry_missing_or_drifted';
        }
        try {
            $this->pageFamilies->assertValid();
            $pageFamilyRef['hash'] = $this->pageFamilies->policyHash();
        } catch (Throwable) {
            $blockers[] = 'page_family_policy_missing_or_drifted';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);
        $snapshot = [
            'snapshot_id' => $this->hasher->hash([$expectedReleaseSha, $expectedEnvironment, $state, $this->contracts->manifest()['manifest_hash']]),
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'environment' => $expectedEnvironment,
            'dependency_mode' => $mode,
            'production_sha' => $expectedReleaseSha,
            'observed_active_sha' => $observed,
            'captured_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $now->addHour()->format('Y-m-d\TH:i:s\Z'),
            'role_registry_ref' => ['id' => $registry['registry_id'], 'version' => $registry['registry_version'], 'hash' => $registry['registry_hash']],
            'evidence_bundle_contract_ref' => ['id' => $evidence['schema_version'], 'version' => $evidence['manifest_version'], 'hash' => $evidence['manifest_hash']],
            'admission_policy_ref' => ['id' => $policy['registry_id'], 'version' => $policy['registry_version'], 'hash' => $policy['registry_hash']],
            'binding_ref' => $binding,
            'orchestrator_ref' => ['id' => 'fap_api.seo_council_orchestrator', 'version' => '1.0.0'],
            'detector_registry_ref' => $detectorRef,
            'page_family_policy_ref' => $pageFamilyRef,
            'source_field_ownership_ref' => $this->ownership->reference(),
            'url_truth_revision' => $state['url_truth_revision'] ?? null,
            'url_truth_projection_hash' => $state['url_truth_projection_hash'] ?? null,
            'runtime_evidence_revision' => $state['runtime_evidence_revision'] ?? null,
            'runtime_evidence_hash' => $state['runtime_evidence_hash'] ?? null,
            'deployment_revision' => $state['deployment_revision'] ?? null,
            'authority_revision' => $state['authority_revision'] ?? null,
            'page_family' => $state['page_family'] ?? null,
            'locale' => $state['locale'] ?? null,
            'source_capability_state' => $state['source_capability_state'] ?? null,
            'historical_authority_immutable' => ! in_array('frozen_dependency_hash_drift', $blockers, true),
            'status' => $blockers === [] ? 'READY' : 'DEPENDENCY_HOLD',
            'blockers' => $blockers,
            'execution_allowed' => false,
            'ready_for_diagnosis' => $blockers === [],
        ];
        $snapshot['snapshot_hash'] = $this->hasher->hash($snapshot);
        $snapshot['snapshot_seal'] = hash_hmac('sha256', $snapshot['snapshot_hash'], $this->sealKey());

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function verify(array $snapshot, string $expectedReleaseSha, string $expectedEnvironment): bool
    {
        try {
            return ($snapshot['snapshot_version'] ?? null) === self::SNAPSHOT_VERSION
                && ($snapshot['production_sha'] ?? null) === $expectedReleaseSha
                && ($snapshot['environment'] ?? null) === $expectedEnvironment
                && ($snapshot['execution_allowed'] ?? null) === false
                && (($snapshot['status'] ?? null) === 'READY') === (($snapshot['blockers'] ?? null) === [])
                && ($snapshot['role_registry_ref']['hash'] ?? null) === $this->roles->registry()['registry_hash']
                && ($snapshot['evidence_bundle_contract_ref']['hash'] ?? null) === $this->evidence->manifest()['manifest_hash']
                && ($snapshot['admission_policy_ref']['hash'] ?? null) === $this->policy->registry()['registry_hash']
                && ($snapshot['binding_ref'] ?? null) === $this->binding->reference()
                && ($snapshot['detector_registry_ref']['registry_hash'] ?? null) === $this->detectors->registryHash()
                && ($snapshot['page_family_policy_ref']['hash'] ?? null) === $this->pageFamilies->policyHash()
                && ($snapshot['source_field_ownership_ref'] ?? null) === $this->ownership->reference()
                && CarbonImmutable::parse((string) ($snapshot['expires_at'] ?? ''))->utc()->isFuture()
                && is_string($snapshot['snapshot_hash'] ?? null)
                && is_string($snapshot['snapshot_seal'] ?? null)
                && hash_equals($this->bodyHash($snapshot), (string) $snapshot['snapshot_hash'])
                && hash_equals(hash_hmac('sha256', (string) $snapshot['snapshot_hash'], $this->sealKey()), (string) $snapshot['snapshot_seal']);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function requestReference(array $snapshot): array
    {
        return [
            'snapshot_id' => $snapshot['snapshot_id'],
            'snapshot_version' => $snapshot['snapshot_version'],
            'snapshot_hash' => $snapshot['snapshot_hash'],
            'production_sha' => $snapshot['production_sha'],
            'environment' => $snapshot['environment'],
        ];
    }

    /** @param array<string, mixed> $snapshot @return array<string, int> */
    public function negativeProbeMetrics(array $snapshot, string $expectedReleaseSha, string $expectedEnvironment): array
    {
        $dependencyBypasses = 0;
        foreach ([
            ['production_sha' => str_repeat('f', 40)],
            ['environment' => $expectedEnvironment === 'production_runtime' ? 'staging_runtime' : 'production_runtime'],
            ['expires_at' => '2000-01-01T00:00:00Z'],
        ] as $mutation) {
            $candidate = array_replace($snapshot, $mutation);
            $candidate['snapshot_hash'] = $this->bodyHash($candidate);
            $dependencyBypasses += (int) $this->verify($candidate, $expectedReleaseSha, $expectedEnvironment);
        }
        $detector = $snapshot;
        $detector['detector_registry_ref']['registry_hash'] = str_repeat('f', 64);
        $detector['snapshot_hash'] = $this->bodyHash($detector);

        return [
            'dependency_ref_mismatch_bypass' => $dependencyBypasses,
            'detector_ref_mismatch_bypass' => (int) $this->verify($detector, $expectedReleaseSha, $expectedEnvironment),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function bodyHash(array $snapshot): string
    {
        unset($snapshot['snapshot_hash'], $snapshot['snapshot_seal']);

        return $this->hasher->hash($snapshot);
    }

    private function sealKey(): string
    {
        $key = (string) config('app.key', '');

        return $key === '' ? 'technical-diagnosis-unconfigured-key-hold' : $key;
    }
}
