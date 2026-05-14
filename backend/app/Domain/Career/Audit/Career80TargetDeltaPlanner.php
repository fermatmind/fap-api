<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class Career80TargetDeltaPlanner
{
    public const SCHEMA_VERSION = 'career_80_target_delta.v1';

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $deltaArtifact
     * @param  array<string, mixed>|null  $runtimePool
     */
    public function plan(array $readiness, array $deltaArtifact, ?array $runtimePool = null, int $target = 80): Career80TargetDeltaResult
    {
        if ($target < 1) {
            throw new RuntimeException('target_invalid');
        }

        $previousSelection = $this->selectedSlugs($readiness);
        $deltaSlugs = $this->deltaSlugs($deltaArtifact);
        $baselineSlugs = array_values(array_diff($previousSelection, $deltaSlugs));
        sort($baselineSlugs);

        $deltaInPrevious = array_values(array_intersect($deltaSlugs, $previousSelection));
        sort($deltaInPrevious);
        $baselineInPrevious = array_values(array_intersect($baselineSlugs, $previousSelection));
        sort($baselineInPrevious);
        $unexpectedSelection = array_values(array_diff($previousSelection, $baselineSlugs, $deltaSlugs));
        sort($unexpectedSelection);
        $deltaMissingFromSelection = array_values(array_diff($deltaSlugs, $previousSelection));
        sort($deltaMissingFromSelection);

        $alreadyPublishedEvidence = $runtimePool === null ? [] : $this->alreadyPublishedSlugs($runtimePool);
        $blockers = $this->blockers(
            target: $target,
            previousSelection: $previousSelection,
            baselineSlugs: $baselineSlugs,
            deltaSlugs: $deltaSlugs,
            unexpectedSelection: $unexpectedSelection,
            deltaMissingFromSelection: $deltaMissingFromSelection,
            alreadyPublishedEvidence: $alreadyPublishedEvidence,
        );
        $pass = $blockers === [];

        return new Career80TargetDeltaResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $pass ? 'pass' : 'blocked',
            'read_only' => true,
            'writes_database' => false,
            'target_public_total' => $target,
            'published_baseline_count' => count($baselineSlugs),
            'delta_promotion_count' => count($deltaSlugs),
            'previous_80_selected_count' => count($previousSelection),
            'published_baseline_slugs' => $baselineSlugs,
            'delta_promotion_slugs' => $deltaSlugs,
            'recommended_rollout_delta_slugs' => $deltaSlugs,
            'validation' => [
                'baseline_plus_delta_count' => count($baselineSlugs) + count($deltaSlugs),
                'baseline_delta_overlap_count' => count(array_intersect($baselineSlugs, $deltaSlugs)),
                'delta_in_previous_80_count' => count($deltaInPrevious),
                'published_baseline_in_previous_80_count' => count($baselineInPrevious),
                'previous_80_not_baseline_or_delta' => $unexpectedSelection,
                'delta_not_in_previous_80' => $deltaMissingFromSelection,
                'already_published_evidence_count' => count($alreadyPublishedEvidence),
                'baseline_matches_already_published_evidence' => $alreadyPublishedEvidence === [] ? null : $baselineSlugs === $alreadyPublishedEvidence,
            ],
            'overlap' => [
                'published_baseline_in_previous_80' => $baselineInPrevious,
                'delta_in_previous_80' => $deltaInPrevious,
            ],
            'blockers' => $blockers,
            'rollout' => [
                'delta_manifest_allowed' => $pass,
                'rollout_dry_run_allowed' => false,
                'apply_allowed' => false,
                'reason' => 'target decomposition only; rollout dry-run and apply require separate later gates',
            ],
            'next_required_action' => $pass ? 'RUNTIME_CANDIDATE_PREP_PLAN_READ_ONLY' : 'FIX_TARGET_DELTA_BLOCKERS',
        ]);
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<string>
     */
    private function selectedSlugs(array $readiness): array
    {
        $slugs = data_get($readiness, 'selection.slugs');
        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('readiness_selection_slugs_missing');
        }

        return $this->normalizedUniqueSlugs($slugs, 'readiness_selection');
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function deltaSlugs(array $artifact): array
    {
        $slugs = $artifact['slugs'] ?? null;
        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('delta_slug_list_missing');
        }

        $normalized = $this->normalizedUniqueSlugs($slugs, 'delta_slug');
        $declaredCount = $artifact['count'] ?? $artifact['slug_count'] ?? data_get($artifact, 'target.slug_count');
        if ($declaredCount !== null && (int) $declaredCount !== count($normalized)) {
            throw new RuntimeException('delta_slug_count_declared_mismatch');
        }

        return $normalized;
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
     * @param  array<string, mixed>  $runtimePool
     * @return list<string>
     */
    private function alreadyPublishedSlugs(array $runtimePool): array
    {
        $rows = data_get($runtimePool, 'runtime_candidate_gate.excluded_rows');
        if (! is_array($rows)) {
            return [];
        }

        $slugs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $reasons = $row['exclusion_reasons'] ?? [];
            if (! is_array($reasons) || ! in_array('already_published', $reasons, true)) {
                continue;
            }
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        return $this->normalizedUniqueSlugs($slugs, 'already_published_evidence');
    }

    /**
     * @param  list<string>  $previousSelection
     * @param  list<string>  $baselineSlugs
     * @param  list<string>  $deltaSlugs
     * @param  list<string>  $unexpectedSelection
     * @param  list<string>  $deltaMissingFromSelection
     * @param  list<string>  $alreadyPublishedEvidence
     * @return list<array<string, mixed>>
     */
    private function blockers(
        int $target,
        array $previousSelection,
        array $baselineSlugs,
        array $deltaSlugs,
        array $unexpectedSelection,
        array $deltaMissingFromSelection,
        array $alreadyPublishedEvidence,
    ): array {
        $blockers = [];

        if (count($previousSelection) !== $target) {
            $blockers[] = $this->blocker('previous_selection_count_mismatch', [
                'expected' => $target,
                'actual' => count($previousSelection),
            ]);
        }

        if (count(array_intersect($baselineSlugs, $deltaSlugs)) > 0) {
            $blockers[] = $this->blocker('baseline_delta_overlap', [
                'overlap' => array_values(array_intersect($baselineSlugs, $deltaSlugs)),
            ]);
        }

        if (count($baselineSlugs) + count($deltaSlugs) !== $target) {
            $blockers[] = $this->blocker('target_total_mismatch', [
                'target' => $target,
                'baseline_count' => count($baselineSlugs),
                'delta_count' => count($deltaSlugs),
            ]);
        }

        if ($unexpectedSelection !== []) {
            $blockers[] = $this->blocker('previous_selection_contains_unknown_slugs', [
                'slugs' => $unexpectedSelection,
            ]);
        }

        if ($deltaMissingFromSelection !== []) {
            $blockers[] = $this->blocker('delta_slugs_missing_from_previous_selection', [
                'slugs' => $deltaMissingFromSelection,
            ]);
        }

        if ($alreadyPublishedEvidence !== [] && $baselineSlugs !== $alreadyPublishedEvidence) {
            $blockers[] = $this->blocker('published_baseline_evidence_mismatch', [
                'baseline_count' => count($baselineSlugs),
                'already_published_evidence_count' => count($alreadyPublishedEvidence),
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
