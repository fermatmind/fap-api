<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerRuntimeArtifactRefreshPlanner
{
    public const SCHEMA_VERSION = 'career_runtime_artifact_refresh_plan.v1';

    public const CANONICAL_LOCALES = ['en', 'zh'];

    private const TARGET = 'career_80_delta';

    private const DELTA_SLUG_COUNT = 51;

    private const PROJECTION_OUTPUT = '/tmp/career_80_delta_runtime_projection_after_candidate_prep.json';

    private const TRUTH_OUTPUT = '/tmp/career_80_delta_runtime_truth_after_candidate_prep.json';

    private const LEDGER_OUTPUT = '/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json';

    private const SUMMARY_OUTPUT = '/tmp/career_80_delta_runtime_artifact_refresh_summary.json';

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    public static function normalizeLocaleList(array $values, string $context): array
    {
        $locales = [];
        foreach ($values as $index => $value) {
            if (! is_scalar($value)) {
                throw new \RuntimeException($context.'_invalid_at_'.$index);
            }

            $locale = self::normalizeLocale((string) $value);
            if ($locale === null) {
                $safe = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $value))) ?: 'unknown';

                throw new \RuntimeException($context.'_unsupported_'.$safe);
            }

            $locales[] = $locale;
        }

        $locales = array_values(array_unique($locales));
        sort($locales);

        if ($locales === []) {
            throw new \RuntimeException($context.'_missing');
        }

        return $locales;
    }

    public static function normalizeLocale(string $value): ?string
    {
        $normalized = strtolower(str_replace('_', '-', trim($value)));

        return match ($normalized) {
            'en', 'en-us', 'en-gb' => 'en',
            'zh', 'zh-cn', 'zh-hans', 'zh-hans-cn' => 'zh',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $deltaPlan
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @param  array<string, mixed>|null  $candidatePrepApply
     */
    public function plan(
        string $target = self::TARGET,
        ?array $deltaPlan = null,
        ?array $candidatePrepPlan = null,
        ?array $candidatePrepApply = null,
    ): CareerRuntimeArtifactRefreshResult {
        $blockers = [];

        if ($target !== self::TARGET) {
            $blockers[] = $this->blocker('target_unsupported', 'Only the Career 80 delta artifact refresh target is supported.', [
                'target' => $target,
            ]);
        }

        if ($deltaPlan === null) {
            $blockers[] = $this->blocker('target_delta_plan_missing', 'The Career 80 target delta plan is required for runtime artifact refresh planning.', []);
        }

        if ($candidatePrepPlan === null) {
            $blockers[] = $this->blocker('candidate_prep_plan_missing', 'The runtime candidate preparation plan is required for runtime artifact refresh planning.', []);
        }

        $deltaValidation = $this->validateDeltaPlan($deltaPlan);
        $candidatePlanValidation = $this->validateCandidatePrepPlan($candidatePrepPlan);
        $deltaSlugCount = $candidatePlanValidation['delta_slug_count'] ?? $deltaValidation['delta_slug_count'] ?? 0;
        $blockers = [
            ...$blockers,
            ...$deltaValidation['blockers'],
            ...$candidatePlanValidation['blockers'],
        ];

        if ($deltaSlugCount !== self::DELTA_SLUG_COUNT) {
            $blockers[] = $this->blocker('delta_slug_count_not_51', 'The runtime artifact refresh plan is scoped to exactly 51 delta slugs.', [
                'delta_slug_count' => $deltaSlugCount,
                'expected' => self::DELTA_SLUG_COUNT,
            ]);
        }

        $phase = 'pre_apply';
        $applyReady = false;
        if ($candidatePrepApply === null) {
            $blockers[] = $this->blocker('candidate_prep_apply_artifact_missing', 'A verified candidate preparation apply artifact is required before runtime artifacts can be refreshed.', []);
        } else {
            $applyValidation = $this->validateCandidatePrepApply($candidatePrepApply, $deltaSlugCount);
            $blockers = [...$blockers, ...$applyValidation['blockers']];
            $applyReady = $applyValidation['ready'];
        }

        if ($candidatePrepApply !== null) {
            $phase = $applyReady ? 'post_apply_ready' : 'blocked';
        }
        $status = $blockers === [] ? 'planned' : 'blocked';

        return new CareerRuntimeArtifactRefreshResult([
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'target' => self::TARGET,
            'phase' => $phase,
            'delta_slug_count' => $deltaSlugCount,
            'candidate_prep_required' => true,
            'candidate_prep_apply_required' => true,
            'writes_database' => false,
            'read_only' => true,
            'required_inputs' => $this->requiredInputs($deltaPlan, $candidatePrepPlan, $candidatePrepApply),
            'required_outputs' => $this->requiredOutputs(),
            'commands' => $this->commands(),
            'blockers' => $blockers,
            'approval_gates' => $this->approvalGates($status, $phase),
            'next_required_action' => $this->nextRequiredAction($status, $phase),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $deltaPlan
     * @return array{delta_slug_count: int|null, blockers: list<array<string, mixed>>}
     */
    private function validateDeltaPlan(?array $deltaPlan): array
    {
        if ($deltaPlan === null) {
            return ['delta_slug_count' => null, 'blockers' => []];
        }

        $blockers = [];
        if (($deltaPlan['status'] ?? null) !== 'pass') {
            $blockers[] = $this->blocker('target_delta_plan_not_pass', 'Target delta plan must have status=pass before runtime artifact refresh readiness can pass.', [
                'status' => $deltaPlan['status'] ?? null,
            ]);
        }

        if (($deltaPlan['schema_version'] ?? null) !== 'career_80_target_delta.v1') {
            $blockers[] = $this->blocker('target_delta_plan_schema_invalid', 'Target delta plan schema_version must be career_80_target_delta.v1 for this refresh target.', [
                'schema_version' => $deltaPlan['schema_version'] ?? null,
            ]);
        }

        $slugs = $this->slugList($deltaPlan, ['recommended_rollout_delta_slugs', 'delta_promotion_slugs', 'slugs']);
        $declaredCount = $this->declaredCount($deltaPlan, ['delta_promotion_count', 'delta_slug_count']);
        if ($declaredCount === null) {
            $blockers[] = $this->blocker('target_delta_slug_count_missing', 'Target delta plan must declare an explicit delta slug count.', []);
        }
        if ($slugs === null) {
            $blockers[] = $this->blocker('target_delta_slug_list_missing', 'Target delta plan must include an explicit delta slug list.', []);
        }
        if (is_array($slugs) && $declaredCount !== null && count($slugs) !== $declaredCount) {
            $blockers[] = $this->blocker('target_delta_slug_count_mismatch', 'Target delta plan slug list count must match the declared delta slug count.', [
                'declared' => $declaredCount,
                'actual' => count($slugs),
            ]);
        }

        return [
            'delta_slug_count' => $declaredCount ?? (is_array($slugs) ? count($slugs) : null),
            'blockers' => $blockers,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @return array{delta_slug_count: int|null, blockers: list<array<string, mixed>>}
     */
    private function validateCandidatePrepPlan(?array $candidatePrepPlan): array
    {
        if ($candidatePrepPlan === null) {
            return ['delta_slug_count' => null, 'blockers' => []];
        }

        $blockers = [];
        if (($candidatePrepPlan['status'] ?? null) !== 'planned') {
            $blockers[] = $this->blocker('candidate_prep_plan_not_planned', 'Runtime candidate preparation plan must have status=planned before artifact refresh readiness can pass.', [
                'status' => $candidatePrepPlan['status'] ?? null,
            ]);
        }

        if (($candidatePrepPlan['schema_version'] ?? null) !== 'career_runtime_candidate_prep_plan.v1') {
            $blockers[] = $this->blocker('candidate_prep_plan_schema_invalid', 'Runtime candidate preparation plan schema_version must be career_runtime_candidate_prep_plan.v1.', [
                'schema_version' => $candidatePrepPlan['schema_version'] ?? null,
            ]);
        }

        if (($candidatePrepPlan['target'] ?? null) !== self::TARGET) {
            $blockers[] = $this->blocker('candidate_prep_plan_target_invalid', 'Runtime candidate preparation plan target must match the artifact refresh target.', [
                'target' => $candidatePrepPlan['target'] ?? null,
                'expected' => self::TARGET,
            ]);
        }

        $deltaSlugCount = $this->declaredCount($candidatePrepPlan, ['delta_slug_count']);
        if ($deltaSlugCount === null) {
            $blockers[] = $this->blocker('candidate_prep_plan_delta_slug_count_missing', 'Runtime candidate preparation plan must declare delta_slug_count.', []);
        }

        $localeCount = $this->localeCount($candidatePrepPlan['locales'] ?? null, 'candidate_prep_plan_locale', $blockers);
        $plannedRowsCount = $this->declaredCount($candidatePrepPlan, ['planned_candidate_rows_count', 'expected_delta_locale_rows', 'expected_locale_rows']);
        if ($deltaSlugCount !== null && $plannedRowsCount !== null && $plannedRowsCount !== $deltaSlugCount * $localeCount) {
            $blockers[] = $this->blocker('candidate_prep_plan_locale_row_count_mismatch', 'Runtime candidate preparation plan locale row count must match delta_slug_count times locale count.', [
                'delta_slug_count' => $deltaSlugCount,
                'locale_count' => $localeCount,
                'declared_locale_rows' => $plannedRowsCount,
            ]);
        }

        return ['delta_slug_count' => $deltaSlugCount, 'blockers' => $blockers];
    }

    /**
     * @param  array<string, mixed>  $candidatePrepApply
     * @return array{ready: bool, blockers: list<array<string, mixed>>}
     */
    private function validateCandidatePrepApply(array $candidatePrepApply, int $expectedSlugCount): array
    {
        $blockers = [];

        if (($candidatePrepApply['write_verified'] ?? null) !== true) {
            $blockers[] = $this->blocker('candidate_prep_apply_not_verified', 'Candidate preparation apply artifact must have write_verified=true before refreshing runtime artifacts.', [
                'write_verified' => $candidatePrepApply['write_verified'] ?? null,
                'status' => $candidatePrepApply['status'] ?? null,
            ]);
        }
        if (($candidatePrepApply['status'] ?? null) !== 'applied') {
            $blockers[] = $this->blocker('candidate_prep_apply_not_applied', 'Candidate preparation apply artifact must have status=applied before refreshing runtime artifacts.', [
                'status' => $candidatePrepApply['status'] ?? null,
            ]);
        }
        if (($candidatePrepApply['writes_database'] ?? null) !== true) {
            $blockers[] = $this->blocker('candidate_prep_apply_write_not_confirmed', 'Candidate preparation apply artifact must confirm writes_database=true.', [
                'writes_database' => $candidatePrepApply['writes_database'] ?? null,
            ]);
        }

        $failures = $candidatePrepApply['failures'] ?? [];
        if (! is_array($failures) || $failures !== []) {
            $blockers[] = $this->blocker('candidate_prep_apply_failures_present', 'Candidate preparation apply artifact must not contain failures.', [
                'failure_count' => is_array($failures) ? count($failures) : null,
            ]);
        }

        $localeCount = $this->localeCount($candidatePrepApply['locales'] ?? null, 'candidate_prep_apply_locale', $blockers);
        foreach (['slug_count', 'created_count', 'verified_count'] as $key) {
            $count = $this->declaredCount($candidatePrepApply, [$key]);
            if ($count === null || $count !== $expectedSlugCount) {
                $blockers[] = $this->blocker('candidate_prep_apply_'.$key.'_mismatch', 'Candidate preparation apply artifact count must match the expected delta slug count.', [
                    'field' => $key,
                    'declared' => $count,
                    'expected' => $expectedSlugCount,
                ]);
            }
        }

        $expectedLocaleRows = $this->declaredCount($candidatePrepApply, ['expected_locale_rows', 'expected_delta_locale_rows']);
        if ($expectedLocaleRows !== null && $expectedLocaleRows !== $expectedSlugCount * $localeCount) {
            $blockers[] = $this->blocker('candidate_prep_apply_locale_row_count_mismatch', 'Candidate preparation apply artifact expected locale rows must match slug count times locale count.', [
                'expected_slug_count' => $expectedSlugCount,
                'locale_count' => $localeCount,
                'declared_locale_rows' => $expectedLocaleRows,
            ]);
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>  $keys
     */
    private function declaredCount(array $artifact, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $artifact[$key] ?? null;
            if (is_numeric($value) && (int) $value >= 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @param  list<string>  $keys
     * @return list<string>|null
     */
    private function slugList(array $artifact, array $keys): ?array
    {
        foreach ($keys as $key) {
            $value = $artifact[$key] ?? null;
            if (! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            $slugs = [];
            foreach ($value as $index => $slug) {
                if (! is_string($slug) || trim($slug) === '') {
                    return null;
                }

                $slugs[] = strtolower(trim($slug));
            }

            $unique = array_values(array_unique($slugs));
            sort($unique);

            return count($unique) === count($slugs) ? $unique : null;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     */
    private function localeCount(mixed $locales, string $context, array &$blockers): int
    {
        if ($locales === null) {
            return count(self::CANONICAL_LOCALES);
        }

        if (! is_array($locales) || ! array_is_list($locales)) {
            $blockers[] = $this->blocker($context.'_invalid', 'Readiness artifact locales must be a list.', [
                'locales' => $locales,
            ]);

            return count(self::CANONICAL_LOCALES);
        }

        try {
            return count(self::normalizeLocaleList($locales, $context));
        } catch (\RuntimeException $exception) {
            $blockers[] = $this->blocker($exception->getMessage(), 'Readiness artifact locales must normalize to supported canonical locales.', [
                'locales' => $locales,
            ]);

            return count(self::CANONICAL_LOCALES);
        }
    }

    /**
     * @param  array<string, mixed>|null  $deltaPlan
     * @param  array<string, mixed>|null  $candidatePrepPlan
     * @param  array<string, mixed>|null  $candidatePrepApply
     * @return list<array<string, mixed>>
     */
    private function requiredInputs(?array $deltaPlan, ?array $candidatePrepPlan, ?array $candidatePrepApply): array
    {
        return [
            [
                'name' => 'target_delta_plan',
                'required' => true,
                'supplied' => $deltaPlan !== null,
                'purpose' => 'Preserves 29 baseline plus 51 delta target accounting.',
            ],
            [
                'name' => 'runtime_candidate_prep_plan',
                'required' => true,
                'supplied' => $candidatePrepPlan !== null,
                'purpose' => 'Supplies the explicit 51 delta candidate preparation plan.',
            ],
            [
                'name' => 'runtime_candidate_prep_apply_artifact',
                'required' => true,
                'supplied' => $candidatePrepApply !== null,
                'write_verified' => $candidatePrepApply['write_verified'] ?? null,
                'purpose' => 'Proves candidate preparation writes were applied and verified before read-only refresh.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requiredOutputs(): array
    {
        return [
            [
                'kind' => 'projection',
                'path' => self::PROJECTION_OUTPUT,
                'required_for' => '51-delta rollout dry-run',
            ],
            [
                'kind' => 'truth',
                'path' => self::TRUTH_OUTPUT,
                'required_for' => '51-delta rollout dry-run',
            ],
            [
                'kind' => 'ledger',
                'path' => self::LEDGER_OUTPUT,
                'required_for' => '51-delta rollout dry-run',
            ],
            [
                'kind' => 'summary',
                'path' => self::SUMMARY_OUTPUT,
                'required_for' => 'operator review and downstream artifact provenance',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commands(): array
    {
        return [
            [
                'command' => 'career:export-full-release-ledger',
                'output' => self::LEDGER_OUTPUT,
                'read_only' => true,
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
            [
                'command' => 'career:export-runtime-publish-projection',
                'output' => self::PROJECTION_OUTPUT,
                'read_only' => true,
                'requires' => [self::LEDGER_OUTPUT],
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
            [
                'command' => 'career:export-canonical-runtime-truth',
                'output' => self::TRUTH_OUTPUT,
                'read_only' => true,
                'requires' => [self::LEDGER_OUTPUT, self::PROJECTION_OUTPUT],
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalGates(string $status, string $phase): array
    {
        $refreshReady = $status === 'planned' && $phase === 'post_apply_ready';

        return [
            [
                'id' => 'RUNTIME_CANDIDATE_PREP_APPLY_51',
                'required_before' => 'RUNTIME_ARTIFACT_REFRESH_READ_ONLY',
                'currently_ready' => false,
                'satisfied' => $phase === 'post_apply_ready',
                'forbidden_actions' => ['rollout', 'rollout_apply', 'backfill', 'rollback', 'quarantine', 'deploy'],
            ],
            [
                'id' => 'RUNTIME_ARTIFACT_REFRESH_READ_ONLY',
                'required_before' => 'DELTA_ROLLOUT_DRY_RUN_51',
                'currently_ready' => $refreshReady,
                'satisfied' => false,
                'forbidden_actions' => ['apply', 'rollout_apply', 'db_mutation', 'deploy'],
            ],
            [
                'id' => 'DELTA_ROLLOUT_DRY_RUN_51',
                'required_before' => 'ROLLOUT_APPLY_51_DELTA',
                'currently_ready' => false,
                'satisfied' => false,
                'forbidden_actions' => ['rollout_apply', 'db_mutation', 'deploy'],
            ],
        ];
    }

    private function nextRequiredAction(string $status, string $phase): string
    {
        if ($status === 'planned' && $phase === 'post_apply_ready') {
            return 'RUNTIME_ARTIFACT_REFRESH_READ_ONLY';
        }

        return 'RUNTIME_CANDIDATE_PREP_DRY_RUN';
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function blocker(string $reason, string $message, array $evidence): array
    {
        return [
            'reason' => $reason,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }
}
