<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class Career80TotalLiveAcceptancePlanner
{
    public const SCHEMA_VERSION = 'career_80_total_live_acceptance.v1';

    /**
     * @param  array<string, mixed>  $targetDelta
     * @param  array<string, mixed>|null  $deltaManifest
     * @param  array<string, mixed>|null  $liveAcceptance
     * @param  list<string>  $locales
     */
    public function plan(
        array $targetDelta,
        ?array $deltaManifest = null,
        ?array $liveAcceptance = null,
        int $targetPublicTotal = 80,
        array $locales = ['en', 'zh'],
        ?string $targetDeltaPath = null,
        ?string $deltaManifestPath = null,
        ?string $liveAcceptancePath = null,
    ): Career80TotalLiveAcceptanceResult {
        if ($targetPublicTotal < 1) {
            throw new RuntimeException('target_public_total_invalid');
        }

        $locales = $this->stringList($locales, 'locale');
        $baselineSlugs = $this->slugList($targetDelta, 'published_baseline_slugs');
        $deltaSlugs = $this->deltaSlugs($targetDelta);
        $combinedSlugs = array_values(array_unique([...$baselineSlugs, ...$deltaSlugs]));
        sort($combinedSlugs);
        $expectedLocaleRows = count($combinedSlugs) * count($locales);
        $blockers = $this->blockers(
            targetDelta: $targetDelta,
            deltaManifest: $deltaManifest,
            liveAcceptance: $liveAcceptance,
            targetPublicTotal: $targetPublicTotal,
            locales: $locales,
            baselineSlugs: $baselineSlugs,
            deltaSlugs: $deltaSlugs,
            combinedSlugs: $combinedSlugs,
            expectedLocaleRows: $expectedLocaleRows,
        );
        $acceptanceSupplied = $liveAcceptance !== null;
        $accepted = $blockers === [] && $acceptanceSupplied && ($liveAcceptance['accepted'] ?? null) === true;
        $status = $accepted ? 'pass' : ($blockers === [] ? 'planned' : 'blocked');

        return new Career80TotalLiveAcceptanceResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'target' => 'career_80_total',
            'target_public_total' => $targetPublicTotal,
            'baseline_count' => count($baselineSlugs),
            'delta_count' => count($deltaSlugs),
            'total_slug_count' => count($combinedSlugs),
            'expected_locale_rows' => $expectedLocaleRows,
            'locales' => $locales,
            'baseline_slugs' => $baselineSlugs,
            'delta_slugs' => $deltaSlugs,
            'combined_slugs' => $combinedSlugs,
            'accepted' => $accepted,
            'read_only' => true,
            'writes_database' => false,
            'apply_allowed' => false,
            'rollout_allowed' => false,
            'live_crawl_executed' => false,
            'source_target_delta' => [
                'path' => $targetDeltaPath,
                'schema_version' => $targetDelta['schema_version'] ?? null,
                'status' => $targetDelta['status'] ?? null,
                'target_public_total' => $targetDelta['target_public_total'] ?? null,
            ],
            'source_delta_manifest' => [
                'path' => $deltaManifestPath,
                'supplied' => $deltaManifest !== null,
                'schema_version' => $deltaManifest['schema_version'] ?? null,
                'status' => $deltaManifest['status'] ?? null,
                'delta_slug_count' => $deltaManifest['delta_slug_count'] ?? null,
            ],
            'source_live_acceptance' => [
                'path' => $liveAcceptancePath,
                'supplied' => $acceptanceSupplied,
                'status' => $liveAcceptance['status'] ?? null,
                'accepted' => $liveAcceptance['accepted'] ?? null,
                'expected_rows' => $liveAcceptance['expected_rows'] ?? null,
            ],
            'requirements' => $this->requirements(),
            'validation' => [
                'baseline_plus_delta_count' => count($baselineSlugs) + count($deltaSlugs),
                'baseline_delta_overlap_count' => count(array_intersect($baselineSlugs, $deltaSlugs)),
                'combined_slug_count' => count($combinedSlugs),
                'expected_locale_rows' => $expectedLocaleRows,
                'target_public_total_validated' => count($combinedSlugs) === $targetPublicTotal,
                'acceptance_artifact_supplied' => $acceptanceSupplied,
            ],
            'blockers' => $blockers,
            'sidecars' => [],
            'next_required_action' => $accepted ? '80_TOTAL_LIVE_ACCEPTANCE_COMPLETE' : 'RUN_80_TOTAL_LIVE_ACCEPTANCE_READ_ONLY',
        ]);
    }

    /**
     * @param  array<string, mixed>  $targetDelta
     * @param  array<string, mixed>|null  $deltaManifest
     * @param  array<string, mixed>|null  $liveAcceptance
     * @param  list<string>  $locales
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $combinedSlugs
     * @return list<array<string, mixed>>
     */
    private function blockers(
        array $targetDelta,
        ?array $deltaManifest,
        ?array $liveAcceptance,
        int $targetPublicTotal,
        array $locales,
        array $baselineSlugs,
        array $deltaSlugs,
        array $combinedSlugs,
        int $expectedLocaleRows,
    ): array {
        $blockers = [];

        if (($targetDelta['schema_version'] ?? null) !== Career80TargetDeltaPlanner::SCHEMA_VERSION) {
            $blockers[] = $this->blocker('target_delta_schema_mismatch', [
                'schema_version' => $targetDelta['schema_version'] ?? null,
            ]);
        }

        if (($targetDelta['status'] ?? null) !== 'pass') {
            $blockers[] = $this->blocker('target_delta_not_passed', [
                'status' => $targetDelta['status'] ?? null,
            ]);
        }

        if (count($baselineSlugs) + count($deltaSlugs) !== $targetPublicTotal || count($combinedSlugs) !== $targetPublicTotal) {
            $blockers[] = $this->blocker('target_public_total_mismatch', [
                'target_public_total' => $targetPublicTotal,
                'baseline_count' => count($baselineSlugs),
                'delta_count' => count($deltaSlugs),
                'combined_slug_count' => count($combinedSlugs),
            ]);
        }

        $overlap = array_values(array_intersect($baselineSlugs, $deltaSlugs));
        if ($overlap !== []) {
            $blockers[] = $this->blocker('baseline_delta_overlap', [
                'slugs' => $overlap,
            ]);
        }

        if ($locales === []) {
            $blockers[] = $this->blocker('locales_missing', []);
        }

        if ($deltaManifest !== null) {
            $manifestDeltaSlugs = $this->slugList($deltaManifest, 'slugs');
            if (($deltaManifest['status'] ?? null) !== 'pass') {
                $blockers[] = $this->blocker('delta_manifest_not_passed', [
                    'status' => $deltaManifest['status'] ?? null,
                ]);
            }
            if ($manifestDeltaSlugs !== $deltaSlugs) {
                $blockers[] = $this->blocker('delta_manifest_slug_mismatch', [
                    'target_delta_count' => count($deltaSlugs),
                    'manifest_delta_count' => count($manifestDeltaSlugs),
                ]);
            }
        }

        if ($liveAcceptance !== null) {
            if (($liveAcceptance['accepted'] ?? null) !== true) {
                $blockers[] = $this->blocker('live_acceptance_not_accepted', [
                    'status' => $liveAcceptance['status'] ?? null,
                    'accepted' => $liveAcceptance['accepted'] ?? null,
                ]);
            }
            if ((int) ($liveAcceptance['expected_rows'] ?? 0) !== $expectedLocaleRows) {
                $blockers[] = $this->blocker('live_acceptance_expected_rows_mismatch', [
                    'expected' => $expectedLocaleRows,
                    'actual' => $liveAcceptance['expected_rows'] ?? null,
                ]);
            }
        }

        return $blockers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requirements(): array
    {
        return [
            ['id' => 'canonical_self', 'required' => true],
            ['id' => 'robots_indexable', 'required' => true],
            ['id' => 'final_200', 'required' => true],
            ['id' => 'dataset_visible', 'required' => true],
            ['id' => 'search_visible', 'required' => true],
            ['id' => 'sitemap_live', 'required' => true],
            ['id' => 'llms_live', 'required' => true],
            ['id' => 'llms_full_live', 'required' => true],
            ['id' => 'release_gate_pass', 'required' => true],
            ['id' => 'career_cta_present', 'required' => true],
        ];
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

        return $this->stringList($slugs, 'delta_slug');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function slugList(array $payload, string $key): array
    {
        $slugs = $payload[$key] ?? null;
        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException($key.'_missing');
        }

        return $this->stringList($slugs, $key);
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
