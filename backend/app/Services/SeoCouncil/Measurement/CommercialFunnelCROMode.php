<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class CommercialFunnelCROMode
{
    private const METRICS = [
        'landing_pv_count', 'article_to_test_click_count', 'start_test_count',
        'complete_test_count', 'aggregate_outcome_view_count', 'return_public_content_count',
    ];

    public function __construct(
        private readonly MeasurementContractValidator $contracts,
        private readonly MeasurementSafetyGuard $guard,
        private readonly MeasurementPrivacyScanner $privacy,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function review(array $context): array
    {
        $validContext = $this->contracts->context($context)
            && ($context['mode_id'] ?? null) === 'commercial_funnel_cro'
            && ($context['role_id'] ?? null) === 'seo.expert.commercial_funnel_cro'
            && ($context['status'] ?? null) === 'READY'
            && ($context['measurement_allowed'] ?? null) === true
            && ! $this->privacy->context($context);
        $facts = (array) ($context['facts'] ?? []);
        $claims = [
            ...(array) ($facts['verified_facts'] ?? []), ...(array) ($facts['associations'] ?? []),
            ...(array) ($facts['hypotheses'] ?? []),
        ];
        $overclaim = $this->guard->containsOverclaim($claims);
        $windows = (array) data_get($context, 'metrics.windows', []);
        $window = current(array_filter($windows, static fn (mixed $item): bool => is_array($item) && ($item['window_days'] ?? null) === 28));
        $metrics = array_intersect_key((array) (is_array($window) ? ($window['metrics'] ?? []) : []), array_flip(self::METRICS));
        $coverage = (array) data_get($context, 'metrics.stage_coverage', []);
        $chainComplete = $coverage !== [] && ! in_array(false, $coverage, true)
            && array_diff(['landing', 'start', 'completion', 'aggregate_outcome_view', 'return_public_content', 'cta'], array_keys($coverage)) === [];
        $sample = max(array_map('intval', $metrics) ?: [0]);
        $ready = $validContext && ! $overclaim && $chainComplete && $sample >= 100;
        $candidate = [
            'version' => 'seo.measurement_candidate.v2',
            'hypothesis' => 'A bounded public promise-parity change may alter qualified test starts.',
            'falsification_rule' => 'Reject the hypothesis when the primary metric does not improve within the predeclared window.',
            'uncertainty' => 'high', 'primary_metric' => 'start_test_count',
            'guardrail_metrics' => ['complete_test_count', 'aggregate_outcome_view_count'],
            'stop_conditions' => ['privacy_hold', 'mapping_hold', 'guardrail_regression', 'sample_limit_reached'],
            'minimum_sample_requirement' => 100, 'execution_allowed' => false,
        ];
        $candidate['candidate_hash'] = $this->hasher->hash($candidate);
        $finding = [
            'version' => 'seo.measurement_finding.v2', 'role_id' => 'seo.expert.commercial_funnel_cro',
            'mode_id' => 'commercial_funnel_cro', 'page_family' => $ready ? (string) $context['page_family'] : 'page_family:held',
            'locale' => $ready ? (string) $context['locale'] : 'und',
            'source_capability' => $context['source_capability'] ?? $this->heldDecision(true),
            'measurement_state' => $context['measurement_state'] ?? $this->heldDecision(false),
            'aggregate_metrics' => $ready ? $metrics : [], 'verified_facts' => [], 'associations' => [],
            'hypotheses' => $ready ? [$candidate['hypothesis']] : [],
            'unknowns' => $overclaim ? ['causal_or_attribution_claim_not_supported'] : [],
            'holds' => $ready ? [] : [$this->holdReason($overclaim, $chainComplete, $sample)],
            'evidence_refs' => $ready ? array_values(array_column((array) ($context['bundle_refs'] ?? []), 'bundle_hash')) : [],
            'execution_allowed' => false,
        ];
        $finding['finding_hash'] = $this->hasher->hash($finding);
        $reason = $ready ? null : $this->holdReason($overclaim, $chainComplete, $sample);
        $output = $this->output($ready ? 'READY' : 'HOLD', [$finding], $ready ? [$candidate] : [], $reason);
        if (! $this->contracts->output($output)
            || $this->privacy->output($output)) {
            return $this->safeHold('output_contract_or_privacy_hold');
        }

        return $output;
    }

    private function holdReason(bool $overclaim, bool $chainComplete, int $sample): string
    {
        return match (true) {
            $overclaim => 'causal_or_attribution_overclaim',
            ! $chainComplete => 'public_funnel_chain_incomplete',
            $sample < 100 => 'insufficient_sample',
            default => 'validated_context_required',
        };
    }

    /** @return array<string, mixed> */
    private function safeHold(string $reason): array
    {
        $finding = [
            'version' => 'seo.measurement_finding.v2', 'role_id' => 'seo.expert.commercial_funnel_cro',
            'mode_id' => 'commercial_funnel_cro', 'page_family' => 'page_family:held', 'locale' => 'und',
            'source_capability' => $this->heldDecision(true), 'measurement_state' => $this->heldDecision(false),
            'aggregate_metrics' => [], 'verified_facts' => [], 'associations' => [], 'hypotheses' => [],
            'unknowns' => [], 'holds' => [$reason], 'evidence_refs' => [], 'execution_allowed' => false,
        ];
        $finding['finding_hash'] = $this->hasher->hash($finding);

        return $this->output('HOLD', [$finding], [], $reason);
    }

    /** @param list<array<string, mixed>> $findings @param list<array<string, mixed>> $candidates @return array<string, mixed> */
    private function output(string $status, array $findings, array $candidates, ?string $reason): array
    {
        $output = [
            'version' => 'seo.measurement_output.v2', 'status' => $status, 'findings' => $findings,
            'candidates' => $candidates, 'hold_reason' => $reason, 'model_calls' => 0, 'tool_calls' => 0,
            'external_calls' => 0, 'write_count' => 0, 'execution_allowed' => false,
        ];
        $output['output_hash'] = $this->hasher->hash($output);

        return $output;
    }

    /** @return array<string, mixed> */
    private function heldDecision(bool $source): array
    {
        $decision = [
            'version' => $source ? 'seo.source_capability_decision.v2' : 'seo.measurement_state_decision.v2',
            'state' => $source ? 'unverified' : 'hold', 'conflict_detected' => false,
            'authority_revision' => 'unavailable', 'source_ref' => 'unavailable', 'window' => [7, 28, 90],
            'freshness' => [], 'state_reason' => 'validated_context_required', 'execution_allowed' => false,
        ];
        $decision['canonical_hash'] = $this->hasher->hash($decision);

        return $decision;
    }
}
