<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;

/** Validates the deploy-installed, data-only A08 activation manifest. */
final readonly class Platform12ActivationEvidence
{
    public const SCHEMA = 'seo.platform12_a08_activation.v2';

    public const REQUIRED_TESTS = [
        'public' => ['SeoPlatform12A01MissionCatalogTest', 'SeoPlatform12A02SchedulerStorageTest',
            'SeoPlatform12A03SchedulerFencingTest', 'SeoPlatform12A04ProductionPersistenceTest',
            'SeoPlatform12A05ReadOnlyRuntimeGateTest', 'SeoPlatform12A08ActivationEvidenceTest',
            'SeoPlatform12A08DailyWiringTest', 'SeoPlatform12A08LegacyScheduleContractTest', 'MigrationPurityGateTest',
            'SeoPlatform12F01NotificationPolicyContractTest', 'SeoPlatform12F02NotificationOutboxTest',
            'SeoPlatform11C', 'SeoOperationsPageTest', 'SeoUxImpl06AgentCouncilTest',
            'SeoPlatform12E02SystemHealthUiTest', 'SeoPlatform12E04TraceDrilldownUiSafetyTest'],
        Platform12DailyMissionSet::IDS[0] => ['SeoPlatform12B01DailyGscCoreRuntimeTest', 'SeoPlatform12A08ProductionEvidenceTest'],
        Platform12DailyMissionSet::IDS[1] => ['SeoPlatform12B02DailyUrlTruthTest', 'SeoPlatform12A08ProductionEvidenceTest'],
        Platform12DailyMissionSet::IDS[2] => ['SeoPlatform12B03DailySecurityDriftTest', 'SeoPlatform12A08ProductionEvidenceTest'],
    ];

    public function __construct(
        private RuntimeCapabilitySnapshotBuilder $capabilities,
        private SeoRegistryHasher $hasher,
    ) {}

    /** @return array{state:string,manifest:?array,production_sha:?string} */
    public function inspect(): array
    {
        $path = (string) config('seo_council.activation_receipt_path', '');
        $digestPath = $path.'.sha256';
        $revisionPath = (string) config('seo_council.release_revision_path', dirname(base_path()).'/REVISION');
        if ($path === '' || is_link($path) || is_link($digestPath)
            || ! is_file($path) || ! is_readable($path) || filesize($path) > 65536
            || ! is_file($digestPath) || ! is_readable($digestPath) || filesize($digestPath) > 128
            || is_link($revisionPath) || ! is_file($revisionPath) || ! is_readable($revisionPath)) {
            return $this->hold(null, 'ACTIVATION_EVIDENCE_MISSING');
        }

        $bytes = file_get_contents($path);
        $expectedDigest = trim((string) file_get_contents($digestPath));
        $productionSha = strtolower(trim((string) file_get_contents($revisionPath)));
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_string($bytes) || ! is_array($manifest)
            || ! $this->digest($expectedDigest) || ! hash_equals($expectedDigest, hash('sha256', $bytes))) {
            return $this->hold($productionSha, 'ACTIVATION_EVIDENCE_CORRUPT');
        }
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA) {
            return $this->hold($productionSha, ($manifest['schema_version'] ?? null) === 'seo.platform12_a08_activation.v1'
                ? 'LEGACY_EVIDENCE_NOT_AUTHORIZATION' : 'ACTIVATION_VERSION_HOLD');
        }
        $reason = $this->validate($manifest, $productionSha);
        if ($reason !== 'READY') {
            return $this->hold($productionSha, $reason);
        }
        $missions = [];
        foreach (Platform12DailyMissionSet::IDS as $id) {
            $proof = $manifest['missions'][$id] ?? [];
            $code = $this->scopedCheck($proof['checks'] ?? null, $productionSha, $id);
            $source = $proof['source_acceptance'] ?? [];
            // A fixture or a natural receipt is never a real source acceptance.
            $accepted = $code && ($source['status'] ?? null) === 'pass'
                && ($source['stage'] ?? null) === 'controlled_source_acceptance'
                && ($source['environment'] ?? null) === 'production'
                && ($source['mission_id'] ?? null) === $id
                && ($source['bound_sha'] ?? null) === $productionSha
                && $this->sha($source['source_sha'] ?? null)
                && $this->digest($source['receipt_digest'] ?? null)
                && $this->artifactDigest($source['artifact_digest'] ?? null)
                && ($source['fingerprint'] ?? null) === ($proof['checks']['fingerprint'] ?? null)
                && ($source['version_vector'] ?? null) === $manifest['runtime']['version_vector']
                && (($source['source_sha'] ?? null) === $productionSha
                    || ($source['ancestor_verified'] ?? null) === true);
            $missions[$id] = ['acceptance_ready' => $code, 'source_accepted' => $accepted,
                'source_receipt_digest' => $accepted ? $source['receipt_digest'] : null,
                'reason' => ! $code ? 'MISSION_SCOPED_EVIDENCE_HOLD' : ($accepted ? 'READY' : 'MISSION_SOURCE_ACCEPTANCE_PENDING')];
        }

        return ['state' => 'READY', 'manifest' => $manifest, 'production_sha' => $productionSha, 'missions' => $missions];
    }

    public function validate(array $manifest, string $productionSha): string
    {
        if (! $this->sha($productionSha) || ($manifest['repository'] ?? null) !== 'fermatmind/fap-api'
            || ($manifest['bound_production_sha'] ?? null) !== $productionSha) {
            return 'RELEASE_SHA_HOLD';
        }
        if (! $this->permissionsClosed($manifest['permissions'] ?? null)
            || data_get($manifest, 'measurement.day_28_started') !== false
            || data_get($manifest, 'measurement.efficiency_claim_allowed') !== false) {
            return 'WRITE_GUARD_HOLD';
        }
        if (! $this->scopedCheck(data_get($manifest, 'validation.public_checks'), $productionSha, 'public')) {
            return 'PUBLIC_SCOPED_EVIDENCE_HOLD';
        }
        if (! $this->releaseReceipt(data_get($manifest, 'validation.ci'), 'CI', '.github/workflows/ci.yml', 'push', $productionSha)) {
            return 'CI_RELEASE_EVIDENCE_HOLD';
        }
        foreach (['staging', 'production'] as $environment) {
            $receipt = data_get($manifest, 'validation.'.$environment);
            if (! is_array($receipt) || ($receipt['sha'] ?? null) !== $productionSha
                || ($receipt['environment'] ?? null) !== $environment
                || ($receipt['check_scope'] ?? null) !== 'deployment_smoke_and_readonly_state'
                || ($receipt['status'] ?? null) !== 'pass'
                || ($receipt['completed_job'] ?? null) !== true
                || ! $this->positiveInteger($receipt['run_id'] ?? null)
                || ! $this->artifactDigest($receipt['artifact_digest'] ?? null)
                || ($receipt['pause_preserved'] ?? null) !== true
                || ($receipt['business_guards_closed'] ?? null) !== true) {
                return 'DEPLOYMENT_SMOKE_EVIDENCE_HOLD';
            }
        }
        $expectedVector = data_get($manifest, 'runtime.version_vector');
        $observed = $this->capabilities->snapshot()['version_vector'];
        if (! is_array($expectedVector) || count($expectedVector) !== count(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS)
            || ! $this->sameVector($expectedVector, $observed)
            || data_get($manifest, 'runtime.version_vector_hash') !== $this->hasher->hash($observed)) {
            return 'PUBLIC_VERSION_VECTOR_HOLD';
        }

        return 'READY';
    }

    private function scopedCheck(mixed $check, string $sha, string $scope): bool
    {
        return is_array($check) && ($check['scope_id'] ?? null) === $scope && ($check['check_scope'] ?? null) === 'a08_scoped_checks'
            && ($check['sha'] ?? null) === $sha && ($check['status'] ?? null) === 'pass'
            && ($check['scope_version'] ?? null) === 'seo-council-a08-dependencies.v2'
            && $this->digest($check['fingerprint'] ?? null)
            && $this->digest($check['result_digest'] ?? null)
            && is_array($check['tests'] ?? null)
            && array_diff(self::REQUIRED_TESTS[$scope], $check['tests']) === [];
    }

    private function permissionsClosed(mixed $permissions): bool
    {
        $keys = ['model_calls', 'tool_broker', 'cms_writes', 'publish_writes', 'canonical_writes',
            'robots_writes', 'url_truth_writes', 'search_submission', 'business_writes'];

        return is_array($permissions) && count($permissions) === count($keys)
            && array_diff(array_keys($permissions), $keys) === []
            && array_reduce($keys, static fn (bool $closed, string $key): bool => $closed
                && array_key_exists($key, $permissions) && $permissions[$key] === false, true);
    }

    private function sameVector(array $expected, array $observed): bool
    {
        foreach (Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS as $dimension) {
            if (! isset($expected[$dimension], $observed[$dimension])
                || ! is_string($expected[$dimension]) || ! is_string($observed[$dimension])
                || preg_match('/^[a-f0-9]{64}$/D', $expected[$dimension]) !== 1
                || ! hash_equals($expected[$dimension], $observed[$dimension])) {
                return false;
            }
        }

        return true;
    }

    private function releaseReceipt(mixed $receipt, string $workflow, string $path, string $event, string $sha): bool
    {
        return is_array($receipt)
            && ($receipt['repository'] ?? null) === 'fermatmind/fap-api'
            && ($receipt['workflow_name'] ?? null) === $workflow
            && ($receipt['workflow_path'] ?? null) === $path
            && ($receipt['head_branch'] ?? null) === 'main'
            && ($receipt['event'] ?? null) === $event
            && ($receipt['sha'] ?? null) === $sha
            && ($receipt['status'] ?? null) === 'success'
            && ($receipt['run_attempt'] ?? null) === 1
            && $this->positiveInteger($receipt['run_id'] ?? null)
            && $this->artifactDigest($receipt['artifact_digest'] ?? null);
    }

    private function positiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1;
    }

    private function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function artifactDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^sha256:[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array{state:string,manifest:null,production_sha:?string} */
    private function hold(?string $productionSha = null, string $reason = 'ACTIVATION_EVIDENCE_HOLD'): array
    {
        return ['state' => $reason, 'manifest' => null, 'production_sha' => $productionSha, 'missions' => []];
    }
}
