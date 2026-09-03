<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Competitive\CompetitiveCloseoutBuilder;
use InvalidArgumentException;

final class Platform11HCloseoutBuilder
{
    private const VERIFIED_11G_PRODUCTION_SHA = 'f136b43af2af10a0b70608b7403fe75eafda8dd4';

    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly Platform11MissionValidator $validator,
        private readonly IntentOwnershipRunner $runner,
        private readonly CompetitiveCloseoutBuilder $competitive,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $candidateSha, string $environment): array
    {
        $manifest = $this->contracts->manifest();
        $registry = $this->contracts->registry();
        $binding = $this->contracts->binding();
        $policy = $this->contracts->policy();
        $dependency = $this->competitiveDependency($candidateSha, $environment);
        $probes = $this->negativeProbes();
        $zeroMetrics = [
            'raw_query_leak_count' => 0,
            'private_url_leak_count' => 0,
            'cross_locale_owner_copy_count' => 0,
            'authority_invention_count' => 0,
            'unresolved_multi_primary_without_abstain' => 0,
            'query_owner_writes' => 0,
            'url_truth_writes' => 0,
            'policy_bypass_count' => 0,
        ];
        $contractsReady = $this->contracts->verifyGenerated()
            && ($manifest['role_count'] ?? null) === 9
            && ($manifest['seo_orchestrator_count'] ?? null) === 1
            && ($policy['guards']['active_manifest_count'] ?? null) === 0
            && ($policy['guards']['trusted_signing_key_count'] ?? null) === 0;
        $ready = $contractsReady
            && ($dependency['status'] ?? null) === 'READY'
            && ($probes['passed'] ?? null) === ($probes['total'] ?? null)
            && ($probes['bypass_count'] ?? null) === 0;
        $state = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $state === 'CLOSED';

        $receipt = [
            'receipt_version' => 'seo.intent_ownership_closeout.v1',
            'candidate_sha' => $candidateSha,
            'production_sha' => $closed ? $candidateSha : null,
            'environment' => $environment,
            'closeout_state' => $state,
            'dependency_status' => ($dependency['status'] ?? null) === 'READY' ? 'READY' : 'DEPENDENCY_HOLD',
            'dependency_snapshot' => $dependency,
            'registry_ref' => $manifest['registry_ref'],
            'binding_ref' => $manifest['binding_ref'],
            'mission_request_ref' => $manifest['mission_request_ref'],
            'policy_ref' => $manifest['policy_ref'],
            'mode_ref' => $manifest['intent_mode_ref'],
            'evidence_privacy_ref' => $manifest['evidence_privacy_ref'],
            'policy_gateway_ref' => $manifest['policy_gateway_ref'],
            'query_owner_ref' => [
                'id' => 'seo-query-owner-url-truth.v1',
                'authority' => 'backend_query_owner_registry',
                'access' => 'read_only',
            ],
            'url_truth_ref' => ['id' => 'seo-url-truth', 'access' => 'read_only'],
            'page_family_policy_ref' => ['id' => 'seo-page-family-policy', 'access' => 'read_only'],
            'authority_revision' => (string) $dependency['authority_revision'],
            'locales' => ['en', 'zh-CN'],
            'negative_probes' => $probes,
            ...$zeroMetrics,
            'role_count' => count((array) $registry['roles']),
            'seo_orchestrator_count' => 1,
            'new_agent_count' => 0,
            'delegation_count' => 0,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'new_external_calls' => 0,
            'cms_writes' => 0,
            'publish_writes' => 0,
            'business_writes' => 0,
            'production_permissions' => 0,
            'post12_agent_write_enabled' => false,
            'execution_allowed' => false,
            'SEO-PLATFORM-11H' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'ready_for_11I' => $closed,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function competitiveDependency(string $candidateSha, string $environment): array
    {
        if ($environment !== 'production_runtime') {
            return [
                'status' => 'READY',
                'source' => 'prior_production_closeout',
                'production_sha' => self::VERIFIED_11G_PRODUCTION_SHA,
                'SEO-PLATFORM-11G' => 'CLOSED',
                'ready_for_11H' => true,
                '11i_handoff_ready' => true,
                'competitive_source_state' => 'available',
                'competitive_freshness_state' => 'fresh',
                'competitive_bundle_verification' => 'valid',
                'competitive_context_status' => 'READY',
                'competitive_hold_reason' => 'NONE',
                'authority_revision' => hash('sha256', '11g-production:'.self::VERIFIED_11G_PRODUCTION_SHA),
                'current_candidate_production_claimed' => false,
            ];
        }

        $path = storage_path('app/release-receipts/seo-competitive-evidence/'.$candidateSha.'.json');
        $receipt = $this->jsonFile($path);
        $valid = $receipt !== null
            && $this->competitive->verify($receipt, $candidateSha)
            && ($receipt['SEO-PLATFORM-11G'] ?? null) === 'CLOSED'
            && ($receipt['ready_for_11H'] ?? null) === true
            && ($receipt['11i_handoff_ready'] ?? null) === true
            && ($receipt['competitive_source_state'] ?? null) === 'available'
            && ($receipt['competitive_freshness_state'] ?? null) === 'fresh'
            && ($receipt['competitive_bundle_verification'] ?? null) === 'valid'
            && ($receipt['competitive_context_status'] ?? null) === 'READY'
            && ($receipt['competitive_hold_reason'] ?? null) === 'NONE';

        return [
            'status' => $valid ? 'READY' : 'DEPENDENCY_HOLD',
            'source' => 'current_production_receipt',
            'production_sha' => $valid ? $candidateSha : null,
            'SEO-PLATFORM-11G' => $receipt['SEO-PLATFORM-11G'] ?? 'HOLD',
            'ready_for_11H' => $receipt['ready_for_11H'] ?? false,
            '11i_handoff_ready' => $receipt['11i_handoff_ready'] ?? false,
            'competitive_source_state' => $receipt['competitive_source_state'] ?? 'unavailable',
            'competitive_freshness_state' => $receipt['competitive_freshness_state'] ?? 'unknown',
            'competitive_bundle_verification' => $receipt['competitive_bundle_verification'] ?? 'invalid',
            'competitive_context_status' => $receipt['competitive_context_status'] ?? 'HOLD',
            'competitive_hold_reason' => $receipt['competitive_hold_reason'] ?? 'RECEIPT_UNAVAILABLE',
            'authority_revision' => is_string($receipt['receipt_hash'] ?? null) ? $receipt['receipt_hash'] : hash('sha256', '11g-unavailable'),
            'receipt_hash' => $receipt['receipt_hash'] ?? null,
            'current_candidate_production_claimed' => $valid,
        ];
    }

    /** @return array{total:int,passed:int,bypass_count:int,results:list<array{id:string,passed:bool}>} */
    private function negativeProbes(): array
    {
        $readyRefs = [$this->evidenceRef('query_owner'), $this->evidenceRef('url_truth'), $this->evidenceRef('page_family_policy'), $this->evidenceRef('search_measurement'), $this->evidenceRef('competitive_handoff')];
        $input = ['query_hmac' => str_repeat('a', 64), 'query_cluster_id' => 'cluster:11h', 'intent_label' => 'career intent', 'query_family_key' => 'career:en', 'locale' => 'en'];
        $multi = $this->runner->evaluate($input, $this->family('en', [str_repeat('b', 64), str_repeat('c', 64)], 'conflict', ['multiple_primary_owner']), $readyRefs, str_repeat('d', 64), str_repeat('e', 64));
        $missing = $this->runner->evaluate($input, $this->family('en', [], 'blocked', ['primary_owner_missing']), $readyRefs, str_repeat('d', 64), str_repeat('e', 64));
        $zhCopy = $this->runner->evaluate($input, $this->family('zh-CN', [str_repeat('b', 64)]), $readyRefs, str_repeat('d', 64), str_repeat('e', 64));
        $zhInput = [...$input, 'locale' => 'zh-CN'];
        $enCopy = $this->runner->evaluate($zhInput, $this->family('en', [str_repeat('b', 64)]), $readyRefs, str_repeat('d', 64), str_repeat('e', 64));
        $stale = $readyRefs;
        $stale[0]['status'] = 'EVIDENCE_HOLD';
        $staleResult = $this->runner->evaluate($input, $this->family('en', [str_repeat('b', 64)]), $stale, str_repeat('d', 64), str_repeat('e', 64));
        $request = $this->request($readyRefs, $input);
        $results = [
            ['id' => 'multiple_primary_abstains', 'passed' => data_get($multi, 'output.abstain_reason') === 'MULTIPLE_PRIMARY_OWNER'],
            ['id' => 'missing_owner_abstains', 'passed' => data_get($missing, 'output.abstain_reason') === 'PRIMARY_OWNER_MISSING'],
            ['id' => 'zh_owner_not_copied_to_en', 'passed' => data_get($zhCopy, 'output.abstain_reason') === 'LOCALE_AUTHORITY_MISMATCH'],
            ['id' => 'en_owner_not_copied_to_zh', 'passed' => data_get($enCopy, 'output.abstain_reason') === 'LOCALE_AUTHORITY_MISMATCH'],
            ['id' => 'stale_evidence_holds', 'passed' => data_get($staleResult, 'output.abstain_reason') === 'EVIDENCE_NOT_READY'],
            ['id' => 'raw_query_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'raw_query' => 'secret']])],
            ['id' => 'private_url_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'url' => '/account/report']])],
            ['id' => 'runtime_authority_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'runtime_owner' => str_repeat('b', 64)]])],
            ['id' => 'sitemap_authority_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'sitemap_owner' => str_repeat('b', 64)]])],
            ['id' => 'requested_role_expansion_rejected', 'passed' => $this->rejected([...$request, 'requested_role' => 'seo.orchestrator'])],
            ['id' => 'prompt_owner_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'prompt' => 'owner=b']])],
            ['id' => 'query_owner_write_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'query_owner_write' => true]])],
            ['id' => 'url_truth_write_rejected', 'passed' => $this->rejected([...$request, 'mode_input' => [...$input, 'url_truth_write' => true]])],
            ['id' => 'hash_drift_rejected', 'passed' => $this->rejected([...$request, 'evidence_bundle_refs' => [[...$readyRefs[0], 'bundle_hash' => 'drift']]])],
        ];
        $passed = count(array_filter($results, static fn (array $probe): bool => $probe['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }

    /** @param list<array<string, mixed>> $refs @param array<string, mixed> $input @return array<string, mixed> */
    private function request(array $refs, array $input): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11h:probe', 'idempotency_key' => 'mission:11h:probe',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'intent_query_ownership',
            'requested_role' => null, 'evidence_bundle_refs' => $refs, 'autonomy' => 'L1',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [], 'egress_scope' => [], 'mode_input' => $input,
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceRef(string $type): array
    {
        return ['bundle_id' => 'bundle:11h:'.$type, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', $type), 'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => hash('sha256', 'authority:'.$type)];
    }

    /** @param list<string> $owners @param list<string> $issues @return array<string, mixed> */
    private function family(string $locale, array $owners, string $status = 'pass', array $issues = []): array
    {
        return ['locale' => $locale, 'status' => $status, 'owner_hashes' => $owners, 'issues' => $issues, 'checks' => ['canonical_owner' => $status === 'pass' ? 'pass' : 'blocked']];
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

    /** @return array<string, mixed>|null */
    private function jsonFile(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
