<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class DomesticSearchUrlEligibilityValidator
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return array{eligible: bool, issues: list<string>, normalized: array<string, mixed>}
     */
    public function validate(array $candidate, string $engine, string $sourceEngine): array
    {
        $issues = [];
        $url = $this->stringOrNull($candidate['canonical_url'] ?? null);
        $entityType = $this->stringOrNull($candidate['page_entity_type'] ?? null);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $issues[] = 'invalid_canonical_url';
        }

        if ((bool) ($candidate['is_draft'] ?? false)) {
            $issues[] = 'draft_url_rejected';
        }

        if ((bool) ($candidate['is_private_flow'] ?? false)) {
            $issues[] = 'private_flow_rejected';
        }

        if (($candidate['indexability_state'] ?? 'indexable') !== 'indexable') {
            $issues[] = 'non_indexable_rejected';
        }

        if ((bool) ($candidate['controlled_published'] ?? true) === false) {
            $issues[] = 'not_controlled_published';
        }

        if ((bool) ($candidate['public_runtime_verified'] ?? true) === false) {
            $issues[] = 'public_runtime_not_verified';
        }

        if ((bool) ($candidate['claim_safe'] ?? true) === false) {
            $issues[] = 'claim_boundary_rejected';
        }

        if ($entityType !== null && in_array($entityType, $this->forbiddenPageEntityTypes(), true)) {
            $issues[] = 'private_flow_entity_type_rejected';
        }

        return [
            'eligible' => $issues === [],
            'issues' => $issues,
            'normalized' => [
                'engine' => $engine,
                'canonical_url_hash' => $url === null ? null : hash('sha256', $url),
                'canonical_url' => $url,
                'locale' => $this->stringOrNull($candidate['locale'] ?? null),
                'source_engine' => $sourceEngine,
                'submission_type' => (string) ($candidate['submission_type'] ?? 'sitemap_or_url'),
                'submission_status' => 'dry_run',
                'metadata_json' => [
                    'fixture_only' => true,
                    'controlled_published' => (bool) ($candidate['controlled_published'] ?? true),
                    'public_runtime_verified' => (bool) ($candidate['public_runtime_verified'] ?? true),
                    'claim_safe' => (bool) ($candidate['claim_safe'] ?? true),
                    'real_url_submission_allowed' => false,
                    'engine_specific_page_generation_allowed' => false,
                    'search_channel_purchase_attribution_allowed' => false,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function forbiddenPageEntityTypes(): array
    {
        return [
            'take',
            'result',
            'order',
            'share',
            'pay',
            'checkout',
            'report_private',
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
