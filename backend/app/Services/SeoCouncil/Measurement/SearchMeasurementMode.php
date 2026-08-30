<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;

final class SearchMeasurementMode
{
    public function __construct(
        private readonly MeasurementStateResolver $states,
        private readonly MeasurementSafetyGuard $guard,
        private readonly SeoDetectorRegistry $detectors,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param array<string, mixed> $evidence @return array<string, mixed> */
    public function review(array $request, array $evidence): array
    {
        $violations = $this->guard->violations($request, $evidence, 'seo.expert.search_analytics_measurement');
        if (! in_array($request['query_cohort'] ?? null, ['aggregate_only', 'branded', 'non_branded'], true)) {
            $violations[] = 'raw_query_cohort';
        }
        if ($violations !== []) {
            $evidence['policy_hold'] = true;
            $evidence['state_reason'] = implode(',', array_values(array_unique($violations)));
        }
        $source = $this->states->sourceCapability($evidence);
        $measurement = $this->states->measurementState($evidence);
        $state = (string) $measurement['state'];
        $valid = in_array($state, ['valid', 'valid_zero'], true);
        $trusted = $violations === [];
        $overclaim = $this->guard->containsOverclaim([
            ...(array) ($evidence['verified_facts'] ?? []),
            ...(array) ($evidence['associations'] ?? []),
        ]);
        $facts = in_array($state, ['valid', 'valid_zero'], true)
            ? $this->guard->evidenceBoundClaims((array) ($evidence['verified_facts'] ?? []))
            : [];
        $detectorIds = array_keys($this->detectors->detectors());
        $finding = [
            'version' => 'seo.search_measurement_finding.v1',
            'surface' => (string) ($request['surface'] ?? 'public_search'),
            'page_family' => (string) ($request['page_family'] ?? 'tests'),
            'locale' => (string) ($request['locale'] ?? 'en'),
            'query_cohort' => (string) ($request['query_cohort'] ?? 'aggregate_only'),
            'windows' => (array) ($request['windows'] ?? [7, 28, 90]),
            'comparison_window' => (array) ($request['comparison_window'] ?? []),
            'source_capability' => $source,
            'measurement_state' => $measurement,
            'trend_metrics' => $valid ? (array) ($evidence['trend_metrics'] ?? []) : [],
            'branded_non_branded' => $valid ? (array) ($evidence['branded_non_branded'] ?? []) : [],
            'detector_results' => $valid ? array_values(array_intersect((array) ($evidence['detector_results'] ?? []), $detectorIds)) : [],
            'freshness' => (array) ($evidence['freshness'] ?? []),
            'lag' => (array) ($evidence['lag'] ?? []),
            'coverage' => (array) ($evidence['coverage'] ?? []),
            'mapping_state' => (string) ($evidence['mapping_state'] ?? 'unverified'),
            'verified_facts' => $facts,
            'associations' => $trusted ? $this->guard->evidenceBoundClaims((array) ($evidence['associations'] ?? [])) : [],
            'hypotheses' => $trusted ? array_values(array_filter((array) ($evidence['hypotheses'] ?? []), 'is_string')) : [],
            'unknowns' => array_values(array_unique([
                ...($trusted ? array_values(array_filter((array) ($evidence['unknowns'] ?? []), 'is_string')) : []),
                ...($overclaim ? ['causal_or_attribution_claim_not_supported'] : []),
            ])),
            'holds' => in_array($state, ['valid', 'valid_zero'], true) ? [] : [$state],
            'evidence_refs' => $trusted ? (array) ($evidence['evidence_refs'] ?? []) : [],
            'attribution_caveat' => 'association_only_not_causal; average_position_is_not_exact_rank',
            'next_step' => (string) ($evidence['next_step'] ?? 'human_review'),
            'execution_allowed' => false,
        ];
        $finding['canonical_hash'] = $this->hasher->hash($finding);

        $output = [
            'version' => 'seo.search_measurement_output.v1',
            'status' => in_array($state, ['valid', 'valid_zero'], true) ? 'READY' : 'HOLD',
            'findings' => [$finding],
            'gai_capability' => $this->gaiCapability($trusted ? (array) ($evidence['gai_capability'] ?? []) : []),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function gaiCapability(array $evidence): array
    {
        $state = in_array($evidence['state'] ?? null, ['unverified', 'manual_export_only', 'unavailable'], true)
            ? $evidence['state']
            : 'unverified';

        return [
            'state' => $state,
            'official_capability_evidence_ref' => $evidence['official_capability_evidence_ref'] ?? null,
            'ordinary_web_search_metrics_relabelled' => false,
            'ai_overview_equated_to_citation_ranking_or_conversion' => false,
            'external_api_connected' => false,
        ];
    }
}
