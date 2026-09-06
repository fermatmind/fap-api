<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;

/** Validates the deploy-installed, data-only A08 activation manifest. */
final readonly class Platform12ActivationEvidence
{
    public const SCHEMA = 'seo.platform12_a08_activation.v2';

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
        if ($path === '' || ! is_file($path) || ! is_file($digestPath)) {
            return $this->hold('NOT_ACTIVATED_HOLD');
        }
        if (is_link($path) || is_link($digestPath)
            || ! is_readable($path) || filesize($path) > 65536
            || ! is_readable($digestPath) || filesize($digestPath) > 128
            || is_link($revisionPath) || ! is_file($revisionPath) || ! is_readable($revisionPath)) {
            return $this->hold('SECURITY_GATE_HOLD');
        }

        $bytes = file_get_contents($path);
        $expectedDigest = trim((string) file_get_contents($digestPath));
        $productionSha = strtolower(trim((string) file_get_contents($revisionPath)));
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_string($bytes) || ! is_array($manifest)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
            || ! hash_equals($expectedDigest, hash('sha256', $bytes))
            || preg_match('/^[a-f0-9]{40}$/D', $productionSha) !== 1) {
            return $this->hold('SECURITY_GATE_HOLD', $productionSha);
        }
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA) {
            return $this->hold('LEGACY_ACTIVATION_EVIDENCE_HOLD', $productionSha);
        }

        $invalid = $this->invalidReason($manifest, $productionSha);
        if ($invalid !== null) {
            return $this->hold($invalid, $productionSha);
        }

        return [
            'state' => ($manifest['activation_state'] ?? null) === 'ACTIVE_READ_ONLY'
                ? 'READY' : 'CONTROLLED_ACCEPTANCE_ONLY',
            'manifest' => $manifest,
            'production_sha' => $productionSha,
        ];
    }

    private function invalidReason(array $manifest, string $productionSha): ?string
    {
        if (($manifest['repository'] ?? null) !== 'fermatmind/fap-api'
            || ($manifest['activation_basis'] ?? null) !== 'A08_SCOPED_READ_ONLY_ACCEPTANCE'
            || ($manifest['bound_production_sha'] ?? null) !== $productionSha
            || ! in_array($manifest['activation_state'] ?? null, ['CONTROLLED_ACCEPTANCE_ONLY', 'ACTIVE_READ_ONLY'], true)
            || ! $this->permissionsClosed($manifest['permissions'] ?? null)
            || data_get($manifest, 'measurement.day_28_started') !== false
            || data_get($manifest, 'measurement.efficiency_claim_allowed') !== false
            || data_get($manifest, 'measurement.baseline_state') !== 'MEASUREMENT_BASELINE_HOLD') {
            return 'SECURITY_GATE_HOLD';
        }

        $ci = data_get($manifest, 'validation.ci');
        $deploy = data_get($manifest, 'validation.deploy');
        $staging = data_get($manifest, 'validation.staging_acceptance');
        $production = data_get($manifest, 'validation.production_smoke');
        if (! $this->releaseReceipt($ci, 'CI', '.github/workflows/ci.yml', 'push', $productionSha)
            || ! $this->releaseReceipt($deploy, 'Deploy', '.github/workflows/deploy.yml', 'workflow_run', $productionSha)
            || ! $this->acceptanceReceipt($staging, $productionSha)
            || ! is_array($production) || ($production['sha'] ?? null) !== $productionSha
            || ($production['status'] ?? null) !== 'pass'
            || ! $this->positiveInteger($production['deploy_run_id'] ?? null)
            || ($production['deploy_run_attempt'] ?? null) !== 1
            || ! $this->artifactDigest($production['artifact_digest'] ?? null)) {
            return 'SCOPED_ACCEPTANCE_EVIDENCE_HOLD';
        }

        $compatibility = $manifest['compatibility'] ?? null;
        $sourceSha = is_array($compatibility) ? ($compatibility['source_sha'] ?? null) : null;
        if (! is_array($compatibility)
            || ! in_array($compatibility['mode'] ?? null, ['exact_sha', 'compatible_descendant'], true)
            || ! $this->sha($sourceSha)
            || ($compatibility['bound_sha'] ?? null) !== $productionSha
            || ! $this->digest(data_get($compatibility, 'fingerprint.sha256'))
            || ! $this->positiveInteger(data_get($compatibility, 'fingerprint.file_count'))
            || data_get($compatibility, 'fingerprint.scope_version') !== 'seo-council-a08-runtime.v2'
            || (($compatibility['mode'] ?? null) === 'exact_sha' && $sourceSha !== $productionSha)
            || (($compatibility['mode'] ?? null) === 'compatible_descendant' && $sourceSha === $productionSha)) {
            return 'DEPENDENCY_CHANGED_HOLD';
        }

        $expectedVector = data_get($manifest, 'runtime.version_vector');
        $observed = $this->capabilities->snapshot()['version_vector'];
        if (! is_array($expectedVector)
            || array_diff(array_keys($expectedVector), Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS) !== []
            || array_diff(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS, array_keys($expectedVector)) !== []
            || count($expectedVector) !== count(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS)
            || ! $this->sameVector($expectedVector, $observed)
            || data_get($manifest, 'runtime.version_vector_hash') !== $this->hasher->hash($observed)) {
            return 'DEPENDENCY_CHANGED_HOLD';
        }

        if (($manifest['activation_state'] ?? null) === 'ACTIVE_READ_ONLY') {
            $acceptance = data_get($manifest, 'validation.production_controlled_acceptance');
            if (! is_array($acceptance)
                || ($acceptance['status'] ?? null) !== 'pass'
                || ($acceptance['source_connected'] ?? null) !== true
                || ($acceptance['mission_count'] ?? null) !== 3
                || ($acceptance['enabled_daily_missions'] ?? null) !== 3
                || ($acceptance['notification_configuration_verified'] ?? null) !== true
                || ($acceptance['receipt_to_ui_verified'] ?? null) !== true
                || ! is_array($acceptance['receipt_hashes'] ?? null)
                || count($acceptance['receipt_hashes']) !== 3
                || count(array_filter($acceptance['receipt_hashes'], fn (mixed $hash): bool => $this->digest($hash))) !== 3) {
                return 'PRODUCTION_CONTROLLED_ACCEPTANCE_HOLD';
            }
        }

        return null;
    }

    private function acceptanceReceipt(mixed $receipt, string $sha): bool
    {
        return is_array($receipt)
            && ($receipt['sha'] ?? null) === $sha
            && ($receipt['status'] ?? null) === 'pass'
            && ($receipt['source_connected'] ?? null) === true
            && ($receipt['mission_count'] ?? null) === 3
            && ($receipt['trigger_mode'] ?? null) === 'controlled_acceptance'
            && ($receipt['natural_slot_receipt'] ?? null) === false
            && ! empty($receipt['notification_delivery_verified'])
            && ! empty($receipt['pause_resume_verified'])
            && ! empty($receipt['receipt_to_ui_verified'])
            && $this->positiveInteger($receipt['deploy_run_id'] ?? null)
            && ($receipt['deploy_run_attempt'] ?? null) === 1
            && $this->artifactDigest($receipt['artifact_digest'] ?? null);
    }

    private function permissionsClosed(mixed $permissions): bool
    {
        $falseKeys = ['model_runtime_enabled', 'tool_broker_enabled', 'post12_agent_write_enabled',
            'cms_agent_write', 'publish', 'canonical_write', 'robots_write', 'url_truth_write',
            'search_submission', 'business_write_enabled', 'L3', 'L4'];

        return is_array($permissions)
            && ($permissions['L2'] ?? null) === 'artifact_only'
            && ($permissions['active_action_manifest_count'] ?? null) === 0
            && ($permissions['trusted_signing_key_count'] ?? null) === 0
            && array_reduce($falseKeys, static fn (bool $closed, string $key): bool => $closed
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
    private function hold(string $state, ?string $productionSha = null): array
    {
        return ['state' => $state, 'manifest' => null, 'production_sha' => $productionSha];
    }
}
