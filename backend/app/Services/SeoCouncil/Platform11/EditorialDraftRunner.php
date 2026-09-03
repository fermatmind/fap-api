<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform11;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;

final class EditorialDraftRunner
{
    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $evidenceRefs @return array<string, mixed> */
    public function evaluate(array $input, array $evidenceRefs, string $runId, string $contextId): array
    {
        $claims = (array) $input['source_claim_locale_map'];
        $links = (array) $input['internal_link_candidates'];
        $reason = $this->holdReason($input, $claims, $links, $evidenceRefs);
        $pass = $reason === 'NONE';
        $package = null;

        if ($pass) {
            $package = [
                'title' => $input['title'],
                'seo_title' => $input['seo_title'],
                'meta_description' => $input['meta_description'],
                'refresh_brief' => $input['refresh_brief'],
                'direct_answer' => $input['direct_response'],
                'faq_or_modules' => $input['faq_or_modules'],
                'internal_link_candidates' => array_map(static fn (array $link): array => [
                    'target_hash' => $link['target_hash'],
                    'locale' => $link['locale'],
                    'authority' => 'public_url_truth',
                ], $links),
                'source_claim_locale_map' => $claims,
                'schema_candidate' => $input['schema_candidate'],
                'duplicate_risk' => $input['duplicate_similarity'],
                'material_change' => $input['material_change'],
                'page_necessity' => $input['page_necessity'],
                'information_gain' => $input['information_gain'],
                'template_overlap' => $input['template_overlap_score'],
                'locale_specific_value' => $input['locale_specific_value'],
                'scaled_content_risk' => $input['scaled_content_score'],
                'evidence_refs' => $evidenceRefs,
                'authority_revision' => $input['authority_revision'],
            ];
            $package['package_hash'] = $this->hasher->hash($package);
        }

        $output = [
            'status' => $pass ? 'DRAFT_READY' : 'HOLD',
            'draft_emitted' => $pass,
            'hold_reason' => $reason,
            'draft_package' => $package,
            'artifact_only' => true,
            'dry_run_only' => true,
            'cms_write' => false,
            'publish' => false,
            'execution_allowed' => false,
        ];
        $receipt = [
            'receipt_version' => 'seo.editorial_draft_receipt.v1',
            'run_id' => $runId,
            'context_id' => $contextId,
            'request_hash' => $this->hasher->hash($input),
            'output_hash' => $this->hasher->hash($output),
            'role_id' => 'seo.expert.content_entity_quality',
            'capability_sequence' => [
                'seo.content_claim_entity_audit',
                'seo.editorial_cms_draft',
                'seo.internal_link_recommendation',
            ],
            'role_call_count' => 1,
            'status' => $pass ? 'PASS' : 'HOLD',
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

    /** @param array<string, mixed> $input @param list<array<string, mixed>> $claims @param list<array<string, mixed>> $links @param list<array<string, mixed>> $evidenceRefs */
    private function holdReason(array $input, array $claims, array $links, array $evidenceRefs): string
    {
        if ($evidenceRefs === [] || array_filter($evidenceRefs, static fn (array $ref): bool => ($ref['status'] ?? null) !== 'READY') !== []) {
            return 'EVIDENCE_NOT_READY';
        }
        if (trim((string) $input['page_necessity']) === '') {
            return 'PAGE_NECESSITY_MISSING';
        }
        if ($claims === []) {
            return 'SOURCE_CLAIM_MISSING';
        }
        foreach ($claims as $claim) {
            if (($claim['freshness_state'] ?? null) !== 'fresh') {
                return 'SOURCE_STALE';
            }
            if (($claim['locale'] ?? null) !== $input['locale']) {
                return 'CLAIM_LOCALE_MISMATCH';
            }
            if (($claim['statement_kind'] ?? null) !== 'fact' || ! $this->hash($claim['source_ref'] ?? null) || ! $this->hash($claim['evidence_ref'] ?? null)) {
                return 'UNSUPPORTED_CLAIM';
            }
        }
        if (($input['translation_only'] ?? true) === true || trim((string) $input['locale_specific_value']) === '') {
            return 'LOCALE_VALUE_MISSING';
        }
        if ((float) $input['duplicate_similarity'] >= 0.85 || (float) $input['template_overlap_score'] >= 0.85) {
            return 'DUPLICATE_OR_TEMPLATE_OVERLAP';
        }
        if ((float) $input['scaled_content_score'] >= 0.70) {
            return 'SCALED_CONTENT_RISK';
        }
        foreach ($links as $link) {
            if (($link['truth_status'] ?? null) !== 'current_public'
                || ($link['visibility'] ?? null) !== 'published'
                || ($link['indexability'] ?? null) !== 'index'
                || ($link['redirect_only'] ?? true) !== false
                || ($link['locale'] ?? null) !== $input['locale']
                || ! $this->hash($link['target_hash'] ?? null)) {
                return 'INTERNAL_LINK_AUTHORITY_DENIED';
            }
        }

        return 'NONE';
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array<string, int> */
    private function zeroMetrics(): array
    {
        return [
            'page_necessity_missing_count' => 0,
            'unsupported_claim_count' => 0,
            'private_data_leak_count' => 0,
            'private_link_candidate_count' => 0,
            'authority_invention_count' => 0,
            'scaled_content_bypass_count' => 0,
            'cms_writes' => 0,
            'publish_writes' => 0,
            'canonical_writes' => 0,
            'robots_writes' => 0,
            'search_writes' => 0,
            'l2_manifest_bypass_count' => 0,
        ];
    }
}
