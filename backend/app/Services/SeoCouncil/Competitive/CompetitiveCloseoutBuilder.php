<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceContractRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;

final class CompetitiveCloseoutBuilder
{
    public function __construct(
        private readonly CompetitiveEvidenceContractRegistry $contracts,
        private readonly CompetitiveModeRegistry $modes,
        private readonly CompetitiveActivityLedger $activity,
        private readonly RoleCapabilityBindingRegistry $binding,
        private readonly GoldenRoutingEvaluator $routing,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $candidateSha, string $environment = 'ci_candidate', ?string $productionSha = null): array
    {
        $manifest = $this->contracts->manifest();
        $runtime = $this->modes->capabilitySnapshot();
        $activity = $this->activity->snapshot();
        $routing = $this->routing->evaluate();
        $offlineReady = $environment === 'ci_candidate'
            && preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['execution_allowed'] ?? null) === false
            && ($this->binding->reference()['version'] ?? null) === '3.0.0'
            && ($routing['corpus_version'] ?? null) === '2.0.0'
            && ($routing['missed_required_mode_rate']['numerator'] ?? null) === 0
            && ($routing['unnecessary_mode_rate']['numerator'] ?? null) === 0
            && array_sum(array_diff_key($activity, ['runner_calls' => true])) === 0;
        $receipt = [
            'receipt_version' => 'seo.competitive_evidence_closeout.v2',
            'environment' => $environment,
            'candidate_sha' => $candidateSha,
            'production_sha' => $productionSha,
            'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'dependency_ingestion' => ['external_reads' => 0],
            'model_calls' => $activity['model_calls'],
            'tool_calls' => $activity['tool_calls'],
            'external_calls' => $activity['external_calls'],
            'cms_writes' => $activity['cms_writes'],
            'url_truth_writes' => $activity['url_truth_writes'],
            'search_writes' => $activity['search_writes'],
            'business_writes' => $activity['business_writes'],
            'production_permissions' => $activity['production_permissions'],
            'SEO-PLATFORM-11G' => $offlineReady ? 'OFFLINE_EVAL_READY' : 'HOLD',
            'ready_for_11H' => false,
            '11i_handoff_ready' => false,
            'competitive_source_state' => 'hold',
            'competitive_freshness_state' => 'unknown',
            'competitive_bundle_verification' => 'missing',
            'competitive_context_status' => 'HOLD',
            'competitive_hold_reason' => $offlineReady ? 'OFFLINE_EVAL_ONLY' : 'OFFLINE_EVAL_HOLD',
            'execution_allowed' => false,
            'digital_pr_scope' => 'deferred_p2_manual',
            'outreach_actions' => 0,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $ingestion @return array<string, mixed> */
    public function buildRuntime(array $ingestion, string $candidateSha, string $environment, ?string $productionSha = null): array
    {
        $manifest = $this->contracts->manifest();
        $reads = max(0, min(64, (int) data_get($ingestion, 'dependency_ingestion.external_reads', 0)));
        $output = (array) ($ingestion['competitive_output'] ?? []);
        $ready = ($ingestion['status'] ?? null) === 'READY'
            && ($ingestion['bundle_verification'] ?? null) === 'valid'
            && ($output['status'] ?? null) === 'READY'
            && data_get($output, '11i_handoff.source_freshness') === 'fresh'
            && (int) data_get($output, '11i_handoff.source_count', 0) >= 2;
        $closed = $ready && $environment === 'production' && $productionSha === $candidateSha;
        $receipt = [
            'receipt_version' => 'seo.competitive_evidence_closeout.v2',
            'environment' => $environment,
            'candidate_sha' => $candidateSha,
            'production_sha' => $productionSha,
            'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'dependency_ingestion' => ['external_reads' => $reads],
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'SEO-PLATFORM-11G' => $closed ? 'CLOSED' : 'HOLD',
            'ready_for_11H' => $closed,
            '11i_handoff_ready' => $closed,
            'competitive_source_state' => $ready ? 'available' : 'hold',
            'competitive_freshness_state' => $ready ? 'fresh' : 'unknown',
            'competitive_bundle_verification' => $ready ? 'valid' : 'missing',
            'competitive_context_status' => $ready ? 'READY' : 'HOLD',
            'competitive_hold_reason' => $ready ? ($closed ? 'NONE' : 'STAGING_VALIDATED') : $this->safeReason($ingestion['hold_reason'] ?? null),
            'execution_allowed' => false,
            'digital_pr_scope' => 'deferred_p2_manual',
            'outreach_actions' => 0,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $candidateSha): bool
    {
        $schema = $this->contracts->schema('seo.competitive_evidence_closeout.v2');
        $expected = (array) ($schema['required'] ?? []);
        $actual = array_keys($receipt);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);

        return $actual === $expected
            && ($receipt['candidate_sha'] ?? null) === $candidateSha
            && in_array($receipt['environment'] ?? null, ['ci_candidate', 'staging', 'production'], true)
            && ($receipt['contract_manifest_version'] ?? null) === '4.0.0'
            && in_array($receipt['SEO-PLATFORM-11G'] ?? null, ['OFFLINE_EVAL_READY', 'HOLD', 'CLOSED'], true)
            && ($receipt['execution_allowed'] ?? null) === false
            && array_sum(array_map('intval', array_intersect_key($receipt, array_flip([
                'model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes',
                'search_writes', 'business_writes', 'production_permissions', 'outreach_actions',
            ])))) === 0
            && is_string($receipt['receipt_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), (string) $receipt['receipt_hash']);
    }

    private function safeReason(mixed $reason): string
    {
        $reason = (string) $reason;

        return preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) === 1 ? $reason : 'SOURCE_POLICY_HOLD';
    }
}
