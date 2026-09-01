<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Competitive;

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceContractRegistry;
use App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Governance\RoleCapabilityBindingRegistry;
use App\Services\SeoCouncil\Routing\GoldenRoutingEvaluator;

final class CompetitiveCloseoutBuilder
{
    private const COHORT = 'competitive.big-five.live.v2';

    public function __construct(
        private readonly CompetitiveEvidenceContractRegistry $contracts,
        private readonly CompetitiveSourcePolicyRegistry $sources,
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
        $snapshot = $this->sources->snapshot(self::COHORT);
        $offlineReady = $environment === 'ci_candidate'
            && preg_match('/^[a-f0-9]{40}$/D', $candidateSha) === 1
            && ($runtime['mode_state'] ?? null) === 'OFFLINE_EVAL_READY'
            && ($runtime['execution_allowed'] ?? null) === false
            && ($this->binding->reference()['version'] ?? null) === '3.0.0'
            && ($routing['corpus_version'] ?? null) === '2.0.0'
            && ($routing['missed_required_mode_rate']['numerator'] ?? null) === 0
            && ($routing['unnecessary_mode_rate']['numerator'] ?? null) === 0
            && array_sum(array_diff_key($activity, ['runner_calls' => true])) === 0;
        $measurement = $this->offlineMeasurement();
        $receipt = $this->baseReceipt($candidateSha, $environment, $productionSha, $manifest, $snapshot, $measurement);
        $receipt += [
            'closeout_state' => $offlineReady ? 'OFFLINE_EVAL_READY' : 'HOLD',
            'competitive_source_state' => 'hold',
            'competitive_freshness_state' => 'unknown',
            'competitive_bundle_verification' => 'missing',
            'competitive_context_status' => 'HOLD',
            'competitive_hold_reason' => $offlineReady ? 'OFFLINE_EVAL_ONLY' : 'OFFLINE_EVAL_HOLD',
            'dependency_ingestion' => ['external_reads' => 0],
            'environment_isolation' => $this->isolation(0),
            'model_calls' => (int) $activity['model_calls'],
            'tool_calls' => (int) $activity['tool_calls'],
            'external_calls' => (int) $activity['external_calls'],
            'cms_writes' => (int) $activity['cms_writes'],
            'url_truth_writes' => (int) $activity['url_truth_writes'],
            'search_writes' => (int) $activity['search_writes'],
            'business_writes' => (int) $activity['business_writes'],
            'production_permissions' => (int) $activity['production_permissions'],
            'execution_allowed' => false,
            'outreach_actions' => 0,
            'digital_pr_scope' => 'deferred_p2_manual',
            'SEO-PLATFORM-11G' => $offlineReady ? 'OFFLINE_EVAL_READY' : 'HOLD',
            'ready_for_11H' => false,
            '11i_handoff_ready' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $ingestion @return array<string, mixed> */
    public function buildRuntime(array $ingestion, string $candidateSha, string $environment, ?string $productionSha = null): array
    {
        $manifest = $this->contracts->manifest();
        $snapshot = (array) ($ingestion['policy_snapshot'] ?? []);
        $measurement = (array) ($ingestion['measurement'] ?? []);
        $reads = max(0, min(64, (int) data_get($ingestion, 'dependency_ingestion.external_reads', 0)));
        $output = (array) ($ingestion['competitive_output'] ?? []);
        $search = (array) ($measurement['search_measurement'] ?? []);
        $cro = (array) ($measurement['cro_measurement'] ?? []);
        $controlledSources = count((array) config('seo_agent_evidence.allowed_sources', []));
        $ready = ($ingestion['status'] ?? null) === 'READY'
            && ($ingestion['bundle_verification'] ?? null) === 'valid'
            && ($measurement['status'] ?? null) === 'READY'
            && $this->measurementReady($search)
            && $this->measurementReady($cro)
            && ($output['status'] ?? null) === 'READY'
            && data_get($output, '11i_handoff.source_freshness') === 'fresh'
            && (int) data_get($output, '11i_handoff.source_count', 0) >= 2
            && $this->snapshotReady($snapshot)
            && $controlledSources === 4;
        $stagingValidated = $ready && $environment === 'staging' && $productionSha === null;
        $closed = $ready && $environment === 'production' && hash_equals($candidateSha, (string) $productionSha);
        $state = $closed ? 'CLOSED' : ($stagingValidated ? 'STAGING_VALIDATED' : 'HOLD');
        $receipt = $this->baseReceipt($candidateSha, $environment, $productionSha, $manifest, $snapshot, $measurement);
        $receipt += [
            'closeout_state' => $state,
            'competitive_source_state' => $ready ? 'available' : 'hold',
            'competitive_freshness_state' => $ready ? 'fresh' : 'unknown',
            'competitive_bundle_verification' => $ready ? 'valid' : 'missing',
            'competitive_context_status' => $ready ? 'READY' : 'HOLD',
            'competitive_hold_reason' => $ready ? 'NONE' : $this->safeReason($ingestion['hold_reason'] ?? null),
            'dependency_ingestion' => ['external_reads' => $reads],
            'environment_isolation' => $this->isolation($controlledSources),
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'cms_writes' => 0,
            'url_truth_writes' => 0,
            'search_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'execution_allowed' => false,
            'outreach_actions' => 0,
            'digital_pr_scope' => 'deferred_p2_manual',
            'SEO-PLATFORM-11G' => $closed ? 'CLOSED' : 'HOLD',
            'ready_for_11H' => $closed,
            '11i_handoff_ready' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @param array<string, mixed> $receipt */
    public function verify(array $receipt, string $candidateSha): bool
    {
        $schema = $this->contracts->schema('seo.competitive_evidence_closeout.v3');
        $expected = (array) ($schema['required'] ?? []);
        $actual = array_keys($receipt);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        $zero = ['model_calls', 'tool_calls', 'external_calls', 'cms_writes', 'url_truth_writes', 'search_writes', 'business_writes', 'production_permissions', 'outreach_actions'];
        $closed = ($receipt['environment'] ?? null) === 'production'
            && ($receipt['closeout_state'] ?? null) === 'CLOSED'
            && ($receipt['production_sha'] ?? null) === $candidateSha
            && ($receipt['competitive_source_state'] ?? null) === 'available'
            && ($receipt['competitive_freshness_state'] ?? null) === 'fresh'
            && ($receipt['competitive_bundle_verification'] ?? null) === 'valid'
            && ($receipt['competitive_context_status'] ?? null) === 'READY'
            && ($receipt['competitive_hold_reason'] ?? null) === 'NONE'
            && data_get($receipt, 'environment_isolation.ordinary_allowed_sources') === 0
            && data_get($receipt, 'environment_isolation.controlled_allowed_sources') === 4
            && data_get($receipt, 'environment_isolation.cross_environment_bundle_reuse') === 0
            && data_get($receipt, 'environment_isolation.release_sha_mismatch') === 0
            && data_get($receipt, 'environment_isolation.duplicate_competitor_domains') === 0
            && $this->measurementReady((array) ($receipt['search_measurement'] ?? []))
            && $this->measurementReady((array) ($receipt['cro_measurement'] ?? []));

        return $actual === $expected
            && ($receipt['receipt_version'] ?? null) === 'seo.competitive_evidence_closeout.v3'
            && ($receipt['candidate_sha'] ?? null) === $candidateSha
            && ($receipt['contract_manifest_version'] ?? null) === '5.0.0'
            && ($receipt['execution_allowed'] ?? null) === false
            && array_sum(array_map('intval', array_intersect_key($receipt, array_flip($zero)))) === 0
            && (($receipt['SEO-PLATFORM-11G'] ?? null) === 'CLOSED') === $closed
            && (($receipt['ready_for_11H'] ?? null) === true) === $closed
            && (($receipt['11i_handoff_ready'] ?? null) === true) === $closed
            && is_string($receipt['receipt_hash'] ?? null)
            && hash_equals($this->hasher->hashWithout($receipt, 'receipt_hash'), (string) $receipt['receipt_hash']);
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $snapshot @param array<string, mixed> $measurement @return array<string, mixed> */
    private function baseReceipt(string $candidateSha, string $environment, ?string $productionSha, array $manifest, array $snapshot, array $measurement): array
    {
        return [
            'receipt_version' => 'seo.competitive_evidence_closeout.v3',
            'environment' => $environment,
            'candidate_sha' => $candidateSha,
            'production_sha' => $productionSha,
            'contract_manifest_version' => $manifest['manifest_version'],
            'contract_manifest_hash' => $manifest['manifest_hash'],
            'source_policy_version' => $snapshot['source_policy_version'],
            'source_policy_set_hash' => $snapshot['source_policy_set_hash'],
            'source_registry_version' => $snapshot['source_registry_version'],
            'source_registry_hash' => $snapshot['source_registry_hash'],
            'cohort_registry_version' => $snapshot['cohort_registry_version'],
            'cohort_registry_hash' => $snapshot['cohort_registry_hash'],
            'cohort_id' => $snapshot['cohort_id'],
            'cohort_hash' => $snapshot['cohort_hash'],
            'measurement_bundle_set_hash' => $measurement['measurement_bundle_set_hash'],
            'search_measurement' => $measurement['search_measurement'],
            'cro_measurement' => $measurement['cro_measurement'],
        ];
    }

    /** @return array<string, mixed> */
    private function offlineMeasurement(): array
    {
        $mode = ['source_state' => 'unavailable', 'freshness_state' => 'unknown', 'bundle_verification' => 'missing', 'context_status' => 'HOLD', 'hold_reason' => 'OFFLINE_EVAL_ONLY', 'bundle_hash' => hash('sha256', 'offline')];

        return ['measurement_bundle_set_hash' => $this->hasher->hash(['offline']), 'search_measurement' => $mode, 'cro_measurement' => $mode];
    }

    /** @return array<string, int> */
    private function isolation(int $controlledSources): array
    {
        return ['ordinary_allowed_sources' => 0, 'controlled_allowed_sources' => $controlledSources, 'cross_environment_bundle_reuse' => 0, 'release_sha_mismatch' => 0, 'duplicate_competitor_domains' => 0];
    }

    /** @param array<string, mixed> $mode */
    private function measurementReady(array $mode): bool
    {
        return ($mode['source_state'] ?? null) === 'available'
            && ($mode['freshness_state'] ?? null) === 'fresh'
            && ($mode['bundle_verification'] ?? null) === 'valid'
            && ($mode['context_status'] ?? null) === 'READY'
            && ($mode['hold_reason'] ?? null) === 'NONE';
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotReady(array $snapshot): bool
    {
        return ($snapshot['source_policy_version'] ?? null) === CompetitiveSourcePolicyRegistry::POLICY_VERSION
            && ($snapshot['source_registry_version'] ?? null) === 'seo.competitive_source_registry.v2'
            && ($snapshot['cohort_registry_version'] ?? null) === 'seo.competitive_cohort_registry.v2';
    }

    private function safeReason(mixed $reason): string
    {
        $reason = (string) $reason;

        return preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) === 1 ? $reason : 'SOURCE_POLICY_HOLD';
    }
}
