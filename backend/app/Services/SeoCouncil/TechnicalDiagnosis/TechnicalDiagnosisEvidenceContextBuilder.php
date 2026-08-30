<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentEvidence\Bundle\SeoEvidenceBundleVerifier;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;
use App\Services\SeoAgentEvidence\Privacy\SeoPrivateRouteNegativeSet;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use Carbon\CarbonImmutable;
use Throwable;

final class TechnicalDiagnosisEvidenceContextBuilder
{
    /** @var list<string> */
    private const FORBIDDEN_TOKENS = [
        'result', 'results', 'attempt', 'attempts', 'report', 'reports', 'history',
        'order', 'orders', 'checkout', 'payment', 'payments', 'account', 'accounts',
        'auth', 'authorization', 'invite', 'invites', 'recovery', 'token', 'tokens',
        'user', 'users', 'identity', 'cookie', 'credential', 'session', 'private', 'share', 'shares',
    ];

    public function __construct(
        private readonly SeoEvidenceBundleVerifier $verifier,
        private readonly SeoPrivateDataScanner $scanner,
        private readonly SeoPrivateRouteNegativeSet $negativeSet,
        private readonly TechnicalDiagnosisContractValidator $contracts,
        private readonly TechnicalDiagnosisDependencySnapshotBuilder $dependencies,
        private readonly TechnicalDiagnosisSourceFieldOwnership $ownership,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param list<array<string, mixed>> $bundles @param array<string, mixed> $dependency @return array<string, mixed> */
    public function build(array $request, array $bundles, array $dependency): array
    {
        $status = $this->bindingStatus($request, $dependency, $bundles);
        $namespaces = $this->emptyNamespaces();
        $refs = [];
        $lineage = [];
        $redactions = [];
        $sourceIdentities = [];
        $sourceTypes = [];
        $authorityRevisions = [];
        $runtimeObservationIds = [];
        $runtimeNodeIds = [];
        $publicRefs = [];
        $requestedRefs = [];
        foreach ((array) ($request['evidence_bundle_refs'] ?? []) as $ref) {
            if (is_array($ref)) {
                $requestedRefs[(string) ($ref['bundle_hash'] ?? '')] = $ref;
            }
        }
        usort($bundles, static fn (array $left, array $right): int => strcmp((string) ($left['bundle_hash'] ?? ''), (string) ($right['bundle_hash'] ?? '')));

        foreach ($bundles as $bundle) {
            $verification = $this->verifier->verify($bundle);
            $hash = (string) ($bundle['bundle_hash'] ?? '');
            $ref = $requestedRefs[$hash] ?? null;
            if (! $verification['valid']) {
                $status = ($verification['code'] ?? null) === 'PRIVATE_DATA_PRESENT' ? 'PRIVATE_DATA_HOLD' : $this->hold($status, 'EVIDENCE_HOLD');

                continue;
            }
            if (! is_array($ref)
                || ($bundle['bundle_id'] ?? null) !== ($ref['bundle_id'] ?? null)
                || ($bundle['bundle_version'] ?? null) !== ($ref['bundle_version'] ?? null)
                || ($bundle['source_type'] ?? null) !== ($ref['source_type'] ?? null)
                || ($bundle['authority_type'] ?? null) !== ($ref['authority_type'] ?? null)
                || ($bundle['mission_id'] ?? null) !== ($request['mission_id'] ?? null)
                || ($bundle['page_family'] ?? null) !== ($request['page_family'] ?? null)
                || ($bundle['locale'] ?? null) !== ($request['locale'] ?? null)) {
                $status = $this->hold($status, 'EVIDENCE_HOLD');

                continue;
            }
            $rule = $this->ownership->rule((string) $bundle['source_type'], (string) $bundle['authority_type']);
            $payload = (array) ($bundle['payload'] ?? []);
            if (! is_array($rule) || ! $this->ownership->fieldsAllowed($payload, $rule)) {
                $status = $this->hold($status, 'EVIDENCE_HOLD');

                continue;
            }
            if ($this->containsForbidden($payload)
                || $this->scanner->scan($payload, SeoPrivateDataScanner::MINIMIZED_PAYLOAD_HASH_PATHS)['private_data_present']) {
                $status = 'PRIVATE_DATA_HOLD';

                continue;
            }
            try {
                if (CarbonImmutable::parse((string) $bundle['expires_at'])->utc()->isPast()) {
                    $status = $this->hold($status, 'MEASUREMENT_HOLD');
                }
            } catch (Throwable) {
                $status = $this->hold($status, 'EVIDENCE_HOLD');
            }
            if (($bundle['source_capability_state'] ?? null) !== 'available') {
                $status = $this->hold($status, 'SOURCE_CAPABILITY_UNAVAILABLE');
            }
            if (($bundle['freshness_state'] ?? null) !== 'fresh') {
                $status = $this->hold($status, 'MEASUREMENT_HOLD');
            }
            $authorityRevision = (string) ($bundle['authority_revision'] ?? '');
            $authorityRevisions[$authorityRevision] = true;
            if ($authorityRevision !== ($request['authority_revision'] ?? null)) {
                $status = $this->hold($status, 'AUTHORITY_CONFLICT_HOLD');
            }
            if (($bundle['source_type'] ?? null) === 'release_evidence'
                && ($payload['deployment_sha'] ?? $payload['deployment_revision'] ?? null) !== ($request['deployment_revision'] ?? null)) {
                $status = $this->hold($status, 'AUTHORITY_CONFLICT_HOLD');
            }
            $namespacePayload = $payload;
            if (($bundle['source_type'] ?? null) === 'runtime_observation') {
                unset($namespacePayload['observation_id'], $namespacePayload['node_id']);
            }
            $conflict = $this->mergeNamespace($namespaces, (string) $rule['namespace'], $namespacePayload);
            if ($conflict) {
                $status = $this->hold($status, 'AUTHORITY_CONFLICT_HOLD');
            }
            $sourceIdentities[(string) $bundle['source_type'].'|'.(string) $bundle['source_ref']] = true;
            $sourceTypes[(string) $bundle['source_type']] = true;
            if (($bundle['source_type'] ?? null) === 'runtime_observation') {
                $runtimeObservationIds[(string) ($payload['observation_id'] ?? $bundle['source_ref'])] = true;
                if (is_string($payload['node_id'] ?? null) && $payload['node_id'] !== '') {
                    $runtimeNodeIds[$payload['node_id']] = true;
                }
            }
            foreach (['sanitized_public_url_reference'] as $field) {
                if (is_string($payload[$field] ?? null) && $this->publicReferenceAllowed($payload[$field])) {
                    $publicRefs[$payload[$field]] = true;
                }
            }
            $refs[] = [
                'bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'],
                'bundle_hash' => $hash, 'source_type' => $bundle['source_type'], 'authority_type' => $bundle['authority_type'],
            ];
            $lineage = array_merge($lineage, (array) ($bundle['lineage_refs'] ?? []));
        }

        if (count($refs) !== count($requestedRefs)) {
            $status = $this->hold($status, 'EVIDENCE_HOLD');
        }
        if (count(array_filter(array_keys($authorityRevisions), 'strlen')) !== 1) {
            $status = $this->hold($status, 'AUTHORITY_CONFLICT_HOLD');
        }
        $detector = (string) data_get($namespaces, 'detector.detector_code', '');
        $required = $this->requiredSourceTypes($detector);
        $requiredPresent = array_diff($required, array_keys($sourceTypes)) === [];
        $requestedPublicRefs = $this->sanitizePublicReferences((array) data_get($request, 'requested_scope.sanitized_public_refs', []));
        foreach ($requestedPublicRefs as $publicRef) {
            $publicRefs[$publicRef] = true;
        }
        $runtimeCount = count($runtimeObservationIds);
        $computed = [
            'source_count' => count($sourceIdentities),
            'runtime_observation_count' => $runtimeCount,
            'node_count' => count($runtimeNodeIds),
            'affected_url_count' => count($publicRefs),
            'affected_family_count' => count($publicRefs) > 0 ? 1 : 0,
            'repeat_observation' => $runtimeCount >= 2,
            'current_revision_consistent' => count($authorityRevisions) === 1
                && isset($authorityRevisions[(string) ($request['authority_revision'] ?? '')])
                && data_get($namespaces, 'release.deployment_sha', data_get($namespaces, 'release.deployment_revision')) === ($request['deployment_revision'] ?? null),
            'direct_reproducible_observation' => $runtimeCount >= 2,
            'required_authority_sources_present' => $requiredPresent,
            'source_types' => array_keys($sourceTypes),
        ];
        sort($computed['source_types'], SORT_STRING);
        usort($refs, static fn (array $left, array $right): int => strcmp($left['bundle_hash'], $right['bundle_hash']));
        $lineage = array_values(array_unique(array_filter($lineage, 'is_string')));
        sort($lineage, SORT_STRING);
        ksort($namespaces, SORT_STRING);
        if ($status !== 'READY') {
            $namespaces = $this->emptyNamespaces();
            $computed['direct_reproducible_observation'] = false;
            $computed['required_authority_sources_present'] = false;
        }
        $context = [
            'context_id' => $this->hasher->hash([$request['request_hash'] ?? null, $refs, $status]),
            'context_version' => 'seo.technical_diagnosis_evidence_context.v2',
            'request_hash' => $request['request_hash'] ?? null,
            'bundle_refs' => $status === 'READY' ? $refs : [],
            'namespaces' => $namespaces,
            'computed_evidence' => $computed,
            'lineage_refs' => $status === 'READY' ? $lineage : [],
            'redaction_summary' => ['redacted_field_count' => count($redactions), 'redacted_fields' => array_keys($redactions)],
            'status' => $status,
            'diagnosis_allowed' => $status === 'READY',
            'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $dependency @param list<array<string, mixed>> $bundles */
    private function bindingStatus(array $request, array $dependency, array $bundles): string
    {
        if (! $this->contracts->request($request)) {
            return 'EVIDENCE_HOLD';
        }
        $ref = (array) $request['dependency_snapshot_ref'];
        $environment = (string) ($ref['environment'] ?? '');
        if (! $this->dependencies->verify($dependency, (string) ($ref['production_sha'] ?? ''), $environment)
            || ($dependency['status'] ?? null) !== 'READY'
            || $ref !== $this->dependencies->requestReference($dependency)) {
            return 'DEPENDENCY_HOLD';
        }
        if (($request['detector_registry_ref'] ?? null) !== ($dependency['detector_registry_ref'] ?? null)
            || ($request['url_truth_revision'] ?? null) !== ($dependency['url_truth_revision'] ?? null)
            || ($request['runtime_revision'] ?? null) !== ($dependency['runtime_evidence_revision'] ?? null)
            || ($request['deployment_revision'] ?? null) !== ($dependency['deployment_revision'] ?? null)
            || ($request['authority_revision'] ?? null) !== ($dependency['authority_revision'] ?? null)
            || $bundles === []) {
            return 'DEPENDENCY_HOLD';
        }

        return 'READY';
    }

    /** @param array<string, mixed> $namespaces @param array<string, mixed> $payload */
    private function mergeNamespace(array &$namespaces, string $namespace, array $payload): bool
    {
        if ($namespace === 'authority.backend') {
            $target = &$namespaces['authority']['backend'];
        } elseif ($namespace === 'authority.url_truth') {
            $target = &$namespaces['authority']['url_truth'];
        } elseif ($namespace === 'authority.page_family_policy') {
            $target = &$namespaces['authority']['page_family_policy'];
        } elseif (array_key_exists($namespace, $namespaces) && is_array($namespaces[$namespace])) {
            $target = &$namespaces[$namespace];
        } else {
            return true;
        }
        $conflict = false;
        foreach ($payload as $field => $value) {
            if (array_key_exists($field, $target) && $target[$field] !== $value) {
                $conflict = true;

                continue;
            }
            $target[$field] = $value;
        }
        ksort($target, SORT_STRING);

        return $conflict;
    }

    /** @return array<string, int> */
    public function ownershipProbeMetrics(): array
    {
        $invalid = [
            ['runtime_observation', 'public_runtime_observation', ['backend_exists' => true]],
            ['runtime_observation', 'public_runtime_observation', ['url_truth_canonical' => '/forged']],
            ['feed_observation', 'feed_observation', ['policy_indexable' => true]],
            ['cache_projection', 'cache_observation', ['url_truth_canonical' => '/forged']],
            ['unknown_source', 'public_runtime_observation', ['runtime_status' => 200]],
            ['runtime_observation', 'url_truth_authority', ['runtime_status' => 200]],
            ['runtime_observation', 'public_runtime_observation', [
                'source_count' => 3, 'repeat_observation' => true, 'affected_url_count' => 100,
                'node_count' => 4, 'reproducible' => true,
            ]],
        ];
        $fieldBypasses = 0;
        foreach ($invalid as [$source, $authority, $payload]) {
            $rule = $this->ownership->rule($source, $authority);
            $fieldBypasses += (int) (is_array($rule) && $this->ownership->fieldsAllowed($payload, $rule));
        }
        $left = $this->emptyNamespaces();
        $right = $this->emptyNamespaces();
        $leftConflict = $this->mergeNamespace($left, 'runtime', ['runtime_status' => 200]);
        $leftConflict = $this->mergeNamespace($left, 'runtime', ['runtime_status' => 404]) || $leftConflict;
        $rightConflict = $this->mergeNamespace($right, 'runtime', ['runtime_status' => 404]);
        $rightConflict = $this->mergeNamespace($right, 'runtime', ['runtime_status' => 200]) || $rightConflict;

        return [
            'cross_source_field_bypass' => $fieldBypasses,
            'cross_source_overwrite_bypass' => (int) (! $leftConflict || ! $rightConflict),
            'bundle_order_variance_count' => (int) ($leftConflict !== $rightConflict),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyNamespaces(): array
    {
        return [
            'authority' => ['backend' => [], 'url_truth' => [], 'page_family_policy' => []],
            'runtime' => [], 'detector' => [], 'publication' => [], 'public_api' => [],
            'feeds' => [], 'cache' => [], 'release' => [],
        ];
    }

    /** @return list<string> */
    private function requiredSourceTypes(string $detector): array
    {
        return match ($detector) {
            'false_404' => ['backend_authority', 'url_truth_projection', 'runtime_observation', 'release_evidence'],
            'false_noindex' => ['page_family_policy', 'url_truth_projection', 'runtime_observation', 'release_evidence'],
            'canonical_drift' => ['backend_authority', 'url_truth_projection', 'runtime_observation', 'release_evidence'],
            'feed_drift' => ['url_truth_projection', 'feed_observation', 'release_evidence'],
            'shared_api_root_cause' => ['runtime_observation', 'release_evidence'],
            default => ['detector_result', 'runtime_observation', 'release_evidence'],
        };
    }

    /** @param list<string> $refs @return list<string> */
    public function sanitizePublicReferences(array $refs): array
    {
        return array_values(array_filter($refs, fn (mixed $ref): bool => is_string($ref) && $this->publicReferenceAllowed($ref)));
    }

    public function publicReferenceAllowed(string $reference): bool
    {
        if (strlen($reference) > 512 || str_contains($reference, '#') || str_contains($reference, '?')) {
            return false;
        }
        $decoded = mb_strtolower($reference, 'UTF-8');
        for ($index = 0; $index < 5; $index++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        if ($this->negativeSet->classify($decoded)['private']) {
            return false;
        }
        $tokens = preg_split('/[^a-z0-9]+/', $decoded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($tokens, self::FORBIDDEN_TOKENS) === [];
    }

    private function containsForbidden(mixed $value, ?string $parent = null): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $normalized = strtolower((string) $key);
                $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (array_intersect($tokens, self::FORBIDDEN_TOKENS) !== [] || $this->containsForbidden($child, $normalized)) {
                    return true;
                }
            }

            return false;
        }
        if (is_string($value) && $parent === 'sanitized_public_url_reference') {
            return ! $this->publicReferenceAllowed($value);
        }

        return false;
    }

    private function hold(string $current, string $next): string
    {
        return $current === 'PRIVATE_DATA_HOLD' ? $current : ($current === 'READY' ? $next : $current);
    }
}
