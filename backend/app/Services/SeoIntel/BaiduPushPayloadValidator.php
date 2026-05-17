<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class BaiduPushPayloadValidator
{
    /**
     * @param  array<string, mixed>  $candidate
     * @return array{eligible: bool, issues: list<string>, normalized: array<string, mixed>}
     */
    public function validate(array $candidate): array
    {
        return $this->validateCandidate($candidate, 'baidu');
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array{eligible: bool, issues: list<string>, normalized: array<string, mixed>}
     */
    private function validateCandidate(array $candidate, string $sourceEngine): array
    {
        $issues = [];
        $url = $this->stringOrNull($candidate['canonical_url'] ?? null);

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

        $eligible = $issues === [];

        return [
            'eligible' => $eligible,
            'issues' => $issues,
            'normalized' => [
                'canonical_url_hash' => $url === null ? null : hash('sha256', $url),
                'canonical_url' => $url,
                'locale' => $this->stringOrNull($candidate['locale'] ?? null),
                'source_engine' => $sourceEngine,
                'submission_type' => (string) ($candidate['submission_type'] ?? 'push'),
                'submission_status' => 'dry_run',
                'metadata_json' => [
                    'fixture_only' => true,
                    'real_url_submission_allowed' => false,
                    'search_channel_purchase_attribution_allowed' => false,
                ],
            ],
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
