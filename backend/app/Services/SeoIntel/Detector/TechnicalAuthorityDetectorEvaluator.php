<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Detector;

use InvalidArgumentException;

final class TechnicalAuthorityDetectorEvaluator
{
    public const SUPPORTED_DETECTORS = [
        'http_404',
        'http_410',
        'http_5xx',
        'redirect_chain',
        'redirect_loop',
        'redirect_wrong_target',
        'false_noindex',
        'canonical_authority_drift',
        'hreflang_locale_counterpart_drift',
        'jsonld_visible_content_mismatch',
        'public_collection_split',
        'cms_published_shell',
        'runtime_api_timeout',
        'runtime_performance_degradation',
        'private_url_public_collection_leak',
        'data_sync_stale',
        'pagination_incomplete',
        'url_mapping_failure',
    ];

    public function __construct(
        private readonly SeoDetectorRegistry $registry = new SeoDetectorRegistry,
    ) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function evaluate(string $detectorId, array $evidence): array
    {
        $definition = $this->definition($detectorId);
        $context = $this->context($detectorId, $evidence);

        if (($evidence['source_state'] ?? 'available') !== 'available') {
            return $this->result($definition, $context, 'measurement_hold', 'source_unavailable', null, 'source_unavailable');
        }
        if (($evidence['evidence_complete'] ?? false) !== true || ($evidence['direct_evidence'] ?? false) !== true) {
            return $this->result($definition, $context, 'measurement_hold', 'insufficient_evidence', null, 'minimum_direct_evidence_not_met');
        }
        if (! $this->minimumEvidencePresent($detectorId, $evidence)) {
            return $this->result($definition, $context, 'measurement_hold', 'insufficient_evidence', null, 'detector_evidence_fields_incomplete');
        }
        if (! $this->applicable($definition, $context)) {
            return $this->result($definition, $context, 'pass', 'direct_evidence', null, 'not_applicable');
        }

        $decision = $this->decision($detectorId, $evidence);
        if ($decision['outcome'] === 'measurement_hold') {
            return $this->result($definition, $context, 'measurement_hold', 'insufficient_evidence', null, $decision['root_cause']);
        }

        $severity = $decision['outcome'] === 'issue'
            ? $this->severity($detectorId, $evidence)
            : null;

        return $this->result($definition, $context, $decision['outcome'], 'direct_evidence', $severity, $decision['root_cause']);
    }

    /** @return array<string,mixed> */
    private function definition(string $detectorId): array
    {
        if (! in_array($detectorId, self::SUPPORTED_DETECTORS, true)) {
            throw new InvalidArgumentException("Unsupported technical authority detector: {$detectorId}.");
        }

        return $this->registry->detectors()[$detectorId];
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function context(string $detectorId, array $evidence): array
    {
        return [
            'detector' => $detectorId,
            'page_family' => $this->safeAxis($evidence['page_family'] ?? 'unclassified'),
            'locale' => $this->safeAxis($evidence['locale'] ?? 'unknown'),
            'indexability_state' => $this->safeAxis($evidence['indexability_state'] ?? 'unknown'),
            'canonical_url_hash' => $this->sha256OrNull($evidence['canonical_url_hash'] ?? null),
            'authority_revision' => $this->safeRevision($evidence['authority_revision'] ?? null),
            'url_truth_revision' => $this->safeRevision($evidence['url_truth_revision'] ?? null),
            'policy_version' => $this->safeRevision($evidence['policy_version'] ?? null),
            'affected_url_count' => max(0, (int) ($evidence['affected_url_count'] ?? 1)),
        ];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $context */
    private function applicable(array $definition, array $context): bool
    {
        return in_array($context['page_family'], (array) data_get($definition, 'applicability.page_families', []), true)
            && in_array($context['locale'], (array) data_get($definition, 'applicability.locales', []), true)
            && in_array($context['indexability_state'], (array) data_get($definition, 'applicability.indexability_states', []), true);
    }

    /** @param array<string,mixed> $evidence @return array{outcome:string,root_cause:string} */
    private function decision(string $detectorId, array $evidence): array
    {
        $issue = match ($detectorId) {
            'http_404' => (int) ($evidence['observed_status'] ?? 0) === 404,
            'http_410' => (int) ($evidence['observed_status'] ?? 0) === 410
                && ($evidence['retirement_authority_matches'] ?? false) !== true,
            'http_5xx' => (int) ($evidence['observed_status'] ?? 0) >= 500
                && (int) ($evidence['observed_status'] ?? 0) <= 599,
            'redirect_chain' => (int) ($evidence['redirect_hop_count'] ?? 0) > 1,
            'redirect_loop' => ($evidence['redirect_loop_detected'] ?? false) === true,
            'redirect_wrong_target' => $this->differentHashes($evidence, 'observed_terminal_url_hash', 'expected_canonical_url_hash'),
            'false_noindex' => ($evidence['authority_indexable'] ?? false) === true
                && ($evidence['observed_noindex'] ?? false) === true,
            'canonical_authority_drift' => ($evidence['observed_canonical_redirect_only'] ?? false) === true
                || $this->differentHashes($evidence, 'observed_canonical_url_hash', 'authority_canonical_url_hash'),
            'hreflang_locale_counterpart_drift' => ($evidence['policy_requires_locale_pair'] ?? false) === true
                && (($evidence['counterpart_authority_exists'] ?? false) !== true
                    || $this->differentHashes($evidence, 'observed_counterpart_url_hash', 'expected_counterpart_url_hash')),
            'jsonld_visible_content_mismatch' => (int) ($evidence['field_diff_count'] ?? 0) > 0,
            'public_collection_split' => (int) ($evidence['collection_set_diff_count'] ?? 0) > 0,
            'cms_published_shell' => ($evidence['authority_published'] ?? false) === true
                && ((int) ($evidence['empty_body_count'] ?? 0) > 0
                    || (int) ($evidence['missing_required_module_count'] ?? 0) > 0
                    || (int) ($evidence['missing_metadata_count'] ?? 0) > 0),
            'runtime_api_timeout' => ($evidence['timed_out'] ?? false) === true,
            'runtime_performance_degradation' => ($evidence['performance_threshold_breached'] ?? false) === true,
            'private_url_public_collection_leak' => ($evidence['private_negative_set_match'] ?? false) === true
                && ($evidence['direct_public_collection_membership'] ?? false) === true,
            'data_sync_stale' => ($evidence['freshness_threshold_exceeded'] ?? false) === true,
            'pagination_incomplete' => ($evidence['termination_condition_valid'] ?? true) !== true
                || (int) ($evidence['rows_seen'] ?? 0) !== (int) ($evidence['rows_accounted'] ?? 0),
            'url_mapping_failure' => ($evidence['mapping_outcome'] ?? 'mapped') === 'failed',
            default => false,
        };

        if ($detectorId === 'http_410'
            && (int) ($evidence['observed_status'] ?? 0) === 410
            && ($evidence['retirement_authority_matches'] ?? false) === true) {
            return ['outcome' => 'pass', 'root_cause' => 'expected_retirement'];
        }
        if ($detectorId === 'hreflang_locale_counterpart_drift'
            && ($evidence['policy_requires_locale_pair'] ?? false) !== true) {
            return ['outcome' => 'pass', 'root_cause' => 'locale_pair_not_required'];
        }
        if ($detectorId === 'public_collection_split'
            && ($evidence['same_revision_snapshots'] ?? false) !== true) {
            return ['outcome' => 'measurement_hold', 'root_cause' => 'collection_revisions_not_comparable'];
        }
        if ($detectorId === 'runtime_performance_degradation'
            && (($evidence['evidence_kind'] ?? '') === 'lighthouse_lab'
                || ($evidence['field_cwv_connected'] ?? false) !== true
                    && (int) ($evidence['bounded_runtime_observation_count'] ?? 0) < 2)) {
            return ['outcome' => 'measurement_hold', 'root_cause' => 'field_cwv_or_repeated_runtime_evidence_unavailable'];
        }

        return [
            'outcome' => $issue ? 'issue' : 'pass',
            'root_cause' => $issue
                ? $this->safeAxis($evidence['root_cause_or_error_code'] ?? $detectorId)
                : $this->safeAxis($evidence['root_cause_or_error_code'] ?? 'condition_not_observed'),
        ];
    }

    /** @param array<string,mixed> $evidence */
    private function severity(string $detectorId, array $evidence): string
    {
        if ($detectorId === 'private_url_public_collection_leak') {
            return match ($evidence['verified_impact'] ?? 'bounded') {
                'critical' => 'P0',
                'high' => 'P1',
                'low' => 'P3',
                default => 'P2',
            };
        }
        if (in_array($detectorId, ['http_5xx', 'runtime_api_timeout'], true)
            && (int) ($evidence['consecutive_observation_count'] ?? 1) < 2) {
            return 'P3';
        }

        return match ($evidence['verified_impact'] ?? 'bounded') {
            'high' => 'P1',
            'low' => 'P3',
            default => 'P2',
        };
    }

    /** @param array<string,mixed> $evidence */
    private function minimumEvidencePresent(string $detectorId, array $evidence): bool
    {
        return match ($detectorId) {
            'http_404', 'http_5xx' => $this->hasInteger($evidence, 'observed_status'),
            'http_410' => $this->hasInteger($evidence, 'observed_status')
                && $this->hasBoolean($evidence, 'retirement_authority_matches'),
            'redirect_chain' => $this->hasInteger($evidence, 'redirect_hop_count'),
            'redirect_loop' => $this->hasBoolean($evidence, 'redirect_loop_detected'),
            'redirect_wrong_target' => $this->comparableHashes($evidence, 'observed_terminal_url_hash', 'expected_canonical_url_hash'),
            'false_noindex' => $this->hasBoolean($evidence, 'authority_indexable')
                && $this->hasBoolean($evidence, 'observed_noindex'),
            'canonical_authority_drift' => ($evidence['observed_canonical_redirect_only'] ?? false) === true
                || $this->comparableHashes($evidence, 'observed_canonical_url_hash', 'authority_canonical_url_hash'),
            'hreflang_locale_counterpart_drift' => $this->hreflangEvidencePresent($evidence),
            'jsonld_visible_content_mismatch' => $this->hasInteger($evidence, 'field_diff_count'),
            'public_collection_split' => $this->hasBoolean($evidence, 'same_revision_snapshots')
                && $this->hasInteger($evidence, 'collection_set_diff_count'),
            'cms_published_shell' => $this->hasBoolean($evidence, 'authority_published')
                && $this->hasInteger($evidence, 'empty_body_count')
                && $this->hasInteger($evidence, 'missing_required_module_count')
                && $this->hasInteger($evidence, 'missing_metadata_count'),
            'runtime_api_timeout' => $this->hasBoolean($evidence, 'timed_out'),
            'runtime_performance_degradation' => $this->hasBoolean($evidence, 'field_cwv_connected')
                && $this->hasInteger($evidence, 'bounded_runtime_observation_count')
                && $this->hasBoolean($evidence, 'performance_threshold_breached'),
            'private_url_public_collection_leak' => $this->hasBoolean($evidence, 'private_negative_set_match')
                && $this->hasBoolean($evidence, 'direct_public_collection_membership'),
            'data_sync_stale' => $this->hasBoolean($evidence, 'freshness_threshold_exceeded'),
            'pagination_incomplete' => $this->hasBoolean($evidence, 'termination_condition_valid')
                && $this->hasInteger($evidence, 'rows_seen')
                && $this->hasInteger($evidence, 'rows_accounted'),
            'url_mapping_failure' => in_array($evidence['mapping_outcome'] ?? null, ['mapped', 'failed'], true),
            default => false,
        };
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function result(array $definition, array $context, string $outcome, string $evidenceState, ?string $severity, string $rootCause): array
    {
        $clusterSeed = implode('|', [
            $context['detector'],
            $rootCause,
            $context['page_family'],
            $context['authority_revision'],
        ]);
        $dedupeSeed = implode('|', [
            $context['detector'],
            $context['canonical_url_hash'] ?? 'cluster',
            $context['locale'],
            $context['authority_revision'],
        ]);

        return [
            'schema_version' => 'seo-detector-result.v1',
            'registry_version' => SeoDetectorRegistry::VERSION,
            'registry_hash' => $this->registry->registryHash(),
            'detector' => $context['detector'],
            'detector_version' => $definition['version'],
            'outcome' => $outcome,
            'evidence_state' => $evidenceState,
            'severity' => $severity,
            'root_cause_or_error_code' => $rootCause,
            'page_family' => $context['page_family'],
            'locale' => $context['locale'],
            'authority_revision' => $context['authority_revision'],
            'url_truth_revision' => $context['url_truth_revision'],
            'policy_version' => $context['policy_version'],
            'canonical_url_hash' => $context['canonical_url_hash'],
            'affected_url_count' => $context['affected_url_count'],
            'cluster_uid' => 'seo_cluster_'.substr(hash('sha256', $clusterSeed), 0, 48),
            'dedupe_key' => hash('sha256', $dedupeSeed),
            'cluster_key_fields' => SeoDetectorRegistry::CLUSTER_KEY,
            'automation_cap' => $definition['automation_cap'],
            'human_intervention_required' => in_array($severity, ['P0', 'P1'], true),
            'privacy' => [
                'private_negative_set_checked' => true,
                'raw_urls_stored' => false,
                'raw_response_stored' => false,
                'sensitive_fields_stored' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $evidence */
    private function differentHashes(array $evidence, string $observedKey, string $expectedKey): bool
    {
        $observed = $this->sha256OrNull($evidence[$observedKey] ?? null);
        $expected = $this->sha256OrNull($evidence[$expectedKey] ?? null);

        return $observed !== null && $expected !== null && ! hash_equals($observed, $expected);
    }

    /** @param array<string,mixed> $evidence */
    private function comparableHashes(array $evidence, string $observedKey, string $expectedKey): bool
    {
        return $this->sha256OrNull($evidence[$observedKey] ?? null) !== null
            && $this->sha256OrNull($evidence[$expectedKey] ?? null) !== null;
    }

    /** @param array<string,mixed> $evidence */
    private function hreflangEvidencePresent(array $evidence): bool
    {
        if (! $this->hasBoolean($evidence, 'policy_requires_locale_pair')) {
            return false;
        }
        if (($evidence['policy_requires_locale_pair'] ?? false) !== true) {
            return true;
        }
        if (! $this->hasBoolean($evidence, 'counterpart_authority_exists')) {
            return false;
        }

        return ($evidence['counterpart_authority_exists'] ?? false) !== true
            || $this->comparableHashes($evidence, 'observed_counterpart_url_hash', 'expected_counterpart_url_hash');
    }

    /** @param array<string,mixed> $evidence */
    private function hasBoolean(array $evidence, string $key): bool
    {
        return array_key_exists($key, $evidence) && is_bool($evidence[$key]);
    }

    /** @param array<string,mixed> $evidence */
    private function hasInteger(array $evidence, string $key): bool
    {
        return array_key_exists($key, $evidence) && is_int($evidence[$key]);
    }

    private function sha256OrNull(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private function safeRevision(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) === 1 ? $value : 'unknown';
    }

    private function safeAxis(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9._:-]{1,128}$/', $value) === 1 ? $value : 'unknown';
    }
}
