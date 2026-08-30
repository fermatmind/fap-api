<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class TechnicalDiagnosisContractValidator
{
    private const HASH = '/^[a-f0-9]{64}$/D';

    private const SHA = '/^[a-f0-9]{40}$/D';

    private const ENVIRONMENTS = ['ci_candidate', 'staging_runtime', 'production_runtime'];

    public function __construct(
        private readonly TechnicalDiagnosisContractRegistry $contracts,
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
        if (! $this->exactSchemaKeys($request, 'seo.technical_diagnosis_request.v2')
            || ($request['diagnosis_version'] ?? null) !== 2
            || ($request['role_id'] ?? null) !== 'seo.expert.technical_search_authority'
            || ($request['mode_id'] ?? null) !== 'technical_search_diagnosis'
            || ! in_array($request['page_family'] ?? null, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)
            || ! in_array($request['locale'] ?? null, ['zh-CN', 'en'], true)
            || ($request['execution_allowed'] ?? null) !== false
            || ($request['allow_delegation'] ?? null) !== false
            || ! $this->nonEmptyStrings($request, ['diagnosis_id', 'mission_id', 'run_id', 'url_truth_revision', 'runtime_revision', 'deployment_revision', 'authority_revision', 'requested_at'])
            || preg_match(self::HASH, (string) ($request['request_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($request, 'request_hash'), (string) $request['request_hash'])) {
            return false;
        }

        $dependency = $request['dependency_snapshot_ref'] ?? null;
        $detector = $request['detector_registry_ref'] ?? null;
        $scope = $request['requested_scope'] ?? null;
        $refs = $request['evidence_bundle_refs'] ?? null;
        if (! is_array($dependency) || ! $this->exactKeys($dependency, ['snapshot_id', 'snapshot_version', 'snapshot_hash', 'production_sha', 'environment'])
            || ! $this->nonEmptyStrings($dependency, ['snapshot_id', 'snapshot_version'])
            || preg_match(self::HASH, (string) ($dependency['snapshot_hash'] ?? '')) !== 1
            || preg_match(self::SHA, (string) ($dependency['production_sha'] ?? '')) !== 1
            || ! in_array($dependency['environment'] ?? null, self::ENVIRONMENTS, true)
            || ! is_array($detector) || ! $this->exactKeys($detector, ['registry_version', 'registry_hash'])
            || ! $this->nonEmptyStrings($detector, ['registry_version'])
            || preg_match(self::HASH, (string) ($detector['registry_hash'] ?? '')) !== 1
            || ! is_array($scope) || ! $this->exactKeys($scope, ['sanitized_public_refs', 'max_urls', 'page_family', 'locale'])
            || ! is_array($scope['sanitized_public_refs'] ?? null)
            || ! is_int($scope['max_urls'] ?? null) || $scope['max_urls'] < 1 || $scope['max_urls'] > 32
            || count($scope['sanitized_public_refs']) > $scope['max_urls']
            || ($scope['page_family'] ?? null) !== $request['page_family']
            || ($scope['locale'] ?? null) !== $request['locale']
            || ! is_array($refs) || ! array_is_list($refs) || $refs === []) {
            return false;
        }
        foreach ($refs as $ref) {
            if (! is_array($ref) || ! $this->exactKeys($ref, ['bundle_id', 'bundle_version', 'bundle_hash', 'source_type', 'authority_type'])
                || ! $this->nonEmptyStrings($ref, ['bundle_id', 'source_type', 'authority_type'])
                || ! is_int($ref['bundle_version'] ?? null) || $ref['bundle_version'] < 1
                || preg_match(self::HASH, (string) ($ref['bundle_hash'] ?? '')) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $finding */
    public function finding(array $finding): bool
    {
        $valid = $this->exactSchemaKeys($finding, 'seo.technical_diagnosis_finding.v2')
            && ($finding['finding_version'] ?? null) === 2
            && in_array($finding['evidence_state'] ?? null, ['verified_fact', 'supported_hypothesis', 'unverified_hypothesis', 'unknown', 'blocked'], true)
            && in_array($finding['severity'] ?? null, ['P0', 'P1', 'P2', 'P3', 'HOLD'], true)
            && in_array($finding['confidence'] ?? null, ['high', 'medium', 'low', 'insufficient'], true)
            && in_array($finding['discovery_decision'] ?? null, ['ALLOW_DISCOVERY_REVIEW', 'HOLD_DISCOVERY'], true)
            && ($finding['execution_allowed'] ?? null) === false
            && ($finding['write_permission'] ?? null) === false
            && ($finding['search_submission_allowed'] ?? null) === false
            && $this->affectedScope((array) ($finding['affected_scope'] ?? []));
        foreach ((array) ($finding['root_cause_hypotheses'] ?? []) as $hypothesis) {
            $valid = $valid && is_array($hypothesis) && $this->hypothesis($hypothesis);
        }
        if (is_array($finding['evidence_gap'] ?? null)) {
            $valid = $valid && $this->evidenceGap($finding['evidence_gap']);
        }
        $p0p1 = in_array($finding['severity'] ?? null, ['P0', 'P1'], true);

        return $valid
            && (! $p0p1 || (($finding['evidence_state'] ?? null) === 'verified_fact'
                && ($finding['confidence'] ?? null) === 'high'
                && ($finding['direct_reproducible_observation'] ?? null) === true
                && ($finding['current_revision_consistent'] ?? null) === true
                && ($finding['required_authority_sources_present'] ?? null) === true))
            && (($finding['evidence_state'] ?? null) === 'verified_fact' || ($finding['verified_facts'] ?? null) === []);
    }

    /** @param array<string, mixed> $output */
    public function output(array $output): bool
    {
        $valid = $this->exactSchemaKeys($output, 'seo.technical_diagnosis_output.v2')
            && ($output['output_version'] ?? null) === 2
            && in_array($output['status'] ?? null, ['READY', 'HOLD'], true)
            && in_array($output['confidence'] ?? null, ['high', 'medium', 'low', 'insufficient'], true)
            && ($output['execution_allowed'] ?? null) === false
            && ($output['write_permission'] ?? null) === false
            && ($output['search_submission_allowed'] ?? null) === false
            && ($output['model_calls'] ?? null) === 0
            && ($output['tool_calls'] ?? null) === 0
            && ($output['external_calls'] ?? null) === 0
            && preg_match(self::HASH, (string) ($output['output_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($output, 'output_hash'), (string) $output['output_hash']);
        foreach ((array) ($output['findings'] ?? []) as $finding) {
            $valid = $valid && is_array($finding) && $this->finding($finding);
        }

        return $valid;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): bool
    {
        return $this->exactSchemaKeys($context, 'seo.technical_diagnosis_evidence_context.v2')
            && ($context['context_version'] ?? null) === 'seo.technical_diagnosis_evidence_context.v2'
            && ($context['execution_allowed'] ?? null) === false
            && is_array($context['namespaces'] ?? null)
            && is_array($context['computed_evidence'] ?? null)
            && preg_match(self::HASH, (string) ($context['context_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($context, 'context_hash'), (string) $context['context_hash']);
    }

    /** @param array<string, mixed> $receipt */
    public function receipt(array $receipt): bool
    {
        if (! $this->exactSchemaKeys($receipt, 'seo.technical_diagnosis_receipt.v2')
            || ($receipt['receipt_version'] ?? null) !== 'seo.technical_diagnosis_closeout.v2'
            || ! in_array($receipt['environment'] ?? null, self::ENVIRONMENTS, true)
            || preg_match(self::SHA, (string) ($receipt['candidate_sha'] ?? '')) !== 1
            || ($receipt['execution_allowed'] ?? null) !== false
            || preg_match(self::HASH, (string) ($receipt['receipt_hash'] ?? '')) !== 1
            || ! hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), (string) $receipt['receipt_hash'])) {
            return false;
        }
        $zeroFields = self::zeroMetricFields();
        foreach ($zeroFields as $field) {
            if (! is_int($receipt[$field] ?? null) || $receipt[$field] < 0) {
                return false;
            }
        }
        $closed = ($receipt['SEO-PLATFORM-11E'] ?? null) === 'CLOSED';
        $state = $receipt['closeout_state'] ?? null;
        $environment = $receipt['environment'] ?? null;
        $dependencyMode = $receipt['dependency_mode'] ?? null;
        if (($receipt['dependency_snapshot_version'] ?? null) !== TechnicalDiagnosisDependencySnapshotBuilder::SNAPSHOT_VERSION
            || preg_match(self::HASH, (string) ($receipt['dependency_snapshot_hash'] ?? '')) !== 1
            || ($state === 'CANDIDATE_READY' && ! ($environment === 'ci_candidate' && $dependencyMode === 'OFFLINE_FIXTURE' && ($receipt['observed_active_sha'] ?? null) === null))
            || ($state === 'STAGING_READY' && ! ($environment === 'staging_runtime' && $dependencyMode === 'RUNTIME_READ_ONLY' && ($receipt['observed_active_sha'] ?? null) === $receipt['candidate_sha']))
            || ($state === 'CLOSED' && ! ($environment === 'production_runtime' && $dependencyMode === 'RUNTIME_READ_ONLY' && ($receipt['observed_active_sha'] ?? null) === $receipt['candidate_sha']))) {
            return false;
        }
        if ($state === 'CLOSED') {
            foreach (['url_truth_projection_hash', 'runtime_evidence_hash', 'detector_registry_hash', 'page_family_policy_hash', 'source_field_ownership_hash', 'context_schema_hash'] as $field) {
                if (preg_match(self::HASH, (string) ($receipt[$field] ?? '')) !== 1) {
                    return false;
                }
            }
            foreach (['url_truth_revision', 'runtime_evidence_revision', 'authority_revision', 'deployment_revision'] as $field) {
                $value = (string) ($receipt[$field] ?? '');
                if ($value === '' || $value === 'unavailable' || str_contains($value, 'fixture') || str_contains($value, 'offline-eval')) {
                    return false;
                }
            }
        }
        $canClose = ($receipt['environment'] ?? null) === 'production_runtime'
            && ($receipt['dependency_mode'] ?? null) === 'RUNTIME_READ_ONLY'
            && ($receipt['closeout_state'] ?? null) === 'CLOSED'
            && ($receipt['observed_active_sha'] ?? null) === $receipt['candidate_sha']
            && array_sum(array_map(static fn (string $field): int => (int) $receipt[$field], $zeroFields)) === 0;

        return $closed === $canClose && $closed === (($receipt['ready_for_11F'] ?? null) === true);
    }

    /** @return list<string> */
    public static function zeroMetricFields(): array
    {
        return [
            'real_dependency_binding_bypass', 'dependency_ref_mismatch_bypass', 'detector_ref_mismatch_bypass',
            'cross_source_field_bypass', 'cross_source_overwrite_bypass', 'bundle_order_variance_count',
            'unsupported_p0_p1_count', 'authority_invention_count', 'hardcoded_negative_guarantee_count',
            'orchestrator_runner_bypass', 'private_url_leak_count', 'policy_bypass_count', 'write_attempt_count',
            'shared_root_misclassification_count', 'model_calls', 'tool_calls', 'external_calls',
            'business_writes', 'cms_writes', 'url_truth_writes', 'canonical_writes', 'robots_writes',
            'feed_writes', 'search_writes', 'active_manifest_count', 'trusted_key_count', 'l4_allow_count',
            'production_permissions',
        ];
    }

    /** @param array<string, mixed> $scope */
    private function affectedScope(array $scope): bool
    {
        return $this->exactSchemaKeys($scope, 'seo.technical_affected_scope.v1')
            && in_array($scope['scope_kind'] ?? null, ['single_url', 'url_cohort', 'shared_layer', 'unknown'], true)
            && is_int($scope['affected_url_count'] ?? null)
            && is_int($scope['affected_family_count'] ?? null);
    }

    /** @param array<string, mixed> $hypothesis */
    private function hypothesis(array $hypothesis): bool
    {
        return $this->exactSchemaKeys($hypothesis, 'seo.technical_root_cause_hypothesis.v1')
            && in_array($hypothesis['evidence_state'] ?? null, ['supported_hypothesis', 'unverified_hypothesis', 'unknown', 'blocked'], true)
            && is_array($hypothesis['falsification_checks'] ?? null)
            && $hypothesis['falsification_checks'] !== [];
    }

    /** @param array<string, mixed> $gap */
    private function evidenceGap(array $gap): bool
    {
        return $this->exactSchemaKeys($gap, 'seo.technical_evidence_gap.v1')
            && is_array($gap['missing_evidence'] ?? null)
            && $gap['missing_evidence'] !== [];
    }

    /** @param array<string, mixed> $value */
    private function exactSchemaKeys(array $value, string $schemaId): bool
    {
        $schema = $this->contracts->schema($schemaId);
        if (($schema['additionalProperties'] ?? null) !== false || ! is_array($schema['required'] ?? null)) {
            return false;
        }

        return $this->exactKeys($value, $schema['required']);
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
}
