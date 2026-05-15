<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class Career2786PublicResolutionPartitionPlanner
{
    public const SCHEMA_VERSION = 'career_2786_public_resolution_partition.v1';

    public const TARGET_PUBLIC_TOTAL = 2786;

    /**
     * @param  list<string>  $currentPublicSlugs
     * @param  list<string>  $locales
     * @param  list<string>|null  $occupationExistingSlugs
     */
    public function partition(
        CareerPublicResolutionPlan $sourcePlan,
        array $currentCloseout,
        array $currentPublicSlugs,
        int $currentPublicTotal,
        int $targetPublicTotal = self::TARGET_PUBLIC_TOTAL,
        array $locales = ['en', 'zh'],
        ?array $occupationExistingSlugs = null,
    ): Career2786PublicResolutionPartitionResult {
        $locales = $this->normalizedStringList($locales, 'locale');
        $baselineSlugs = $this->normalizedSlugList($currentPublicSlugs, 'baseline_slug');
        $occupationExistingSlugs = $occupationExistingSlugs === null
            ? null
            : $this->normalizedSlugList($occupationExistingSlugs, 'occupation_existing_slug');
        $baselineSet = array_fill_keys($baselineSlugs, true);
        $occupationExistingSet = $occupationExistingSlugs === null ? [] : array_fill_keys($occupationExistingSlugs, true);
        $requiresOccupationExists = $occupationExistingSlugs !== null;
        $seenSourceSlugs = [];
        $duplicateSourceSlugs = [];
        $sourceSlugMissingCount = 0;
        $partitions = [
            'already_public_baseline' => [],
            'canonical_rollout_candidate' => [],
            'occupation_missing_remediation' => [],
            'cn_proxy_policy_asset' => [],
            'software_manual_hold' => [],
        ];
        $rows = [];

        foreach ($sourcePlan->rows as $position => $row) {
            $slug = $this->normalizedSlug($row->canonicalSlug);
            if ($slug === null) {
                $sourceSlugMissingCount++;

                continue;
            }

            if (isset($seenSourceSlugs[$slug])) {
                $duplicateSourceSlugs[] = $slug;

                continue;
            }
            $seenSourceSlugs[$slug] = true;

            $partition = $this->partitionFor(
                row: $row,
                slug: $slug,
                baselineSet: $baselineSet,
                occupationExistingSet: $occupationExistingSet,
                requiresOccupationExists: $requiresOccupationExists,
            );
            $partitions[$partition][] = $slug;
            $rows[] = [
                'slug' => $slug,
                'source_position' => $position + 1,
                'source_row_number' => $row->rowNumber,
                'partition' => $partition,
                'locales' => $row->locales === [] ? $locales : $row->locales,
                'public_resolution_state' => $row->publicResolutionState,
                'canonical_public_type' => $row->canonicalPublicType,
                'source_code' => $row->sourceCode,
                'title_en' => $row->titleEn,
                'title_zh' => $row->titleZh,
                'rollout_candidate' => $partition === 'canonical_rollout_candidate',
                'canonical_rollout_candidate' => $partition === 'canonical_rollout_candidate',
                'candidate_prep_allowed' => false,
                'requires_policy_decision' => in_array($partition, ['cn_proxy_policy_asset', 'software_manual_hold'], true),
                'requires_entity_remediation' => $partition === 'occupation_missing_remediation',
            ];
        }

        $issues = $this->issues(
            currentCloseout: $currentCloseout,
            baselineSlugs: $baselineSlugs,
            currentPublicTotal: $currentPublicTotal,
            targetPublicTotal: $targetPublicTotal,
            sourceRows: count($sourcePlan->rows),
            partitionedRows: count($rows),
            sourceSlugMissingCount: $sourceSlugMissingCount,
            duplicateSourceSlugs: $duplicateSourceSlugs,
        );
        $partitionCounts = [];
        foreach ($partitions as $partition => $slugs) {
            $partitionCounts[$partition] = count($slugs);
        }

        $canonicalCandidateCount = $partitionCounts['canonical_rollout_candidate'];
        $targetDeltaCount = max(0, $targetPublicTotal - $currentPublicTotal);
        $status = $issues === [] ? 'pass' : 'blocked';
        $canonicalRolloutCanReachTarget = count($baselineSlugs) + $canonicalCandidateCount >= $targetPublicTotal;
        $occupationMissingCount = $partitionCounts['occupation_missing_remediation'];
        $cnProxyCount = $partitionCounts['cn_proxy_policy_asset'];
        $softwareManualHoldCount = $partitionCounts['software_manual_hold'];

        return new Career2786PublicResolutionPartitionResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'partition_pass' => $issues === [],
            'partition_status' => $issues === [] ? 'partitioned' : 'blocked',
            'readiness_pass' => false,
            'read_only' => true,
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'candidate_prep_allowed' => false,
            'current_public_total' => $currentPublicTotal,
            'target_public_total' => $targetPublicTotal,
            'baseline_count' => count($baselineSlugs),
            'target_delta_count' => $targetDeltaCount,
            'expected_total_locale_rows' => $targetPublicTotal * count($locales),
            'locale_count' => count($locales),
            'locales' => $locales,
            'partition_counts' => $partitionCounts,
            'canonical_rollout_candidate_count' => $canonicalCandidateCount,
            'canonical_rollout_possible_total' => count($baselineSlugs) + $canonicalCandidateCount,
            'canonical_rollout_shortfall' => max(0, $targetPublicTotal - count($baselineSlugs) - $canonicalCandidateCount),
            'canonical_rollout_can_reach_target' => $canonicalRolloutCanReachTarget,
            'occupation_missing_remediation_count' => $occupationMissingCount,
            'cn_proxy_policy_asset_count' => $cnProxyCount,
            'software_manual_hold_count' => $softwareManualHoldCount,
            'policy_partition_required' => $cnProxyCount > 0 || $softwareManualHoldCount > 0,
            'entity_remediation_required' => $occupationMissingCount > 0,
            'partitions' => $partitions,
            'rows' => $rows,
            'source_plan' => [
                'source_path' => $sourcePlan->sourcePath,
                'checksum' => $sourcePlan->checksum,
                'row_count' => count($sourcePlan->rows),
            ],
            'entity_context' => [
                'required_for_partition' => $requiresOccupationExists,
                'occupation_exists_count' => $occupationExistingSlugs === null ? null : count($occupationExistingSlugs),
                'occupation_missing_remediation_count' => $occupationMissingCount,
            ],
            'blockers' => array_map(
                static fn (CareerProgressiveReadinessSelectionIssue $issue): array => $issue->toArray(),
                $issues,
            ),
            'sidecars' => [],
            'next_required_actions' => $this->nextRequiredActions(
                canonicalRolloutCanReachTarget: $canonicalRolloutCanReachTarget,
                occupationMissingCount: $occupationMissingCount,
                cnProxyCount: $cnProxyCount,
                softwareManualHoldCount: $softwareManualHoldCount,
            ),
            'next_required_action' => $issues === []
                ? '2786_PUBLIC_RESOLUTION_PARTITION_REVIEW'
                : 'FIX_2786_PUBLIC_RESOLUTION_PARTITION_INPUTS',
        ]);
    }

    /**
     * @param  array<string, bool>  $baselineSet
     * @param  array<string, bool>  $occupationExistingSet
     */
    private function partitionFor(
        CareerPublicResolutionPlanRow $row,
        string $slug,
        array $baselineSet,
        array $occupationExistingSet,
        bool $requiresOccupationExists,
    ): string {
        if (isset($baselineSet[$slug])) {
            return 'already_public_baseline';
        }

        if ($slug === 'software-developers') {
            return 'software_manual_hold';
        }

        if ($this->isCnProxyPolicyRow($row, $slug)) {
            return 'cn_proxy_policy_asset';
        }

        if ($requiresOccupationExists && ! isset($occupationExistingSet[$slug])) {
            return 'occupation_missing_remediation';
        }

        return 'canonical_rollout_candidate';
    }

    private function isCnProxyPolicyRow(CareerPublicResolutionPlanRow $row, string $slug): bool
    {
        if (str_starts_with($slug, 'cn-')) {
            return true;
        }

        $state = strtolower(trim((string) $row->publicResolutionState));
        if (in_array($state, ['cn_proxy_hold', 'blocked_until_cn_authority_policy'], true)) {
            return true;
        }

        $publicType = strtolower(trim((string) $row->canonicalPublicType));
        if (in_array($publicType, ['public_cn_proxy_page', 'public_cn_proxy_page_candidate'], true)) {
            return true;
        }

        $recommendedResolution = strtolower(trim((string) ($row->raw['recommended_resolution'] ?? '')));

        return in_array($recommendedResolution, ['public_cn_proxy_page', 'public_cn_proxy_page_candidate'], true);
    }

    /**
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $duplicateSourceSlugs
     * @return list<CareerProgressiveReadinessSelectionIssue>
     */
    private function issues(
        array $currentCloseout,
        array $baselineSlugs,
        int $currentPublicTotal,
        int $targetPublicTotal,
        int $sourceRows,
        int $partitionedRows,
        int $sourceSlugMissingCount,
        array $duplicateSourceSlugs,
    ): array {
        $issues = [];
        if (($currentCloseout['status'] ?? null) !== 'complete' || ($currentCloseout['accepted'] ?? false) !== true) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'current_closeout_not_accepted',
                message: 'Current closeout artifact must be complete and accepted.',
                severity: 'blocker_for_publication',
                evidence: [
                    'status' => $currentCloseout['status'] ?? null,
                    'accepted' => $currentCloseout['accepted'] ?? null,
                ],
            );
        }

        $declaredTotal = $currentCloseout['total_slug_count'] ?? $currentCloseout['target_public_total'] ?? null;
        if ($declaredTotal !== null && (int) $declaredTotal !== count($baselineSlugs)) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'baseline_count_closeout_mismatch',
                message: 'Baseline slug count does not match the closeout declared total.',
                severity: 'high',
                evidence: [
                    'declared' => $declaredTotal,
                    'actual' => count($baselineSlugs),
                ],
            );
        }

        if ($currentPublicTotal !== count($baselineSlugs)) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'current_public_total_baseline_mismatch',
                message: 'Current public total must match the supplied accepted baseline slug count.',
                severity: 'high',
                evidence: [
                    'current_public_total' => $currentPublicTotal,
                    'baseline_count' => count($baselineSlugs),
                ],
            );
        }

        if ($targetPublicTotal !== self::TARGET_PUBLIC_TOTAL) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'target_public_total_unsupported',
                message: 'Final public-resolution partition only supports target_public_total=2786.',
                severity: 'high',
                evidence: ['target_public_total' => $targetPublicTotal],
            );
        }

        if ($sourceRows !== self::TARGET_PUBLIC_TOTAL) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'source_plan_row_count_mismatch',
                message: 'Final public-resolution partition requires the complete 2786-row source plan.',
                severity: 'high',
                evidence: [
                    'expected' => self::TARGET_PUBLIC_TOTAL,
                    'actual' => $sourceRows,
                ],
            );
        }

        if ($sourceSlugMissingCount > 0) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'source_slug_missing',
                message: 'Source plan contains rows without canonical slugs.',
                severity: 'high',
                evidence: ['count' => $sourceSlugMissingCount],
            );
        }

        if ($duplicateSourceSlugs !== []) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'duplicate_source_slugs',
                message: 'Source plan contains duplicate canonical slugs.',
                severity: 'high',
                evidence: ['slugs' => array_values(array_unique($duplicateSourceSlugs))],
            );
        }

        if ($partitionedRows + $sourceSlugMissingCount + count($duplicateSourceSlugs) !== $sourceRows) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'partition_accounting_mismatch',
                message: 'Partition accounting does not match source row count.',
                severity: 'high',
                evidence: [
                    'source_rows' => $sourceRows,
                    'partitioned_rows' => $partitionedRows,
                    'source_slug_missing_count' => $sourceSlugMissingCount,
                    'duplicate_source_slug_count' => count($duplicateSourceSlugs),
                ],
            );
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function nextRequiredActions(
        bool $canonicalRolloutCanReachTarget,
        int $occupationMissingCount,
        int $cnProxyCount,
        int $softwareManualHoldCount,
    ): array {
        $actions = [];
        if (! $canonicalRolloutCanReachTarget) {
            $actions[] = 'DO_NOT_RUN_2786_CANONICAL_CANDIDATE_PREP';
        }
        if ($occupationMissingCount > 0) {
            $actions[] = '2786_OCCUPATION_ENTITY_REMEDIATION_1';
        }
        if ($cnProxyCount > 0) {
            $actions[] = 'CN_PROXY_AUTHORITY_POLICY_DECISION_1';
        }
        if ($softwareManualHoldCount > 0) {
            $actions[] = 'SOFTWARE_MANUAL_HOLD_FINAL_POLICY_DECISION_1';
        }
        $actions[] = '2786_READINESS_RERUN_AFTER_PARTITION_DECISIONS';

        return $actions;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizedStringList(array $values, string $context): array
    {
        if (! array_is_list($values)) {
            throw new RuntimeException($context.'_list_invalid');
        }

        $normalized = [];
        $seen = [];
        foreach ($values as $index => $value) {
            if (! is_string($value)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }
            $value = strtolower(trim($value));
            if ($value === '') {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }
            if (! isset($seen[$value])) {
                $seen[$value] = true;
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function normalizedSlugList(array $slugs, string $context): array
    {
        $normalized = [];
        $seen = [];
        foreach ($slugs as $index => $slug) {
            if (! is_string($slug)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }
            $slug = strtolower(trim($slug));
            if ($slug === '' || str_contains($slug, '*')) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }
            if (isset($seen[$slug])) {
                throw new RuntimeException($context.'_duplicate_'.$slug);
            }
            $seen[$slug] = true;
            $normalized[] = $slug;
        }

        return $normalized;
    }

    private function normalizedSlug(?string $slug): ?string
    {
        $slug = strtolower(trim((string) $slug));

        return $slug === '' || str_contains($slug, '*') ? null : $slug;
    }
}
