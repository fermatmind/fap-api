<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerRuntimeCandidatePrepPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CareerRuntimeCandidatePrepPlannerTest extends TestCase
{
    public function test_plans_51_delta_slug_rows(): void
    {
        $slugs = $this->slugs('delta', 51);

        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta($slugs),
            projection: $this->projection([]),
            truth: $this->truth([]),
            ledger: $this->ledger([]),
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('career_runtime_candidate_prep_plan.v1', $payload['schema_version']);
        $this->assertSame(51, $payload['delta_slug_count']);
        $this->assertSame(102, $payload['expected_locale_rows']);
        $this->assertSame(102, $payload['planned_candidate_rows_count']);
        $this->assertSame('published_candidate', $payload['planned_candidate_rows'][0]['runtime_publish_state']);
        $this->assertTrue($payload['planned_candidate_rows'][0]['candidate_pre_route_expected']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_blocks_target_delta_plan_that_did_not_pass(): void
    {
        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: [
                ...$this->targetDelta(['delta-001']),
                'status' => 'blocked',
            ],
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_delta_plan_not_pass', $payload['blockers'][0]['reason']);
        $this->assertSame(2, $payload['planned_candidate_rows_count']);
    }

    public function test_blocks_duplicate_delta_slugs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('delta_slug_duplicate_delta-001');

        (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: [
                ...$this->targetDelta(['delta-001']),
                'recommended_rollout_delta_slugs' => ['delta-001', 'delta-001'],
            ],
        );
    }

    public function test_counts_existing_context_gaps_and_repairs(): void
    {
        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta(['delta-001', 'delta-002']),
            projection: $this->projection([
                'delta-001' => 'blocked',
            ]),
            truth: $this->truth([
                'delta-001' => 'published_candidate',
            ]),
            ledger: $this->ledger(['delta-001']),
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame(1, $payload['context_summary']['ledger_member_missing_count']);
        $this->assertSame(1, $payload['context_summary']['projection_row_missing_count']);
        $this->assertSame(1, $payload['context_summary']['truth_row_missing_count']);
        $this->assertSame(1, $payload['context_summary']['candidate_state_repair_needed_count']);
        $this->assertSame(['delta-002'], $payload['context_slug_sets']['ledger_member_missing']);
        $this->assertSame(['delta-001'], $payload['context_slug_sets']['candidate_state_repair_needed']);
    }

    public function test_schema_is_stable(): void
    {
        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta(['delta-001']),
        )->toArray();

        $this->assertSame([
            'schema_version',
            'status',
            'read_only',
            'writes_database',
            'target',
            'target_public_total',
            'delta_slug_count',
            'locales',
            'expected_locale_rows',
            'planned_candidate_rows_count',
            'planned_candidate_rows',
            'slug_rows',
            'context_summary',
            'context_slug_sets',
            'blockers',
            'approval_gate',
            'apply_allowed',
            'next_required_action',
        ], array_keys($payload));
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
    private function targetDelta(array $slugs): array
    {
        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => 80,
            'delta_promotion_count' => count($slugs),
            'recommended_rollout_delta_slugs' => $slugs,
        ];
    }

    /**
     * @param  array<string, string>  $states
     * @return array<string, mixed>
     */
    private function projection(array $states): array
    {
        return ['items' => $this->runtimeRows($states, 'runtime_publish_state')];
    }

    /**
     * @param  array<string, string>  $states
     * @return array<string, mixed>
     */
    private function truth(array $states): array
    {
        return ['items' => $this->runtimeRows($states, 'projection_state')];
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function ledger(array $slugs): array
    {
        return [
            'members' => array_map(static fn (string $slug): array => [
                'canonical_slug' => $slug,
                'release_cohort' => 'public_detail_conservative',
            ], $slugs),
        ];
    }

    /**
     * @param  array<string, string>  $states
     * @return list<array<string, mixed>>
     */
    private function runtimeRows(array $states, string $stateKey): array
    {
        $rows = [];
        foreach ($states as $slug => $state) {
            foreach (['en', 'zh'] as $locale) {
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    $stateKey => $state,
                ];
            }
        }

        return $rows;
    }
}
