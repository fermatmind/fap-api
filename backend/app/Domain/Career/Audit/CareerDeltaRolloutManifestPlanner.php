<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerDeltaRolloutManifestPlanner
{
    public const SCHEMA_VERSION = 'career_delta_rollout_manifest.v1';

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @param  list<string>  $locales
     */
    public function plan(
        array $targetDeltaPlan,
        ?array $candidatePrepPlan = null,
        int $targetPublicTotal = 80,
        int $expectedDeltaCount = 51,
        array $locales = ['en', 'zh'],
        string $batchId = 'career_80_delta_canonical_001',
        ?string $targetDeltaPath = null,
        ?string $candidatePrepPlanPath = null,
    ): CareerDeltaRolloutManifestResult {
        if ($targetPublicTotal < 1) {
            throw new RuntimeException('target_public_total_invalid');
        }

        if ($expectedDeltaCount < 1) {
            throw new RuntimeException('expected_delta_count_invalid');
        }

        $batchId = trim($batchId);
        if ($batchId === '') {
            throw new RuntimeException('batch_id_missing');
        }

        $locales = $this->normalizedUniqueStrings($locales, 'locale');
        $baselineSlugs = $this->slugList($targetDeltaPlan, 'published_baseline_slugs', 'published_baseline_slug');
        $deltaSlugs = $this->deltaSlugs($targetDeltaPlan);
        $blockers = $this->blockers(
            targetDeltaPlan: $targetDeltaPlan,
            candidatePrepPlan: $candidatePrepPlan,
            targetPublicTotal: $targetPublicTotal,
            expectedDeltaCount: $expectedDeltaCount,
            baselineSlugs: $baselineSlugs,
            deltaSlugs: $deltaSlugs,
            locales: $locales,
        );
        $pass = $blockers === [];
        $members = array_map(static fn (string $slug): array => [
            'slug' => $slug,
            'locales' => $locales,
            'source' => 'career_80_delta_target_delta',
            'baseline_included' => false,
            'reasons' => [],
            'sidecars' => [],
        ], $deltaSlugs);

        return new CareerDeltaRolloutManifestResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'target' => 'career_80_delta',
            'target_public_total' => $targetPublicTotal,
            'published_baseline_count' => count($baselineSlugs),
            'delta_slug_count' => count($deltaSlugs),
            'selected_count' => count($deltaSlugs),
            'expected_delta_locale_rows' => count($deltaSlugs) * count($locales),
            'batch_id' => $batchId,
            'locales' => $locales,
            'published_baseline_slugs' => $baselineSlugs,
            'slugs' => $deltaSlugs,
            'rollback_group' => $deltaSlugs,
            'read_only' => true,
            'writes_database' => false,
            'rollout_allowed' => false,
            'dry_run_allowed' => $pass,
            'apply_allowed' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
            'source_target_delta' => [
                'path' => $targetDeltaPath,
                'schema_version' => $targetDeltaPlan['schema_version'] ?? null,
                'status' => $targetDeltaPlan['status'] ?? null,
                'target_public_total' => $targetDeltaPlan['target_public_total'] ?? null,
                'published_baseline_count' => $targetDeltaPlan['published_baseline_count'] ?? null,
                'delta_promotion_count' => $targetDeltaPlan['delta_promotion_count'] ?? null,
            ],
            'source_candidate_prep_plan' => [
                'path' => $candidatePrepPlanPath,
                'supplied' => $candidatePrepPlan !== null,
                'schema_version' => $candidatePrepPlan['schema_version'] ?? null,
                'status' => $candidatePrepPlan['status'] ?? null,
                'delta_slug_count' => $candidatePrepPlan['delta_slug_count'] ?? null,
            ],
            'validation' => [
                'target_accounting_count' => count($baselineSlugs) + count($deltaSlugs),
                'baseline_delta_overlap_count' => count(array_intersect($baselineSlugs, $deltaSlugs)),
                'rollback_group_type' => 'explicit_slug_list',
                'rollback_group_count' => count($deltaSlugs),
                'expected_delta_locale_rows' => count($deltaSlugs) * count($locales),
                'baseline_excluded_from_delta' => count(array_intersect($baselineSlugs, $deltaSlugs)) === 0,
            ],
            'batches' => [[
                'batch_id' => $batchId,
                'target' => 'career_80_delta',
                'target_public_total' => $targetPublicTotal,
                'published_baseline_count' => count($baselineSlugs),
                'delta_slug_count' => count($deltaSlugs),
                'slugs' => $deltaSlugs,
                'locales' => $locales,
                'expected_delta_locale_rows' => count($deltaSlugs) * count($locales),
                'rollback_group' => $deltaSlugs,
                'rollout_allowed' => false,
                'dry_run_allowed' => $pass,
                'apply_allowed' => false,
                'members' => $members,
            ]],
            'blockers' => $blockers,
            'sidecars' => [],
            'next_required_action' => $pass ? 'DELTA_ROLLOUT_DRY_RUN_51' : 'FIX_DELTA_ROLLOUT_MANIFEST_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function blockers(
        array $targetDeltaPlan,
        ?array $candidatePrepPlan,
        int $targetPublicTotal,
        int $expectedDeltaCount,
        array $baselineSlugs,
        array $deltaSlugs,
        array $locales,
    ): array {
        $blockers = [];

        if (($targetDeltaPlan['status'] ?? null) !== 'pass') {
            $blockers[] = $this->blocker('target_delta_not_passed', [
                'status' => $targetDeltaPlan['status'] ?? null,
            ]);
        }

        if ((int) ($targetDeltaPlan['target_public_total'] ?? 0) !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_public_total_mismatch', [
                'expected' => $targetPublicTotal,
                'actual' => $targetDeltaPlan['target_public_total'] ?? null,
            ]);
        }

        if (count($deltaSlugs) !== $expectedDeltaCount) {
            $blockers[] = $this->blocker('delta_slug_count_mismatch', [
                'expected' => $expectedDeltaCount,
                'actual' => count($deltaSlugs),
            ]);
        }

        if (count($baselineSlugs) + count($deltaSlugs) !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_accounting_mismatch', [
                'target_public_total' => $targetPublicTotal,
                'published_baseline_count' => count($baselineSlugs),
                'delta_slug_count' => count($deltaSlugs),
            ]);
        }

        $overlap = array_values(array_intersect($baselineSlugs, $deltaSlugs));
        if ($overlap !== []) {
            $blockers[] = $this->blocker('baseline_slug_in_delta_manifest', [
                'slugs' => $overlap,
            ]);
        }

        if (data_get($targetDeltaPlan, 'rollout.delta_manifest_allowed') !== true) {
            $blockers[] = $this->blocker('target_delta_manifest_not_allowed', [
                'delta_manifest_allowed' => data_get($targetDeltaPlan, 'rollout.delta_manifest_allowed'),
            ]);
        }

        if ($candidatePrepPlan !== null) {
            if (($candidatePrepPlan['status'] ?? null) !== 'planned') {
                $blockers[] = $this->blocker('candidate_prep_plan_not_planned', [
                    'status' => $candidatePrepPlan['status'] ?? null,
                ]);
            }

            if ((int) ($candidatePrepPlan['delta_slug_count'] ?? 0) !== count($deltaSlugs)) {
                $blockers[] = $this->blocker('candidate_prep_delta_count_mismatch', [
                    'manifest_delta_slug_count' => count($deltaSlugs),
                    'candidate_prep_delta_slug_count' => $candidatePrepPlan['delta_slug_count'] ?? null,
                ]);
            }
        }

        if ($locales === []) {
            $blockers[] = $this->blocker('locales_missing', []);
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function deltaSlugs(array $payload): array
    {
        $slugs = $payload['recommended_rollout_delta_slugs']
            ?? $payload['delta_promotion_slugs']
            ?? $payload['slugs']
            ?? null;

        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('delta_slug_list_missing');
        }

        return $this->normalizedUniqueStrings($slugs, 'delta_slug');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function slugList(array $payload, string $key, string $context): array
    {
        $slugs = $payload[$key] ?? null;
        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException($key.'_missing');
        }

        return $this->normalizedUniqueStrings($slugs, $context);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedUniqueStrings(array $values, string $context): array
    {
        $normalized = [];
        $seen = [];
        foreach ($values as $index => $value) {
            if (! is_string($value)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            $trimmed = strtolower(trim($value));
            if ($trimmed === '' || str_contains($trimmed, '*')) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            if (isset($seen[$trimmed])) {
                throw new RuntimeException($context.'_duplicate_'.$trimmed);
            }

            $seen[$trimmed] = true;
            $normalized[] = $trimmed;
        }

        sort($normalized);

        return $normalized;
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
