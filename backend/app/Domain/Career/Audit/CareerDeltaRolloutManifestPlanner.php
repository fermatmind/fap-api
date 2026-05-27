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
        ?string $target = null,
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
        $target = $this->target($target, $targetDeltaPlan, $targetPublicTotal);
        $blockers = $this->blockers(
            targetDeltaPlan: $targetDeltaPlan,
            candidatePrepPlan: $candidatePrepPlan,
            targetPublicTotal: $targetPublicTotal,
            expectedDeltaCount: $expectedDeltaCount,
            baselineSlugs: $baselineSlugs,
            deltaSlugs: $deltaSlugs,
            locales: $locales,
            target: $target,
        );
        $pass = $blockers === [];
        $targetAuthority = $this->targetAuthority($target, $locales);
        $members = array_map(fn (string $slug): array => [
            'slug' => $slug,
            'locales' => $locales,
            'source' => $target.'_target_delta',
            'baseline_included' => false,
            'reasons' => [],
            'sidecars' => [],
        ], $deltaSlugs);

        return new CareerDeltaRolloutManifestResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'target' => $target,
            'target_key' => $target,
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
            'target_authority' => $targetAuthority,
            'source_target_delta' => [
                'path' => $targetDeltaPath,
                'target_key' => $targetDeltaPlan['target_key'] ?? null,
                'schema_version' => $targetDeltaPlan['schema_version'] ?? null,
                'status' => $targetDeltaPlan['status'] ?? null,
                'current_public_total' => $targetDeltaPlan['current_public_total'] ?? null,
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
                'target' => $target,
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
            'next_required_action' => $pass ? $this->nextRequiredAction($target) : 'FIX_DELTA_ROLLOUT_MANIFEST_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     */
    private function target(?string $target, array $targetDeltaPlan, int $targetPublicTotal): string
    {
        $candidate = trim((string) ($target ?? ($targetDeltaPlan['target_key'] ?? ($targetDeltaPlan['target'] ?? ''))));
        if ($candidate === '') {
            $currentTotal = $targetDeltaPlan['current_public_total'] ?? null;
            if (is_numeric($currentTotal)) {
                $candidate = 'career_'.(int) $currentTotal.'_to_'.$targetPublicTotal.'_delta';
            } else {
                $candidate = $targetPublicTotal === 80 ? 'career_80_delta' : 'career_'.$targetPublicTotal.'_delta';
            }
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower($candidate)) ?? $candidate;

        return trim($normalized, '_') ?: 'career_80_delta';
    }

    private function nextRequiredAction(string $target): string
    {
        return match ($target) {
            'career_80_delta' => 'DELTA_ROLLOUT_DRY_RUN_51',
            CareerDetailReadyTargetAuthority::TARGET_KEY => 'DETAIL_READY_1048_ROLLOUT_GATE_DRY_RUN',
            default => 'PROGRESSIVE_ROLLOUT_DRY_RUN',
        };
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
        string $target,
    ): array {
        $blockers = [];

        if (($targetDeltaPlan['status'] ?? null) !== 'pass') {
            $blockers[] = $this->blocker('target_delta_not_passed', [
                'status' => $targetDeltaPlan['status'] ?? null,
            ]);
        }

        $targetDeltaBlockers = $this->nonEmptyList($targetDeltaPlan['blockers'] ?? []);
        if ($targetDeltaBlockers !== []) {
            $blockers[] = $this->blocker('target_delta_blockers_present', [
                'blocker_count' => count($targetDeltaBlockers),
                'blocker_reasons' => $this->blockerReasons($targetDeltaBlockers),
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

        if (in_array('software-developers', $deltaSlugs, true)) {
            $blockers[] = $this->blocker('software_developers_manual_hold_in_delta_manifest', [
                'slugs' => ['software-developers'],
            ]);
        }

        if (data_get($targetDeltaPlan, 'rollout.delta_manifest_allowed') !== true) {
            $blockers[] = $this->blocker('target_delta_manifest_not_allowed', [
                'delta_manifest_allowed' => data_get($targetDeltaPlan, 'rollout.delta_manifest_allowed'),
            ]);
        }

        if (data_get($targetDeltaPlan, 'rollout.apply_allowed') === true || ($targetDeltaPlan['apply_allowed'] ?? null) === true) {
            $blockers[] = $this->blocker('target_delta_apply_must_not_be_allowed', [
                'rollout_apply_allowed' => data_get($targetDeltaPlan, 'rollout.apply_allowed'),
                'apply_allowed' => $targetDeltaPlan['apply_allowed'] ?? null,
            ]);
        }

        foreach ($this->nonEmptyList(data_get($targetDeltaPlan, 'selection.rows', [])) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $reasons = $this->nonEmptyList($row['reasons'] ?? []);
            if (($row['source_ready'] ?? true) !== true || $reasons !== []) {
                $blockers[] = $this->blocker('target_delta_unready_selection_row', [
                    'row_index' => $index,
                    'slug' => $row['slug'] ?? null,
                    'source_ready' => $row['source_ready'] ?? null,
                    'reasons' => $reasons,
                ]);
            }
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

        if ($target === CareerDetailReadyTargetAuthority::TARGET_KEY) {
            array_push(
                $blockers,
                ...$this->detailReady1048Blockers($targetDeltaPlan, $candidatePrepPlan, $targetPublicTotal, $expectedDeltaCount, $baselineSlugs, $deltaSlugs, $locales)
            );
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
            ?? data_get($payload, 'ready_not_public_1018.slugs')
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

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function detailReady1048Blockers(
        array $targetDeltaPlan,
        ?array $candidatePrepPlan,
        int $targetPublicTotal,
        int $expectedDeltaCount,
        array $baselineSlugs,
        array $deltaSlugs,
        array $locales,
    ): array {
        $blockers = [];

        if (($targetDeltaPlan['target_key'] ?? null) !== CareerDetailReadyTargetAuthority::TARGET_KEY) {
            $blockers[] = $this->blocker('detail_ready_1048_target_key_missing', [
                'target_key' => $targetDeltaPlan['target_key'] ?? null,
            ]);
        }

        if ($targetPublicTotal !== CareerDetailReadyTargetAuthority::TARGET_PUBLIC_TOTAL) {
            $blockers[] = $this->blocker('detail_ready_1048_target_public_total_mismatch', [
                'expected' => CareerDetailReadyTargetAuthority::TARGET_PUBLIC_TOTAL,
                'actual' => $targetPublicTotal,
            ]);
        }

        if ($expectedDeltaCount !== CareerDetailReadyTargetAuthority::READY_NOT_PUBLIC_DELTA) {
            $blockers[] = $this->blocker('detail_ready_1048_expected_delta_count_mismatch', [
                'expected' => CareerDetailReadyTargetAuthority::READY_NOT_PUBLIC_DELTA,
                'actual' => $expectedDeltaCount,
            ]);
        }

        if (count($baselineSlugs) !== CareerDetailReadyTargetAuthority::CURRENT_PUBLIC_DETAIL_TOTAL) {
            $blockers[] = $this->blocker('detail_ready_1048_current_public_baseline_mismatch', [
                'expected' => CareerDetailReadyTargetAuthority::CURRENT_PUBLIC_DETAIL_TOTAL,
                'actual' => count($baselineSlugs),
            ]);
        }

        $declaredReadyNotPublicCount = data_get($targetDeltaPlan, 'ready_not_public_1018.count');
        if ($declaredReadyNotPublicCount !== null && (int) $declaredReadyNotPublicCount !== count($deltaSlugs)) {
            $blockers[] = $this->blocker('detail_ready_1048_ready_not_public_count_mismatch', [
                'declared' => $declaredReadyNotPublicCount,
                'actual' => count($deltaSlugs),
            ]);
        }

        $blockedSets = [
            'manual_hold' => $this->slugSetAtAny($targetDeltaPlan, [
                'manual_hold.ready_slugs',
                'manual_hold.slugs',
            ]),
            'review_needed' => $this->slugSetAtAny($targetDeltaPlan, [
                'review_needed.ready_slugs',
                'review_needed.slugs',
            ]),
            'family_handoff' => $this->slugSetAtAny($targetDeltaPlan, [
                'family_handoff.ready_slugs',
                'family_handoff.slugs',
            ]),
            'blocked' => $this->slugSetAtAny($targetDeltaPlan, [
                'blocked.ready_slugs',
                'blocked.slugs',
            ]),
            'cn_proxy' => $this->slugSetAtAny($targetDeltaPlan, [
                'cn_proxy.ready_slugs',
                'cn_proxy.slugs',
                'cn_proxy_policy_asset.slugs',
            ]),
        ];

        foreach ($blockedSets as $reason => $slugs) {
            $intersect = array_values(array_intersect($deltaSlugs, $slugs));
            if ($intersect !== []) {
                $blockers[] = $this->blocker('detail_ready_1048_delta_contains_'.$reason.'_slugs', [
                    'count' => count($intersect),
                    'sample_slugs' => array_slice($intersect, 0, 20),
                ]);
            }
        }

        $manualHoldPolicyIntersect = array_values(array_intersect($deltaSlugs, CareerDetailReadyTargetAuthority::MANUAL_HOLD_SLUGS));
        if ($manualHoldPolicyIntersect !== []) {
            $blockers[] = $this->blocker('detail_ready_1048_delta_contains_manual_hold_policy_slugs', [
                'count' => count($manualHoldPolicyIntersect),
                'sample_slugs' => array_slice($manualHoldPolicyIntersect, 0, 20),
            ]);
        }

        if ($candidatePrepPlan !== null) {
            if (($candidatePrepPlan['target'] ?? null) !== CareerDetailReadyTargetAuthority::TARGET_KEY) {
                $blockers[] = $this->blocker('detail_ready_1048_candidate_prep_target_mismatch', [
                    'target' => $candidatePrepPlan['target'] ?? null,
                ]);
            }

            if ((int) ($candidatePrepPlan['expected_delta_locale_rows'] ?? 0) !== count($deltaSlugs) * count($locales)) {
                $blockers[] = $this->blocker('detail_ready_1048_candidate_prep_locale_rows_mismatch', [
                    'expected' => count($deltaSlugs) * count($locales),
                    'actual' => $candidatePrepPlan['expected_delta_locale_rows'] ?? null,
                ]);
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function slugSetAtAny(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_array($value) && array_is_list($value)) {
                return $this->normalizedUniqueStrings($value, str_replace('.', '_', $path));
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function targetAuthority(string $target, array $locales): array
    {
        if ($target === CareerDetailReadyTargetAuthority::TARGET_KEY) {
            return (new CareerDetailReadyTargetAuthority)->target($locales);
        }

        return [
            'target_key' => $target,
            'candidate_prep_apply_allowed' => false,
            'rollout_apply_allowed' => false,
            'production_deploy_allowed' => false,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function nonEmptyList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== '' && $item !== []));
    }

    /**
     * @param  list<mixed>  $blockers
     * @return list<string>
     */
    private function blockerReasons(array $blockers): array
    {
        $reasons = [];
        foreach ($blockers as $blocker) {
            if (is_array($blocker) && is_scalar($blocker['reason'] ?? null)) {
                $reason = trim((string) $blocker['reason']);
                if ($reason !== '') {
                    $reasons[] = $reason;
                }
            } elseif (is_scalar($blocker)) {
                $reason = trim((string) $blocker);
                if ($reason !== '') {
                    $reasons[] = $reason;
                }
            }
        }

        return array_values(array_unique($reasons));
    }
}
