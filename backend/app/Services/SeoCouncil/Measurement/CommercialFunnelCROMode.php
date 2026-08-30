<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class CommercialFunnelCROMode
{
    private const ALLOWED_METRICS = [
        'landing_pv_count', 'article_to_test_click_count', 'start_test_count',
        'complete_test_count', 'view_result_count', 'return_public_content_count',
        'cta_exposure_count', 'cta_click_count',
    ];

    public function __construct(
        private readonly MeasurementStateResolver $states,
        private readonly MeasurementSafetyGuard $guard,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @param array<string, mixed> $evidence @return array<string, mixed> */
    public function review(array $request, array $evidence): array
    {
        $violations = $this->guard->violations($request, $evidence, 'seo.expert.commercial_funnel_cro');
        $privacyViolation = array_intersect($violations, ['private_data', 'private_url']) !== [];
        $minimumSample = max(1, (int) ($evidence['minimum_sample_requirement'] ?? 100));
        $metrics = array_intersect_key((array) ($evidence['aggregate_metrics'] ?? []), array_flip(self::ALLOWED_METRICS));
        $observedSample = max(array_map('intval', $metrics) ?: [0]);
        $insufficientSample = $observedSample < $minimumSample;
        $evidence['privacy_failed'] = $privacyViolation || ($evidence['privacy_failed'] ?? false) === true;
        if ($violations !== [] || $insufficientSample) {
            $evidence['policy_hold'] = true;
            $evidence['state_reason'] = implode(',', [...$violations, ...($insufficientSample ? ['insufficient_sample'] : [])]);
        }
        $source = $this->states->sourceCapability($evidence);
        $measurement = $this->states->measurementState($evidence);
        $state = (string) $measurement['state'];
        $trusted = $violations === [];
        $candidate = [
            'version' => 'seo.cro_experiment_candidate.v1',
            'hypothesis' => (string) ($evidence['experiment_hypothesis'] ?? 'insufficient_evidence_for_experiment'),
            'falsification_rule' => (string) ($evidence['falsification_rule'] ?? 'primary_metric_does_not_improve'),
            'primary_metric' => (string) ($evidence['primary_metric'] ?? 'start_test_count'),
            'guardrail_metrics' => array_values(array_filter((array) ($evidence['guardrail_metrics'] ?? ['complete_test_count']), 'is_string')),
            'minimum_sample_requirement' => $minimumSample,
            'uncertainty' => (string) ($evidence['uncertainty'] ?? 'high'),
            'stop_conditions' => array_values(array_filter((array) ($evidence['stop_conditions'] ?? ['privacy_or_mapping_hold']), 'is_string')),
            'owners' => [
                'frontend' => (string) ($evidence['frontend_owner'] ?? 'frontend'),
                'backend' => (string) ($evidence['backend_owner'] ?? 'backend'),
                'cms' => (string) ($evidence['cms_owner'] ?? 'cms'),
                'analytics' => (string) ($evidence['analytics_owner'] ?? 'analytics'),
            ],
            'execution_allowed' => false,
        ];
        $candidate['canonical_hash'] = $this->hasher->hash($candidate);
        $finding = [
            'version' => 'seo.commercial_funnel_cro_finding.v1',
            'page_family' => (string) ($request['page_family'] ?? 'tests'),
            'locale' => (string) ($request['locale'] ?? 'en'),
            'window' => (array) ($request['window'] ?? []),
            'source_capability' => $source,
            'measurement_state' => $measurement,
            'aggregate_metrics' => $trusted ? $metrics : [],
            'stage_coverage' => $trusted ? (array) ($evidence['stage_coverage'] ?? []) : [],
            'dropoffs' => $trusted ? (array) ($evidence['dropoffs'] ?? []) : [],
            'promise_parity' => $trusted ? (string) ($evidence['promise_parity'] ?? 'unknown') : 'unknown',
            'intent_continuity' => $trusted ? (string) ($evidence['intent_continuity'] ?? 'unknown') : 'unknown',
            'friction' => $trusted ? (array) ($evidence['friction'] ?? []) : [],
            'locale_differences' => $trusted ? (array) ($evidence['locale_differences'] ?? []) : [],
            'instrumentation_gaps' => $trusted ? (array) ($evidence['instrumentation_gaps'] ?? []) : [],
            'mapping_revision' => $trusted ? (string) ($evidence['mapping_revision'] ?? 'unavailable') : 'unavailable',
            'privacy_violation' => $privacyViolation,
            'verified_facts' => $trusted && in_array($state, ['valid', 'valid_zero'], true) ? (array) ($evidence['verified_facts'] ?? []) : [],
            'associations' => $trusted ? (array) ($evidence['associations'] ?? []) : [],
            'hypotheses' => $trusted ? (array) ($evidence['hypotheses'] ?? []) : [],
            'unknowns' => $trusted ? (array) ($evidence['unknowns'] ?? []) : [],
            'holds' => in_array($state, ['valid', 'valid_zero'], true) ? [] : [$state],
            'evidence_refs' => $trusted ? (array) ($evidence['evidence_refs'] ?? []) : [],
            'execution_allowed' => false,
        ];
        $finding['canonical_hash'] = $this->hasher->hash($finding);
        $output = [
            'version' => 'seo.commercial_funnel_cro_output.v1',
            'status' => in_array($state, ['valid', 'valid_zero'], true) ? 'READY' : 'HOLD',
            'findings' => [$finding],
            'experiment_candidates' => in_array($state, ['valid', 'valid_zero'], true) ? [$candidate] : [],
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }
}
