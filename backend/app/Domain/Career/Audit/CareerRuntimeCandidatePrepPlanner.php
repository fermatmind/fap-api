<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use RuntimeException;

final class CareerRuntimeCandidatePrepPlanner
{
    public const SCHEMA_VERSION = 'career_runtime_candidate_prep_plan.v1';

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @param  array<string, mixed>|null  $projection
     * @param  array<string, mixed>|null  $truth
     * @param  array<string, mixed>|null  $ledger
     * @param  list<string>  $locales
     */
    public function plan(
        array $targetDeltaPlan,
        ?array $projection = null,
        ?array $truth = null,
        ?array $ledger = null,
        array $locales = ['en', 'zh'],
        ?int $targetPublicTotal = null,
        ?string $cohort = null,
    ): CareerRuntimeCandidatePrepResult {
        $deltaSlugs = $this->deltaSlugs($targetDeltaPlan);
        $locales = CareerRuntimeArtifactRefreshPlanner::normalizeLocaleList($locales, 'locale');
        $artifactTargetPublicTotal = $this->artifactTargetPublicTotal($targetDeltaPlan);
        $currentPublicTotal = $this->artifactCurrentPublicTotal($targetDeltaPlan);
        $targetPublicTotal ??= $artifactTargetPublicTotal ?? 80;
        $cohort = $this->cohortValue($cohort, $targetPublicTotal, $currentPublicTotal);

        $projectionBySlugLocale = $this->rowsBySlugLocale($this->artifactRows($projection, ['items', 'rows']));
        $truthBySlugLocale = $this->rowsBySlugLocale($this->artifactRows($truth, ['items', 'rows']));
        $ledgerBySlug = $this->ledgerBySlug($this->artifactRows($ledger, ['members', 'items', 'rows']));

        $plannedRows = [];
        $slugRows = [];
        $missingLedger = [];
        $missingProjection = [];
        $missingTruth = [];
        $stateRepairNeeded = [];

        foreach ($deltaSlugs as $slug) {
            $slugEvidence = [
                'slug' => $slug,
                'ledger_member_exists' => isset($ledgerBySlug[$slug]),
                'ledger_release_cohort' => $ledgerBySlug[$slug]['release_cohort'] ?? null,
                'projection_locales_present' => [],
                'truth_locales_present' => [],
                'projection_states' => [],
                'truth_states' => [],
                'required_actions' => [],
            ];

            if (! isset($ledgerBySlug[$slug])) {
                $missingLedger[] = $slug;
                $slugEvidence['required_actions'][] = 'create_or_update_ledger_candidate_member';
            }

            foreach ($locales as $locale) {
                $projectionRows = $projectionBySlugLocale[$slug][$locale] ?? [];
                $truthRows = $truthBySlugLocale[$slug][$locale] ?? [];
                $projectionState = $this->runtimeState($projectionRows, 'runtime_publish_state', 'projection_state');
                $truthState = $this->runtimeState($truthRows, 'projection_state', 'runtime_publish_state');

                if ($projectionRows === []) {
                    $missingProjection[] = $slug;
                    $slugEvidence['required_actions'][] = 'create_projection_candidate_row';
                } else {
                    $slugEvidence['projection_locales_present'][] = $locale;
                    if ($projectionState !== null) {
                        $slugEvidence['projection_states'][] = $projectionState;
                    }
                    if ($projectionState !== Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE) {
                        $stateRepairNeeded[] = $slug;
                        $slugEvidence['required_actions'][] = 'repair_projection_candidate_state';
                    }
                }

                if ($truthRows === []) {
                    $missingTruth[] = $slug;
                    $slugEvidence['required_actions'][] = 'create_truth_candidate_row';
                } else {
                    $slugEvidence['truth_locales_present'][] = $locale;
                    if ($truthState !== null) {
                        $slugEvidence['truth_states'][] = $truthState;
                    }
                    if ($truthState !== Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE) {
                        $stateRepairNeeded[] = $slug;
                        $slugEvidence['required_actions'][] = 'repair_truth_candidate_state';
                    }
                }

                $plannedRows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'runtime_publish_state' => Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE,
                    'projection_state' => Career80RolloutCandidateGate::EXPECTED_RUNTIME_STATE,
                    'public_resolution_type' => 'public_canonical_job',
                    'candidate_pre_route_expected' => true,
                    'source' => $cohort.'_runtime_candidate_prep',
                    'write_intent' => 'planned_only',
                ];
            }

            $slugEvidence['projection_locales_present'] = $this->normalizedUniqueStrings($slugEvidence['projection_locales_present'], 'projection_locale_present', allowEmpty: true);
            $slugEvidence['truth_locales_present'] = $this->normalizedUniqueStrings($slugEvidence['truth_locales_present'], 'truth_locale_present', allowEmpty: true);
            $slugEvidence['projection_states'] = $this->normalizedUniqueStrings($slugEvidence['projection_states'], 'projection_state', allowEmpty: true);
            $slugEvidence['truth_states'] = $this->normalizedUniqueStrings($slugEvidence['truth_states'], 'truth_state', allowEmpty: true);
            $slugEvidence['required_actions'] = $this->normalizedUniqueStrings($slugEvidence['required_actions'], 'required_action', allowEmpty: true);
            $slugRows[] = $slugEvidence;
        }

        $blockers = $this->blockers($targetDeltaPlan, $deltaSlugs, $targetPublicTotal, $artifactTargetPublicTotal);
        $status = $blockers === [] ? 'planned' : 'blocked';

        return new CareerRuntimeCandidatePrepResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'read_only' => true,
            'writes_database' => false,
            'target' => $cohort,
            'current_public_total' => $currentPublicTotal,
            'target_public_total' => $targetPublicTotal,
            'delta_slug_count' => count($deltaSlugs),
            'locales' => $locales,
            'expected_locale_rows' => count($deltaSlugs) * count($locales),
            'expected_delta_locale_rows' => count($deltaSlugs) * count($locales),
            'planned_candidate_rows_count' => count($plannedRows),
            'planned_candidate_rows' => $plannedRows,
            'slug_rows' => $slugRows,
            'context_summary' => [
                'ledger_member_missing_count' => count(array_unique($missingLedger)),
                'projection_row_missing_count' => count(array_unique($missingProjection)),
                'truth_row_missing_count' => count(array_unique($missingTruth)),
                'candidate_state_repair_needed_count' => count(array_unique($stateRepairNeeded)),
            ],
            'context_slug_sets' => [
                'ledger_member_missing' => $this->normalizedUniqueStrings($missingLedger, 'ledger_missing', allowEmpty: true),
                'projection_row_missing' => $this->normalizedUniqueStrings($missingProjection, 'projection_missing', allowEmpty: true),
                'truth_row_missing' => $this->normalizedUniqueStrings($missingTruth, 'truth_missing', allowEmpty: true),
                'candidate_state_repair_needed' => $this->normalizedUniqueStrings($stateRepairNeeded, 'state_repair_needed', allowEmpty: true),
            ],
            'blockers' => $blockers,
            'approval_gate' => [
                'apply_allowed' => false,
                'approval_required_for_apply' => true,
                'reason' => 'runtime candidate preparation is planned only; apply requires a separate explicit approval gate',
            ],
            'apply_allowed' => false,
            'next_required_action' => $status === 'planned' ? 'RUNTIME_CANDIDATE_PREP_DRY_RUN' : 'FIX_RUNTIME_CANDIDATE_PREP_PLAN',
        ]);
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @return list<string>
     */
    private function deltaSlugs(array $targetDeltaPlan): array
    {
        $slugs = $targetDeltaPlan['recommended_rollout_delta_slugs']
            ?? $targetDeltaPlan['delta_promotion_slugs']
            ?? $targetDeltaPlan['slugs']
            ?? null;

        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('delta_slug_list_missing');
        }

        return $this->normalizedUniqueStrings($slugs, 'delta_slug');
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedUniqueStrings(array $values, string $context, bool $allowEmpty = false): array
    {
        $normalized = [];
        $seen = [];
        foreach ($values as $index => $value) {
            if (! is_string($value)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            $trimmed = strtolower(trim($value));
            if ($trimmed === '') {
                if ($allowEmpty) {
                    continue;
                }

                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            if (isset($seen[$trimmed])) {
                if ($allowEmpty) {
                    continue;
                }

                throw new RuntimeException($context.'_duplicate_'.$trimmed);
            }

            $seen[$trimmed] = true;
            $normalized[] = $trimmed;
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $artifact
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function artifactRows(?array $artifact, array $keys): array
    {
        if ($artifact === null) {
            return [];
        }

        foreach ($keys as $key) {
            if (isset($artifact[$key]) && is_array($artifact[$key]) && array_is_list($artifact[$key])) {
                return array_values(array_filter(
                    $artifact[$key],
                    static fn (mixed $row): bool => is_array($row) && ! array_is_list($row)
                ));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function rowsBySlugLocale(array $rows): array
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = $this->stringValue($row, 'slug');
            $locale = $this->stringValue($row, 'locale');
            if ($slug === null || $locale === null) {
                continue;
            }

            $bySlug[$slug] ??= [];
            $bySlug[$slug][$locale] ??= [];
            $bySlug[$slug][$locale][] = $row;
        }

        return $bySlug;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array<string, array<string, mixed>>
     */
    private function ledgerBySlug(array $members): array
    {
        $bySlug = [];
        foreach ($members as $member) {
            $slug = $this->stringValue($member, 'canonical_slug') ?? $this->stringValue($member, 'slug');
            if ($slug !== null) {
                $bySlug[$slug] = $member;
            }
        }

        return $bySlug;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function runtimeState(array $rows, string $preferredKey, string $fallbackKey): ?string
    {
        foreach ($rows as $row) {
            $state = $this->stringValue($row, $preferredKey) ?? $this->stringValue($row, $fallbackKey);
            if ($state !== null) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     */
    private function artifactTargetPublicTotal(array $targetDeltaPlan): ?int
    {
        $value = $targetDeltaPlan['target_public_total'] ?? data_get($targetDeltaPlan, 'target.public_total');

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     */
    private function artifactCurrentPublicTotal(array $targetDeltaPlan): ?int
    {
        $value = $targetDeltaPlan['current_public_total'] ?? data_get($targetDeltaPlan, 'current.public_total');

        return is_numeric($value) ? (int) $value : null;
    }

    private function cohortValue(?string $cohort, int $targetPublicTotal, ?int $currentPublicTotal): string
    {
        $normalized = strtolower(trim((string) $cohort));
        if ($normalized !== '') {
            $key = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

            return trim($key, '_') ?: $normalized;
        }

        if ($currentPublicTotal !== null && $targetPublicTotal !== 80) {
            return sprintf('career_%d_to_%d_delta', $currentPublicTotal, $targetPublicTotal);
        }

        return $targetPublicTotal === 80 ? 'career_80_delta' : sprintf('career_progressive_%d_delta', $targetPublicTotal);
    }

    /**
     * @param  array<string, mixed>  $targetDeltaPlan
     * @param  list<string>  $deltaSlugs
     * @return list<array<string, mixed>>
     */
    private function blockers(array $targetDeltaPlan, array $deltaSlugs, int $targetPublicTotal, ?int $artifactTargetPublicTotal): array
    {
        $blockers = [];
        if (($targetDeltaPlan['status'] ?? null) !== 'pass') {
            $blockers[] = [
                'reason' => 'target_delta_plan_not_pass',
                'message' => 'Target delta plan must pass before runtime candidate preparation can be planned.',
            ];
        }

        $declaredDeltaCount = $targetDeltaPlan['delta_promotion_count'] ?? $targetDeltaPlan['delta_slug_count'] ?? null;
        if ($declaredDeltaCount !== null && (int) $declaredDeltaCount !== count($deltaSlugs)) {
            $blockers[] = [
                'reason' => 'delta_slug_count_mismatch',
                'message' => 'Declared delta slug count does not match the explicit delta slug list.',
                'evidence' => [
                    'declared' => (int) $declaredDeltaCount,
                    'actual' => count($deltaSlugs),
                ],
            ];
        }

        if ($artifactTargetPublicTotal !== null && $artifactTargetPublicTotal !== $targetPublicTotal) {
            $blockers[] = [
                'reason' => 'target_public_total_mismatch',
                'message' => 'Requested target public total does not match the target delta artifact.',
                'evidence' => [
                    'requested' => $targetPublicTotal,
                    'artifact' => $artifactTargetPublicTotal,
                ],
            ];
        }

        return $blockers;
    }
}
