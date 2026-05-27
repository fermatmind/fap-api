<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerRuntimeArtifactRefreshPlanner;
use PHPUnit\Framework\TestCase;

final class CareerRuntimeArtifactRefreshPlannerTest extends TestCase
{
    public function test_emits_required_projection_truth_and_ledger_paths(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
            candidatePrepApply: $this->candidatePrepApply(writeVerified: true),
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('career_runtime_artifact_refresh_plan.v1', $payload['schema_version']);
        $this->assertSame('career_80_delta', $payload['target']);
        $this->assertSame(80, $payload['target_public_total']);
        $this->assertSame('/tmp/career_80_delta_runtime_projection_after_candidate_prep.json', $payload['required_outputs'][0]['path']);
        $this->assertSame('/tmp/career_80_delta_runtime_truth_after_candidate_prep.json', $payload['required_outputs'][1]['path']);
        $this->assertSame('/tmp/career_80_delta_full_release_ledger_after_candidate_prep.json', $payload['required_outputs'][2]['path']);
        $this->assertSame('/tmp/career_80_delta_runtime_artifact_refresh_summary.json', $payload['required_outputs'][3]['path']);
    }

    public function test_blocks_until_candidate_prep_apply_artifact_exists(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('pre_apply', $payload['phase']);
        $this->assertSame('candidate_prep_apply_artifact_missing', $payload['blockers'][0]['reason']);
        $this->assertSame('RUNTIME_CANDIDATE_PREP_DRY_RUN', $payload['next_required_action']);
    }

    public function test_passes_when_candidate_prep_apply_is_write_verified(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
            candidatePrepApply: $this->candidatePrepApply(writeVerified: true),
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('post_apply_ready', $payload['phase']);
        $this->assertSame([], $payload['blockers']);
        $this->assertSame('RUNTIME_ARTIFACT_REFRESH_READ_ONLY', $payload['next_required_action']);
        $this->assertTrue($payload['approval_gates'][1]['currently_ready']);
    }

    public function test_blocks_when_candidate_prep_apply_is_not_write_verified(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
            candidatePrepApply: $this->candidatePrepApply(writeVerified: false),
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $payload['phase']);
        $this->assertSame('candidate_prep_apply_not_verified', $payload['blockers'][0]['reason']);
    }

    public function test_blocks_malformed_target_and_candidate_artifacts(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: [
                'schema_version' => 'career_80_target_delta.v1',
                'status' => 'blocked',
            ],
            candidatePrepPlan: [
                'schema_version' => 'career_runtime_candidate_prep_plan.v1',
                'status' => 'planned',
                'target' => 'career_80_delta',
                'delta_slug_count' => 51,
                'planned_candidate_rows_count' => 102,
            ],
            candidatePrepApply: $this->candidatePrepApply(writeVerified: true),
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_delta_plan_not_pass', array_column($payload['blockers'], 'reason'));
        $this->assertContains('target_delta_slug_count_missing', array_column($payload['blockers'], 'reason'));
        $this->assertContains('target_delta_slug_list_missing', array_column($payload['blockers'], 'reason'));
    }

    public function test_blocks_apply_artifact_with_failures_or_mismatched_counts(): void
    {
        $apply = [
            ...$this->candidatePrepApply(writeVerified: true),
            'failures' => [['reason' => 'write_failed']],
            'verified_count' => 50,
        ];

        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
            candidatePrepApply: $apply,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('candidate_prep_apply_failures_present', array_column($payload['blockers'], 'reason'));
        $this->assertContains('candidate_prep_apply_verified_count_mismatch', array_column($payload['blockers'], 'reason'));
    }

    public function test_marks_writes_database_false_and_commands_read_only(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
            candidatePrepApply: $this->candidatePrepApply(writeVerified: true),
        )->toArray();

        $this->assertFalse($payload['writes_database']);
        $this->assertTrue($payload['read_only']);
        $this->assertSame([
            'career:export-full-release-ledger',
            'career:export-runtime-publish-projection',
            'career:export-canonical-runtime-truth',
        ], array_column($payload['commands'], 'command'));
        $this->assertSame([true, true, true], array_column($payload['commands'], 'read_only'));
    }

    public function test_plans_detail_ready_1048_refresh_without_writing_artifacts(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            target: 'detail_ready_1048',
            deltaPlan: $this->detailReadyPlan(),
            candidatePrepPlan: $this->detailReadyCandidatePrepPlan(),
            candidatePrepApply: $this->detailReadyCandidatePrepApply(writeVerified: true),
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('post_apply_ready', $payload['phase']);
        $this->assertSame('detail_ready_1048', $payload['target']);
        $this->assertSame(1048, $payload['target_public_total']);
        $this->assertSame(1018, $payload['delta_slug_count']);
        $this->assertSame(2036, $payload['expected_locale_rows']);
        $this->assertSame('/tmp/career_detail_ready_1048_runtime_projection_after_candidate_prep.json', $payload['required_outputs'][0]['path']);
        $this->assertSame('/tmp/career_detail_ready_1048_runtime_truth_after_candidate_prep.json', $payload['required_outputs'][1]['path']);
        $this->assertSame('/tmp/career_detail_ready_1048_full_release_ledger_after_candidate_prep.json', $payload['required_outputs'][2]['path']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertSame([
            'dataset_hub',
            'career_jobs_api',
            'career_job_detail_api',
            'sitemap',
            'llms',
            'llms_full',
        ], $payload['runtime_authority_contract']['consumers']);
        $this->assertFalse($payload['writes_database']);
        $this->assertTrue($payload['read_only']);
    }

    public function test_detail_ready_1048_blocks_wrong_delta_count_and_target_key(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            target: 'detail_ready_1048',
            deltaPlan: [
                ...$this->detailReadyPlan(count: 1017),
                'target_key' => 'career_80_delta',
            ],
            candidatePrepPlan: [
                ...$this->detailReadyCandidatePrepPlan(deltaCount: 1017),
            ],
            candidatePrepApply: $this->detailReadyCandidatePrepApply(writeVerified: true, deltaCount: 1017),
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_delta_plan_target_key_invalid', array_column($payload['blockers'], 'reason'));
        $this->assertContains('detail_ready_1048_delta_count_mismatch', array_column($payload['blockers'], 'reason'));
    }

    public function test_schema_is_stable(): void
    {
        $payload = (new CareerRuntimeArtifactRefreshPlanner)->plan(
            deltaPlan: $this->deltaPlan(),
            candidatePrepPlan: $this->candidatePrepPlan(),
        )->toArray();

        $this->assertSame([
            'schema_version',
            'status',
            'target',
            'phase',
            'delta_slug_count',
            'target_public_total',
            'expected_locale_rows',
            'candidate_prep_required',
            'candidate_prep_apply_required',
            'writes_database',
            'read_only',
            'target_authority',
            'runtime_authority_contract',
            'required_inputs',
            'required_outputs',
            'commands',
            'blockers',
            'approval_gates',
            'next_required_action',
        ], array_keys($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function deltaPlan(): array
    {
        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'delta_promotion_count' => 51,
            'recommended_rollout_delta_slugs' => $this->slugs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePrepPlan(): array
    {
        return [
            'schema_version' => 'career_runtime_candidate_prep_plan.v1',
            'status' => 'planned',
            'target' => 'career_80_delta',
            'delta_slug_count' => 51,
            'locales' => ['en', 'zh'],
            'expected_locale_rows' => 102,
            'planned_candidate_rows_count' => 102,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidatePrepApply(bool $writeVerified): array
    {
        return [
            'status' => $writeVerified ? 'applied' : 'blocked',
            'writes_database' => $writeVerified,
            'write_verified' => $writeVerified,
            'slug_count' => $writeVerified ? 51 : 0,
            'expected_locale_rows' => $writeVerified ? 102 : 0,
            'created_count' => $writeVerified ? 51 : 0,
            'verified_count' => $writeVerified ? 51 : 0,
            'failures' => [],
            'locales' => ['en', 'zh-CN'],
        ];
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        $slugs = [];
        for ($i = 1; $i <= 51; $i++) {
            $slugs[] = sprintf('delta-%03d', $i);
        }

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailReadyPlan(int $count = 1018): array
    {
        return [
            'schema_version' => 'career_detail_ready_publication_candidates.v1',
            'status' => 'pass',
            'target_key' => 'detail_ready_1048',
            'current_public_total' => 30,
            'target_public_total' => 1048,
            'ready_not_public_1018' => [
                'count' => $count,
                'slugs' => $this->detailReadySlugs($count),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailReadyCandidatePrepPlan(int $deltaCount = 1018): array
    {
        return [
            'schema_version' => 'career_runtime_candidate_prep_plan.v1',
            'status' => 'planned',
            'target' => 'detail_ready_1048',
            'delta_slug_count' => $deltaCount,
            'locales' => ['en', 'zh'],
            'expected_locale_rows' => $deltaCount * 2,
            'planned_candidate_rows_count' => $deltaCount * 2,
            'target_public_total' => 1048,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailReadyCandidatePrepApply(bool $writeVerified, int $deltaCount = 1018): array
    {
        return [
            'status' => $writeVerified ? 'applied' : 'blocked',
            'writes_database' => $writeVerified,
            'write_verified' => $writeVerified,
            'slug_count' => $writeVerified ? $deltaCount : 0,
            'expected_locale_rows' => $writeVerified ? $deltaCount * 2 : 0,
            'created_count' => $writeVerified ? $deltaCount : 0,
            'verified_count' => $writeVerified ? $deltaCount : 0,
            'failures' => [],
            'locales' => ['en', 'zh-CN'],
        ];
    }

    /**
     * @return list<string>
     */
    private function detailReadySlugs(int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('ready-%04d', $i);
        }

        return $slugs;
    }
}
