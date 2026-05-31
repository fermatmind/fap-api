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
        $this->assertSame('career_80_delta', $payload['target']);
        $this->assertSame(51, $payload['delta_slug_count']);
        $this->assertSame(102, $payload['expected_locale_rows']);
        $this->assertSame(102, $payload['planned_candidate_rows_count']);
        $this->assertSame('published_candidate', $payload['planned_candidate_rows'][0]['runtime_publish_state']);
        $this->assertTrue($payload['planned_candidate_rows'][0]['candidate_pre_route_expected']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_plans_220_delta_rows_for_300_progressive_target(): void
    {
        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta($this->slugs('delta', 220), currentTotal: 80, targetTotal: 300, schemaVersion: 'career_progressive_cohort_delta_plan.v1'),
            targetPublicTotal: 300,
            cohort: 'career_80_to_300_delta',
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('career_80_to_300_delta', $payload['target']);
        $this->assertSame(80, $payload['current_public_total']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_locale_rows']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame(440, $payload['planned_candidate_rows_count']);
        $this->assertSame('career_80_to_300_delta_runtime_candidate_prep', $payload['planned_candidate_rows'][0]['source']);
    }

    public function test_plans_500_and_1986_progressive_delta_rows(): void
    {
        $fiveHundred = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta($this->slugs('delta', 500), currentTotal: 300, targetTotal: 800, schemaVersion: 'career_progressive_cohort_delta_plan.v1'),
        )->toArray();

        $this->assertSame('career_300_to_800_delta', $fiveHundred['target']);
        $this->assertSame(500, $fiveHundred['delta_slug_count']);
        $this->assertSame(1000, $fiveHundred['expected_delta_locale_rows']);

        $full = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta($this->slugs('delta', 1986), currentTotal: 800, targetTotal: 2786, schemaVersion: 'career_progressive_cohort_delta_plan.v1'),
        )->toArray();

        $this->assertSame('career_800_to_2786_delta', $full['target']);
        $this->assertSame(1986, $full['delta_slug_count']);
        $this->assertSame(3972, $full['expected_delta_locale_rows']);
    }

    public function test_plans_detail_ready_1048_delta_from_publication_scan_with_chunks(): void
    {
        $slugs = $this->slugs('ready', 1018);

        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: [
                'schema_version' => 'career_detail_ready_publication_candidates.v1',
                'status' => 'pass',
                'target_key' => 'detail_ready_1048',
                'current_public_total' => 30,
                'target_public_total' => 1048,
                'ready_not_public_1018' => [
                    'count' => 1018,
                    'slugs' => $slugs,
                ],
                'manual_hold' => [
                    'ready_slugs' => [],
                ],
            ],
            targetPublicTotal: 1048,
            cohort: 'detail_ready_1048',
            chunkSize: 400,
        )->toArray();

        $this->assertSame('planned', $payload['status']);
        $this->assertSame('detail_ready_1048', $payload['target']);
        $this->assertSame(30, $payload['current_public_total']);
        $this->assertSame(1048, $payload['target_public_total']);
        $this->assertSame(1018, $payload['delta_slug_count']);
        $this->assertSame(2036, $payload['expected_delta_locale_rows']);
        $this->assertSame('detail_ready_1048', $payload['target_authority']['target_key']);
        $this->assertCount(3, $payload['chunked_slug_artifacts']);
        $this->assertSame([400, 400, 218], array_column($payload['chunked_slug_artifacts'], 'slug_count'));
        $this->assertFalse($payload['chunked_slug_artifacts'][0]['writes_database']);
        $this->assertFalse($payload['chunked_slug_artifacts'][0]['apply_allowed']);
    }

    public function test_detail_ready_1048_blocks_gated_slugs(): void
    {
        $slugs = $this->slugs('ready', 1017);
        $slugs[] = 'software-developers';

        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: [
                'schema_version' => 'career_detail_ready_publication_candidates.v1',
                'status' => 'pass',
                'target_key' => 'detail_ready_1048',
                'current_public_total' => 30,
                'target_public_total' => 1048,
                'ready_not_public_1018' => [
                    'count' => 1018,
                    'slugs' => $slugs,
                ],
                'manual_hold' => [
                    'ready_slugs' => ['software-developers'],
                ],
                'review_needed' => [
                    'slugs' => ['ready-001'],
                ],
                'family_handoff' => [
                    'slugs' => ['ready-002'],
                ],
                'blocked' => [
                    'slugs' => ['ready-003'],
                ],
                'cn_proxy' => [
                    'slugs' => ['ready-004'],
                ],
            ],
            targetPublicTotal: 1048,
            cohort: 'detail_ready_1048',
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $reasons = array_column($payload['blockers'], 'reason');
        $this->assertContains('delta_contains_manual_hold_slugs', $reasons);
        $this->assertContains('delta_contains_manual_hold_policy_slugs', $reasons);
        $this->assertContains('delta_contains_review_needed_slugs', $reasons);
        $this->assertContains('delta_contains_family_handoff_slugs', $reasons);
        $this->assertContains('delta_contains_blocked_slugs', $reasons);
        $this->assertContains('delta_contains_cn_proxy_slugs', $reasons);
    }

    public function test_detail_ready_1048_blocks_fallback_gated_slug_lists(): void
    {
        $slugs = $this->slugs('ready', 1018);

        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: [
                'schema_version' => 'career_detail_ready_publication_candidates.v1',
                'status' => 'pass',
                'target_key' => 'detail_ready_1048',
                'current_public_total' => 30,
                'target_public_total' => 1048,
                'ready_not_public_1018' => [
                    'count' => 1018,
                    'slugs' => $slugs,
                ],
                'manual_hold' => [
                    'ready_slugs' => [],
                    'slugs' => ['ready-001'],
                ],
                'blocked' => [
                    'ready_slugs' => [],
                    'slugs' => ['ready-002'],
                ],
                'cn_proxy' => [
                    'ready_slugs' => [],
                    'slugs' => [],
                ],
                'cn_proxy_policy_asset' => [
                    'slugs' => ['ready-003'],
                ],
            ],
            targetPublicTotal: 1048,
            cohort: 'detail_ready_1048',
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $reasons = array_column($payload['blockers'], 'reason');
        $this->assertContains('delta_contains_manual_hold_slugs', $reasons);
        $this->assertContains('delta_contains_blocked_slugs', $reasons);
        $this->assertContains('delta_contains_cn_proxy_slugs', $reasons);
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

    public function test_blocks_target_public_total_mismatch(): void
    {
        $payload = (new CareerRuntimeCandidatePrepPlanner)->plan(
            targetDeltaPlan: $this->targetDelta($this->slugs('delta', 220), currentTotal: 80, targetTotal: 300, schemaVersion: 'career_progressive_cohort_delta_plan.v1'),
            targetPublicTotal: 800,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('target_public_total_mismatch', $payload['blockers'][0]['reason']);
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
            'current_public_total',
            'target_public_total',
            'delta_slug_count',
            'locales',
            'expected_locale_rows',
            'expected_delta_locale_rows',
            'planned_candidate_rows_count',
            'planned_candidate_rows',
            'target_authority',
            'chunked_slug_artifacts',
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
    private function targetDelta(array $slugs, int $currentTotal = 29, int $targetTotal = 80, string $schemaVersion = 'career_80_target_delta.v1'): array
    {
        return [
            'schema_version' => $schemaVersion,
            'status' => 'pass',
            'current_public_total' => $currentTotal,
            'target_public_total' => $targetTotal,
            'delta_promotion_count' => count($slugs),
            'delta_slug_count' => count($slugs),
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
