<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerDeltaRolloutGatePlanner
{
    public const SCHEMA_VERSION = 'career_delta_rollout_gate.v1';

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function plan(
        array $manifest,
        int $targetPublicTotal = 80,
        int $expectedDeltaCount = 51,
        ?string $manifestPath = null,
        ?string $target = null,
    ): CareerDeltaRolloutGateResult {
        if ($targetPublicTotal < 1) {
            throw new RuntimeException('target_public_total_invalid');
        }

        if ($expectedDeltaCount < 1) {
            throw new RuntimeException('expected_delta_count_invalid');
        }

        $baselineSlugs = $this->slugList($manifest, 'published_baseline_slugs', required: true);
        $deltaSlugs = $this->slugList($manifest, 'slugs', required: true);
        $rollbackGroup = $this->slugList($manifest, 'rollback_group', required: true);
        $locales = $this->stringList($manifest['locales'] ?? [], 'locale');
        $batchId = trim((string) ($manifest['batch_id'] ?? ''));
        $target = $this->target($target, $manifest, $targetPublicTotal);
        $expectedRows = count($deltaSlugs) * count($locales);
        $blockers = $this->blockers(
            manifest: $manifest,
            targetPublicTotal: $targetPublicTotal,
            expectedDeltaCount: $expectedDeltaCount,
            baselineSlugs: $baselineSlugs,
            deltaSlugs: $deltaSlugs,
            rollbackGroup: $rollbackGroup,
            locales: $locales,
            batchId: $batchId,
            expectedRows: $expectedRows,
            target: $target,
        );
        $pass = $blockers === [];
        $targetAuthority = $this->targetAuthority($target, $locales);

        return new CareerDeltaRolloutGateResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'target' => $target,
            'target_key' => $target,
            'read_only' => true,
            'writes_database' => false,
            'target_public_total' => $targetPublicTotal,
            'published_baseline_count' => count($baselineSlugs),
            'delta_slug_count' => count($deltaSlugs),
            'expected_delta_locale_rows' => $expectedRows,
            'batch_id' => $batchId,
            'locales' => $locales,
            'published_baseline_slugs' => $baselineSlugs,
            'delta_slugs' => $deltaSlugs,
            'rollback_group' => $rollbackGroup,
            'source_manifest' => [
                'path' => $manifestPath,
                'schema_version' => $manifest['schema_version'] ?? null,
                'status' => $manifest['status'] ?? null,
                'dry_run_allowed' => $manifest['dry_run_allowed'] ?? null,
                'apply_allowed' => $manifest['apply_allowed'] ?? null,
            ],
            'target_authority' => $targetAuthority,
            'validation' => [
                'target_accounting_count' => count($baselineSlugs) + count($deltaSlugs),
                'baseline_delta_overlap_count' => count(array_intersect($baselineSlugs, $deltaSlugs)),
                'rollback_group_type' => 'explicit_delta_slug_list',
                'rollback_group_count' => count($rollbackGroup),
                'rollback_group_matches_delta' => $rollbackGroup === $deltaSlugs,
                'expected_delta_locale_rows' => $expectedRows,
                'baseline_excluded_from_delta' => count(array_intersect($baselineSlugs, $deltaSlugs)) === 0,
                'promotion_scope' => 'delta_only',
                'total_public_target_validated' => count($baselineSlugs) + count($deltaSlugs) === $targetPublicTotal,
            ],
            'future_rollout_dry_run' => [
                'allowed' => $pass,
                'command' => 'career:execute-canonical-rollout-batch',
                'batch_id' => $batchId,
                'slugs' => $deltaSlugs,
                'slugs_csv' => implode(',', $deltaSlugs),
                'locales' => $locales,
                'locales_csv' => implode(',', $locales),
                'rollback_group' => $rollbackGroup,
                'rollback_group_csv' => implode(',', $rollbackGroup),
                'dry_run_required' => true,
                'apply_allowed' => false,
                'writes_database' => false,
            ],
            'apply_allowed' => false,
            'rollout_apply_allowed' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
            'blockers' => $blockers,
            'sidecars' => [],
            'next_required_action' => $pass ? $this->nextRequiredAction($target) : 'FIX_DELTA_ROLLOUT_GATE_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function target(?string $target, array $manifest, int $targetPublicTotal): string
    {
        $candidate = trim((string) ($target ?? ($manifest['target_key'] ?? ($manifest['target'] ?? ''))));
        if ($candidate === '') {
            $candidate = $targetPublicTotal === 80 ? 'career_80_delta' : 'career_'.$targetPublicTotal.'_delta';
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower($candidate)) ?? $candidate;

        return trim($normalized, '_') ?: 'career_80_delta';
    }

    private function nextRequiredAction(string $target): string
    {
        return match ($target) {
            'career_80_delta' => 'DELTA_ROLLOUT_DRY_RUN_51',
            CareerDetailReadyTargetAuthority::TARGET_KEY => 'DETAIL_READY_1048_ROLLOUT_DRY_RUN',
            default => 'PROGRESSIVE_ROLLOUT_DRY_RUN',
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $rollbackGroup
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function blockers(
        array $manifest,
        int $targetPublicTotal,
        int $expectedDeltaCount,
        array $baselineSlugs,
        array $deltaSlugs,
        array $rollbackGroup,
        array $locales,
        string $batchId,
        int $expectedRows,
        string $target,
    ): array {
        $blockers = [];

        if (($manifest['schema_version'] ?? null) !== CareerDeltaRolloutManifestPlanner::SCHEMA_VERSION) {
            $blockers[] = $this->blocker('delta_manifest_schema_mismatch', [
                'schema_version' => $manifest['schema_version'] ?? null,
            ]);
        }

        if (($manifest['status'] ?? null) !== 'pass') {
            $blockers[] = $this->blocker('delta_manifest_not_passed', [
                'status' => $manifest['status'] ?? null,
            ]);
        }

        if ($batchId === '') {
            $blockers[] = $this->blocker('batch_id_missing', []);
        }

        if ((int) ($manifest['target_public_total'] ?? 0) !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_public_total_mismatch', [
                'expected' => $targetPublicTotal,
                'actual' => $manifest['target_public_total'] ?? null,
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
            $blockers[] = $this->blocker('baseline_slug_in_delta_rollout', [
                'slugs' => $overlap,
            ]);
        }

        if ($rollbackGroup === []) {
            $blockers[] = $this->blocker('rollback_group_missing', []);
        } elseif ($rollbackGroup !== $deltaSlugs) {
            $blockers[] = $this->blocker('rollback_group_must_match_delta_slugs', [
                'delta_slugs' => $deltaSlugs,
                'rollback_group' => $rollbackGroup,
            ]);
        }

        if ($locales === []) {
            $blockers[] = $this->blocker('locales_missing', []);
        }

        if ((int) ($manifest['expected_delta_locale_rows'] ?? 0) !== $expectedRows) {
            $blockers[] = $this->blocker('expected_delta_locale_rows_mismatch', [
                'expected' => $expectedRows,
                'actual' => $manifest['expected_delta_locale_rows'] ?? null,
            ]);
        }

        if (($manifest['dry_run_allowed'] ?? null) !== true) {
            $blockers[] = $this->blocker('delta_manifest_dry_run_not_allowed', [
                'dry_run_allowed' => $manifest['dry_run_allowed'] ?? null,
            ]);
        }

        if (($manifest['apply_allowed'] ?? null) !== false) {
            $blockers[] = $this->blocker('delta_manifest_apply_must_remain_false', [
                'apply_allowed' => $manifest['apply_allowed'] ?? null,
            ]);
        }

        if ($target === CareerDetailReadyTargetAuthority::TARGET_KEY) {
            array_push(
                $blockers,
                ...$this->detailReady1048Blockers($manifest, $targetPublicTotal, $expectedDeltaCount, $baselineSlugs, $deltaSlugs, $rollbackGroup, $locales)
            );
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function slugList(array $payload, string $key, bool $required): array
    {
        $values = $payload[$key] ?? null;
        if (! is_array($values) || ! array_is_list($values)) {
            if ($required) {
                throw new RuntimeException($key.'_missing');
            }

            return [];
        }

        return $this->stringList($values, $key);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values, string $context): array
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
     * @return array{reason: string, context: array<string, mixed>}
     */
    private function blocker(string $reason, array $context): array
    {
        return [
            'reason' => $reason,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $rollbackGroup
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function detailReady1048Blockers(
        array $manifest,
        int $targetPublicTotal,
        int $expectedDeltaCount,
        array $baselineSlugs,
        array $deltaSlugs,
        array $rollbackGroup,
        array $locales,
    ): array {
        $blockers = [];

        if (($manifest['target_key'] ?? $manifest['target'] ?? null) !== CareerDetailReadyTargetAuthority::TARGET_KEY) {
            $blockers[] = $this->blocker('detail_ready_1048_manifest_target_mismatch', [
                'target_key' => $manifest['target_key'] ?? null,
                'target' => $manifest['target'] ?? null,
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

        $manualHoldIntersect = array_values(array_intersect($deltaSlugs, CareerDetailReadyTargetAuthority::MANUAL_HOLD_SLUGS));
        if ($manualHoldIntersect !== []) {
            $blockers[] = $this->blocker('detail_ready_1048_delta_contains_manual_hold_policy_slugs', [
                'count' => count($manualHoldIntersect),
                'sample_slugs' => array_slice($manualHoldIntersect, 0, 20),
            ]);
        }

        $rollbackManualHoldIntersect = array_values(array_intersect($rollbackGroup, CareerDetailReadyTargetAuthority::MANUAL_HOLD_SLUGS));
        if ($rollbackManualHoldIntersect !== []) {
            $blockers[] = $this->blocker('detail_ready_1048_rollback_group_contains_manual_hold_policy_slugs', [
                'count' => count($rollbackManualHoldIntersect),
                'sample_slugs' => array_slice($rollbackManualHoldIntersect, 0, 20),
            ]);
        }

        foreach ($this->manifestMembers($manifest) as $index => $row) {
            $slug = $row['slug'] ?? null;
            if (! is_string($slug)) {
                continue;
            }

            $reasons = $this->nonEmptyList($row['reasons'] ?? []);
            $sidecars = $this->nonEmptyList($row['sidecars'] ?? []);
            if (($row['source_ready'] ?? true) !== true || $reasons !== [] || $sidecars !== []) {
                $blockers[] = $this->blocker('detail_ready_1048_unready_manifest_member', [
                    'row_index' => $index,
                    'slug' => $slug,
                    'source_ready' => $row['source_ready'] ?? null,
                    'reasons' => $reasons,
                    'sidecars' => $sidecars,
                ]);
            }

            if ($this->isCnProxyRow($row)) {
                $blockers[] = $this->blocker('detail_ready_1048_cn_proxy_manifest_member_forbidden', [
                    'row_index' => $index,
                    'slug' => $slug,
                    'public_resolution_type' => $row['public_resolution_type'] ?? null,
                    'canonical_public_type' => $row['canonical_public_type'] ?? null,
                    'current_status' => $row['current_status'] ?? null,
                ]);
            }
        }

        $candidatePrep = $manifest['source_candidate_prep_plan'] ?? null;
        if (is_array($candidatePrep) && ! array_is_list($candidatePrep)) {
            if (($candidatePrep['status'] ?? null) !== 'planned') {
                $blockers[] = $this->blocker('detail_ready_1048_candidate_prep_not_planned', [
                    'status' => $candidatePrep['status'] ?? null,
                ]);
            }

            if (($candidatePrep['delta_slug_count'] ?? null) !== null && (int) $candidatePrep['delta_slug_count'] !== count($deltaSlugs)) {
                $blockers[] = $this->blocker('detail_ready_1048_candidate_prep_delta_count_mismatch', [
                    'expected' => count($deltaSlugs),
                    'actual' => $candidatePrep['delta_slug_count'] ?? null,
                ]);
            }
        }

        $expectedLocaleRows = CareerDetailReadyTargetAuthority::READY_NOT_PUBLIC_DELTA * count($locales);
        if ($expectedRows = (int) ($manifest['expected_delta_locale_rows'] ?? 0)) {
            if ($expectedRows !== $expectedLocaleRows) {
                $blockers[] = $this->blocker('detail_ready_1048_expected_locale_rows_mismatch', [
                    'expected' => $expectedLocaleRows,
                    'actual' => $expectedRows,
                ]);
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array<string, mixed>>
     */
    private function manifestMembers(array $manifest): array
    {
        $members = data_get($manifest, 'batches.0.members', []);
        if (! is_array($members) || ! array_is_list($members)) {
            return [];
        }

        return array_values(array_filter($members, static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isCnProxyRow(array $row): bool
    {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if (str_starts_with($slug, 'cn-')) {
            return true;
        }

        foreach (['public_resolution_type', 'canonical_public_type', 'current_status', 'source'] as $key) {
            $value = strtolower((string) ($row[$key] ?? ''));
            if (str_contains($value, 'cn_proxy') || str_contains($value, 'public_cn_proxy_page')) {
                return true;
            }
        }

        return false;
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
}
