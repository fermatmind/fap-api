<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class RiasecMajorGraphAuthorityValidator
{
    /**
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $issues = [];
        $clusters = $payload['clusters'] ?? null;

        if (($payload['schema_version'] ?? null) !== 'riasec-major-graph-authority.v1') {
            $issues[] = 'invalid_schema_version';
        }

        if (($payload['task'] ?? null) !== 'FA30-API-09') {
            $issues[] = 'invalid_task';
        }

        if (! is_array($clusters) || $clusters === []) {
            $issues[] = 'clusters_missing';
            $clusters = [];
        }

        foreach ($clusters as $index => $cluster) {
            if (! is_array($cluster)) {
                $issues[] = 'cluster.'.$index.'.not_object';

                continue;
            }

            array_push($issues, ...$this->validateCluster($cluster, $index));
        }

        return [
            'ok' => $issues === [],
            'status' => $issues === [] ? 'pass' : 'fail',
            'issue_count' => count($issues),
            'issues' => $issues,
            'summary' => [
                'cluster_count' => count($clusters),
                'indexable_cluster_count' => count(array_filter(
                    $clusters,
                    static fn (mixed $cluster): bool => is_array($cluster)
                        && ($cluster['indexability_status'] ?? null) === 'indexable'
                )),
                'reviewed_cluster_count' => count(array_filter(
                    $clusters,
                    static fn (mixed $cluster): bool => is_array($cluster)
                        && in_array(($cluster['review_status'] ?? null), ['approved', 'operator_reviewed'], true)
                )),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function validateClaimText(string $text, string $context = 'claim'): array
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
     * @param  array<string, mixed>  $cluster
     * @return list<string>
     */
    private function validateCluster(array $cluster, int|string $index): array
    {
        $issues = [];
        $id = $this->nonEmptyString($cluster['cluster_id'] ?? null) ?? (string) $index;

        foreach ([
            'cluster_id',
            'cluster_name',
            'discipline_family',
            'review_status',
            'claim_tier',
            'indexability_status',
        ] as $field) {
            if ($this->nonEmptyString($cluster[$field] ?? null) === null) {
                $issues[] = 'cluster.'.$id.'.'.$field.'.missing';
            }
        }

        foreach ([
            'riasec_primary_codes',
            'riasec_secondary_codes',
            'learning_activity_families',
            'work_activity_families',
            'evidence_sources',
            'allowed_claims',
        ] as $field) {
            if (! is_array($cluster[$field] ?? null) || ($cluster[$field] ?? []) === []) {
                $issues[] = 'cluster.'.$id.'.'.$field.'.missing';
            }
        }

        foreach (array_merge((array) ($cluster['riasec_primary_codes'] ?? []), (array) ($cluster['riasec_secondary_codes'] ?? [])) as $code) {
            if (! in_array($code, ['R', 'I', 'A', 'S', 'E', 'C'], true)) {
                $issues[] = 'cluster.'.$id.'.riasec_code.invalid';
            }
        }

        $reviewStatus = (string) ($cluster['review_status'] ?? '');
        $claimTier = (string) ($cluster['claim_tier'] ?? '');
        $indexabilityStatus = (string) ($cluster['indexability_status'] ?? '');

        if (! in_array($reviewStatus, ['draft', 'operator_review_required', 'operator_reviewed', 'approved'], true)) {
            $issues[] = 'cluster.'.$id.'.review_status.invalid';
        }

        if (! in_array($claimTier, ['exploration_only', 'reviewed_exploration'], true)) {
            $issues[] = 'cluster.'.$id.'.claim_tier.invalid';
        }

        if (! in_array($indexabilityStatus, ['noindex', 'blocked', 'indexable'], true)) {
            $issues[] = 'cluster.'.$id.'.indexability_status.invalid';
        }

        if ($indexabilityStatus === 'indexable' && ! in_array($reviewStatus, ['operator_reviewed', 'approved'], true)) {
            $issues[] = 'cluster.'.$id.'.indexable_requires_review';
        }

        if ($indexabilityStatus === 'indexable' && $claimTier !== 'reviewed_exploration') {
            $issues[] = 'cluster.'.$id.'.indexable_requires_reviewed_exploration_claim_tier';
        }

        foreach ((array) ($cluster['allowed_claims'] ?? []) as $claimIndex => $claim) {
            if (! is_string($claim)) {
                $issues[] = 'cluster.'.$id.'.allowed_claims.'.$claimIndex.'.not_string';

                continue;
            }

            array_push($issues, ...$this->validateClaimText($claim, 'cluster.'.$id.'.allowed_claims.'.$claimIndex));
        }

        foreach ((array) ($cluster['evidence_sources'] ?? []) as $sourceIndex => $source) {
            if (! is_array($source)) {
                $issues[] = 'cluster.'.$id.'.evidence_sources.'.$sourceIndex.'.not_object';

                continue;
            }

            foreach (['source_id', 'source_type', 'allowed_use', 'limitation'] as $field) {
                if ($this->nonEmptyString($source[$field] ?? null) === null) {
                    $issues[] = 'cluster.'.$id.'.evidence_sources.'.$sourceIndex.'.'.$field.'.missing';
                }
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @return array<string, string>
     */
    private static function forbiddenClaimPatterns(): array
    {
        return [
            'best_major' => '/\\b(best|perfect|top|ideal)[ -]?(major|college major|degree)\\b|最佳专业|最适合专业|推荐最佳专业/u',
            'major_recommendation' => '/\\brecommend(s|ed)? (this )?(major|degree)\\b|推荐专业|专业推荐/u',
            'gaokao_admission' => '/gaokao .*admission|admission probability|college admission prediction|高考录取|录取概率|录取预测/u',
            'salary_or_employment' => '/employment salary|salary prediction|employment rate prediction|就业薪资|薪资预测|就业率预测/u',
            'success_rate' => '/success rate|career success|成功率|职业成功/u',
            'advisor_replacement' => '/replace(s)? (a )?(counselor|advisor|teacher)|代替顾问|替代顾问|代替老师|替代升学规划/u',
            'guarantee' => '/guarantee(d)? outcome|guarantee(d)? result|保证结果|保障结果/u',
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
