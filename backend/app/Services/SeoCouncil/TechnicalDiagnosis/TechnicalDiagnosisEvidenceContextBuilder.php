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
    private const ALLOWED_FIELDS = [
        'detector_code', 'url_truth_public_projection', 'backend_authority_hash',
        'publication_indexability_state', 'runtime_status', 'sanitized_html_observations',
        'final_url_redirect_summary', 'canonical', 'meta_robots', 'x_robots_tag',
        'hreflang', 'jsonld_visible_content_parity', 'public_api_source_hash',
        'cache_revision', 'sitemap_evidence', 'llms_evidence', 'llms_full_evidence',
        'deployment_sha', 'page_family', 'locale', 'sanitized_public_url_reference',
        'observations', 'source_count', 'repeat_observation', 'revision_consistent',
        'shared_component', 'affected_url_count', 'affected_family_count',
    ];

    /** @var list<string> */
    private const SOURCE_TYPES = [
        'runtime_observation', 'url_truth_projection', 'detector_result', 'backend_authority',
        'publication_projection', 'public_api_projection', 'feed_observation',
        'cache_projection', 'release_evidence',
    ];

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
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param list<array<string, mixed>> $bundles @param array<string, mixed> $dependency @return array<string, mixed> */
    public function build(array $request, array $bundles, array $dependency): array
    {
        $status = 'READY';
        if (! $this->contracts->request($request)) {
            $status = 'EVIDENCE_HOLD';
        } elseif (! $this->dependencies->verify($dependency, (string) ($dependency['production_sha'] ?? ''))
            || ($dependency['status'] ?? null) !== 'READY') {
            $status = 'DEPENDENCY_HOLD';
        } elseif ($bundles === []) {
            $status = 'EVIDENCE_HOLD';
        }

        $payload = [];
        $refs = [];
        $lineage = [];
        $redactions = [];
        $authorityRevisions = [];
        $deploymentRevisions = [];
        $requestedRefs = [];
        foreach ((array) ($request['evidence_bundle_refs'] ?? []) as $ref) {
            if (is_array($ref)) {
                $requestedRefs[(string) ($ref['bundle_hash'] ?? '')] = $ref;
            }
        }

        foreach ($bundles as $bundle) {
            $verification = $this->verifier->verify($bundle);
            $hash = (string) ($bundle['bundle_hash'] ?? '');
            $ref = $requestedRefs[$hash] ?? null;
            if (! $verification['valid']) {
                $status = ($verification['code'] ?? null) === 'PRIVATE_DATA_PRESENT'
                    ? 'PRIVATE_DATA_HOLD'
                    : ($status === 'READY' ? 'EVIDENCE_HOLD' : $status);

                continue;
            }
            if (! is_array($ref)
                || ($bundle['bundle_id'] ?? null) !== ($ref['bundle_id'] ?? null)
                || ($bundle['bundle_version'] ?? null) !== ($ref['bundle_version'] ?? null)
                || ($bundle['mission_id'] ?? null) !== ($request['mission_id'] ?? null)
                || ($bundle['page_family'] ?? null) !== ($request['page_family'] ?? null)
                || ($bundle['locale'] ?? null) !== ($request['locale'] ?? null)
                || ! in_array($bundle['source_type'] ?? null, self::SOURCE_TYPES, true)) {
                $status = $status === 'READY' ? 'EVIDENCE_HOLD' : $status;

                continue;
            }
            if ($this->containsForbidden($bundle['payload'] ?? null)
                || $this->scanner->scan($bundle['payload'] ?? null, SeoPrivateDataScanner::MINIMIZED_PAYLOAD_HASH_PATHS)['private_data_present']) {
                $status = 'PRIVATE_DATA_HOLD';

                continue;
            }
            try {
                if (CarbonImmutable::parse((string) $bundle['expires_at'])->utc()->isPast()) {
                    $status = $status === 'READY' ? 'MEASUREMENT_HOLD' : $status;
                }
            } catch (Throwable) {
                $status = $status === 'READY' ? 'EVIDENCE_HOLD' : $status;
            }
            if (($bundle['source_capability_state'] ?? null) !== 'available') {
                $status = $status === 'READY' ? 'SOURCE_CAPABILITY_UNAVAILABLE' : $status;
            }
            if (($bundle['freshness_state'] ?? null) !== 'fresh') {
                $status = $status === 'READY' ? 'MEASUREMENT_HOLD' : $status;
            }
            $authorityRevisions[(string) ($bundle['authority_revision'] ?? '')] = true;
            $deployment = data_get($bundle, 'payload.deployment_sha');
            if (is_string($deployment) && $deployment !== '') {
                $deploymentRevisions[$deployment] = true;
            }
            foreach ((array) ($bundle['payload'] ?? []) as $field => $value) {
                if (in_array((string) $field, self::ALLOWED_FIELDS, true)) {
                    $payload[(string) $field] = $value;
                } else {
                    $redactions[(string) $field] = true;
                }
            }
            $refs[] = ['bundle_id' => $bundle['bundle_id'], 'bundle_version' => $bundle['bundle_version'], 'bundle_hash' => $hash];
            $lineage = array_merge($lineage, (array) ($bundle['lineage_refs'] ?? []));
        }

        if (count($refs) !== count($requestedRefs) && $status === 'READY') {
            $status = 'EVIDENCE_HOLD';
        }
        if (count(array_filter(array_keys($authorityRevisions), 'strlen')) !== 1
            || ! isset($authorityRevisions[(string) ($request['authority_revision'] ?? '')])
            || ($dependency['authority_revision'] ?? null) !== ($request['authority_revision'] ?? null)) {
            $status = $status === 'PRIVATE_DATA_HOLD' ? $status : 'AUTHORITY_CONFLICT_HOLD';
        }
        if ($deploymentRevisions !== []
            && (count($deploymentRevisions) !== 1
                || ! isset($deploymentRevisions[(string) ($request['deployment_revision'] ?? '')]))) {
            $status = $status === 'PRIVATE_DATA_HOLD' ? $status : 'AUTHORITY_CONFLICT_HOLD';
        }
        $publicRef = $payload['sanitized_public_url_reference'] ?? null;
        if (is_string($publicRef) && ! $this->publicReferenceAllowed($publicRef)) {
            $status = 'PRIVATE_DATA_HOLD';
        }
        if ($this->containsForbidden($payload)) {
            $status = 'PRIVATE_DATA_HOLD';
        }
        if ($status !== 'READY') {
            $payload = [];
            $refs = [];
            $lineage = [];
            $redactions = [];
        }
        $lineage = array_values(array_unique(array_filter($lineage, 'is_string')));
        sort($lineage, SORT_STRING);
        $redactedFields = array_keys($redactions);
        sort($redactedFields, SORT_STRING);
        $context = [
            'context_id' => $this->hasher->hash([$request['request_hash'] ?? null, $refs, $status]),
            'context_version' => 'seo.technical_diagnosis_evidence_context.v1',
            'request_hash' => $request['request_hash'] ?? null,
            'bundle_refs' => $refs,
            'payload' => $payload,
            'lineage_refs' => $lineage,
            'redaction_summary' => ['redacted_field_count' => count($redactedFields), 'redacted_fields' => $redactedFields],
            'status' => $status,
            'diagnosis_allowed' => $status === 'READY',
            'execution_allowed' => false,
        ];
        $context['context_hash'] = $this->hasher->hash($context);

        return $context;
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
        for ($i = 0; $i < 5; $i++) {
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
                if (array_intersect($tokens, self::FORBIDDEN_TOKENS) !== []) {
                    return true;
                }
                if ($this->containsForbidden($child, $normalized)) {
                    return true;
                }
            }

            return false;
        }
        if (is_string($value) && in_array($parent, ['sanitized_public_url_reference'], true)) {
            return ! $this->publicReferenceAllowed($value);
        }

        return false;
    }
}
