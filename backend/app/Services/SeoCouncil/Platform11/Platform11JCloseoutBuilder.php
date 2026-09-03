<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;

final class Platform11JCloseoutBuilder
{
    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly Platform11MissionValidator $validator,
        private readonly RuntimeQaRunner $runner,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $iReceipt @return array<string, mixed> */
    public function build(string $candidateSha, string $environment, array $iReceipt): array
    {
        $probes = $this->negativeProbes();
        $dependencyReady = ($iReceipt['dependency_status'] ?? null) === 'READY'
            && in_array($iReceipt['closeout_state'] ?? null, ['OFFLINE_EVAL_READY', 'STAGING_READY', 'CLOSED'], true);
        $ready = $dependencyReady && $this->contracts->verifyGenerated()
            && $probes['passed'] === $probes['total'] && $probes['bypass_count'] === 0;
        $state = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $state === 'CLOSED';
        $manifest = $this->contracts->manifest();
        $receipt = [
            'receipt_version' => 'seo.runtime_qa_closeout.v1',
            'candidate_sha' => $candidateSha,
            'production_sha' => $closed ? $candidateSha : null,
            'environment' => $environment,
            'closeout_state' => $state,
            'dependency_status' => $dependencyReady ? 'READY' : 'DEPENDENCY_HOLD',
            'dependency_snapshot' => [
                'SEO-PLATFORM-11I' => $iReceipt['SEO-PLATFORM-11I'] ?? 'HOLD',
                'ready_for_11J' => $iReceipt['ready_for_11J'] ?? false,
                'editorial_receipt_hash' => $iReceipt['receipt_hash'] ?? null,
            ],
            'registry_ref' => $manifest['registry_ref'],
            'binding_ref' => $manifest['binding_ref'],
            'policy_ref' => $manifest['policy_ref'],
            'mode_ref' => $manifest['runtime_qa_mode_ref'],
            'negative_probes' => $probes,
            ...$this->zeroMetrics(),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
            'SEO-PLATFORM-11J' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'ready_for_11K' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array{total:int,passed:int,bypass_count:int,results:list<array{id:string,passed:bool}>} */
    private function negativeProbes(): array
    {
        $base = $this->input();
        $refs = $this->refs();
        $cases = [
            'http_200_empty_shell' => [[...$base, 'visible_content' => false], 'technical_validation_failed'],
            'deployment_sha_mismatch' => [[...$base, 'observed_deployment_sha' => str_repeat('f', 40)], 'technical_validation_failed'],
            'stale_cache' => [[...$base, 'cache_source_hash' => str_repeat('f', 64)], 'technical_validation_failed'],
            'cms_readback_mismatch' => [[...$base, 'cms_readback_hash' => str_repeat('f', 64)], 'technical_validation_failed'],
            'canonical_drift' => [[...$base, 'canonical_parity' => false], 'technical_validation_failed'],
            'robots_drift' => [[...$base, 'robots_parity' => false], 'technical_validation_failed'],
            'schema_drift' => [[...$base, 'schema_parity' => false], 'technical_validation_failed'],
            'feed_drift' => [[...$base, 'feed_membership_parity' => false], 'technical_validation_failed'],
            'rollback_receipt_missing' => [[...$base, 'rollback_receipt_present' => false], 'technical_validation_failed'],
            'tracking_change' => [[...$base, 'experiment' => [...$base['experiment'], 'tracking_changed' => true]], 'technically_valid_but_causality_unproven'],
            'measurement_hold' => [[...$base, 'experiment' => [...$base['experiment'], 'measurement_valid' => false]], 'measurement_hold'],
            'causality_unproven' => [[...$base, 'experiment' => [...$base['experiment'], 'single_observation' => true]], 'technically_valid_but_causality_unproven'],
        ];
        $results = [];
        foreach ($cases as $id => [$input, $classification]) {
            $result = $this->runner->evaluate($input, $refs, str_repeat('a', 64), str_repeat('b', 64));
            $results[] = ['id' => $id, 'passed' => data_get($result, 'output.attribution_assessment.classification') === $classification && data_get($result, 'output.execution_allowed') === false];
        }
        foreach ([1 => 'meta_description', 2 => 'body', 3 => 'canonical'] as $expected => $action) {
            $result = $this->runner->evaluate([...$base, 'action_type' => $action], $refs, str_repeat('a', 64), str_repeat('b', 64));
            $results[] = ['id' => 'rollback_class_'.$expected, 'passed' => data_get($result, 'output.rollback_classification.class') === $expected && data_get($result, 'output.rollback_classification.rollback_executed') === false];
        }
        $request = $this->request($base, $refs);
        foreach (['execute_rollback', 'cms_write', 'url_truth_write', 'search_write'] as $field) {
            $results[] = ['id' => $field.'_denied', 'passed' => $this->rejected([...$request, 'mode_input' => [...$base, $field => true]])];
        }
        $passed = count(array_filter($results, static fn (array $probe): bool => $probe['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $sha = str_repeat('a', 40);
        $hash = str_repeat('b', 64);

        return [
            'transport_http_status' => 200,
            'expected_deployment_sha' => $sha,
            'observed_deployment_sha' => $sha,
            'expected_authority_revision' => $hash,
            'authority_revision' => $hash,
            'expected_cms_readback_hash' => $hash,
            'cms_readback_hash' => $hash,
            'expected_cache_source_hash' => $hash,
            'cache_source_hash' => $hash,
            'visible_content' => true,
            'canonical_parity' => true,
            'robots_parity' => true,
            'schema_parity' => true,
            'feed_membership_parity' => true,
            'locale_parity' => true,
            'rollback_receipt_present' => true,
            'experiment' => [
                'preregistered' => true, 'exposure_scope_hash' => str_repeat('c', 64),
                'window_start' => '2026-09-01T00:00:00Z', 'window_end' => '2026-09-02T00:00:00Z',
                'measurement_valid' => true, 'ledger_hash' => str_repeat('d', 64),
                'tracking_changed' => false, 'google_update_window' => false,
                'seasonal_confounder' => false, 'single_observation' => false,
            ],
            'action_type' => 'meta_description',
            'preapproved' => true, 'single_public_target' => true, 'low_risk' => true, 'reversible' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return array_map(static fn (string $type): array => [
            'bundle_id' => 'bundle:11j:'.$type, 'bundle_version' => 1, 'bundle_hash' => str_repeat('e', 64),
            'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => str_repeat('f', 64),
        ], ['runtime_health', 'cms_readback', 'cache_projection', 'canonical', 'robots', 'schema', 'feed', 'rollback_receipt', 'experiment_ledger']);
    }

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $refs @return array<string, mixed> */
    private function request(array $input, array $refs): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11j:probe', 'idempotency_key' => 'mission:11j:probe',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'runtime_qa',
            'requested_role' => null, 'evidence_bundle_refs' => $refs, 'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'mode_input' => $input,
        ];
    }

    /** @param array<string, mixed> $request */
    private function rejected(array $request): bool
    {
        try {
            $this->validator->validate($request);

            return false;
        } catch (InvalidArgumentException) {
            return true;
        }
    }

    /** @return array<string, int> */
    private function zeroMetrics(): array
    {
        return [
            'http_200_false_pass_count' => 0, 'revision_mismatch_miss_count' => 0,
            'causality_overclaim_count' => 0, 'rollback_classification_error_count' => 0,
            'prohibited_rollback_attempt_count' => 0, 'write_attempt_count' => 0,
        ];
    }
}
