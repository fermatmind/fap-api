<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class CompetitorAlternativesSourceLedgerValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $issues = [];
        $entries = $payload['entries'] ?? null;

        if (($payload['schema_version'] ?? null) !== 'competitor-alternatives-source-ledger.v1') {
            $issues[] = 'invalid_schema_version';
        }

        if (($payload['task'] ?? null) !== 'FA30-API-10') {
            $issues[] = 'invalid_task';
        }

        if (! is_array($entries) || $entries === []) {
            $issues[] = 'entries_missing';
            $entries = [];
        }

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $issues[] = 'entry.'.$index.'.not_object';

                continue;
            }

            array_push($issues, ...$this->validateEntry($entry, $index));
        }

        array_push($issues, ...$this->scanForbiddenKeys($entries, 'entries'));

        return [
            'ok' => $issues === [],
            'status' => $issues === [] ? 'pass' : 'fail',
            'issue_count' => count($issues),
            'issues' => array_values(array_unique($issues)),
            'summary' => [
                'entry_count' => count($entries),
                'indexable_entry_count' => count(array_filter(
                    $entries,
                    static fn (mixed $entry): bool => is_array($entry)
                        && ($entry['indexability_status'] ?? null) === 'indexable'
                )),
                'legal_approved_count' => count(array_filter(
                    $entries,
                    static fn (mixed $entry): bool => is_array($entry)
                        && ($entry['legal_review_status'] ?? null) === 'approved'
                )),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function validateComparisonText(string $text, string $context = 'comparison_text'): array
    {
        $issues = [];
        $normalized = mb_strtolower($text);

        foreach (self::forbiddenClaimPatterns() as $code => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                $issues[] = $context.'.forbidden_claim.'.$code;
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function validateEntry(array $entry, int|string $index): array
    {
        $issues = [];
        $id = $this->nonEmptyString($entry['ledger_id'] ?? null) ?? (string) $index;

        foreach ([
            'ledger_id',
            'comparison_surface',
            'source_review_status',
            'claim_review_status',
            'legal_review_status',
            'indexability_status',
        ] as $field) {
            if ($this->nonEmptyString($entry[$field] ?? null) === null) {
                $issues[] = 'entry.'.$id.'.'.$field.'.missing';
            }
        }

        foreach ([
            'operator_reviewed_source_notes',
            'fermatmind_first_party_facts',
            'allowed_claims',
            'forbidden_claims',
        ] as $field) {
            if (! is_array($entry[$field] ?? null) || ($entry[$field] ?? []) === []) {
                $issues[] = 'entry.'.$id.'.'.$field.'.missing';
            }
        }

        if (! in_array(($entry['source_review_status'] ?? null), ['operator_review_required', 'operator_reviewed', 'approved'], true)) {
            $issues[] = 'entry.'.$id.'.source_review_status.invalid';
        }

        if (! in_array(($entry['claim_review_status'] ?? null), ['not_reviewed', 'operator_review_required', 'approved'], true)) {
            $issues[] = 'entry.'.$id.'.claim_review_status.invalid';
        }

        if (! in_array(($entry['legal_review_status'] ?? null), ['not_reviewed', 'required', 'approved'], true)) {
            $issues[] = 'entry.'.$id.'.legal_review_status.invalid';
        }

        if (! in_array(($entry['indexability_status'] ?? null), ['noindex', 'blocked', 'indexable'], true)) {
            $issues[] = 'entry.'.$id.'.indexability_status.invalid';
        }

        if (($entry['indexability_status'] ?? null) === 'indexable') {
            if (($entry['claim_review_status'] ?? null) !== 'approved') {
                $issues[] = 'entry.'.$id.'.indexable_requires_claim_approval';
            }

            if (($entry['legal_review_status'] ?? null) !== 'approved') {
                $issues[] = 'entry.'.$id.'.indexable_requires_legal_approval';
            }
        }

        foreach (['operator_reviewed_source_notes', 'fermatmind_first_party_facts', 'allowed_claims'] as $field) {
            foreach ((array) ($entry[$field] ?? []) as $claimIndex => $claim) {
                if (! is_string($claim)) {
                    $issues[] = 'entry.'.$id.'.'.$field.'.'.$claimIndex.'.not_string';

                    continue;
                }

                array_push($issues, ...$this->validateComparisonText($claim, 'entry.'.$id.'.'.$field.'.'.$claimIndex));
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return list<string>
     */
    private function scanForbiddenKeys(mixed $value, string $path): array
    {
        $issues = [];

        if (! is_array($value)) {
            return $issues;
        }

        foreach ($value as $key => $child) {
            $keyString = is_string($key) ? $key : (string) $key;
            if (in_array($keyString, self::forbiddenFieldNames(), true)) {
                $issues[] = $path.'.'.$keyString.'.forbidden_field';
            }

            array_push($issues, ...$this->scanForbiddenKeys($child, $path.'.'.$keyString));
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private static function forbiddenFieldNames(): array
    {
        return [
            'competitor_description',
            'copied_description',
            'scraped_reviews',
            'review_score',
            'rating',
            'ranking',
            'rank',
            'price',
            'pricing',
            'stars',
            'testimonials',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function forbiddenClaimPatterns(): array
    {
        return [
            'copied_competitor_copy' => '/copied from|verbatim competitor|competitor copy|复制竞品|照搬竞品/u',
            'ranking_claim' => '/\\b(no\\. ?1|#1|best|top ranked|better than|superior to|outperforms)\\b|排名第一|优于竞品|更好|最佳/u',
            'review_or_rating_claim' => '/\\brated\\b|\\breview score\\b|reviews say|user reviews|评分|评价说|用户评论/u',
            'pricing_claim' => '/\\bprice\\b|\\bpricing\\b|cheaper than|more expensive than|收费|价格|更便宜/u',
            'endorsement_claim' => '/endorsed by|official partner|官方合作|官方背书/u',
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
