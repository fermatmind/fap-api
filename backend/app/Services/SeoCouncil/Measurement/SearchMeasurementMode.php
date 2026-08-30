<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class SearchMeasurementMode
{
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
            && ($context['mode_id'] ?? null) === 'search_measurement'
            && ($context['role_id'] ?? null) === 'seo.expert.search_analytics_measurement'
            && ($context['status'] ?? null) === 'READY'
            && ($context['measurement_allowed'] ?? null) === true
            && ! $this->privacy->context($context);
        $facts = (array) ($context['facts'] ?? []);
        $claims = [...(array) ($facts['verified_facts'] ?? []), ...(array) ($facts['associations'] ?? [])];
        $overclaim = $this->guard->containsOverclaim($claims);
        $ready = $validContext && ! $overclaim;
        $finding = [
            'version' => 'seo.measurement_finding.v2',
            'role_id' => 'seo.expert.search_analytics_measurement', 'mode_id' => 'search_measurement',
            'page_family' => $ready ? (string) $context['page_family'] : 'page_family:held',
            'locale' => $ready ? (string) $context['locale'] : 'und',
            'source_capability' => $context['source_capability'] ?? $this->heldDecision(true),
            'measurement_state' => $context['measurement_state'] ?? $this->heldDecision(false),
            'aggregate_metrics' => $ready ? (array) ($context['metrics'] ?? []) : [],
            'verified_facts' => $ready ? $this->guard->evidenceBoundClaims((array) ($facts['verified_facts'] ?? [])) : [],
            'associations' => $ready ? $this->guard->evidenceBoundClaims((array) ($facts['associations'] ?? [])) : [],
            'hypotheses' => $ready ? array_values(array_filter((array) ($facts['hypotheses'] ?? []), 'is_string')) : [],
            'unknowns' => array_values(array_unique([
                ...($ready ? array_values(array_filter((array) ($facts['unknowns'] ?? []), 'is_string')) : []),
                ...($overclaim ? ['causal_or_attribution_claim_not_supported'] : []),
            ])),
            'holds' => $ready ? [] : [$overclaim ? 'causal_or_attribution_overclaim' : 'validated_context_required'],
            'evidence_refs' => $ready ? array_values(array_column((array) ($context['bundle_refs'] ?? []), 'bundle_hash')) : [],
            'execution_allowed' => false,
        ];
        $finding['finding_hash'] = $this->hasher->hash($finding);
        $output = $this->output($ready ? 'READY' : 'HOLD', [$finding], [], $ready ? null : ($overclaim ? 'causal_or_attribution_overclaim' : 'validated_context_required'));
        if (! $this->contracts->output($output)
            || $this->privacy->output($output)) {
            return $this->safeHold('output_contract_or_privacy_hold');
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function safeHold(string $reason): array
    {
        $finding = [
            'version' => 'seo.measurement_finding.v2', 'role_id' => 'seo.expert.search_analytics_measurement',
            'mode_id' => 'search_measurement', 'page_family' => 'page_family:held', 'locale' => 'und',
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
