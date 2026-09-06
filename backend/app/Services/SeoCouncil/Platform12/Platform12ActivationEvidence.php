<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RuntimeCapabilitySnapshotBuilder;

/** Validates the deploy-installed, data-only A08 activation manifest. */
final readonly class Platform12ActivationEvidence
{
    public const SCHEMA = 'seo.platform12_a08_activation.v1';

    private const REQUIRED_DOMAINS = [
        'authority_contract',
        'full_phpunit',
        'dependency_audit',
        'workflow_contracts',
        'security_scan',
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
            return $this->hold();
        }

        $bytes = file_get_contents($path);
        $expectedDigest = trim((string) file_get_contents($digestPath));
        $productionSha = strtolower(trim((string) file_get_contents($revisionPath)));
        $manifest = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_string($bytes) || ! is_array($manifest)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedDigest) !== 1
            || ! hash_equals($expectedDigest, hash('sha256', $bytes))
            || preg_match('/^[a-f0-9]{40}$/D', $productionSha) !== 1
            || ! $this->valid($manifest, $productionSha)) {
            return $this->hold($productionSha);
        }

        return ['state' => 'READY', 'manifest' => $manifest, 'production_sha' => $productionSha];
    }

    private function valid(array $manifest, string $productionSha): bool
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA
            || ($manifest['repository'] ?? null) !== 'fermatmind/fap-api'
            || ($manifest['bound_production_sha'] ?? null) !== $productionSha
            || ! $this->permissionsClosed($manifest['permissions'] ?? null)
            || data_get($manifest, 'measurement.day_28_started') !== false
            || data_get($manifest, 'measurement.efficiency_claim_allowed') !== false) {
            return false;
        }

        $nightly = data_get($manifest, 'validation.nightly');
        if (! is_array($nightly)
            || ($nightly['repository'] ?? null) !== 'fermatmind/fap-api'
            || ($nightly['workflow_name'] ?? null) !== 'Nightly'
            || ($nightly['workflow_path'] ?? null) !== '.github/workflows/nightly.yml'
            || ($nightly['head_branch'] ?? null) !== 'main'
            || ($nightly['event'] ?? null) !== 'schedule'
            || ($nightly['run_attempt'] ?? null) !== 1
            || ! $this->positiveInteger($nightly['run_id'] ?? null)
            || ! $this->sha($nightly['sha'] ?? null)
            || ! $this->artifactDigest($nightly['artifact_digest'] ?? null)
            || ! $this->digest($nightly['receipt_digest'] ?? null)
            || ($nightly['check_scope'] ?? null) !== 'weekly_full_checks'
            || ($nightly['status'] ?? null) !== 'pass') {
            return false;
        }
        foreach (self::REQUIRED_DOMAINS as $domain) {
            if (data_get($nightly, 'domains.'.$domain.'.required') !== true
                || data_get($nightly, 'domains.'.$domain.'.result') !== 'success') {
                return false;
            }
        }

        $ci = data_get($manifest, 'validation.ci');
        $deploy = data_get($manifest, 'validation.deploy');
        $staging = data_get($manifest, 'validation.staging_acceptance');
        if (! $this->releaseReceipt($ci, 'CI', '.github/workflows/ci.yml', 'push', $productionSha)
            || ! $this->releaseReceipt($deploy, 'Deploy', '.github/workflows/deploy.yml', 'workflow_run', $productionSha)
            || ! is_array($staging) || ($staging['sha'] ?? null) !== $nightly['sha']
            || ($staging['status'] ?? null) !== 'pass'
            || ($staging['mission_count'] ?? null) !== 3
            || ! $this->positiveInteger($staging['deploy_run_id'] ?? null)
            || ($staging['deploy_run_attempt'] ?? null) !== 1
            || ! $this->artifactDigest($staging['artifact_digest'] ?? null)) {
            return false;
        }

        $compatibility = $manifest['compatibility'] ?? null;
        $sourceSha = $nightly['sha'];
        if (! is_array($compatibility)
            || ! in_array($compatibility['mode'] ?? null, ['exact_sha', 'compatible_descendant'], true)
            || ($compatibility['source_sha'] ?? null) !== $sourceSha
            || ($compatibility['bound_sha'] ?? null) !== $productionSha
            || ! $this->digest(data_get($compatibility, 'fingerprint.sha256'))
            || ! $this->positiveInteger(data_get($compatibility, 'fingerprint.file_count'))
            || data_get($compatibility, 'fingerprint.scope_version') !== 'seo-council-a08-runtime.v1'
            || (($compatibility['mode'] ?? null) === 'exact_sha' && $sourceSha !== $productionSha)
            || (($compatibility['mode'] ?? null) === 'compatible_descendant' && $sourceSha === $productionSha)) {
            return false;
        }

        $expectedVector = data_get($manifest, 'runtime.version_vector');
        $observed = $this->capabilities->snapshot()['version_vector'];

        return is_array($expectedVector)
            && array_diff(array_keys($expectedVector), Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS) === []
            && array_diff(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS, array_keys($expectedVector)) === []
            && count($expectedVector) === count(Platform12ReadOnlyRuntimeGate::VERSION_DIMENSIONS)
            && $this->sameVector($expectedVector, $observed)
            && data_get($manifest, 'runtime.version_vector_hash') === $this->hasher->hash($observed);
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
    private function hold(?string $productionSha = null): array
    {
        return ['state' => 'FULL_NIGHTLY_EVIDENCE_HOLD', 'manifest' => null, 'production_sha' => $productionSha];
    }
}
