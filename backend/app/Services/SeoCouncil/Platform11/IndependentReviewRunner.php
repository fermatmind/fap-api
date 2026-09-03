<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class IndependentReviewRunner
{
    private const VERDICTS = ['recommend_approve', 'hold', 'reject'];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $evidenceRefs @return array<string, mixed> */
    public function evaluate(array $input, array $evidenceRefs, string $reviewRunId, string $reviewContextId): array
    {
        $reasons = [];
        $manifest = (array) ($input['frozen_manifest'] ?? []);
        $manifestHash = (string) ($manifest['manifest_hash'] ?? '');
        $manifestBody = $manifest;
        unset($manifestBody['manifest_hash']);

        if (hash_equals((string) ($input['generation_run_id'] ?? ''), $reviewRunId)) {
            $reasons[] = 'RUN_ID_REUSE_DENIED';
        }
        if (hash_equals((string) ($input['generation_context_id'] ?? ''), $reviewContextId)) {
            $reasons[] = 'CONTEXT_ID_REUSE_DENIED';
        }
        if (($manifest['frozen'] ?? null) !== true) {
            $reasons[] = 'MUTABLE_MANIFEST_DENIED';
        }
        if (! $this->hash($manifestHash) || ! hash_equals($this->hasher->hash($manifestBody), $manifestHash)) {
            $reasons[] = 'MANIFEST_HASH_DRIFT';
        }
        if (! hash_equals((string) ($manifest['candidate_artifact_hash'] ?? ''), (string) ($input['candidate_artifact_hash'] ?? ''))) {
            $reasons[] = 'ARTIFACT_HASH_DRIFT';
        }

        $evidenceReady = $evidenceRefs !== []
            && in_array('frozen_artifact', array_column($evidenceRefs, 'evidence_type'), true)
            && array_filter($evidenceRefs, static fn (array $ref): bool => ($ref['status'] ?? null) !== 'READY') === [];
        if (! $evidenceReady) {
            $reasons[] = 'EVIDENCE_BUNDLE_HOLD';
        }

        $states = array_map(static fn (string $field): string => (string) ($manifest[$field] ?? 'REJECT'), [
            'policy_review', 'experiment_review', 'safety_review',
        ]);
        $integrityFailure = array_filter($reasons, static fn (string $reason): bool => $reason !== 'EVIDENCE_BUNDLE_HOLD') !== [];
        $verdict = match (true) {
            $integrityFailure, in_array('REJECT', $states, true) => 'reject',
            ! $evidenceReady, in_array('HOLD', $states, true) => 'hold',
            default => 'recommend_approve',
        };
        if (! in_array($verdict, self::VERDICTS, true)) {
            $verdict = 'reject';
            $reasons[] = 'VERDICT_ENUM_DENIED';
        }
        if ($verdict === 'recommend_approve') {
            $reasons[] = 'POLICY_FINAL_VETO_WRITE_DISABLED';
        }

        $output = [
            'verdict' => $verdict,
            'reason_codes' => array_values(array_unique($reasons)),
            'evidence_refs' => array_map(static fn (array $ref): array => [
                'bundle_id' => $ref['bundle_id'],
                'bundle_version' => $ref['bundle_version'],
                'bundle_hash' => $ref['bundle_hash'],
                'authority_revision' => $ref['authority_revision'],
            ], $evidenceRefs),
            'manifest_hash' => $manifestHash,
            'candidate_artifact_hash' => $input['candidate_artifact_hash'] ?? null,
            'policy_gateway_decision' => 'HOLD',
            'tool_allowlist' => [],
            'egress_allowlist' => [],
            'cms_access' => false,
            'deploy_access' => false,
            'url_truth_access' => false,
            'search_access' => false,
            'allow_delegation' => false,
            'execution_allowed' => false,
        ];
        $receipt = [
            'receipt_version' => 'seo.independent_review_receipt.v1',
            'run_id' => $reviewRunId,
            'context_id' => $reviewContextId,
            'prompt_namespace' => 'seo.independent_review.v1',
            'request_hash' => $this->hasher->hash($input),
            'output_hash' => $this->hasher->hash($output),
            'role_id' => 'seo.independent_reviewer',
            'capability_id' => 'seo.independent_policy_experiment_safety_review',
            'verdict' => $verdict,
            'policy_gateway_decision' => 'HOLD',
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return ['output' => $output, 'receipt' => $receipt];
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
