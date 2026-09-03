<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class IntentOwnershipRunner
{
    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $input @param array<string, mixed> $family @param list<array<string, mixed>> $evidenceRefs @return array<string, mixed> */
    public function evaluate(array $input, array $family, array $evidenceRefs, string $runId, string $contextId): array
    {
        $ownerHashes = array_values(array_filter((array) ($family['owner_hashes'] ?? []), static fn (mixed $hash): bool => is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1));
        $issues = array_values(array_filter((array) ($family['issues'] ?? []), 'is_string'));
        $sameLocale = ($family['locale'] ?? null) === ($input['locale'] ?? null);
        $authorityConsistent = ($family['status'] ?? null) === 'pass'
            && ($family['checks']['canonical_owner'] ?? null) === 'pass';
        $unique = count($ownerHashes) === 1;
        $pass = $sameLocale && $authorityConsistent && $unique && $this->evidenceReady($evidenceRefs);
        $reason = match (true) {
            ! $sameLocale => 'LOCALE_AUTHORITY_MISMATCH',
            count($ownerHashes) > 1 || in_array('multiple_primary_owner', $issues, true) => 'MULTIPLE_PRIMARY_OWNER',
            $ownerHashes === [] || in_array('primary_owner_missing', $issues, true) => 'PRIMARY_OWNER_MISSING',
            ! $this->evidenceReady($evidenceRefs) => 'EVIDENCE_NOT_READY',
            ! $authorityConsistent => 'AUTHORITY_CONFLICT',
            default => 'NONE',
        };
        $output = [
            'intent' => (string) $input['intent_label'],
            'primary_owner_candidate' => $pass ? $ownerHashes[0] : null,
            'supporting_owners' => [],
            'cannibalization_cluster' => [
                'query_cluster_id' => (string) $input['query_cluster_id'],
                'locale' => (string) $input['locale'],
                'owner_hashes' => $ownerHashes,
                'status' => count($ownerHashes) > 1 ? 'conflict' : ($ownerHashes === [] ? 'owner_missing' : 'clear'),
                'evidence_refs' => $evidenceRefs,
            ],
            'authority_gap' => $pass ? null : $reason,
            'locale_reasoning' => $sameLocale ? 'locale_evaluated_independently' : 'locale_authority_mismatch',
            'owner_change_proposal' => null,
            'abstain_reason' => $pass ? null : $reason,
            'evidence_refs' => $evidenceRefs,
            'confidence' => $pass ? 1.0 : 0.0,
            'execution_allowed' => false,
        ];
        $outputHash = $this->hasher->hash($output);
        $receipt = [
            'receipt_version' => 'seo.intent_ownership_receipt.v1',
            'run_id' => $runId,
            'context_id' => $contextId,
            'request_hash' => $this->hasher->hash($input),
            'output_hash' => $outputHash,
            'role_id' => 'seo.expert.content_entity_quality',
            'capability_id' => 'seo.intent_query_ownership',
            'status' => $pass ? 'PASS' : 'HOLD',
            'negative_metrics' => [
                'raw_query_leak_count' => 0,
                'private_url_leak_count' => 0,
                'cross_locale_owner_copy_count' => 0,
                'authority_invention_count' => 0,
                'unresolved_multi_primary_without_abstain' => 0,
                'query_owner_writes' => 0,
                'url_truth_writes' => 0,
                'policy_bypass_count' => 0,
            ],
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'execution_allowed' => false,
        ];
        $receipt['receipt_hash'] = $this->hasher->hash($receipt);

        return ['output' => $output, 'output_hash' => $outputHash, 'receipt' => $receipt];
    }

    /** @param list<array<string, mixed>> $refs */
    private function evidenceReady(array $refs): bool
    {
        return $refs !== [] && array_filter($refs, static fn (array $ref): bool => ($ref['status'] ?? null) !== 'READY') === [];
    }
}
