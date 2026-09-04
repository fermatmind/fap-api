<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class Platform12MetricContract
{
    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function contract(): array
    {
        $contract = [
            'schema_version' => 'seo.platform12_metric_contract.v1',
            'contract_id' => 'fermatmind.seo.platform12_metric_contract',
            'contract_version' => '1.0.0',
            'contract_state' => 'FROZEN_PRE_DAY1',
            'evaluation_window' => [
                'duration_days' => 28,
                'planned_daily_slots' => 28,
                'clock_state' => 'NOT_STARTED',
                'clock_start_authorized' => false,
                'minimum_complete_baseline_weeks' => 4,
            ],
            'frozen_strata' => [
                'families' => ['mbti', 'big_five', 'enneagram', 'career', 'riasec', 'other_public'],
                'locales' => ['zh-CN', 'en'],
                'routing_minimum_per_family_locale' => 8,
                'routing_minimum_total' => 96,
            ],
            'terminal_states' => [
                'PASS', 'FAIL', 'HOLD', 'MEASUREMENT_HOLD', 'INCONCLUSIVE',
                'MEASUREMENT_BASELINE_HOLD', 'RESTART_REQUIRED',
            ],
            'decision_rules' => [
                'external_data_failure' => [
                    'state' => 'MEASUREMENT_HOLD',
                    'denominator_policy' => 'RETAIN_ALL_PLANNED_OR_ELIGIBLE_UNITS',
                    'silent_exclusion_allowed' => false,
                ],
                'insufficient_baseline' => [
                    'state' => 'INCONCLUSIVE',
                    'reason' => 'MEASUREMENT_BASELINE_HOLD',
                    'minimum_complete_real_weeks' => 4,
                    'fabricated_or_proxy_baseline_allowed' => false,
                ],
                'contract_change' => [
                    'new_version_required' => true,
                    'new_hash_required' => true,
                    'restart_scope' => 'ALL_AFFECTED_METRICS',
                ],
                'restart_triggers' => [
                    'PRIVATE_DATA_LEAK',
                    'AUTHORITY_VIOLATION',
                    'UNAUTHORIZED_EXECUTION',
                    'WRONG_CANONICAL_OR_NOINDEX',
                    'SCALING_AFTER_GUARDRAIL_FAILURE',
                    'FABRICATED_EVIDENCE',
                ],
            ],
            'metrics' => $this->metrics(),
            'runtime_controls' => [
                'starts_28_day_clock' => false,
                'modifies_runtime_flags' => false,
                'activates_runtime_writes' => false,
                'measurement_only' => true,
            ],
        ];
        $contract['contract_hash'] = $this->hasher->hash($contract);

        return $contract;
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'schema_id' => 'seo.platform12_metric_contract.v1',
            'schema_version' => '1.0.0',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'schema_version', 'contract_id', 'contract_version', 'contract_state',
                'evaluation_window', 'frozen_strata', 'terminal_states', 'decision_rules',
                'metrics', 'runtime_controls', 'contract_hash',
            ],
            'properties' => [
                'schema_version' => ['const' => 'seo.platform12_metric_contract.v1'],
                'contract_id' => ['const' => 'fermatmind.seo.platform12_metric_contract'],
                'contract_version' => ['type' => 'string', 'pattern' => '^\\d+\\.\\d+\\.\\d+$'],
                'contract_state' => ['const' => 'FROZEN_PRE_DAY1'],
                'evaluation_window' => ['type' => 'object'],
                'frozen_strata' => ['type' => 'object'],
                'terminal_states' => ['type' => 'array', 'minItems' => 7, 'uniqueItems' => true],
                'decision_rules' => ['type' => 'object'],
                'metrics' => [
                    'type' => 'array',
                    'minItems' => 19,
                    'maxItems' => 19,
                    'items' => ['$ref' => '#/$defs/metric'],
                ],
                'runtime_controls' => ['type' => 'object'],
                'contract_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            ],
            '$defs' => [
                'metric' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'metric_id', 'numerator', 'denominator', 'data_sources', 'sample_method',
                        'minimum_sample', 'strata', 'confidence_interval_95', 'threshold',
                        'allowed_terminal_states', 'hold_rules', 'fail_rules',
                        'inconclusive_rules', 'restart_rules',
                    ],
                    'properties' => [
                        'metric_id' => ['type' => 'string', 'pattern' => '^[a-z0-9_]+$'],
                        'numerator' => ['type' => 'string', 'minLength' => 1],
                        'denominator' => ['type' => 'string', 'minLength' => 1],
                        'data_sources' => ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true],
                        'sample_method' => ['type' => 'string', 'minLength' => 1],
                        'minimum_sample' => ['type' => 'integer', 'minimum' => 0],
                        'strata' => ['type' => 'array', 'uniqueItems' => true],
                        'confidence_interval_95' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['required', 'method', 'confidence_level'],
                            'properties' => [
                                'required' => ['type' => 'boolean'],
                                'method' => ['type' => 'string', 'minLength' => 1],
                                'confidence_level' => ['const' => 0.95],
                            ],
                        ],
                        'threshold' => ['type' => 'string', 'minLength' => 1],
                        'allowed_terminal_states' => ['type' => 'array', 'minItems' => 4, 'uniqueItems' => true],
                        'hold_rules' => ['type' => 'array', 'minItems' => 1],
                        'fail_rules' => ['type' => 'array', 'minItems' => 1],
                        'inconclusive_rules' => ['type' => 'array', 'minItems' => 1],
                        'restart_rules' => ['type' => 'array', 'minItems' => 1],
                    ],
                ],
            ],
        ];
        $schema['schema_hash'] = $this->hasher->hash($schema);

        return $schema;
    }

    /** @param array<string, mixed> $candidate */
    public function verify(array $candidate): bool
    {
        $hash = $candidate['contract_hash'] ?? null;

        return is_string($hash)
            && hash_equals($this->hasher->hashWithout($candidate, 'contract_hash'), $hash)
            && $candidate === $this->contract();
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array{state: string, reason: string}
     */
    public function classifyGuardrails(array $signals): array
    {
        $restartSignals = [
            'private_data_leak_count',
            'authority_violation_count',
            'unauthorized_execution_count',
            'wrong_canonical_or_noindex_count',
            'fabricated_evidence_count',
        ];

        foreach ($restartSignals as $signal) {
            if (($signals[$signal] ?? 0) !== 0) {
                return ['state' => 'RESTART_REQUIRED', 'reason' => strtoupper($signal)];
            }
        }
        if (($signals['scaled_after_guardrail_failure'] ?? false) === true) {
            return ['state' => 'RESTART_REQUIRED', 'reason' => 'SCALING_AFTER_GUARDRAIL_FAILURE'];
        }
        if (($signals['external_data_available'] ?? false) !== true) {
            return ['state' => 'MEASUREMENT_HOLD', 'reason' => 'EXTERNAL_DATA_FAILURE'];
        }
        if (! is_int($signals['complete_real_baseline_weeks'] ?? null)
            || $signals['complete_real_baseline_weeks'] < 4) {
            return ['state' => 'INCONCLUSIVE', 'reason' => 'MEASUREMENT_BASELINE_HOLD'];
        }

        return ['state' => 'NOT_STARTED', 'reason' => 'DAY1_CLOCK_NOT_AUTHORIZED'];
    }

    /** @return list<array<string, mixed>> */
    private function metrics(): array
    {
        $census = ['required' => false, 'method' => 'NOT_APPLICABLE_CENSUS', 'confidence_level' => 0.95];
        $sampled = ['required' => true, 'method' => 'WILSON_SCORE_OR_EXACT_BINOMIAL', 'confidence_level' => 0.95];

        return [
            $this->metric('gsc_daily_slot_success_rate', 'successful GSC daily slots', 'all 28 planned daily slots; misses retained', ['GSC daily slot receipts'], 'FIXED_28_SLOT_CENSUS', 28, [], $census, '>=95%'),
            $this->metric('gsc_data_lag_compliance_rate', 'planned daily slots with GSC data lag <=3 days', 'all 28 planned daily slots; unavailable slots retained', ['GSC data freshness receipts'], 'FIXED_28_SLOT_CENSUS', 28, [], $census, '100% with lag <=3 days'),
            $this->metric('url_truth_reconciliation_rate', 'eligible public URLs reconciled to canonical/indexability truth', 'all eligible public URLs', ['URL Truth inventory', 'canonical and noindex observations'], 'ELIGIBLE_URL_CENSUS', 1, ['family', 'locale'], $census, '=100%'),
            $this->metric('cluster_valid_coverage_rate', 'cluster-eligible issues assigned to a valid cluster', 'all cluster-eligible issues', ['weekly opportunity cluster evidence'], 'ELIGIBLE_ISSUE_CENSUS', 1, ['family', 'locale'], $census, '>=90%'),
            $this->metric('dedupe_precision', 'labeled dedupe predictions confirmed correct', 'all items in the frozen labeled dedupe sample', ['frozen dedupe labels', 'dedupe decisions'], 'FROZEN_LABELED_SAMPLE', 100, ['family', 'locale'], $sampled, '>=90%'),
            $this->metric('false_positive_rate', 'negative sample items incorrectly flagged positive', 'all items in the frozen negative sample', ['frozen negative labels', 'detector decisions'], 'FROZEN_NEGATIVE_SAMPLE', 50, ['family', 'locale'], $sampled, '<10%'),
            $this->metric('routing_required_mode_safety_recall', 'required-mode safety cases correctly routed', 'all required-mode safety cases in the frozen routing sample', ['frozen routing labels', 'routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '=100%'),
            $this->metric('routing_authority_recall', 'authority-boundary cases correctly routed', 'all authority-boundary cases in the frozen routing sample', ['frozen routing labels', 'routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '=100%'),
            $this->metric('routing_overall_recall', 'positive routing cases correctly invoked', 'all positive cases in the frozen routing sample', ['frozen routing labels', 'routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '>=95%'),
            $this->metric('routing_precision', 'invocations matching the frozen required routing label', 'all invocations in the frozen routing sample', ['frozen routing labels', 'routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '>=90%'),
            $this->metric('routing_unnecessary_mode_rate', 'cases routed to an unnecessary mode', 'all cases in the frozen routing sample', ['frozen routing labels', 'routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '<=10%'),
            $this->metric('routing_all_team_invocation_rate', 'all-team invocations', 'all cases in the frozen routing sample', ['routing decisions'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '=0%'),
            $this->metric('routing_human_correction_rate', 'routing decisions requiring human correction', 'all cases in the frozen routing sample', ['frozen routing labels', 'human correction receipts'], 'FROZEN_STRATIFIED_SAMPLE', 96, ['family', 'locale', 'minimum 8 per family×locale'], $sampled, '<=10%'),
            $this->metric('trace_completeness_rate', 'required trace fields present and valid', 'all eligible mission traces', ['sanitized mission traces', 'trace schema validation'], 'ELIGIBLE_TRACE_CENSUS', 1, ['family', 'locale'], $census, '=100%'),
            $this->metric('policy_incident_count', 'confirmed policy incidents', 'complete 28-day evaluation window', ['security drift receipts', 'incident evidence'], 'INCIDENT_CENSUS', 28, [], $census, '=0'),
            $this->metric('private_data_incident_count', 'confirmed private-data leak incidents', 'complete 28-day evaluation window', ['security drift receipts', 'incident evidence'], 'INCIDENT_CENSUS', 28, [], $census, '=0'),
            $this->metric('unauthorized_execution_incident_count', 'confirmed unauthorized execution incidents', 'complete 28-day evaluation window', ['tool and write guard receipts', 'incident evidence'], 'INCIDENT_CENSUS', 28, [], $census, '=0'),
            $this->metric('routine_maintenance_median_minutes_per_week', 'median routine maintenance minutes over completed evaluation weeks', 'count of completed evaluation weeks', ['weekly efficiency evidence'], 'COMPLETE_WEEK_CENSUS', 4, [], $census, '<=30 minutes/week'),
            $this->metric('routine_maintenance_relative_reduction', 'pre-automation baseline median minus evaluation median', 'pre-automation baseline median from at least 4 complete real weeks', ['frozen pre-automation weekly baseline', 'weekly efficiency evidence'], 'PAIRED_COMPLETE_WEEK_COMPARISON', 4, [], $sampled, '>=80%'),
        ];
    }

    /**
     * @param  list<string>  $dataSources
     * @param  list<string>  $strata
     * @param  array{required: bool, method: string, confidence_level: float}  $confidence
     * @return array<string, mixed>
     */
    private function metric(
        string $id,
        string $numerator,
        string $denominator,
        array $dataSources,
        string $sampleMethod,
        int $minimumSample,
        array $strata,
        array $confidence,
        string $threshold,
    ): array {
        return [
            'metric_id' => $id,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'data_sources' => $dataSources,
            'sample_method' => $sampleMethod,
            'minimum_sample' => $minimumSample,
            'strata' => $strata,
            'confidence_interval_95' => $confidence,
            'threshold' => $threshold,
            'allowed_terminal_states' => ['PASS', 'FAIL', 'HOLD', 'MEASUREMENT_HOLD', 'INCONCLUSIVE', 'RESTART_REQUIRED'],
            'hold_rules' => ['external data unavailable => MEASUREMENT_HOLD; denominator remains frozen'],
            'fail_rules' => ['complete valid evidence misses the frozen threshold => FAIL'],
            'inconclusive_rules' => ['minimum sample or required real baseline not met => INCONCLUSIVE'],
            'restart_rules' => ['any global restart trigger or relevant contract version/hash change => RESTART_REQUIRED'],
        ];
    }
}
