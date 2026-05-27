<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerRuntimeArtifactRefreshPlanner
{
    public const SCHEMA_VERSION = 'career_runtime_artifact_refresh_plan.v1';

    public const CANONICAL_LOCALES = ['en', 'zh'];

    public const TARGET_CAREER_80_DELTA = 'career_80_delta';

    public const TARGET_DETAIL_READY_1048 = CareerDetailReadyTargetAuthority::TARGET_KEY;

    private const DELTA_SLUG_COUNT_51 = 51;

    private const DELTA_SLUG_COUNT_DETAIL_READY_1048 = CareerDetailReadyTargetAuthority::READY_NOT_PUBLIC_DELTA;

    private const TARGET_PUBLIC_TOTAL_80 = 80;

    private const TARGET_PUBLIC_TOTAL_DETAIL_READY_1048 = CareerDetailReadyTargetAuthority::TARGET_PUBLIC_TOTAL;

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
        string $target = self::TARGET_CAREER_80_DELTA,
        ?array $deltaPlan = null,
        ?array $candidatePrepPlan = null,
        ?array $candidatePrepApply = null,
    ): CareerRuntimeArtifactRefreshResult {
        $target = $this->normalizeTarget($target);
        $targetConfig = $this->targetConfig($target);
        $blockers = [];

        if ($targetConfig === null) {
            $targetConfig = $this->targetConfig(self::TARGET_CAREER_80_DELTA);
            $blockers[] = $this->blocker('target_unsupported', 'Only supported Career runtime artifact refresh targets can be planned.', [
                'target' => $target,
                'supported_targets' => [
                    self::TARGET_CAREER_80_DELTA,
                    self::TARGET_DETAIL_READY_1048,
                ],
            ]);
        }

        if ($deltaPlan === null) {
            $blockers[] = $this->blocker('target_delta_plan_missing', 'The target delta plan is required for runtime artifact refresh planning.', [
                'target' => $target,
            ]);
        }

        if ($candidatePrepPlan === null) {
            $blockers[] = $this->blocker('candidate_prep_plan_missing', 'The runtime candidate preparation plan is required for runtime artifact refresh planning.', [
                'target' => $target,
            ]);
        }

        $deltaValidation = $this->validateDeltaPlan($deltaPlan, $targetConfig);
        $candidatePlanValidation = $this->validateCandidatePrepPlan($candidatePrepPlan, $targetConfig);
        $deltaSlugCount = $candidatePlanValidation['delta_slug_count'] ?? $deltaValidation['delta_slug_count'] ?? 0;
        $blockers = [
            ...$blockers,
            ...$deltaValidation['blockers'],
            ...$candidatePlanValidation['blockers'],
        ];

        if ($deltaSlugCount !== $targetConfig['expected_delta_slug_count']) {
            $blockers[] = $this->blocker($targetConfig['delta_count_blocker'], 'The runtime artifact refresh plan must preserve the expected target delta slug count.', [
                'delta_slug_count' => $deltaSlugCount,
                'expected' => $targetConfig['expected_delta_slug_count'],
                'target' => $targetConfig['target'],
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
            'target' => $targetConfig['target'],
            'phase' => $phase,
            'delta_slug_count' => $deltaSlugCount,
            'target_public_total' => $targetConfig['target_public_total'],
            'expected_locale_rows' => $deltaSlugCount * count(self::CANONICAL_LOCALES),
            'candidate_prep_required' => true,
            'candidate_prep_apply_required' => true,
            'writes_database' => false,
            'read_only' => true,
            'target_authority' => $this->targetAuthority($targetConfig['target']),
            'runtime_authority_contract' => $this->runtimeAuthorityContract($targetConfig['target']),
            'required_inputs' => $this->requiredInputs($targetConfig, $deltaPlan, $candidatePrepPlan, $candidatePrepApply),
            'required_outputs' => $this->requiredOutputs($targetConfig),
            'commands' => $this->commands($targetConfig),
            'blockers' => $blockers,
            'approval_gates' => $this->approvalGates($status, $phase, $targetConfig),
            'next_required_action' => $this->nextRequiredAction($status, $phase, $targetConfig),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $deltaPlan
     * @param  array<string, mixed>  $targetConfig
     * @return array{delta_slug_count: int|null, blockers: list<array<string, mixed>>}
     */
    private function validateDeltaPlan(?array $deltaPlan, array $targetConfig): array
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

        if (! in_array(($deltaPlan['schema_version'] ?? null), $targetConfig['accepted_delta_plan_schemas'], true)) {
            $blockers[] = $this->blocker('target_delta_plan_schema_invalid', 'Target delta plan schema_version is not supported for this refresh target.', [
                'schema_version' => $deltaPlan['schema_version'] ?? null,
                'accepted' => $targetConfig['accepted_delta_plan_schemas'],
            ]);
        }

        if ($targetConfig['target'] === self::TARGET_DETAIL_READY_1048 && ($deltaPlan['target_key'] ?? null) !== self::TARGET_DETAIL_READY_1048) {
            $blockers[] = $this->blocker('target_delta_plan_target_key_invalid', 'detail_ready_1048 artifact refresh requires a matching target_key.', [
                'target_key' => $deltaPlan['target_key'] ?? null,
                'expected' => self::TARGET_DETAIL_READY_1048,
            ]);
        }

        $slugs = $this->slugList($deltaPlan, ['recommended_rollout_delta_slugs', 'delta_promotion_slugs', 'ready_not_public_1018.slugs', 'slugs']);
        $declaredCount = $this->declaredCount($deltaPlan, ['delta_promotion_count', 'delta_slug_count']);
        if ($declaredCount === null && $targetConfig['target'] === self::TARGET_DETAIL_READY_1048) {
            $declaredCount = $this->declaredCount((array) ($deltaPlan['ready_not_public_1018'] ?? []), ['count']);
        }
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
     * @param  array<string, mixed>  $targetConfig
     * @return array{delta_slug_count: int|null, blockers: list<array<string, mixed>>}
     */
    private function validateCandidatePrepPlan(?array $candidatePrepPlan, array $targetConfig): array
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

        if (($candidatePrepPlan['target'] ?? null) !== $targetConfig['target']) {
            $blockers[] = $this->blocker('candidate_prep_plan_target_invalid', 'Runtime candidate preparation plan target must match the artifact refresh target.', [
                'target' => $candidatePrepPlan['target'] ?? null,
                'expected' => $targetConfig['target'],
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
            $value = str_contains($key, '.') ? data_get($artifact, $key) : ($artifact[$key] ?? null);
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
    private function requiredInputs(array $targetConfig, ?array $deltaPlan, ?array $candidatePrepPlan, ?array $candidatePrepApply): array
    {
        return [
            [
                'name' => 'target_delta_plan',
                'required' => true,
                'supplied' => $deltaPlan !== null,
                'purpose' => $targetConfig['input_purpose'],
            ],
            [
                'name' => 'runtime_candidate_prep_plan',
                'required' => true,
                'supplied' => $candidatePrepPlan !== null,
                'purpose' => 'Supplies the explicit '.$targetConfig['expected_delta_slug_count'].' delta candidate preparation plan.',
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
    private function requiredOutputs(array $targetConfig): array
    {
        return [
            [
                'kind' => 'projection',
                'path' => $targetConfig['outputs']['projection'],
                'required_for' => $targetConfig['required_for'],
            ],
            [
                'kind' => 'truth',
                'path' => $targetConfig['outputs']['truth'],
                'required_for' => $targetConfig['required_for'],
            ],
            [
                'kind' => 'ledger',
                'path' => $targetConfig['outputs']['ledger'],
                'required_for' => $targetConfig['required_for'],
            ],
            [
                'kind' => 'summary',
                'path' => $targetConfig['outputs']['summary'],
                'required_for' => 'operator review and downstream artifact provenance',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commands(array $targetConfig): array
    {
        return [
            [
                'command' => 'career:export-full-release-ledger',
                'output' => $targetConfig['outputs']['ledger'],
                'read_only' => true,
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
            [
                'command' => 'career:export-runtime-publish-projection',
                'output' => $targetConfig['outputs']['projection'],
                'read_only' => true,
                'requires' => [$targetConfig['outputs']['ledger']],
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
            [
                'command' => 'career:export-canonical-runtime-truth',
                'output' => $targetConfig['outputs']['truth'],
                'read_only' => true,
                'requires' => [$targetConfig['outputs']['ledger'], $targetConfig['outputs']['projection']],
                'execution' => 'future_read_only_run_after_candidate_prep_apply',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvalGates(string $status, string $phase, array $targetConfig): array
    {
        $refreshReady = $status === 'planned' && $phase === 'post_apply_ready';

        return [
            [
                'id' => $targetConfig['approval_gate_prefix'].'_CANDIDATE_PREP_APPLY',
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
                'id' => $targetConfig['approval_gate_prefix'].'_ROLLOUT_DRY_RUN',
                'required_before' => $targetConfig['approval_gate_prefix'].'_ROLLOUT_APPLY',
                'currently_ready' => false,
                'satisfied' => false,
                'forbidden_actions' => ['rollout_apply', 'db_mutation', 'deploy'],
            ],
        ];
    }

    private function nextRequiredAction(string $status, string $phase, array $targetConfig): string
    {
        if ($status === 'planned' && $phase === 'post_apply_ready') {
            return 'RUNTIME_ARTIFACT_REFRESH_READ_ONLY';
        }

        return $targetConfig['candidate_prep_action'];
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

    private function normalizeTarget(string $target): string
    {
        $normalized = strtolower(trim($target));
        if ($normalized === '') {
            return self::TARGET_CAREER_80_DELTA;
        }

        $key = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;

        return trim($key, '_') ?: self::TARGET_CAREER_80_DELTA;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function targetConfig(string $target): ?array
    {
        return match ($target) {
            self::TARGET_CAREER_80_DELTA => [
                'target' => self::TARGET_CAREER_80_DELTA,
                'target_public_total' => self::TARGET_PUBLIC_TOTAL_80,
                'expected_delta_slug_count' => self::DELTA_SLUG_COUNT_51,
                'delta_count_blocker' => 'delta_slug_count_not_51',
                'accepted_delta_plan_schemas' => ['career_80_target_delta.v1'],
                'input_purpose' => 'Preserves 29 baseline plus 51 delta target accounting.',
                'required_for' => '51-delta rollout dry-run',
                'candidate_prep_action' => 'RUNTIME_CANDIDATE_PREP_DRY_RUN',
                'approval_gate_prefix' => 'CAREER_80_DELTA',
                'outputs' => [
                    'projection' => '/tmp/career_80_delta_runtime_projection_after_candidate_prep.json',
                    'truth' => '/tmp/career_80_delta_runtime_truth_after_candidate_prep.json',
                    'ledger' => '/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json',
                    'summary' => '/tmp/career_80_delta_runtime_artifact_refresh_summary.json',
                ],
            ],
            self::TARGET_DETAIL_READY_1048 => [
                'target' => self::TARGET_DETAIL_READY_1048,
                'target_public_total' => self::TARGET_PUBLIC_TOTAL_DETAIL_READY_1048,
                'expected_delta_slug_count' => self::DELTA_SLUG_COUNT_DETAIL_READY_1048,
                'delta_count_blocker' => 'detail_ready_1048_delta_count_mismatch',
                'accepted_delta_plan_schemas' => ['career_detail_ready_publication_candidates.v1'],
                'input_purpose' => 'Preserves current public 30 plus the 1018 detail-ready delta without treating 2786 raw occupation assets as public authority.',
                'required_for' => 'detail_ready_1048 rollout gate dry-run',
                'candidate_prep_action' => 'DETAIL_READY_1048_RUNTIME_CANDIDATE_PREP_DRY_RUN',
                'approval_gate_prefix' => 'DETAIL_READY_1048',
                'outputs' => [
                    'projection' => '/tmp/career_detail_ready_1048_runtime_projection_after_candidate_prep.json',
                    'truth' => '/tmp/career_detail_ready_1048_runtime_truth_after_candidate_prep.json',
                    'ledger' => '/tmp/career_detail_ready_1048_full_release_ledger_after_candidate_prep.json',
                    'summary' => '/tmp/career_detail_ready_1048_runtime_artifact_refresh_summary.json',
                ],
            ],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function targetAuthority(string $target): array
    {
        if ($target === self::TARGET_DETAIL_READY_1048) {
            return (new CareerDetailReadyTargetAuthority)->target(self::CANONICAL_LOCALES);
        }

        return [
            'target_key' => $target,
            'candidate_prep_apply_allowed' => false,
            'rollout_apply_allowed' => false,
            'production_deploy_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeAuthorityContract(string $target): array
    {
        return [
            'contract_version' => 'career_runtime_artifact_shared_authority.v1',
            'target' => $target,
            'single_runtime_authority' => true,
            'authority_source' => 'runtime_projection_truth_ledger_refresh_after_verified_candidate_prep',
            'consumers' => [
                'dataset_hub',
                'career_jobs_api',
                'career_job_detail_api',
                'sitemap',
                'llms',
                'llms_full',
            ],
            'must_not_publish_from_raw_assets' => true,
            'must_not_use_fap_web_fallback_authority' => true,
            'pre_rollout_candidate_rows_remain_hidden' => true,
        ];
    }
}
