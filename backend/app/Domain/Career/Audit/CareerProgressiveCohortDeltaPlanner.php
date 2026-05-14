<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerProgressiveCohortDeltaPlanner
{
    public const SCHEMA_VERSION = 'career_progressive_cohort_delta_plan.v1';

    /**
     * @param  array<string, mixed>  $currentCloseout
     * @param  list<string>  $currentPublicSlugs
     * @param  array<string, mixed>  $targetSelection
     * @param  list<string>  $locales
     */
    public function plan(
        array $currentCloseout,
        array $currentPublicSlugs,
        array $targetSelection,
        int $targetPublicTotal,
        array $locales = ['en', 'zh'],
    ): CareerProgressiveCohortDeltaPlanResult {
        if ($targetPublicTotal < 1) {
            throw new RuntimeException('target_public_total_invalid');
        }

        $locales = $this->normalizedLocales($locales);
        $currentPublicSlugs = $this->normalizedUniqueSlugs($currentPublicSlugs, 'current_public_slug');
        $targetSlugs = $this->targetSlugs($targetSelection);
        $deltaSlugs = array_values(array_diff($targetSlugs, $currentPublicSlugs));
        sort($deltaSlugs);
        $missingCurrentFromTarget = array_values(array_diff($currentPublicSlugs, $targetSlugs));
        sort($missingCurrentFromTarget);
        $overlap = array_values(array_intersect($currentPublicSlugs, $deltaSlugs));
        sort($overlap);

        $blockers = $this->blockers(
            currentCloseout: $currentCloseout,
            currentPublicSlugs: $currentPublicSlugs,
            targetSlugs: $targetSlugs,
            targetPublicTotal: $targetPublicTotal,
            deltaSlugs: $deltaSlugs,
            missingCurrentFromTarget: $missingCurrentFromTarget,
            overlap: $overlap,
        );
        $pass = $blockers === [];

        return new CareerProgressiveCohortDeltaPlanResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'read_only' => true,
            'writes_database' => false,
            'current_public_total' => count($currentPublicSlugs),
            'target_public_total' => $targetPublicTotal,
            'delta_slug_count' => count($deltaSlugs),
            'locale_count' => count($locales),
            'locales' => $locales,
            'expected_delta_locale_rows' => count($deltaSlugs) * count($locales),
            'expected_total_locale_rows' => $targetPublicTotal * count($locales),
            'current_public_slugs' => $currentPublicSlugs,
            'target_public_slugs' => $targetSlugs,
            'delta_promotion_slugs' => $deltaSlugs,
            'recommended_rollout_delta_slugs' => $deltaSlugs,
            'validation' => [
                'current_plus_delta_count' => count($currentPublicSlugs) + count($deltaSlugs),
                'target_selection_count' => count($targetSlugs),
                'current_target_overlap_count' => count(array_intersect($currentPublicSlugs, $targetSlugs)),
                'current_missing_from_target_count' => count($missingCurrentFromTarget),
                'current_delta_overlap_count' => count($overlap),
                'target_total_validated' => count($targetSlugs) === $targetPublicTotal,
                'delta_count_validated' => count($deltaSlugs) === ($targetPublicTotal - count($currentPublicSlugs)),
            ],
            'blockers' => $blockers,
            'rollout' => [
                'delta_manifest_allowed' => $pass,
                'rollout_dry_run_allowed' => false,
                'apply_allowed' => false,
                'reason' => 'progressive target-delta planning only; rollout dry-run and apply require separate gates',
            ],
            'next_required_action' => $pass ? 'RUNTIME_CANDIDATE_PREP_PLAN_READ_ONLY' : 'FIX_PROGRESSIVE_COHORT_DELTA_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return list<string>
     */
    private function targetSlugs(array $selection): array
    {
        $slugs = data_get($selection, 'selection.slugs');
        if (! is_array($slugs)) {
            $slugs = $selection['selected_slugs'] ?? $selection['slugs'] ?? null;
        }

        if (! is_array($slugs)) {
            $rows = $selection['rows'] ?? $selection['candidates'] ?? null;
            if (is_array($rows)) {
                $slugs = [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (($row['selected'] ?? true) === false && ($row['eligible'] ?? true) === false) {
                        continue;
                    }
                    $slugs[] = $row['slug'] ?? null;
                }
            }
        }

        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('target_selection_slugs_missing');
        }

        return $this->normalizedUniqueSlugs($slugs, 'target_selection_slug');
    }

    /**
     * @param  list<mixed>  $slugs
     * @return list<string>
     */
    private function normalizedUniqueSlugs(array $slugs, string $context): array
    {
        $normalized = [];
        $seen = [];
        foreach ($slugs as $index => $slug) {
            if (! is_string($slug)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            $value = strtolower(trim($slug));
            if ($value === '' || str_contains($value, '*')) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            if (isset($seen[$value])) {
                throw new RuntimeException($context.'_duplicate_'.$value);
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function normalizedLocales(array $locales): array
    {
        $normalized = [];
        $seen = [];
        foreach ($locales as $index => $locale) {
            $value = strtolower(trim($locale));
            if ($value === '') {
                throw new RuntimeException('locale_invalid_at_'.$index);
            }
            if (isset($seen[$value])) {
                throw new RuntimeException('locale_duplicate_'.$value);
            }
            $seen[$value] = true;
            $normalized[] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $currentCloseout
     * @param  list<string>  $currentPublicSlugs
     * @param  list<string>  $targetSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $missingCurrentFromTarget
     * @param  list<string>  $overlap
     * @return list<array<string, mixed>>
     */
    private function blockers(
        array $currentCloseout,
        array $currentPublicSlugs,
        array $targetSlugs,
        int $targetPublicTotal,
        array $deltaSlugs,
        array $missingCurrentFromTarget,
        array $overlap,
    ): array {
        $blockers = [];

        if (($currentCloseout['status'] ?? null) !== 'complete' || ($currentCloseout['accepted'] ?? false) !== true) {
            $blockers[] = $this->blocker('current_closeout_not_accepted', [
                'status' => $currentCloseout['status'] ?? null,
                'accepted' => $currentCloseout['accepted'] ?? null,
            ]);
        }

        $declaredCurrentTotal = $currentCloseout['total_slug_count'] ?? $currentCloseout['target_public_total'] ?? null;
        if ($declaredCurrentTotal !== null && (int) $declaredCurrentTotal !== count($currentPublicSlugs)) {
            $blockers[] = $this->blocker('current_public_total_mismatch', [
                'declared' => $declaredCurrentTotal,
                'actual' => count($currentPublicSlugs),
            ]);
        }

        if ($targetPublicTotal <= count($currentPublicSlugs)) {
            $blockers[] = $this->blocker('target_not_greater_than_current', [
                'current_public_total' => count($currentPublicSlugs),
                'target_public_total' => $targetPublicTotal,
            ]);
        }

        if (count($targetSlugs) !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_selection_count_mismatch', [
                'expected' => $targetPublicTotal,
                'actual' => count($targetSlugs),
            ]);
        }

        if ($missingCurrentFromTarget !== []) {
            $blockers[] = $this->blocker('current_public_missing_from_target_selection', [
                'slugs' => $missingCurrentFromTarget,
            ]);
        }

        if ($overlap !== []) {
            $blockers[] = $this->blocker('current_delta_overlap', [
                'slugs' => $overlap,
            ]);
        }

        if (count($deltaSlugs) !== $targetPublicTotal - count($currentPublicSlugs)) {
            $blockers[] = $this->blocker('delta_count_mismatch', [
                'expected' => $targetPublicTotal - count($currentPublicSlugs),
                'actual' => count($deltaSlugs),
            ]);
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function blocker(string $reason, array $evidence): array
    {
        return [
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
