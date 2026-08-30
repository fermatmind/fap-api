<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Measurement;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class MeasurementContractValidator
{
    private const HASH = '/^[a-f0-9]{64}$/D';

    private const SHA = '/^[a-f0-9]{40}$/D';

    private const BOUNDED_ID = '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D';

    private const WINDOWS = [7, 28, 90];

    private const MODES = [
        'search_measurement' => 'seo.expert.search_analytics_measurement',
        'commercial_funnel_cro' => 'seo.expert.commercial_funnel_cro',
    ];

    public function __construct(
        private readonly MeasurementContractRegistry $contracts,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function sealRequest(array $request): array
    {
        unset($request['request_hash']);
        $request['request_hash'] = $this->hasher->hash($request);

        return $request;
    }

    /** @param array<string, mixed> $request */
    public function request(array $request): bool
    {
        if (! $this->exactSchemaKeys($request, 'seo.measurement_request.v2')
            || ($request['version'] ?? null) !== 'seo.measurement_request.v2'
            || ! isset(self::MODES[(string) ($request['mode_id'] ?? '')])
            || ($request['role_id'] ?? null) !== self::MODES[(string) $request['mode_id']]
            || ! in_array($request['page_family'] ?? null, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)
            || ! in_array($request['locale'] ?? null, ['en', 'zh-CN'], true)
            || ($request['windows'] ?? null) !== self::WINDOWS
            || ($request['execution_allowed'] ?? null) !== false
            || ! $this->nonEmptyStrings($request, ['mission_id', 'run_id', 'authority_revision'])
            || preg_match(self::BOUNDED_ID, (string) ($request['mission_id'] ?? '')) !== 1
            || preg_match(self::BOUNDED_ID, (string) ($request['run_id'] ?? '')) !== 1
            || preg_match(self::HASH, (string) ($request['authority_revision'] ?? '')) !== 1
            || preg_match(self::HASH, (string) ($request['request_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($request, 'request_hash'), (string) $request['request_hash'])) {
            return false;
        }
        $refs = $request['evidence_bundle_refs'] ?? null;
        if (! is_array($refs) || ! array_is_list($refs) || $refs === [] || count($refs) > 2) {
            return false;
        }
        foreach ($refs as $ref) {
            if (! is_array($ref)
                || ! $this->exactKeys($ref, ['bundle_id', 'bundle_version', 'bundle_hash', 'source_type', 'authority_type'])
                || ! $this->nonEmptyStrings($ref, ['bundle_id', 'source_type', 'authority_type'])
                || ($ref['bundle_version'] ?? null) !== 2
                || preg_match(self::HASH, (string) ($ref['bundle_hash'] ?? '')) !== 1) {
                return false;
            }
            $expectedSource = $request['mode_id'] === 'search_measurement' ? 'gsc_aggregate' : 'public_funnel_aggregate';
            if ($ref['source_type'] !== $expectedSource || $ref['authority_type'] !== 'measurement_readmodel') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): bool
    {
        return $this->exactSchemaKeys($context, 'seo.measurement_evidence_context.v2')
            && ($context['version'] ?? null) === 'seo.measurement_evidence_context.v2'
            && isset(self::MODES[(string) ($context['mode_id'] ?? '')])
            && ($context['role_id'] ?? null) === self::MODES[(string) $context['mode_id']]
            && in_array($context['page_family'] ?? null, [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, 'page_family:held'], true)
            && in_array($context['locale'] ?? null, ['en', 'zh-CN', 'und'], true)
            && ($context['windows'] ?? null) === self::WINDOWS
            && in_array($context['status'] ?? null, ['READY', 'HOLD'], true)
            && is_bool($context['measurement_allowed'] ?? null)
            && (($context['status'] ?? null) === 'READY') === (($context['measurement_allowed'] ?? null) === true)
            && ($context['execution_allowed'] ?? null) === false
            && $this->sourceDecision((array) ($context['source_capability'] ?? []))
            && $this->measurementDecision((array) ($context['measurement_state'] ?? []))
            && is_array($context['metrics'] ?? null) && is_array($context['facts'] ?? null)
            && $this->contextBundleRefs(
                (array) ($context['bundle_refs'] ?? []),
                (string) ($context['mode_id'] ?? ''),
                (string) ($context['status'] ?? ''),
            )
            && $this->facts((array) ($context['facts'] ?? []))
            && (($context['status'] ?? null) !== 'READY'
                || $this->metrics((array) ($context['metrics'] ?? []), (string) ($context['mode_id'] ?? '')))
            && preg_match(self::HASH, (string) ($context['request_hash'] ?? '')) === 1
            && preg_match(self::HASH, (string) ($context['context_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($context, 'context_hash'), (string) $context['context_hash']);
    }

    /** @param array<string, mixed> $decision */
    public function sourceDecision(array $decision): bool
    {
        return $this->decision($decision, 'seo.source_capability_decision.v2', [
            'api_ready', 'available', 'manual_export_only', 'unavailable', 'unverified',
        ]);
    }

    /** @param array<string, mixed> $decision */
    public function measurementDecision(array $decision): bool
    {
        return $this->decision($decision, 'seo.measurement_state_decision.v2', [
            'valid', 'valid_zero', 'missing', 'delayed', 'window_incomplete', 'mapping_failed', 'hold',
        ]);
    }

    /** @param array<string, mixed> $finding */
    public function finding(array $finding): bool
    {
        if (! $this->exactSchemaKeys($finding, 'seo.measurement_finding.v2')
            || ($finding['version'] ?? null) !== 'seo.measurement_finding.v2'
            || ! isset(self::MODES[(string) ($finding['mode_id'] ?? '')])
            || ($finding['role_id'] ?? null) !== self::MODES[(string) $finding['mode_id']]
            || ! in_array($finding['page_family'] ?? null, [...PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, 'page_family:held'], true)
            || ! in_array($finding['locale'] ?? null, ['en', 'zh-CN', 'und'], true)
            || ! is_array($finding['aggregate_metrics'] ?? null)
            || ! $this->stringList($finding['verified_facts'] ?? null)
            || ! $this->stringList($finding['associations'] ?? null)
            || ! $this->stringList($finding['hypotheses'] ?? null)
            || ! $this->stringList($finding['unknowns'] ?? null)
            || ! $this->stringList($finding['holds'] ?? null)
            || ! $this->hashList($finding['evidence_refs'] ?? null)
            || ($finding['execution_allowed'] ?? null) !== false
            || ! $this->sourceDecision((array) ($finding['source_capability'] ?? []))
            || ! $this->measurementDecision((array) ($finding['measurement_state'] ?? []))
            || preg_match(self::HASH, (string) ($finding['finding_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($finding, 'finding_hash'), (string) $finding['finding_hash'])) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $candidate */
    public function candidate(array $candidate): bool
    {
        return $this->exactSchemaKeys($candidate, 'seo.measurement_candidate.v2')
            && ($candidate['version'] ?? null) === 'seo.measurement_candidate.v2'
            && $this->nonEmptyStrings($candidate, ['hypothesis', 'falsification_rule', 'primary_metric'])
            && in_array($candidate['uncertainty'] ?? null, ['high', 'medium', 'low'], true)
            && $this->nonEmptyStringList($candidate['guardrail_metrics'] ?? null)
            && $this->nonEmptyStringList($candidate['stop_conditions'] ?? null)
            && is_int($candidate['minimum_sample_requirement'] ?? null)
            && $candidate['minimum_sample_requirement'] > 0
            && ($candidate['execution_allowed'] ?? null) === false
            && preg_match(self::HASH, (string) ($candidate['candidate_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($candidate, 'candidate_hash'), (string) $candidate['candidate_hash']);
    }

    /** @param array<string, mixed> $output */
    public function output(array $output): bool
    {
        if (! $this->exactSchemaKeys($output, 'seo.measurement_output.v2')
            || ($output['version'] ?? null) !== 'seo.measurement_output.v2'
            || ! in_array($output['status'] ?? null, ['READY', 'HOLD'], true)
            || ($output['execution_allowed'] ?? null) !== false
            || ($output['model_calls'] ?? null) !== 0 || ($output['tool_calls'] ?? null) !== 0
            || ($output['external_calls'] ?? null) !== 0 || ($output['write_count'] ?? null) !== 0
            || preg_match(self::HASH, (string) ($output['output_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($output, 'output_hash'), (string) $output['output_hash'])) {
            return false;
        }
        foreach ((array) ($output['findings'] ?? []) as $finding) {
            if (! is_array($finding) || ! $this->finding($finding)) {
                return false;
            }
        }
        foreach ((array) ($output['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate) || ! $this->candidate($candidate)) {
                return false;
            }
        }

        return ($output['status'] === 'READY' && $output['hold_reason'] === null)
            || ($output['status'] === 'HOLD' && is_string($output['hold_reason']) && $output['hold_reason'] !== '');
    }

    /** @param array<string, mixed> $receipt */
    public function receipt(array $receipt): bool
    {
        if (! $this->exactSchemaKeys($receipt, 'seo.measurement_closeout_receipt.v2')
            || ($receipt['receipt_version'] ?? null) !== 'seo.measurement_closeout.v2'
            || ! in_array($receipt['environment'] ?? null, ['ci_candidate', 'staging_runtime', 'production_runtime'], true)
            || preg_match(self::SHA, (string) ($receipt['candidate_sha'] ?? '')) !== 1
            || preg_match(self::SHA, (string) ($receipt['production_sha'] ?? '')) !== 1
            || preg_match(self::HASH, (string) ($receipt['dependency_snapshot_hash'] ?? '')) !== 1
            || preg_match(self::HASH, (string) ($receipt['contract_manifest_hash'] ?? '')) !== 1
            || ($receipt['execution_allowed'] ?? null) !== false
            || ($receipt['production_execution_enabled'] ?? null) !== false
            || preg_match(self::HASH, (string) ($receipt['receipt_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), (string) $receipt['receipt_hash'])) {
            return false;
        }
        foreach (self::zeroMetricFields() as $field) {
            if (! is_int($receipt[$field] ?? null) || $receipt[$field] < 0) {
                return false;
            }
        }
        $closed = $receipt['environment'] === 'production_runtime'
            && $receipt['closeout_state'] === 'CLOSED'
            && $receipt['evidence_source_state'] === 'available'
            && $receipt['evidence_freshness_state'] === 'fresh'
            && preg_match(self::HASH, (string) $receipt['evidence_authority_revision']) === 1
            && array_sum(array_map(static fn (string $field): int => $receipt[$field], self::zeroMetricFields())) === 0;

        return (($receipt['SEO-PLATFORM-11F'] ?? null) === 'CLOSED') === $closed
            && (($receipt['ready_for_11G'] ?? null) === true) === $closed;
    }

    /** @return list<string> */
    public static function zeroMetricFields(): array
    {
        return [
            'real_evidence_bundle_bypass_count', 'bundle_verifier_bypass_count', 'context_builder_bypass_count',
            'request_pii_bypass_count', 'evidence_pii_bypass_count', 'metadata_pii_bypass_count',
            'output_pii_bypass_count', 'private_url_leak_count', 'cro_causal_overclaim_count',
            'source_conflict_bypass_count', 'schema_validation_bypass_count', 'orchestrator_runner_bypass_count',
            'direct_mode_entry_bypass_count', 'policy_bypass_count', 'role_expansion_bypass_count',
            'write_attempt_count', 'model_calls', 'tool_calls', 'external_calls', 'cms_writes',
            'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions',
        ];
    }

    /** @param array<string, mixed> $decision @param list<string> $states */
    private function decision(array $decision, string $schemaId, array $states): bool
    {
        return $this->exactSchemaKeys($decision, $schemaId)
            && ($decision['version'] ?? null) === $schemaId
            && in_array($decision['state'] ?? null, $states, true)
            && is_bool($decision['conflict_detected'] ?? null)
            && ($decision['window'] ?? null) === self::WINDOWS && is_array($decision['freshness'] ?? null)
            && $this->nonEmptyStrings($decision, ['authority_revision', 'source_ref', 'state_reason'])
            && ($decision['execution_allowed'] ?? null) === false
            && preg_match(self::HASH, (string) ($decision['canonical_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($decision, 'canonical_hash'), (string) $decision['canonical_hash']);
    }

    /** @param array<string, mixed> $metrics */
    private function metrics(array $metrics, string $modeId): bool
    {
        $expectedKeys = $modeId === 'search_measurement'
            ? ['windows', 'branded_non_branded', 'detector_findings', 'freshness', 'mapping_state']
            : ['windows', 'stage_coverage', 'freshness', 'revision_hash', 'mapping_state'];
        if (! $this->exactKeys($metrics, $expectedKeys) || ! in_array($metrics['mapping_state'] ?? null, ['mapped', 'failed'], true)) {
            return false;
        }
        $windows = $metrics['windows'] ?? null;
        if (! is_array($windows) || ! array_is_list($windows) || count($windows) !== 3) {
            return false;
        }
        if (array_column($windows, 'window_days') !== self::WINDOWS) {
            return false;
        }
        foreach ($windows as $window) {
            if (! is_array($window) || ! $this->exactKeys($window, ['window_days', 'metrics']) || ! is_array($window['metrics'])) {
                return false;
            }
            $validMetrics = $modeId === 'search_measurement'
                ? $this->nonNegativeIntegers($window['metrics'], ['clicks', 'impressions', 'ctr_ppm', 'average_position_milli'])
                : $this->nonNegativeIntegers($window['metrics'], [
                    'landing_pv_count', 'article_to_test_click_count', 'start_test_count', 'complete_test_count',
                    'aggregate_outcome_view_count', 'return_public_content_count',
                ]);
            if (! $validMetrics) {
                return false;
            }
        }
        if ($modeId === 'search_measurement') {
            $split = $metrics['branded_non_branded'] ?? null;
            if (! is_array($split) || ! $this->exactKeys($split, ['branded', 'non_branded'])
                || ! $this->nonNegativeIntegers((array) $split['branded'], ['clicks', 'impressions', 'ctr_ppm', 'average_position_milli'])
                || ! $this->nonNegativeIntegers((array) $split['non_branded'], ['clicks', 'impressions', 'ctr_ppm', 'average_position_milli'])
                || ! $this->stringList($metrics['detector_findings'] ?? null)
                || ! $this->freshness((array) ($metrics['freshness'] ?? []), true)) {
                return false;
            }
        } else {
            $coverage = $metrics['stage_coverage'] ?? null;
            if (! is_array($coverage)
                || ! $this->exactKeys($coverage, ['landing', 'start', 'completion', 'aggregate_outcome_view', 'return_public_content', 'cta'])
                || array_filter($coverage, static fn (mixed $value): bool => ! is_bool($value)) !== []
                || preg_match(self::HASH, (string) ($metrics['revision_hash'] ?? '')) !== 1
                || ! $this->freshness((array) ($metrics['freshness'] ?? []), false)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $refs */
    private function contextBundleRefs(array $refs, string $modeId, string $status): bool
    {
        if ($status === 'HOLD') {
            return $refs === [];
        }
        if (! array_is_list($refs) || count($refs) !== 1) {
            return false;
        }
        $expectedSource = $modeId === 'search_measurement' ? 'gsc_aggregate' : 'public_funnel_aggregate';
        $ref = $refs[0];

        return $this->exactKeys($ref, ['bundle_id', 'bundle_version', 'bundle_hash', 'source_type', 'authority_type'])
            && $ref['bundle_version'] === 2 && $ref['source_type'] === $expectedSource
            && $ref['authority_type'] === 'measurement_readmodel'
            && preg_match(self::HASH, (string) $ref['bundle_hash']) === 1;
    }

    /** @param array<string, mixed> $facts */
    private function facts(array $facts): bool
    {
        if (! $this->exactKeys($facts, ['verified_facts', 'associations', 'hypotheses', 'unknowns'])) {
            return false;
        }
        foreach ($facts as $claims) {
            if (! $this->stringList($claims)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $freshness */
    private function freshness(array $freshness, bool $search): bool
    {
        $keys = $search
            ? ['lag_days_required', 'max_source_age_days', 'min_source_date', 'max_source_date']
            : ['age_hours', 'max_age_hours', 'latest_refresh_status'];
        if (! $this->exactKeys($freshness, $keys)) {
            return false;
        }

        return $search
            ? (is_int($freshness['lag_days_required']) || $freshness['lag_days_required'] === null)
                && (is_int($freshness['max_source_age_days']) || $freshness['max_source_age_days'] === null)
                && (is_string($freshness['min_source_date']) || $freshness['min_source_date'] === null)
                && (is_string($freshness['max_source_date']) || $freshness['max_source_date'] === null)
            : (is_int($freshness['age_hours']) || $freshness['age_hours'] === null)
                && is_int($freshness['max_age_hours'])
                && (is_string($freshness['latest_refresh_status']) || $freshness['latest_refresh_status'] === null);
    }

    /** @param array<string, mixed> $value @param list<string> $fields */
    private function nonNegativeIntegers(array $value, array $fields): bool
    {
        if (! $this->exactKeys($value, $fields)) {
            return false;
        }
        foreach ($fields as $field) {
            if (! is_int($value[$field]) || $value[$field] < 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value */
    private function exactSchemaKeys(array $value, string $schemaId): bool
    {
        $schema = $this->contracts->schema($schemaId);

        return ($schema['additionalProperties'] ?? null) === false
            && is_array($schema['required'] ?? null)
            && $this->exactKeys($value, $schema['required']);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    /** @param array<string, mixed> $value @param list<string> $fields */
    private function nonEmptyStrings(array $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (! is_string($value[$field] ?? null) || trim($value[$field]) === '') {
                return false;
            }
        }

        return true;
    }

    private function stringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && array_filter($value, static fn (mixed $item): bool => ! is_string($item)) === [];
    }

    private function nonEmptyStringList(mixed $value): bool
    {
        return $this->stringList($value) && $value !== []
            && array_filter($value, static fn (string $item): bool => trim($item) === '') === [];
    }

    private function hashList(mixed $value): bool
    {
        return $this->stringList($value)
            && array_filter($value, static fn (string $item): bool => preg_match(self::HASH, $item) !== 1) === [];
    }
}
