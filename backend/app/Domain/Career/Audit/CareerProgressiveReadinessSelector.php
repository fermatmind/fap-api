<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerProgressiveReadinessSelector
{
    public const SCHEMA_VERSION = 'career_progressive_readiness_selection.v1';

    /**
     * @param  list<string>  $currentPublicSlugs
     * @param  list<string>  $locales
     * @param  list<string>  $excludeSlugs
     * @param  list<string>|null  $occupationExistingSlugs
     */
    public function select(
        CareerPublicResolutionPlan $sourcePlan,
        array $currentCloseout,
        array $currentPublicSlugs,
        int $currentPublicTotal,
        int $targetPublicTotal,
        array $locales = ['en', 'zh'],
        array $excludeSlugs = [],
        ?array $occupationExistingSlugs = null,
        ?array $cnProxyPublicOwnerPlan = null,
        bool $strict = false,
    ): CareerProgressiveReadinessSelectionResult {
        $locales = $this->normalizedStringList($locales, 'locale');
        $baselineSlugs = $this->normalizedSlugList($currentPublicSlugs, 'baseline_slug');
        $excludeSlugs = $this->normalizedSlugList($excludeSlugs, 'exclude_slug');
        $occupationExistingSlugs = $occupationExistingSlugs === null
            ? null
            : $this->normalizedSlugList($occupationExistingSlugs, 'occupation_existing_slug');
        $baselineSet = array_fill_keys($baselineSlugs, true);
        $excludeSet = array_fill_keys($excludeSlugs, true);
        $occupationExistingSet = $occupationExistingSlugs === null ? [] : array_fill_keys($occupationExistingSlugs, true);
        $requiresOccupationExists = $occupationExistingSlugs !== null;
        $deltaNeeded = max(0, $targetPublicTotal - $currentPublicTotal);
        $issues = $this->initialIssues($currentCloseout, $baselineSlugs, $currentPublicTotal, $targetPublicTotal);
        $cnProxyPublicOwnerAuthority = $this->cnProxyPublicOwnerAuthority($cnProxyPublicOwnerPlan, $targetPublicTotal);
        foreach ($cnProxyPublicOwnerAuthority['issues'] as $issue) {
            $issues[] = $issue;
        }
        $publicOwnerDeltaCount = $cnProxyPublicOwnerAuthority['ready']
            ? min((int) $cnProxyPublicOwnerAuthority['public_owner_count'], $deltaNeeded)
            : 0;
        $canonicalDeltaNeeded = max(0, $deltaNeeded - $publicOwnerDeltaCount);
        $candidates = [];
        $selectedSlugs = [];
        $duplicateSourceSlugs = [];
        $seenSourceSlugs = [];
        $sourceReadyCount = 0;
        $selectableCandidateCount = 0;
        $occupationMissingExcludedCount = 0;
        $excludedByReason = [];

        foreach ($sourcePlan->rows as $position => $row) {
            $slug = $this->normalizedSlug($row->canonicalSlug);
            if ($slug === null) {
                $excludedByReason['source_slug_missing'] = ($excludedByReason['source_slug_missing'] ?? 0) + 1;

                continue;
            }

            if (isset($seenSourceSlugs[$slug])) {
                $duplicateSourceSlugs[] = $slug;
                $excludedByReason['duplicate_source_slug'] = ($excludedByReason['duplicate_source_slug'] ?? 0) + 1;

                continue;
            }
            $seenSourceSlugs[$slug] = true;

            $reasons = [];
            $baselineExcluded = isset($baselineSet[$slug]);
            if ($baselineExcluded) {
                $reasons[] = 'already_public_baseline';
            }
            if (isset($excludeSet[$slug])) {
                $reasons[] = 'explicitly_excluded';
            }

            $hardBlockers = $this->hardBlockerReasons($row);
            $reasons = [...$reasons, ...$hardBlockers];
            $sourceReady = $hardBlockers === [];
            if ($sourceReady && ! $baselineExcluded && ! isset($excludeSet[$slug])) {
                $sourceReadyCount++;
            }
            $occupationMissing = $requiresOccupationExists
                && $sourceReady
                && ! $baselineExcluded
                && ! isset($excludeSet[$slug])
                && ! isset($occupationExistingSet[$slug]);
            if ($occupationMissing) {
                $reasons[] = 'occupation_missing';
                $occupationMissingExcludedCount++;
            }
            if ($sourceReady && ! $baselineExcluded && ! isset($excludeSet[$slug]) && ! $occupationMissing) {
                $selectableCandidateCount++;
            }

            $selected = $sourceReady
                && ! $baselineExcluded
                && ! isset($excludeSet[$slug])
                && ! $occupationMissing
                && count($selectedSlugs) < $canonicalDeltaNeeded
                && $issues === [];

            if ($selected) {
                $selectedSlugs[] = $slug;
            }

            foreach ($reasons as $reason) {
                $excludedByReason[$reason] = ($excludedByReason[$reason] ?? 0) + 1;
            }

            $candidates[] = new CareerProgressiveReadinessCandidate(
                slug: $slug,
                sourcePosition: $position + 1,
                sourceRowNumber: $row->rowNumber,
                selected: $selected,
                baselineExcluded: $baselineExcluded,
                sourceReady: $sourceReady,
                locales: $row->locales === [] ? $locales : $row->locales,
                reasons: array_values(array_unique($reasons)),
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

        if (count($selectedSlugs) < $canonicalDeltaNeeded) {
            $finalPublicAccountedCount = $currentPublicTotal + count($selectedSlugs) + $publicOwnerDeltaCount;
            $reason = $publicOwnerDeltaCount > 0
                ? 'insufficient_final_public_partition_authority'
                : 'insufficient_source_ready_delta_slugs';
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: $reason,
                message: $publicOwnerDeltaCount > 0
                    ? 'Final 2786 public accounting still has an unresolved partition shortfall after CN proxy public-owner evidence.'
                    : 'Fewer than the required progressive delta source-ready slugs are available.',
                severity: 'blocker_for_publication',
                evidence: [
                    'required_delta_slug_count' => $deltaNeeded,
                    'required_canonical_delta_slug_count' => $canonicalDeltaNeeded,
                    'public_owner_delta_slug_count' => $publicOwnerDeltaCount,
                    'selected_count' => count($selectedSlugs),
                    'final_public_accounted_count' => $finalPublicAccountedCount,
                    'final_public_shortfall' => max(0, $targetPublicTotal - $finalPublicAccountedCount),
                    'source_ready_delta_count' => $sourceReadyCount,
                    'selectable_candidate_count' => $selectableCandidateCount,
                    'occupation_missing_excluded_count' => $occupationMissingExcludedCount,
                ],
            );
        }

        if ($strict && $sourcePlan->rows === []) {
            throw new RuntimeException('source_plan_rows_missing');
        }

        ksort($excludedByReason);
        $targetSlugs = [...$baselineSlugs, ...$selectedSlugs];
        $status = $issues === [] ? 'pass' : 'blocked';
        $finalPublicAccountedCount = $currentPublicTotal + count($selectedSlugs) + $publicOwnerDeltaCount;
        $finalPublicShortfall = max(0, $targetPublicTotal - $finalPublicAccountedCount);
        $selectionStrategy = $publicOwnerDeltaCount > 0
            ? 'progressive_source_ready_after_closeout_baseline_with_public_owner_partition'
            : 'progressive_source_ready_after_closeout_baseline';

        return new CareerProgressiveReadinessSelectionResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'readiness_pass' => $status === 'pass',
            'read_only' => true,
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'current_public_total' => $currentPublicTotal,
            'target_public_total' => $targetPublicTotal,
            'baseline_count' => count($baselineSlugs),
            'delta_slug_count' => $deltaNeeded,
            'canonical_delta_slug_count' => $canonicalDeltaNeeded,
            'public_owner_delta_slug_count' => $publicOwnerDeltaCount,
            'selected_count' => count($selectedSlugs),
            'candidate_count' => $selectableCandidateCount,
            'source_ready_count' => $sourceReadyCount,
            'locale_count' => count($locales),
            'locales' => $locales,
            'expected_delta_locale_rows' => $deltaNeeded * count($locales),
            'expected_canonical_delta_locale_rows' => $canonicalDeltaNeeded * count($locales),
            'expected_public_owner_locale_rows' => $publicOwnerDeltaCount * count($locales),
            'expected_total_locale_rows' => $targetPublicTotal * count($locales),
            'final_public_accounted_count' => $finalPublicAccountedCount,
            'final_public_shortfall' => $finalPublicShortfall,
            'baseline_slugs' => $baselineSlugs,
            'selected_slugs' => $selectedSlugs,
            'delta_promotion_slugs' => $selectedSlugs,
            'canonical_rollout_slugs' => $selectedSlugs,
            'target_public_slugs' => $targetSlugs,
            'selection' => [
                'strategy' => $selectionStrategy,
                'slugs' => $targetSlugs,
                'delta_slugs' => $selectedSlugs,
                'canonical_delta_slugs' => $selectedSlugs,
                'rows' => array_map(
                    static fn (CareerProgressiveReadinessCandidate $candidate): array => $candidate->toArray(),
                    array_values(array_filter(
                        $candidates,
                        static fn (CareerProgressiveReadinessCandidate $candidate): bool => $candidate->selected,
                    )),
                ),
            ],
            'source_plan' => [
                'source_path' => $sourcePlan->sourcePath,
                'checksum' => $sourcePlan->checksum,
                'row_count' => count($sourcePlan->rows),
            ],
            'entity_context' => [
                'required_for_selection' => $requiresOccupationExists,
                'occupation_exists_count' => $occupationExistingSlugs === null ? null : count($occupationExistingSlugs),
                'occupation_missing_excluded_count' => $occupationMissingExcludedCount,
            ],
            'cn_proxy_public_owner_plan' => $cnProxyPublicOwnerAuthority['summary'],
            'excluded' => [
                'excluded_by_reason' => $excludedByReason,
                'baseline_excluded_count' => count($baselineSlugs),
            ],
            'blockers' => array_map(
                static fn (CareerProgressiveReadinessSelectionIssue $issue): array => $issue->toArray(),
                $issues,
            ),
            'sidecars' => [],
            'next_required_action' => $status === 'pass'
                ? 'PROGRESSIVE_RUNTIME_CANDIDATE_PREP'
                : 'FIX_PROGRESSIVE_READINESS_SELECTION_BLOCKERS',
        ]);
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

    /**
     * @param  list<string>  $baselineSlugs
     * @return list<CareerProgressiveReadinessSelectionIssue>
     */
    private function initialIssues(array $currentCloseout, array $baselineSlugs, int $currentPublicTotal, int $targetPublicTotal): array
    {
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

        if (! in_array($targetPublicTotal, [300, 800, 2786], true)) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'target_public_total_unsupported',
                message: 'Progressive readiness selection supports only target totals 300, 800, and 2786.',
                severity: 'high',
                evidence: ['target_public_total' => $targetPublicTotal],
            );
        }

        if ($targetPublicTotal <= $currentPublicTotal) {
            $issues[] = new CareerProgressiveReadinessSelectionIssue(
                reason: 'target_not_greater_than_current',
                message: 'Target public total must be greater than current public total.',
                severity: 'high',
                evidence: [
                    'current_public_total' => $currentPublicTotal,
                    'target_public_total' => $targetPublicTotal,
                ],
            );
        }

        return $issues;
    }

    /**
     * @return array{ready: bool, public_owner_count: int, issues: list<CareerProgressiveReadinessSelectionIssue>, summary: array<string, mixed>}
     */
    private function cnProxyPublicOwnerAuthority(?array $plan, int $targetPublicTotal): array
    {
        $summary = [
            'provided' => $plan !== null,
            'ready' => false,
            'public_owner_count' => 0,
            'source_path' => $plan['source_path'] ?? null,
            'guarded_public_owner_state' => $plan['guarded_public_owner_state'] ?? null,
        ];

        if ($plan === null) {
            return [
                'ready' => false,
                'public_owner_count' => 0,
                'issues' => [],
                'summary' => $summary,
            ];
        }

        if ($targetPublicTotal !== 2786) {
            $issue = new CareerProgressiveReadinessSelectionIssue(
                reason: 'cn_proxy_public_owner_plan_target_scope_invalid',
                message: 'CN proxy public-owner partition evidence is valid only for the final 2786 readiness target.',
                severity: 'high',
                evidence: ['target_public_total' => $targetPublicTotal],
            );

            return [
                'ready' => false,
                'public_owner_count' => 0,
                'issues' => [$issue],
                'summary' => $summary,
            ];
        }

        $count = (int) ($plan['public_cn_proxy_page_rows'] ?? $plan['cn_proxy_rows'] ?? 0);
        $required = [
            'status' => ($plan['status'] ?? null) === 'validated',
            'dry_run' => ($plan['dry_run'] ?? null) === true,
            'did_write' => ($plan['did_write'] ?? null) === false,
            'reviewed_trust_manifest_complete' => ($plan['reviewed_trust_manifest_complete'] ?? null) === true,
            'public_owner_plan_ready' => ($plan['public_owner_plan_ready'] ?? null) === true,
            'route_owner_enabled' => ($plan['route_owner_enabled'] ?? null) === false,
            'public_route_allowed' => ($plan['public_route_allowed'] ?? null) === false,
            'public_pages_exposed' => (int) ($plan['public_pages_exposed'] ?? -1) === 0,
            'noindex_default' => ($plan['noindex_default'] ?? null) === true,
            'indexable_CN_proxy_rows' => (int) ($plan['indexable_CN_proxy_rows'] ?? -1) === 0,
            'sitemap_CN_urls' => (int) ($plan['sitemap_CN_urls'] ?? -1) === 0,
            'llms_CN_urls' => (int) ($plan['llms_CN_urls'] ?? -1) === 0,
            'llms_full_CN_urls' => (int) ($plan['llms_full_CN_urls'] ?? -1) === 0,
            'blockers_empty' => ($plan['blockers'] ?? []) === [],
            'public_owner_count_positive' => $count > 0,
        ];
        $failed = array_keys(array_filter($required, static fn (bool $passed): bool => ! $passed));
        $summary = [
            ...$summary,
            'ready' => $failed === [],
            'public_owner_count' => $failed === [] ? $count : 0,
            'declared_public_owner_count' => $count,
            'failed_requirements' => $failed,
        ];

        if ($failed === []) {
            return [
                'ready' => true,
                'public_owner_count' => $count,
                'issues' => [],
                'summary' => $summary,
            ];
        }

        $issue = new CareerProgressiveReadinessSelectionIssue(
            reason: 'cn_proxy_public_owner_plan_invalid',
            message: 'CN proxy public-owner plan cannot be used for final 2786 partition accounting.',
            severity: 'blocker_for_publication',
            evidence: [
                'failed_requirements' => $failed,
                'declared_public_owner_count' => $count,
            ],
        );

        return [
            'ready' => false,
            'public_owner_count' => 0,
            'issues' => [$issue],
            'summary' => $summary,
        ];
    }

    /**
     * @return list<string>
     */
    private function hardBlockerReasons(CareerPublicResolutionPlanRow $row): array
    {
        $reasons = [];
        $slug = strtolower(trim((string) $row->canonicalSlug));
        if ($slug === 'software-developers') {
            $reasons[] = 'software_developers_manual_hold_excluded_from_canonical_rollout';
        }

        if (str_starts_with($slug, 'cn-')) {
            $reasons[] = 'cn_proxy_excluded_from_canonical_rollout';
        }

        $state = strtolower(trim((string) $row->publicResolutionState));
        if (in_array($state, [
            'occupation_missing',
            'missing',
            'blocked',
            'hard_blocked',
            'do_not_publish',
            'not_public',
            'excluded',
            'cn_proxy_hold',
            'blocked_until_cn_authority_policy',
        ], true)) {
            $reasons[] = 'source_state_'.$state;
        }

        $publicType = strtolower(trim((string) $row->canonicalPublicType));
        if (in_array($publicType, ['non_public', 'private', 'excluded'], true)) {
            $reasons[] = 'source_public_type_'.$publicType;
        }
        if (in_array($publicType, ['public_cn_proxy_page', 'public_cn_proxy_page_candidate'], true)) {
            $reasons[] = 'cn_proxy_excluded_from_canonical_rollout';
        }

        foreach (['occupation_missing', 'missing_occupation', 'hard_blocked', 'safety_blocked'] as $key) {
            if (($row->raw[$key] ?? false) === true) {
                $reasons[] = $key;
            }
        }

        $recommendedResolution = strtolower(trim((string) ($row->raw['recommended_resolution'] ?? '')));
        if (in_array($recommendedResolution, ['public_cn_proxy_page', 'public_cn_proxy_page_candidate'], true)) {
            $reasons[] = 'cn_proxy_excluded_from_canonical_rollout';
        }

        foreach (['hard_blockers', 'safety_blockers'] as $key) {
            if (isset($row->raw[$key]) && is_array($row->raw[$key]) && $row->raw[$key] !== []) {
                $reasons[] = $key;
            }
        }

        return array_values(array_unique($reasons));
    }
}
