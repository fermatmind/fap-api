<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class MeasurementStateResolver
{
    public const VERSION = 'seo.measurement_state_resolver.v1';

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function sourceCapability(array $evidence): array
    {
        $state = match (true) {
            ($evidence['quality_gate_passed'] ?? false) === true
                && ($evidence['current_window_readable'] ?? false) === true => 'available',
            ($evidence['manual_export_verified'] ?? false) === true => 'manual_export_only',
            ($evidence['api_ready'] ?? false) === true
                && ($evidence['property_verified'] ?? false) === true
                && ($evidence['adapter_ready'] ?? false) === true => 'api_ready',
            ($evidence['unavailable_proven'] ?? false) === true => 'unavailable',
            default => 'unverified',
        };

        return $this->decision('seo.source_capability_decision.v1', $state, $evidence);
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function measurementState(array $evidence): array
    {
        $state = match (true) {
            ($evidence['authority_conflict'] ?? false) === true,
            ($evidence['privacy_failed'] ?? false) === true,
            ($evidence['policy_hold'] ?? false) === true => 'hold',
            ($evidence['mapping_failed'] ?? false) === true => 'mapping_failed',
            ($evidence['expected_evidence_present'] ?? true) === false => 'missing',
            ($evidence['within_normal_lag'] ?? false) === true => 'delayed',
            ($evidence['window_complete'] ?? false) === false => 'window_incomplete',
            ($evidence['quality_gate_passed'] ?? false) === true
                && ($evidence['explicit_zero_proof'] ?? false) === true
                && ($evidence['all_relevant_values_zero'] ?? false) === true => 'valid_zero',
            ($evidence['quality_gate_passed'] ?? false) === true
                && ($evidence['valid_measurement_present'] ?? false) === true => 'valid',
            default => 'hold',
        };

        return $this->decision('seo.measurement_state_decision.v1', $state, $evidence);
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    private function decision(string $version, string $state, array $evidence): array
    {
        $decision = [
            'version' => $version,
            'state' => $state,
            'authority_revision' => (string) ($evidence['authority_revision'] ?? 'unavailable'),
            'source_ref' => (string) ($evidence['source_ref'] ?? 'unavailable'),
            'window' => (array) ($evidence['window'] ?? []),
            'freshness' => (array) ($evidence['freshness'] ?? []),
            'state_reason' => (string) ($evidence['state_reason'] ?? $state),
            'execution_allowed' => false,
        ];
        $decision['canonical_hash'] = $this->hasher->hash($decision);

        return $decision;
    }
}
