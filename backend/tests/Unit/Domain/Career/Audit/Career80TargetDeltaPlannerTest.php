<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\Career80TargetDeltaPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Career80TargetDeltaPlannerTest extends TestCase
{
    public function test_passes_for_29_baseline_plus_51_delta(): void
    {
        $baseline = $this->slugs('baseline', 29);
        $delta = $this->slugs('delta', 51);

        $payload = (new Career80TargetDeltaPlanner)->plan(
            readiness: $this->readiness([...$baseline, ...$delta]),
            deltaArtifact: $this->deltaArtifact($delta),
            runtimePool: $this->runtimePool($baseline),
            target: 80,
        )->toArray();

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(29, $payload['published_baseline_count']);
        $this->assertSame(51, $payload['delta_promotion_count']);
        $this->assertSame(80, $payload['target_public_total']);
        $this->assertSame(80, $payload['validation']['baseline_plus_delta_count']);
        $this->assertTrue($payload['validation']['baseline_matches_already_published_evidence']);
        $this->assertSame($delta, $payload['recommended_rollout_delta_slugs']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
    }

    public function test_blocks_duplicate_delta_slugs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delta_slug_duplicate_delta-001');

        (new Career80TargetDeltaPlanner)->plan(
            readiness: $this->readiness(['baseline-001', 'delta-001']),
            deltaArtifact: $this->deltaArtifact(['delta-001', 'delta-001']),
            target: 2,
        );
    }

    public function test_blocks_when_total_does_not_equal_target(): void
    {
        $payload = (new Career80TargetDeltaPlanner)->plan(
            readiness: $this->readiness(['baseline-001', 'delta-001']),
            deltaArtifact: $this->deltaArtifact(['delta-001']),
            target: 3,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('previous_selection_count_mismatch', $payload['blockers'][0]['reason']);
        $this->assertSame('target_total_mismatch', $payload['blockers'][1]['reason']);
    }

    public function test_blocks_when_delta_missing_from_previous_selection(): void
    {
        $payload = (new Career80TargetDeltaPlanner)->plan(
            readiness: $this->readiness(['baseline-001', 'delta-001']),
            deltaArtifact: $this->deltaArtifact(['delta-001', 'delta-002']),
            target: 2,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('delta_slugs_missing_from_previous_selection', array_column($payload['blockers'], 'reason'));
    }

    public function test_blocks_when_already_published_evidence_does_not_match_baseline(): void
    {
        $payload = (new Career80TargetDeltaPlanner)->plan(
            readiness: $this->readiness(['baseline-001', 'delta-001']),
            deltaArtifact: $this->deltaArtifact(['delta-001']),
            runtimePool: $this->runtimePool(['wrong-baseline']),
            target: 2,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('published_baseline_evidence_mismatch', array_column($payload['blockers'], 'reason'));
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%03d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function readiness(array $slugs): array
    {
        sort($slugs);

        return [
            'schema_version' => 'career_80_cohort_readiness.v1',
            'status' => 'pass',
            'readiness_pass' => true,
            'target' => count($slugs),
            'selection' => [
                'strategy' => 'test',
                'slugs' => $slugs,
                'rows' => [],
            ],
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function deltaArtifact(array $slugs): array
    {
        return [
            'schema_version' => 'career_minimum_index_state_remediation.v1',
            'count' => count($slugs),
            'slugs' => $slugs,
        ];
    }

    /**
     * @param  list<string>  $alreadyPublished
     * @return array<string, mixed>
     */
    private function runtimePool(array $alreadyPublished): array
    {
        return [
            'schema_version' => 'career_80_runtime_candidate_pool_plan.v1',
            'runtime_candidate_gate' => [
                'excluded_rows' => array_map(static fn (string $slug): array => [
                    'slug' => $slug,
                    'exclusion_reasons' => ['already_published'],
                ], $alreadyPublished),
            ],
        ];
    }
}
