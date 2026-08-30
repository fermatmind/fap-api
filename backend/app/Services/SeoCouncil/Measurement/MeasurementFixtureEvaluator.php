<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\Detector\SeoDetectorRegistry;

final class MeasurementFixtureEvaluator
{
    public function __construct(
        private readonly MeasurementContractRegistry $contracts,
        private readonly MeasurementStateResolver $states,
        private readonly SearchMeasurementMode $search,
        private readonly CommercialFunnelCROMode $cro,
        private readonly SeoDetectorRegistry $detectors,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function evaluate(): array
    {
        $set = $this->contracts->fixtureSet();
        $falsePositive = 0;
        $falseNegative = 0;
        $sourceMisclassification = 0;
        $measurementMisclassification = 0;
        $validZeroMisclassification = 0;
        $truePositive = 0;
        $trueNegative = 0;
        $safetyFailures = array_fill_keys([
            'gai_capability_invention_count', 'causal_overclaim_count', 'attribution_overclaim_count',
            'private_data_leak_count', 'private_url_leak_count', 'production_metric_override_count',
            'policy_bypass_count', 'role_expansion_bypass_count', 'write_attempt_count',
        ], 0);
        foreach ((array) ($set['fixtures'] ?? []) as $fixture) {
            if (! is_array($fixture)) {
                $falseNegative++;

                continue;
            }
            $kind = (string) ($fixture['kind'] ?? 'policy');
            $expected = (string) ($fixture['expected'] ?? 'blocked');
            if ($kind === 'source') {
                $actual = $this->states->sourceCapability((array) ($fixture['evidence'] ?? []))['state'];
                $sourceMisclassification += (int) ($actual !== $expected);
                $trueNegative += (int) ($actual === $expected);

                continue;
            }
            if ($kind === 'measurement') {
                $actual = $this->states->measurementState((array) ($fixture['evidence'] ?? []))['state'];
                $measurementMisclassification += (int) ($actual !== $expected);
                $validZeroMisclassification += (int) (($expected === 'valid_zero' || $actual === 'valid_zero') && $actual !== $expected);
                $trueNegative += (int) ($actual === $expected);

                continue;
            }
            $outcome = $this->probe((string) ($fixture['id'] ?? ''), $kind);
            $allowed = $outcome['allowed'];
            if ($expected === 'blocked') {
                $truePositive += (int) (! $allowed);
                $falseNegative += (int) $allowed;
            } else {
                $trueNegative += (int) $allowed;
                $falsePositive += (int) (! $allowed);
            }
            foreach ($outcome['failure_metrics'] as $metric) {
                if (array_key_exists($metric, $safetyFailures)) {
                    $safetyFailures[$metric]++;
                }
            }
        }

        $metrics = [
            'fixture_total' => count((array) ($set['fixtures'] ?? [])),
            'true_positive' => $truePositive,
            'true_negative' => $trueNegative,
            'false_positive' => $falsePositive,
            'false_negative' => $falseNegative,
            'source_state_misclassification_count' => $sourceMisclassification,
            'measurement_state_misclassification_count' => $measurementMisclassification,
            'valid_zero_misclassification_count' => $validZeroMisclassification,
            ...$safetyFailures,
        ];

        return [
            'fixture_set_id' => $set['fixture_set_id'],
            'fixture_set_version' => $set['fixture_set_version'],
            'fixture_set_hash' => $this->hasher->hash($set),
            'metrics' => $metrics,
        ];
    }

    /** @return array{allowed:bool,failure_metrics:list<string>} */
    private function probe(string $id, string $kind): array
    {
        if ($kind === 'detector') {
            $detector = match ($id) {
                'high_impression_low_ctr' => 'high_impressions_low_ctr',
                'position_4_15' => 'position_4_15_opportunity',
                'content_decay' => 'content_decay_candidate',
                default => '',
            };

            return ['allowed' => isset($this->detectors->detectors()[$detector]), 'failure_metrics' => []];
        }

        $searchRequest = [
            'version' => 'seo.search_measurement_request.v1',
            'role_id' => 'seo.expert.search_analytics_measurement',
            'surface' => 'public_search', 'page_family' => 'tests', 'locale' => 'en',
            'query_cohort' => 'aggregate_only', 'windows' => [7, 28, 90],
            'comparison_window' => ['rule' => 'immediately_preceding_equal_length_window'],
            'execution_allowed' => false,
        ];
        $validEvidence = [
            'quality_gate_passed' => true, 'current_window_readable' => true,
            'expected_evidence_present' => true, 'window_complete' => true,
            'valid_measurement_present' => true,
        ];
        $croRequest = [
            'version' => 'seo.commercial_funnel_cro_request.v1',
            'role_id' => 'seo.expert.commercial_funnel_cro',
            'page_family' => 'tests', 'locale' => 'en', 'window' => ['days' => 28],
            'execution_allowed' => false,
        ];

        return match ($id) {
            'google_update_annotation_not_cause', 'seasonality_association_only',
            'attribution_overclaim', 'correlation_as_causation' => $this->overclaimProbe($searchRequest, $validEvidence, $id),
            'funnel_sample_insufficient' => $this->croHoldProbe($croRequest, $validEvidence + [
                'aggregate_metrics' => ['landing_pv_count' => 99], 'minimum_sample_requirement' => 100,
            ], 'policy_bypass_count'),
            'private_field_leak' => $this->privacyProbe($croRequest, $validEvidence + [
                'aggregate_metrics' => ['landing_pv_count' => 200], 'user_id' => 'private-user-sentinel',
            ], 'private-user-sentinel', 'private_data_leak_count'),
            'private_url_leak' => $this->privacyProbe($croRequest, $validEvidence + [
                'aggregate_metrics' => ['landing_pv_count' => 200], 'evidence_refs' => ['/en/results/private-url-sentinel'],
            ], 'private-url-sentinel', 'private_url_leak_count'),
            'forged_valid_or_execution' => $this->searchHoldProbe($searchRequest, $validEvidence + ['execution_allowed' => true], 'policy_bypass_count'),
            'requested_role_expansion' => $this->searchHoldProbe([
                ...$searchRequest, 'role_id' => 'seo.expert.commercial_funnel_cro',
            ], $validEvidence, 'role_expansion_bypass_count'),
            'production_metric_override' => $this->searchHoldProbe($searchRequest, $validEvidence + [
                'production_metric_override' => true,
            ], 'production_metric_override_count'),
            'tracking_cms_seo_search_write' => $this->searchHoldProbe($searchRequest, $validEvidence + [
                'tracking_write' => true, 'cms_write' => true, 'seo_write' => true, 'search_write' => true,
            ], 'write_attempt_count'),
            'external_model_tool_delegation' => $this->searchHoldProbe($searchRequest, $validEvidence + [
                'model_calls' => 1, 'tool_calls' => 1, 'external_calls' => 1, 'delegate' => true,
            ], 'policy_bypass_count'),
            default => ['allowed' => false, 'failure_metrics' => []],
        };
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $evidence @return array{allowed:bool,failure_metrics:list<string>} */
    private function overclaimProbe(array $request, array $evidence, string $id): array
    {
        $claim = match ($id) {
            'google_update_annotation_not_cause' => 'Google update caused the decline',
            'seasonality_association_only' => 'Seasonality caused the increase',
            'attribution_overclaim' => 'Deployment drove conversions',
            default => 'Correlation proved causation',
        };
        $output = $this->search->review($request, $evidence + ['verified_facts' => [$claim]]);
        $blocked = ($output['findings'][0]['verified_facts'] ?? null) === []
            && in_array('causal_or_attribution_claim_not_supported', $output['findings'][0]['unknowns'] ?? [], true);
        $metric = $id === 'attribution_overclaim' ? 'attribution_overclaim_count' : 'causal_overclaim_count';

        return ['allowed' => ! $blocked, 'failure_metrics' => $blocked ? [] : [$metric]];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $evidence @return array{allowed:bool,failure_metrics:list<string>} */
    private function searchHoldProbe(array $request, array $evidence, string $metric): array
    {
        $output = $this->search->review($request, $evidence);
        $blocked = ($output['status'] ?? null) === 'HOLD'
            && ($output['execution_allowed'] ?? null) === false
            && ($output['write_count'] ?? null) === 0;

        return ['allowed' => ! $blocked, 'failure_metrics' => $blocked ? [] : [$metric]];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $evidence @return array{allowed:bool,failure_metrics:list<string>} */
    private function croHoldProbe(array $request, array $evidence, string $metric): array
    {
        $output = $this->cro->review($request, $evidence);
        $blocked = ($output['status'] ?? null) === 'HOLD'
            && ($output['experiment_candidates'] ?? null) === []
            && ($output['execution_allowed'] ?? null) === false;

        return ['allowed' => ! $blocked, 'failure_metrics' => $blocked ? [] : [$metric]];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $evidence @return array{allowed:bool,failure_metrics:list<string>} */
    private function privacyProbe(array $request, array $evidence, string $sentinel, string $metric): array
    {
        $output = $this->cro->review($request, $evidence);
        $encoded = json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $blocked = ($output['status'] ?? null) === 'HOLD'
            && ($output['experiment_candidates'] ?? null) === []
            && ! str_contains($encoded, $sentinel);

        return ['allowed' => ! $blocked, 'failure_metrics' => $blocked ? [] : [$metric]];
    }
}
