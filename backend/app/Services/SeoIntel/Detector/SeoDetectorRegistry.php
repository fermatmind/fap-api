<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Detector;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use RuntimeException;

final class SeoDetectorRegistry
{
    public const VERSION = 'seo-detector-registry.v1';

    public const OUTPUTS = ['pass', 'issue', 'opportunity', 'measurement_hold'];

    public const EVIDENCE_STATES = ['direct_evidence', 'insufficient_evidence', 'source_unavailable'];

    public const CLUSTER_KEY = ['detector', 'root_cause_or_error_code', 'page_family', 'authority_revision'];

    public const REQUIRED_FIELDS = [
        'detector_id',
        'version',
        'enabled',
        'output_type',
        'allowed_outputs',
        'evidence_sources',
        'minimum_evidence',
        'applicability',
        'evidence_states',
        'severity_policy',
        'dedupe_key_fields',
        'root_cause_cluster_key',
        'max_agent_risk_level',
        'automation_cap',
        'human_intervention_conditions',
        'recovery_conditions',
        'close_conditions',
        'reopen_conditions',
        'required_revisions',
        'privacy_constraints',
    ];

    /** @return array<string, array<string, mixed>> */
    public function detectors(): array
    {
        return [
            'http_404' => $this->issue('http_404', ['runtime_http'], ['bounded_http_observation', 'status_404']),
            'http_410' => $this->issue('http_410', ['runtime_http', 'backend_authority'], ['bounded_http_observation', 'status_410', 'retirement_authority_decision'], [
                'severity_policy' => $this->severityPolicy('pass_when_status_410_matches_explicit_retirement_authority'),
            ]),
            'http_5xx' => $this->issue('http_5xx', ['runtime_http'], ['bounded_http_observation', 'status_5xx'], [
                'severity_policy' => $this->severityPolicy('single_transient_failure_is_evidence_only; repeated_or_sustained_direct_failures_raise_severity'),
            ]),
            'redirect_chain' => $this->issue('redirect_chain', ['runtime_http', 'url_truth'], ['bounded_redirect_trace', 'more_than_one_redirect_hop']),
            'redirect_loop' => $this->issue('redirect_loop', ['runtime_http', 'url_truth'], ['bounded_redirect_trace', 'repeated_location_identity']),
            'redirect_wrong_target' => $this->issue('redirect_wrong_target', ['runtime_http', 'url_truth', 'backend_authority'], ['bounded_redirect_trace', 'expected_canonical_target', 'observed_terminal_target']),
            'false_noindex' => $this->issue('false_noindex', ['runtime_html', 'url_truth', 'page_family_policy'], ['observed_robots_directive', 'indexable_authority_decision']),
            'canonical_authority_drift' => $this->issue('canonical_authority_drift', ['runtime_html', 'url_truth', 'backend_authority'], ['observed_canonical', 'current_authority_canonical', 'redirect_only_rejected_as_canonical']),
            'hreflang_locale_counterpart_drift' => $this->issue('hreflang_locale_counterpart_drift', ['runtime_html', 'url_truth', 'page_family_policy'], ['policy_requires_locale_pair', 'both_locale_authorities', 'observed_hreflang_set']),
            'jsonld_visible_content_mismatch' => $this->issue('jsonld_visible_content_mismatch', ['runtime_html', 'backend_authority'], ['parsed_json_ld', 'visible_content_projection', 'field_level_difference']),
            'public_collection_split' => $this->issue('public_collection_split', ['url_truth', 'sitemap', 'llms', 'llms_full', 'runtime_public_api'], ['same_revision_collection_snapshots', 'set_difference']),
            'cms_published_shell' => $this->issue('cms_published_shell', ['backend_authority', 'runtime_public_api', 'page_family_policy'], ['published_authority_record', 'required_module_projection', 'missing_or_empty_required_module']),
            'runtime_api_timeout' => $this->issue('runtime_api_timeout', ['runtime_http', 'runtime_public_api'], ['bounded_request_timing', 'timeout_outcome'], [
                'severity_policy' => $this->severityPolicy('single_network_timeout_is_evidence_only; repeated_direct_failures_raise_severity'),
            ]),
            'runtime_performance_degradation' => $this->issue('runtime_performance_degradation', ['runtime_http', 'field_cwv'], ['bounded_runtime_timing_or_field_data', 'policy_threshold', 'repeat_observation'], [
                'allowed_outputs' => self::OUTPUTS,
                'minimum_evidence' => ['field_data_when_connected_or_repeated_bounded_runtime_timing', 'policy_threshold'],
                'source_unavailable_outcome' => 'measurement_hold',
                'measurement_hold_reason' => 'field_cwv_not_connected; lighthouse_lab_substitution_forbidden',
            ]),
            'private_url_public_collection_leak' => $this->issue('private_url_public_collection_leak', ['page_family_policy', 'url_truth', 'sitemap', 'llms', 'runtime_public_api'], ['private_negative_set_match', 'direct_public_collection_membership'], [
                'applicability' => [
                    'page_families' => [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS],
                    'locales' => ['zh-CN', 'en'],
                    'indexability_states' => ['indexable', 'noindex', 'private', 'retired', 'redirect_only'],
                ],
                'severity_policy' => $this->severityPolicy('direct_private_exposure_may_be_p0_or_p1_according_to_verified_impact'),
                'max_agent_risk_level' => 'L0',
                'automation_cap' => 'queue_materialization_and_public_collection_fail_closed_only',
            ]),
            'data_sync_stale' => $this->issue('data_sync_stale', ['sync_receipt', 'backend_authority'], ['last_successful_sync_at', 'source_revision', 'freshness_policy']),
            'pagination_incomplete' => $this->issue('pagination_incomplete', ['sync_receipt', 'source_adapter'], ['pages_fetched', 'termination_condition', 'row_accounting']),
            'url_mapping_failure' => $this->issue('url_mapping_failure', ['source_adapter', 'url_truth'], ['normalized_source_url_hash', 'mapping_outcome', 'mapping_error_code']),
            'query_page_owner_conflict' => $this->issue('query_page_owner_conflict', ['query_owner_read_model', 'url_truth', 'page_family_policy'], ['stable_query_hash', 'more_than_one_current_owner', 'owner_authority_bindings']),
            'keyword_cannibalization' => $this->gscOpportunity('keyword_cannibalization', ['seo_gsc_daily', 'url_truth', 'gsc_quality_gate'], ['quality_gate_pass', 'stable_query_hash', 'multiple_current_public_canonicals']),
            'high_impressions_low_ctr' => $this->gscOpportunity('high_impressions_low_ctr', ['seo_gsc_daily', 'url_truth', 'gsc_quality_gate'], ['quality_gate_pass', 'complete_window', 'policy_impression_threshold', 'policy_ctr_threshold']),
            'position_4_15_opportunity' => $this->gscOpportunity('position_4_15_opportunity', ['seo_gsc_daily', 'url_truth', 'gsc_quality_gate'], ['quality_gate_pass', 'complete_window', 'average_position_between_4_and_15']),
            'content_decay_candidate' => $this->gscOpportunity('content_decay_candidate', ['seo_gsc_daily', 'gsc_quality_gate', 'content_lifecycle', 'incident_calendar', 'url_truth'], ['quality_gate_pass', 'two_complete_28_day_windows', 'two_consecutive_weekly_detections', 'baseline_impression_threshold', 'outside_new_or_major_edit_protection', 'incident_and_seasonality_excluded']),
            'review_overdue' => $this->issue('review_overdue', ['content_lifecycle', 'page_family_policy'], ['last_reviewed_at', 'family_review_cycle_days'], [
                'allowed_outputs' => self::OUTPUTS,
                'source_unavailable_outcome' => 'measurement_hold',
                'measurement_hold_reason' => 'review_source_unavailable; overdue_must_not_be_inferred',
            ]),
            'orphan_page' => $this->issue('orphan_page', ['internal_link_graph', 'url_truth', 'page_family_policy'], ['complete_graph_snapshot', 'current_public_canonical', 'zero_eligible_inbound_links']),
            'insufficient_internal_links' => $this->opportunity('insufficient_internal_links', ['internal_link_graph', 'url_truth', 'page_family_policy'], ['complete_graph_snapshot', 'current_public_canonical', 'family_specific_internal_link_policy']),
            'gsc_funnel_freshness' => $this->issue('gsc_funnel_freshness', ['gsc_sync_receipt', 'seo_conversion_daily', 'quality_gate'], ['last_successful_refresh_at', 'data_max_date', 'freshness_policy']),
            'gsc_canonical_unmapped_url_truth' => $this->issue('gsc_canonical_unmapped_url_truth', ['seo_gsc_daily', 'url_truth', 'gsc_quality_gate'], ['normalized_gsc_canonical_hash', 'mapping_failure', 'mapping_root_cause']),
        ];
    }

    public function registryHash(): string
    {
        $payload = ['version' => self::VERSION, 'detectors' => $this->detectors()];

        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function assertValid(): void
    {
        $families = [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS];

        foreach ($this->detectors() as $id => $detector) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $detector)) {
                    throw new RuntimeException("SEO detector {$id} is missing {$field}.");
                }
            }
            if (($detector['detector_id'] ?? null) !== $id || ($detector['version'] ?? null) !== 'v1') {
                throw new RuntimeException("SEO detector {$id} has an invalid identity or version.");
            }
            if (! in_array($detector['output_type'] ?? null, ['issue', 'opportunity'], true)
                || array_diff((array) ($detector['allowed_outputs'] ?? []), self::OUTPUTS) !== []) {
                throw new RuntimeException("SEO detector {$id} has an invalid output contract.");
            }
            if (($detector['evidence_states'] ?? null) !== self::EVIDENCE_STATES
                || ($detector['root_cause_cluster_key'] ?? null) !== self::CLUSTER_KEY) {
                throw new RuntimeException("SEO detector {$id} has an invalid evidence or cluster contract.");
            }
            if (array_diff((array) data_get($detector, 'applicability.page_families', []), $families) !== []) {
                throw new RuntimeException("SEO detector {$id} references an unknown or non-public page family.");
            }
            if (data_get($detector, 'severity_policy.p0_p1_requires') !== 'direct_evidence'
                || data_get($detector, 'severity_policy.inference_or_single_transient_max') !== 'P2'
                || data_get($detector, 'severity_policy.missing_data_outcome') !== 'measurement_hold') {
                throw new RuntimeException("SEO detector {$id} may promote indirect evidence to P0/P1.");
            }
            if (data_get($detector, 'privacy_constraints.private_negative_set_required') !== true
                || data_get($detector, 'privacy_constraints.raw_sensitive_fields_allowed') !== false) {
                throw new RuntimeException("SEO detector {$id} does not fail closed on privacy.");
            }
        }
    }

    /** @param list<string> $sources @param list<string> $minimumEvidence @param array<string,mixed> $overrides */
    private function issue(string $id, array $sources, array $minimumEvidence, array $overrides = []): array
    {
        return $this->definition($id, 'issue', $sources, $minimumEvidence, $overrides);
    }

    /** @param list<string> $sources @param list<string> $minimumEvidence */
    private function opportunity(string $id, array $sources, array $minimumEvidence): array
    {
        return $this->definition($id, 'opportunity', $sources, $minimumEvidence, [
            'automation_cap' => 'queue_materialization_only; no_content_rewrite_or_indexability_change',
        ]);
    }

    /** @param list<string> $sources @param list<string> $minimumEvidence */
    private function gscOpportunity(string $id, array $sources, array $minimumEvidence): array
    {
        return $this->definition($id, 'opportunity', $sources, $minimumEvidence, [
            'automation_cap' => 'queue_materialization_only; no_content_rewrite_or_indexability_change',
            'quality_gate_failure_outcome' => 'measurement_hold',
            'metric_segmentation' => ['branded', 'non_branded'],
        ]);
    }

    /** @param list<string> $sources @param list<string> $minimumEvidence @param array<string,mixed> $overrides */
    private function definition(string $id, string $outputType, array $sources, array $minimumEvidence, array $overrides): array
    {
        $definition = [
            'detector_id' => $id,
            'version' => 'v1',
            'enabled' => true,
            'output_type' => $outputType,
            'allowed_outputs' => ['pass', $outputType, 'measurement_hold'],
            'evidence_sources' => $sources,
            'minimum_evidence' => $minimumEvidence,
            'applicability' => [
                'page_families' => [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS],
                'locales' => ['zh-CN', 'en'],
                'indexability_states' => ['indexable'],
            ],
            'evidence_states' => self::EVIDENCE_STATES,
            'severity_policy' => $this->severityPolicy('verified_impact_and_scope'),
            'dedupe_key_fields' => ['detector_id', 'canonical_url_hash', 'locale', 'authority_revision'],
            'root_cause_cluster_key' => self::CLUSTER_KEY,
            'max_agent_risk_level' => 'L1',
            'automation_cap' => 'queue_materialization_only',
            'human_intervention_conditions' => ['P0_or_P1', 'authority_conflict', 'private_negative_set_match', 'automation_cap_exceeded'],
            'recovery_conditions' => ['fresh_direct_evidence_passes_on_current_required_revisions'],
            'close_conditions' => ['root_cause_absent_on_bounded_recheck', 'affected_url_count_is_zero'],
            'reopen_conditions' => ['same_cluster_key_fails_after_close_with_fresh_direct_evidence'],
            'required_revisions' => ['url_truth_revision' => 'current', 'authority_revision' => 'current'],
            'privacy_constraints' => [
                'private_negative_set_required' => true,
                'private_urls_may_be_persisted' => false,
                'raw_sensitive_fields_allowed' => false,
                'forbidden_fields' => ['raw_query', 'private_url', 'user_agent', 'session', 'attempt_id', 'result_id', 'order_id', 'user_id', 'email', 'phone', 'token', 'secret'],
                'artifacts_and_read_models' => 'sanitized_aggregate_only',
            ],
            'source_unavailable_outcome' => 'measurement_hold',
        ];

        return array_replace($definition, $overrides);
    }

    /** @return array<string,string> */
    private function severityPolicy(string $directEvidenceRule): array
    {
        return [
            'P0' => 'verified_critical_public_or_private_exposure_impact',
            'P1' => 'verified_high_impact_or_broad_public_surface_failure',
            'P2' => 'verified_bounded_impact_or_repeated_degradation',
            'P3' => 'low_impact_direct_observation',
            'direct_evidence_rule' => $directEvidenceRule,
            'p0_p1_requires' => 'direct_evidence',
            'inference_or_single_transient_max' => 'P2',
            'missing_data_outcome' => 'measurement_hold',
        ];
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
