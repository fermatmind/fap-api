<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;

final class Platform11KCloseoutBuilder
{
    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly Platform11MissionValidator $validator,
        private readonly IndependentReviewRunner $runner,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $jReceipt @return array<string, mixed> */
    public function build(string $candidateSha, string $environment, array $jReceipt): array
    {
        $probes = $this->negativeProbes();
        $dependencyReady = ($jReceipt['dependency_status'] ?? null) === 'READY'
            && in_array($jReceipt['closeout_state'] ?? null, ['OFFLINE_EVAL_READY', 'STAGING_READY', 'CLOSED'], true);
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
            'receipt_version' => 'seo.independent_review_closeout.v1',
            'candidate_sha' => $candidateSha,
            'production_sha' => $closed ? $candidateSha : null,
            'environment' => $environment,
            'closeout_state' => $state,
            'dependency_status' => $dependencyReady ? 'READY' : 'DEPENDENCY_HOLD',
            'dependency_snapshot' => [
                'SEO-PLATFORM-11J' => $jReceipt['SEO-PLATFORM-11J'] ?? 'HOLD',
                'ready_for_11K' => $jReceipt['ready_for_11K'] ?? false,
                'runtime_qa_receipt_hash' => $jReceipt['receipt_hash'] ?? null,
            ],
            'registry_ref' => $manifest['registry_ref'],
            'binding_ref' => $manifest['binding_ref'],
            'policy_ref' => $manifest['policy_ref'],
            'mode_ref' => $manifest['independent_review_mode_ref'],
            'negative_probes' => $probes,
            ...$this->zeroMetrics(),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'deploy_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
            'SEO-PLATFORM-11K' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'ready_for_11L' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array{total:int,passed:int,bypass_count:int,results:list<array{id:string,passed:bool}>} */
    private function negativeProbes(): array
    {
        $input = $this->input();
        $refs = $this->refs();
        $run = str_repeat('a', 64);
        $context = str_repeat('b', 64);
        $results = [];
        $review = fn (array $value, ?array $evidence = null, ?string $reviewRun = null, ?string $reviewContext = null): array => $this->runner->evaluate(
            $value,
            $evidence ?? $refs,
            $reviewRun ?? $run,
            $reviewContext ?? $context,
        );
        $results[] = ['id' => 'run_id_reuse_denied', 'passed' => data_get($review([...$input, 'generation_run_id' => $run]), 'output.verdict') === 'reject'];
        $results[] = ['id' => 'context_id_reuse_denied', 'passed' => data_get($review([...$input, 'generation_context_id' => $context]), 'output.verdict') === 'reject'];
        foreach (['generation_prompt', 'hidden_reasoning', 'full_trace'] as $field) {
            $results[] = ['id' => $field.'_denied', 'passed' => $this->rejected([...$this->request($input, $refs), 'mode_input' => [...$input, $field => 'forbidden']])];
        }
        $mutable = $input;
        $mutable['frozen_manifest']['frozen'] = false;
        $results[] = ['id' => 'mutable_manifest_denied', 'passed' => data_get($review($mutable), 'output.verdict') === 'reject'];
        $drift = $input;
        $drift['frozen_manifest']['manifest_hash'] = str_repeat('f', 64);
        $results[] = ['id' => 'manifest_hash_drift_denied', 'passed' => data_get($review($drift), 'output.verdict') === 'reject'];
        $artifact = [...$input, 'candidate_artifact_hash' => str_repeat('f', 64)];
        $results[] = ['id' => 'artifact_hash_drift_denied', 'passed' => data_get($review($artifact), 'output.verdict') === 'reject'];
        $invalidRefs = $refs;
        $invalidRefs[0]['status'] = 'EVIDENCE_HOLD';
        $results[] = ['id' => 'invalid_evidence_hold', 'passed' => data_get($review($input, $invalidRefs), 'output.verdict') === 'hold'];
        foreach (['fourth_verdict', 'claimed_recommend_approve', 'cms_access', 'deploy_access', 'url_truth_access', 'search_access'] as $field) {
            $results[] = ['id' => $field.'_denied', 'passed' => $this->rejected([...$this->request($input, $refs), 'mode_input' => [...$input, $field => true]])];
        }
        $request = $this->request($input, $refs);
        $results[] = ['id' => 'tool_scope_denied', 'passed' => $this->rejected([...$request, 'tool_scope' => ['cms']])];
        $valid = $review($input);
        $results[] = ['id' => 'recommendation_policy_bypass_denied', 'passed' => data_get($valid, 'output.verdict') === 'recommend_approve'
            && data_get($valid, 'output.policy_gateway_decision') === 'HOLD'
            && data_get($valid, 'output.execution_allowed') === false];
        $passed = count(array_filter($results, static fn (array $probe): bool => $probe['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $manifest = [
            'manifest_id' => 'artifact:11k:probe', 'manifest_version' => 1, 'frozen' => true,
            'candidate_artifact_hash' => str_repeat('c', 64),
            'policy_review' => 'PASS', 'experiment_review' => 'PASS', 'safety_review' => 'PASS',
        ];
        $manifest['manifest_hash'] = $this->hasher->hash($manifest);

        return [
            'generation_run_id' => str_repeat('d', 64),
            'generation_context_id' => str_repeat('e', 64),
            'frozen_manifest' => $manifest,
            'candidate_artifact_hash' => str_repeat('c', 64),
            'policy_ref' => ['id' => 'policy:v2', 'version' => '2.0.0', 'hash' => str_repeat('1', 64)],
            'registry_ref' => ['id' => 'registry:v2', 'version' => '2.0.0', 'hash' => str_repeat('2', 64)],
            'binding_ref' => ['id' => 'binding:v4', 'version' => '4.0.0', 'hash' => str_repeat('3', 64)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return [[
            'bundle_id' => 'bundle:11k:frozen', 'bundle_version' => 1, 'bundle_hash' => str_repeat('4', 64),
            'evidence_type' => 'frozen_artifact', 'status' => 'READY', 'authority_revision' => str_repeat('5', 64),
        ]];
    }

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $refs @return array<string, mixed> */
    private function request(array $input, array $refs): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11k:probe', 'idempotency_key' => 'mission:11k:probe',
            'mission_type' => 'independent_registry_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => null,
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
            'run_id_reuse_count' => 0, 'context_reuse_count' => 0,
            'generation_context_inheritance_count' => 0, 'hidden_reasoning_ingestion_count' => 0,
            'mutable_manifest_acceptance_count' => 0, 'forbidden_tool_exposure_count' => 0,
            'verdict_enum_violation_count' => 0, 'policy_approve_bypass_count' => 0,
        ];
    }
}
