<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Detector;

use InvalidArgumentException;

final class SearchContentLinkDetectorEvaluator
{
    public const SUPPORTED_DETECTORS = [
        'query_page_owner_conflict',
        'keyword_cannibalization',
        'high_impressions_low_ctr',
        'position_4_15_opportunity',
        'content_decay_candidate',
        'review_overdue',
        'orphan_page',
        'insufficient_internal_links',
        'gsc_funnel_freshness',
        'gsc_canonical_unmapped_url_truth',
    ];

    private const GSC_QUALITY_GATED = [
        'keyword_cannibalization',
        'high_impressions_low_ctr',
        'position_4_15_opportunity',
        'content_decay_candidate',
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
        if (in_array($detectorId, self::GSC_QUALITY_GATED, true)
            && ($evidence['gsc_quality_gate_pass'] ?? false) !== true) {
            return $this->result($definition, $context, 'measurement_hold', 'insufficient_evidence', null, 'gsc_quality_gate_failed');
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
            ? $this->severity($evidence)
            : null;

        return $this->result($definition, $context, $decision['outcome'], 'direct_evidence', $severity, $decision['root_cause']);
    }

    /** @return array<string, mixed> */
    private function definition(string $detectorId): array
    {
        if (! in_array($detectorId, self::SUPPORTED_DETECTORS, true)) {
            throw new InvalidArgumentException("Unsupported search/content/link detector: {$detectorId}.");
        }

        return $this->registry->detectors()[$detectorId];
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function context(string $detectorId, array $evidence): array
    {
        return [
            'detector' => $detectorId,
            'page_family' => $this->safeAxis($evidence['page_family'] ?? 'unclassified'),
            'locale' => $this->safeAxis($evidence['locale'] ?? 'unknown'),
            'indexability_state' => $this->safeAxis($evidence['indexability_state'] ?? 'unknown'),
            'canonical_url_hash' => $this->sha256OrNull($evidence['canonical_url_hash'] ?? null),
            'query_hash' => $this->sha256OrNull($evidence['query_hash'] ?? null),
            'query_segment' => $this->safeQuerySegment($evidence['query_segment'] ?? null),
            'authority_revision' => $this->safeRevision($evidence['authority_revision'] ?? null),
            'url_truth_revision' => $this->safeRevision($evidence['url_truth_revision'] ?? null),
            'policy_version' => $this->safeRevision($evidence['policy_version'] ?? null),
            'affected_url_count' => max(0, (int) ($evidence['affected_url_count'] ?? 1)),
        ];
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $context */
    private function applicable(array $definition, array $context): bool
    {
        return in_array($context['page_family'], (array) data_get($definition, 'applicability.page_families', []), true)
            && in_array($context['locale'], (array) data_get($definition, 'applicability.locales', []), true)
            && in_array($context['indexability_state'], (array) data_get($definition, 'applicability.indexability_states', []), true);
    }

    /** @param array<string, mixed> $evidence @return array{outcome:string, root_cause:string} */
    private function decision(string $detectorId, array $evidence): array
    {
        if ($detectorId === 'content_decay_candidate') {
            if (($evidence['window_days'] ?? null) !== 28
                || ($evidence['comparison_window_days'] ?? null) !== 28
                || (int) ($evidence['consecutive_weekly_detection_count'] ?? 0) < 2) {
                return ['outcome' => 'pass', 'root_cause' => 'decay_lifecycle_not_confirmed'];
            }
            if (($evidence['inside_new_or_major_edit_protection'] ?? false) === true
                || ($evidence['incident_excluded'] ?? false) !== true
                || ($evidence['seasonality_excluded'] ?? false) !== true) {
                return ['outcome' => 'pass', 'root_cause' => 'decay_candidate_excluded'];
            }
        }

        $triggered = match ($detectorId) {
            'query_page_owner_conflict' => (int) ($evidence['current_owner_count'] ?? 0) > 1,
            'keyword_cannibalization' => (int) ($evidence['current_public_canonical_count'] ?? 0) > 1,
            'high_impressions_low_ctr' => (int) ($evidence['impressions'] ?? 0) >= (int) ($evidence['policy_impression_threshold'] ?? PHP_INT_MAX)
                && (float) ($evidence['ctr'] ?? 1.0) < (float) ($evidence['policy_ctr_threshold'] ?? 0.0),
            'position_4_15_opportunity' => (float) ($evidence['average_position'] ?? 0.0) >= 4.0
                && (float) ($evidence['average_position'] ?? 0.0) <= 15.0,
            'content_decay_candidate' => (int) ($evidence['baseline_impressions'] ?? 0) >= (int) ($evidence['policy_baseline_impression_threshold'] ?? PHP_INT_MAX)
                && (float) ($evidence['recent_28_day_impressions'] ?? 0.0) < (float) ($evidence['previous_28_day_impressions'] ?? 0.0),
            'review_overdue' => (int) ($evidence['days_since_review'] ?? 0) > (int) ($evidence['family_review_cycle_days'] ?? PHP_INT_MAX),
            'orphan_page' => (int) ($evidence['eligible_inbound_link_count'] ?? -1) === 0,
            'insufficient_internal_links' => (int) ($evidence['eligible_inbound_link_count'] ?? PHP_INT_MAX)
                < (int) ($evidence['family_minimum_internal_links'] ?? 0),
            'gsc_funnel_freshness' => ($evidence['gsc_freshness_threshold_exceeded'] ?? false) === true
                || ($evidence['funnel_freshness_threshold_exceeded'] ?? false) === true,
            'gsc_canonical_unmapped_url_truth' => ($evidence['mapping_outcome'] ?? 'mapped') === 'failed',
            default => false,
        };

        return [
            'outcome' => $triggered ? (string) $this->registry->detectors()[$detectorId]['output_type'] : 'pass',
            'root_cause' => $triggered
                ? $this->safeAxis($evidence['root_cause_or_error_code'] ?? $detectorId)
                : 'condition_not_observed',
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function minimumEvidencePresent(string $detectorId, array $evidence): bool
    {
        return match ($detectorId) {
            'query_page_owner_conflict' => $this->validHash($evidence, 'query_hash')
                && $this->hasInteger($evidence, 'current_owner_count'),
            'keyword_cannibalization' => $this->metricSegmentPresent($evidence)
                && $this->validHash($evidence, 'query_hash')
                && $this->hasInteger($evidence, 'current_public_canonical_count'),
            'high_impressions_low_ctr' => $this->metricSegmentPresent($evidence)
                && ($evidence['complete_window'] ?? false) === true
                && $this->hasInteger($evidence, 'impressions')
                && $this->hasNumeric($evidence, 'ctr')
                && $this->hasInteger($evidence, 'policy_impression_threshold')
                && $this->hasNumeric($evidence, 'policy_ctr_threshold'),
            'position_4_15_opportunity' => $this->metricSegmentPresent($evidence)
                && ($evidence['complete_window'] ?? false) === true
                && $this->hasNumeric($evidence, 'average_position'),
            'content_decay_candidate' => $this->metricSegmentPresent($evidence)
                && ($evidence['complete_windows'] ?? false) === true
                && $this->hasInteger($evidence, 'window_days')
                && $this->hasInteger($evidence, 'comparison_window_days')
                && $this->hasInteger($evidence, 'consecutive_weekly_detection_count')
                && $this->hasInteger($evidence, 'baseline_impressions')
                && $this->hasInteger($evidence, 'policy_baseline_impression_threshold')
                && $this->hasNumeric($evidence, 'recent_28_day_impressions')
                && $this->hasNumeric($evidence, 'previous_28_day_impressions')
                && $this->hasBoolean($evidence, 'inside_new_or_major_edit_protection')
                && $this->hasBoolean($evidence, 'incident_excluded')
                && $this->hasBoolean($evidence, 'seasonality_excluded'),
            'review_overdue' => $this->hasInteger($evidence, 'days_since_review')
                && $this->hasInteger($evidence, 'family_review_cycle_days'),
            'orphan_page' => ($evidence['complete_graph_snapshot'] ?? false) === true
                && $this->hasInteger($evidence, 'eligible_inbound_link_count'),
            'insufficient_internal_links' => ($evidence['complete_graph_snapshot'] ?? false) === true
                && ($evidence['internal_link_threshold_source'] ?? null) === 'page_family_policy'
                && $this->hasInteger($evidence, 'eligible_inbound_link_count')
                && $this->hasInteger($evidence, 'family_minimum_internal_links'),
            'gsc_funnel_freshness' => $this->hasBoolean($evidence, 'gsc_freshness_threshold_exceeded')
                && $this->hasBoolean($evidence, 'funnel_freshness_threshold_exceeded'),
            'gsc_canonical_unmapped_url_truth' => $this->validHash($evidence, 'normalized_gsc_canonical_hash')
                && in_array($evidence['mapping_outcome'] ?? null, ['mapped', 'failed'], true)
                && is_string($evidence['mapping_root_cause'] ?? null),
            default => false,
        };
    }

    /** @param array<string, mixed> $evidence */
    private function severity(array $evidence): string
    {
        return match ($evidence['verified_impact'] ?? 'bounded') {
            'critical' => 'P0',
            'high' => 'P1',
            'low' => 'P3',
            default => 'P2',
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function result(array $definition, array $context, string $outcome, string $evidenceState, ?string $severity, string $rootCause): array
    {
        $clusterSeed = implode('|', [$context['detector'], $rootCause, $context['page_family'], $context['authority_revision']]);
        $dedupeSeed = implode('|', [
            $context['detector'],
            $context['canonical_url_hash'] ?? $context['query_hash'] ?? 'cluster',
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
            'query_segment' => $context['query_segment'],
            'authority_revision' => $context['authority_revision'],
            'url_truth_revision' => $context['url_truth_revision'],
            'policy_version' => $context['policy_version'],
            'canonical_url_hash' => $context['canonical_url_hash'],
            'query_hash' => $context['query_hash'],
            'affected_url_count' => $context['affected_url_count'],
            'cluster_uid' => 'seo_cluster_'.substr(hash('sha256', $clusterSeed), 0, 48),
            'dedupe_key' => hash('sha256', $dedupeSeed),
            'cluster_key_fields' => SeoDetectorRegistry::CLUSTER_KEY,
            'queue' => match ($outcome) {
                'issue' => 'issue',
                'opportunity' => 'opportunity',
                'measurement_hold' => 'measurement_hold',
                default => null,
            },
            'automation_cap' => $definition['automation_cap'],
            'human_intervention_required' => in_array($severity, ['P0', 'P1'], true),
            'privacy' => [
                'private_negative_set_checked' => true,
                'raw_query_stored' => false,
                'raw_urls_stored' => false,
                'session_or_business_identifiers_stored' => false,
                'sensitive_fields_stored' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function metricSegmentPresent(array $evidence): bool
    {
        return in_array($evidence['query_segment'] ?? null, ['branded', 'non_branded'], true);
    }

    /** @param array<string, mixed> $evidence */
    private function validHash(array $evidence, string $key): bool
    {
        return $this->sha256OrNull($evidence[$key] ?? null) !== null;
    }

    /** @param array<string, mixed> $evidence */
    private function hasBoolean(array $evidence, string $key): bool
    {
        return array_key_exists($key, $evidence) && is_bool($evidence[$key]);
    }

    /** @param array<string, mixed> $evidence */
    private function hasInteger(array $evidence, string $key): bool
    {
        return array_key_exists($key, $evidence) && is_int($evidence[$key]);
    }

    /** @param array<string, mixed> $evidence */
    private function hasNumeric(array $evidence, string $key): bool
    {
        return array_key_exists($key, $evidence) && is_numeric($evidence[$key]);
    }

    private function safeQuerySegment(mixed $value): ?string
    {
        return in_array($value, ['branded', 'non_branded'], true) ? $value : null;
    }

    private function sha256OrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function safeAxis(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $value) !== 1) {
            return 'unknown';
        }

        return $value;
    }

    private function safeRevision(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-zA-Z0-9_.:-]{1,160}$/', $value) !== 1) {
            return 'unknown';
        }

        return $value;
    }
}
