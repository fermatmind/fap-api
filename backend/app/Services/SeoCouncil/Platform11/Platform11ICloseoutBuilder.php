<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;

final class Platform11ICloseoutBuilder
{
    public function __construct(
        private readonly Platform11ContractRegistry $contracts,
        private readonly Platform11MissionValidator $validator,
        private readonly EditorialDraftRunner $runner,
        private readonly Post12L2DraftWriteAdapter $adapter,
        private readonly SeoRegistryHasher $hasher,
    ) {}

    /** @param array<string, mixed> $hReceipt @return array<string, mixed> */
    public function build(string $candidateSha, string $environment, array $hReceipt): array
    {
        $probes = $this->negativeProbes();
        $hReady = ($hReceipt['dependency_status'] ?? null) === 'READY'
            && in_array($hReceipt['closeout_state'] ?? null, ['OFFLINE_EVAL_READY', 'STAGING_READY', 'CLOSED'], true);
        $ready = $hReady && $this->contracts->verifyGenerated()
            && $probes['passed'] === $probes['total'] && $probes['bypass_count'] === 0;
        $state = match ($environment) {
            'production_runtime' => $ready ? 'CLOSED' : 'DEPENDENCY_HOLD',
            'staging_runtime' => $ready ? 'STAGING_READY' : 'DEPENDENCY_HOLD',
            default => $ready ? 'OFFLINE_EVAL_READY' : 'DEPENDENCY_HOLD',
        };
        $closed = $environment === 'production_runtime' && $state === 'CLOSED';
        $manifest = $this->contracts->manifest();
        $receipt = [
            'receipt_version' => 'seo.editorial_draft_closeout.v1',
            'candidate_sha' => $candidateSha,
            'production_sha' => $closed ? $candidateSha : null,
            'environment' => $environment,
            'closeout_state' => $state,
            'dependency_status' => $hReady ? 'READY' : 'DEPENDENCY_HOLD',
            'dependency_snapshot' => [
                'SEO-PLATFORM-11H' => $hReceipt['SEO-PLATFORM-11H'] ?? 'HOLD',
                'ready_for_11I' => $hReceipt['ready_for_11I'] ?? false,
                '11i_handoff_ready' => $hReceipt['dependency_snapshot']['11i_handoff_ready'] ?? false,
                'intent_ownership_receipt_hash' => $hReceipt['receipt_hash'] ?? null,
                'competitive_receipt_hash' => $hReceipt['dependency_snapshot']['receipt_hash'] ?? null,
            ],
            'registry_ref' => $manifest['registry_ref'],
            'binding_ref' => $manifest['binding_ref'],
            'policy_ref' => $manifest['policy_ref'],
            'mode_ref' => $manifest['editorial_mode_ref'],
            'l2_manifest_schema_ref' => $manifest['l2_manifest_schema_ref'],
            'negative_probes' => $probes,
            ...$this->zeroMetrics(),
            'artifact_only' => true,
            'dry_run_only' => true,
            'cms_write' => false,
            'publish' => false,
            'active_manifest_count' => 0,
            'trusted_signing_key_count' => 0,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'production_permissions' => 0,
            'post12_agent_write_enabled' => false,
            'execution_allowed' => false,
            'SEO-PLATFORM-11I' => $closed ? 'CLOSED' : ($ready ? $state : 'DEPENDENCY_HOLD'),
            'ready_for_11J' => $closed,
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
            'page_necessity_missing' => [[...$base, 'page_necessity' => ''], 'PAGE_NECESSITY_MISSING'],
            'source_claim_missing' => [[...$base, 'source_claim_locale_map' => []], 'SOURCE_CLAIM_MISSING'],
            'source_stale' => [[...$base, 'source_claim_locale_map' => [[...$base['source_claim_locale_map'][0], 'freshness_state' => 'stale']]], 'SOURCE_STALE'],
            'translation_only' => [[...$base, 'translation_only' => true], 'LOCALE_VALUE_MISSING'],
            'duplicate_overlap' => [[...$base, 'duplicate_similarity' => 0.90], 'DUPLICATE_OR_TEMPLATE_OVERLAP'],
            'scaled_content' => [[...$base, 'scaled_content_score' => 0.90], 'SCALED_CONTENT_RISK'],
            'private_link' => [[...$base, 'internal_link_candidates' => [[...$base['internal_link_candidates'][0], 'visibility' => 'private']]], 'INTERNAL_LINK_AUTHORITY_DENIED'],
            'redirect_alias' => [[...$base, 'internal_link_candidates' => [[...$base['internal_link_candidates'][0], 'redirect_only' => true]]], 'INTERNAL_LINK_AUTHORITY_DENIED'],
            'unsupported_claim' => [[...$base, 'source_claim_locale_map' => [[...$base['source_claim_locale_map'][0], 'statement_kind' => 'competitor_marketing']]], 'UNSUPPORTED_CLAIM'],
        ];
        $results = [];
        foreach ($cases as $id => [$input, $reason]) {
            $result = $this->runner->evaluate($input, $refs, str_repeat('a', 64), str_repeat('b', 64));
            $results[] = ['id' => $id, 'passed' => ($result['output']['hold_reason'] ?? null) === $reason && ($result['output']['draft_emitted'] ?? true) === false];
        }
        foreach (['unsigned_l2', 'expired_l2', 'wrong_scope_l2', 'enabled_l2'] as $id) {
            $manifest = match ($id) {
                'expired_l2' => ['signed' => true, 'expires_at' => 'expired', 'scope' => 'limited_cms_draft_fields'],
                'wrong_scope_l2' => ['signed' => true, 'expires_at' => 'future_verified_by_gateway', 'scope' => 'publish'],
                'enabled_l2' => ['signed' => true, 'expires_at' => 'future_verified_by_gateway', 'scope' => 'limited_cms_draft_fields'],
                default => [],
            };
            $decision = $this->adapter->authorize($manifest);
            $results[] = ['id' => $id, 'passed' => $decision['status'] === 'DENY' && $decision['write_count'] === 0 && $decision['execution_allowed'] === false];
        }
        $request = $this->request($base, $refs);
        foreach (['cms_write', 'publish', 'canonical', 'robots', 'scoring', 'private_result', 'search_submission', 'prompt'] as $field) {
            $results[] = [
                'id' => $field.'_request_denied',
                'passed' => $this->rejected([...$request, 'mode_input' => [...$base, $field => true]]),
            ];
        }
        $passed = count(array_filter($results, static fn (array $probe): bool => $probe['passed']));

        return ['total' => count($results), 'passed' => $passed, 'bypass_count' => count($results) - $passed, 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'owner_candidate_hash' => hash('sha256', 'owner'), 'locale' => 'en', 'title' => 'Evidence-backed career guide',
            'seo_title' => 'Evidence-backed career guide', 'meta_description' => 'A source-bound career guide.',
            'refresh_brief' => 'Refresh verified facts only.', 'direct_response' => 'Use the evidence to compare this path.',
            'faq_or_modules' => [['module_id' => 'overview', 'evidence_ref' => hash('sha256', 'module-evidence')]],
            'internal_link_candidates' => [['target_hash' => hash('sha256', 'public-target'), 'truth_status' => 'current_public', 'visibility' => 'published', 'indexability' => 'index', 'redirect_only' => false, 'locale' => 'en']],
            'source_claim_locale_map' => [['claim_id' => 'claim-1', 'source_ref' => hash('sha256', 'source'), 'evidence_ref' => hash('sha256', 'claim-evidence'), 'locale' => 'en', 'risk_level' => 'low', 'freshness_state' => 'fresh', 'statement_kind' => 'fact']],
            'schema_candidate' => ['type' => 'Article'], 'material_change' => true,
            'page_necessity' => 'This locale has a distinct public decision task and verified evidence.',
            'information_gain' => 'Adds a source-bound decision comparison absent from the existing owner.',
            'template_overlap_score' => 0.10, 'duplicate_similarity' => 0.10, 'translation_only' => false,
            'locale_specific_value' => 'Uses locale-specific employment terminology.', 'scaled_content_score' => 0.10,
            'authority_revision' => hash('sha256', 'authority'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function refs(): array
    {
        return array_map(static fn (string $type): array => [
            'bundle_id' => 'bundle:11i:'.$type, 'bundle_version' => 1, 'bundle_hash' => hash('sha256', $type),
            'evidence_type' => $type, 'status' => 'READY', 'authority_revision' => str_repeat('a', 64),
        ], ['intent_ownership', 'competitive_handoff', 'content_claim', 'entity', 'duplicate', 'lifecycle', 'url_truth']);
    }

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $refs @return array<string, mixed> */
    private function request(array $input, array $refs): array
    {
        return [
            'schema_version' => 'seo.mission_request.v2', 'mission_id' => 'mission:11i:probe', 'idempotency_key' => 'mission:11i:probe',
            'mission_type' => 'bounded_review', 'family' => 'career', 'locale' => 'en', 'review_domain' => 'editorial_draft',
            'requested_role' => null, 'evidence_bundle_refs' => $refs, 'autonomy' => 'L1',
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
            'page_necessity_missing_count' => 0, 'unsupported_claim_count' => 0, 'private_data_leak_count' => 0,
            'private_link_candidate_count' => 0, 'authority_invention_count' => 0, 'scaled_content_bypass_count' => 0,
            'cms_writes' => 0, 'publish_writes' => 0, 'canonical_writes' => 0, 'robots_writes' => 0,
            'search_writes' => 0, 'l2_manifest_bypass_count' => 0,
        ];
    }
}
