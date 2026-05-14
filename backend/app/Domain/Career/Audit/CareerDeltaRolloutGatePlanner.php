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
        );
        $pass = $blockers === [];

        return new CareerDeltaRolloutGateResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'target' => 'career_80_delta',
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
            'next_required_action' => $pass ? 'DELTA_ROLLOUT_DRY_RUN_51' : 'FIX_DELTA_ROLLOUT_GATE_BLOCKERS',
        ]);
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
}
