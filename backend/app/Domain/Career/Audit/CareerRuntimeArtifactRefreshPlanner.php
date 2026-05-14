<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerRuntimeArtifactRefreshPlanner
{
    public const SCHEMA_VERSION = 'career_runtime_artifact_refresh_plan.v1';

    private const TARGET = 'career_80_delta';

    private const DELTA_SLUG_COUNT = 51;

    private const PROJECTION_OUTPUT = '/tmp/career_80_delta_runtime_projection_after_candidate_prep.json';

    private const TRUTH_OUTPUT = '/tmp/career_80_delta_runtime_truth_after_candidate_prep.json';

    private const LEDGER_OUTPUT = '/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json';

    private const SUMMARY_OUTPUT = '/tmp/career_80_delta_runtime_artifact_refresh_summary.json';

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

        $deltaSlugCount = $this->deltaSlugCount($deltaPlan, $candidatePrepPlan);
        if ($deltaSlugCount !== self::DELTA_SLUG_COUNT) {
            $blockers[] = $this->blocker('delta_slug_count_not_51', 'The runtime artifact refresh plan is scoped to exactly 51 delta slugs.', [
                'delta_slug_count' => $deltaSlugCount,
                'expected' => self::DELTA_SLUG_COUNT,
            ]);
        }

        $phase = 'pre_apply';
        if ($candidatePrepApply === null) {
            $blockers[] = $this->blocker('candidate_prep_apply_artifact_missing', 'A verified candidate preparation apply artifact is required before runtime artifacts can be refreshed.', []);
        } elseif (($candidatePrepApply['write_verified'] ?? null) !== true) {
            $phase = 'blocked';
            $blockers[] = $this->blocker('candidate_prep_apply_not_verified', 'Candidate preparation apply artifact must have write_verified=true before refreshing runtime artifacts.', [
                'write_verified' => $candidatePrepApply['write_verified'] ?? null,
                'status' => $candidatePrepApply['status'] ?? null,
            ]);
        } else {
            $phase = 'post_apply_ready';
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
     * @param  array<string, mixed>|null  $candidatePrepPlan
     */
    private function deltaSlugCount(?array $deltaPlan, ?array $candidatePrepPlan): int
    {
        $count = $candidatePrepPlan['delta_slug_count']
            ?? $deltaPlan['delta_promotion_count']
            ?? $deltaPlan['delta_slug_count']
            ?? null;

        if (is_numeric($count)) {
            return (int) $count;
        }

        $slugs = $candidatePrepPlan['slugs']
            ?? $candidatePrepPlan['recommended_rollout_delta_slugs']
            ?? $candidatePrepPlan['delta_promotion_slugs']
            ?? $deltaPlan['recommended_rollout_delta_slugs']
            ?? $deltaPlan['delta_promotion_slugs']
            ?? $deltaPlan['slugs']
            ?? null;

        return is_array($slugs) && array_is_list($slugs) ? count(array_unique($slugs)) : self::DELTA_SLUG_COUNT;
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
