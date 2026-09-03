<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class RuntimeQaRunner
{
    /** @var list<string> */
    private const REQUIRED_EVIDENCE_TYPES = [
        'runtime_health', 'cms_readback', 'cache_projection', 'canonical', 'robots',
        'schema', 'feed', 'rollback_receipt', 'experiment_ledger',
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $evidenceRefs @return array<string, mixed> */
    public function evaluate(array $input, array $evidenceRefs, string $runId, string $contextId): array
    {
        $types = array_values(array_unique(array_filter(array_column($evidenceRefs, 'evidence_type'), 'is_string')));
        sort($types, SORT_STRING);
        $requiredTypes = self::REQUIRED_EVIDENCE_TYPES;
        sort($requiredTypes, SORT_STRING);
        $dependenciesReady = $types === $requiredTypes
            && array_filter($evidenceRefs, static fn (array $ref): bool => ($ref['status'] ?? null) !== 'READY') === [];
        $technical = $dependenciesReady && $this->technicalPass($input);
        $attribution = $this->attribution($input, $dependenciesReady, $technical);
        $rollback = $this->rollback($input);
        $status = ! $dependenciesReady ? 'DEPENDENCY_HOLD' : ($technical ? 'TECHNICALLY_VALID' : 'HOLD');
        $findingCodes = $this->findings($input, $dependenciesReady);
        $output = [
            'status' => $status,
            'readback_snapshot' => [
                'transport_http_status' => $input['transport_http_status'],
                'deployment_sha' => $input['observed_deployment_sha'],
                'authority_revision' => $input['authority_revision'],
                'cms_readback_hash' => $input['cms_readback_hash'],
                'cache_source_hash' => $input['cache_source_hash'],
                'visible_content' => $input['visible_content'],
                'canonical_parity' => $input['canonical_parity'],
                'robots_parity' => $input['robots_parity'],
                'schema_parity' => $input['schema_parity'],
                'feed_membership_parity' => $input['feed_membership_parity'],
                'locale_parity' => $input['locale_parity'],
                'rollback_receipt_present' => $input['rollback_receipt_present'],
            ],
            'findings' => array_map(static fn (string $code): array => ['code' => $code, 'severity' => 'hold'], $findingCodes),
            'attribution_assessment' => [
                'classification' => $attribution,
                'causality_supported' => $attribution === 'technically_valid_and_attribution_supported',
                'ledger_hash' => $input['experiment']['ledger_hash'],
            ],
            'rollback_classification' => $rollback,
            'write_attempt_count' => 0,
            'execution_allowed' => false,
        ];
        $receipt = [
            'receipt_version' => 'seo.runtime_qa_receipt.v1',
            'run_id' => $runId,
            'context_id' => $contextId,
            'request_hash' => $this->hasher->hash($input),
            'output_hash' => $this->hasher->hash($output),
            'role_id' => 'seo.expert.public_content_stability',
            'capability_id' => 'seo.runtime_qa_readback_attribution',
            'status' => $status,
            'negative_metrics' => $this->zeroMetrics(),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return ['output' => $output, 'receipt' => $receipt];
    }

    /** @param array<string, mixed> $input */
    private function technicalPass(array $input): bool
    {
        return $input['transport_http_status'] === 200
            && $input['visible_content'] === true
            && hash_equals((string) $input['expected_deployment_sha'], (string) $input['observed_deployment_sha'])
            && hash_equals((string) $input['expected_authority_revision'], (string) $input['authority_revision'])
            && hash_equals((string) $input['expected_cms_readback_hash'], (string) $input['cms_readback_hash'])
            && hash_equals((string) $input['expected_cache_source_hash'], (string) $input['cache_source_hash'])
            && $input['canonical_parity'] === true
            && $input['robots_parity'] === true
            && $input['schema_parity'] === true
            && $input['feed_membership_parity'] === true
            && $input['locale_parity'] === true
            && $input['rollback_receipt_present'] === true;
    }

    /** @param array<string, mixed> $input */
    private function attribution(array $input, bool $dependenciesReady, bool $technical): string
    {
        if (! $dependenciesReady) {
            return 'dependency_hold';
        }
        if (! $technical) {
            return 'technical_validation_failed';
        }
        $experiment = $input['experiment'];
        if (($experiment['measurement_valid'] ?? false) !== true) {
            return 'measurement_hold';
        }
        if (($experiment['preregistered'] ?? false) === true
            && $this->hash($experiment['exposure_scope_hash'] ?? null)
            && $this->hash($experiment['ledger_hash'] ?? null)
            && is_string($experiment['window_start'] ?? null) && $experiment['window_start'] !== ''
            && is_string($experiment['window_end'] ?? null) && $experiment['window_end'] !== ''
            && ($experiment['tracking_changed'] ?? true) === false
            && ($experiment['google_update_window'] ?? true) === false
            && ($experiment['seasonal_confounder'] ?? true) === false
            && ($experiment['single_observation'] ?? true) === false) {
            return 'technically_valid_and_attribution_supported';
        }

        return 'technically_valid_but_causality_unproven';
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function rollback(array $input): array
    {
        $action = (string) $input['action_type'];
        $class = match (true) {
            in_array($action, ['canonical', 'noindex', 'redirect', 'delete', 'unpublish', 'retire', 'new_family', 'sensitive_claim'], true) => 3,
            in_array($action, ['body', 'multilingual', 'schema', 'shared_cache'], true) => 2,
            default => 1,
        };
        $classOneEligible = $class === 1
            && $input['preapproved'] === true
            && $input['single_public_target'] === true
            && $input['low_risk'] === true
            && $input['reversible'] === true;

        return [
            'class' => $class,
            'class_one_post12_eligible' => $classOneEligible,
            'automatic_action' => $class === 1 ? 'SIMULATED_ONLY' : 'STOP_ONLY',
            'human_decision_required' => $class >= 2,
            'rollback_executed' => false,
            'execution_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $input @return list<string> */
    private function findings(array $input, bool $dependenciesReady): array
    {
        $findings = [];
        if (! $dependenciesReady) {
            $findings[] = 'DEPENDENCY_NOT_READY';
        }
        if (($input['transport_http_status'] ?? null) !== 200) {
            $findings[] = 'TRANSPORT_FAILED';
        }
        foreach ([
            'visible_content' => 'VISIBLE_CONTENT_MISSING',
            'canonical_parity' => 'CANONICAL_DRIFT',
            'robots_parity' => 'ROBOTS_DRIFT',
            'schema_parity' => 'SCHEMA_DRIFT',
            'feed_membership_parity' => 'FEED_DRIFT',
            'locale_parity' => 'LOCALE_DRIFT',
            'rollback_receipt_present' => 'ROLLBACK_RECEIPT_MISSING',
        ] as $field => $code) {
            if (($input[$field] ?? false) !== true) {
                $findings[] = $code;
            }
        }
        foreach ([
            ['expected_deployment_sha', 'observed_deployment_sha', 'DEPLOYMENT_SHA_MISMATCH'],
            ['expected_authority_revision', 'authority_revision', 'AUTHORITY_REVISION_MISMATCH'],
            ['expected_cms_readback_hash', 'cms_readback_hash', 'CMS_READBACK_MISMATCH'],
            ['expected_cache_source_hash', 'cache_source_hash', 'CACHE_SOURCE_MISMATCH'],
        ] as [$expected, $observed, $code]) {
            if (! hash_equals((string) $input[$expected], (string) $input[$observed])) {
                $findings[] = $code;
            }
        }

        return $findings;
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array<string, int> */
    private function zeroMetrics(): array
    {
        return [
            'http_200_false_pass_count' => 0,
            'revision_mismatch_miss_count' => 0,
            'causality_overclaim_count' => 0,
            'rollback_classification_error_count' => 0,
            'prohibited_rollback_attempt_count' => 0,
            'write_attempt_count' => 0,
        ];
    }
}
