<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\TechnicalDiagnosis;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class TechnicalDiagnosisContractValidator
{
    private const HASH = '/^[a-f0-9]{64}$/D';

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
        return $this->exactSchemaKeys($request, 'seo.technical_diagnosis_request.v1')
            && ($request['diagnosis_version'] ?? null) === 1
            && ($request['role_id'] ?? null) === 'seo.expert.technical_search_authority'
            && ($request['mode_id'] ?? null) === 'technical_search_diagnosis'
            && in_array($request['page_family'] ?? null, PageFamilyPolicyRegistry::PUBLIC_FAMILY_IDS, true)
            && in_array($request['locale'] ?? null, ['zh-CN', 'en'], true)
            && is_array($request['evidence_bundle_refs'] ?? null)
            && $request['evidence_bundle_refs'] !== []
            && is_array($request['dependency_snapshot_ref'] ?? null)
            && is_array($request['detector_registry_ref'] ?? null)
            && ($request['execution_allowed'] ?? null) === false
            && ($request['allow_delegation'] ?? null) === false
            && preg_match(self::HASH, (string) ($request['request_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($request, 'request_hash'), (string) $request['request_hash']);
    }

    /** @param array<string, mixed> $finding */
    public function finding(array $finding): bool
    {
        $valid = $this->exactSchemaKeys($finding, 'seo.technical_diagnosis_finding.v1')
            && ($finding['finding_version'] ?? null) === 1
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

        return $valid
            && (! in_array($finding['severity'], ['P0', 'P1'], true) || $finding['evidence_state'] === 'verified_fact')
            && ($finding['evidence_state'] === 'verified_fact' || $finding['verified_facts'] === []);
    }

    /** @param array<string, mixed> $output */
    public function output(array $output): bool
    {
        $valid = $this->exactSchemaKeys($output, 'seo.technical_diagnosis_output.v1')
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

    /** @param array<string, mixed> $receipt */
    public function receipt(array $receipt): bool
    {
        $valid = $this->exactSchemaKeys($receipt, 'seo.technical_diagnosis_receipt.v1')
            && ($receipt['receipt_version'] ?? null) === 'seo.technical_diagnosis_closeout.v1'
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($receipt['source_sha'] ?? '')) === 1
            && ($receipt['source_sha'] ?? null) === ($receipt['production_sha'] ?? null)
            && ($receipt['execution_allowed'] ?? null) === false
            && (($receipt['SEO-PLATFORM-11E'] ?? null) === 'CLOSED') === (($receipt['ready_for_11F'] ?? null) === true)
            && preg_match(self::HASH, (string) ($receipt['receipt_hash'] ?? '')) === 1
            && hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), (string) $receipt['receipt_hash']);
        foreach ([
            'private_url_leak_count', 'unsupported_p0_p1_count', 'authority_invention_count',
            'policy_bypass_count', 'write_attempt_count', 'shared_root_misclassification_count',
            'model_calls', 'tool_calls', 'external_calls', 'business_writes', 'cms_writes',
            'url_truth_writes', 'canonical_writes', 'robots_writes', 'feed_writes', 'search_writes',
            'active_manifest_count', 'trusted_key_count', 'l4_allow_count', 'production_permissions',
        ] as $field) {
            $valid = $valid && ($receipt[$field] ?? null) === 0;
        }

        return $valid;
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
        $actual = array_keys($value);
        $expected = $schema['required'];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
