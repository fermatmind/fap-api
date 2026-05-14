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
            'candidate_prep_required',
            'candidate_prep_apply_required',
            'writes_database',
            'read_only',
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
            'created_count' => $writeVerified ? 51 : 0,
            'verified_count' => $writeVerified ? 51 : 0,
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
}
