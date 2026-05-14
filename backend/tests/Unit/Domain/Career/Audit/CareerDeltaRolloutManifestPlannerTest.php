<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerDeltaRolloutManifestPlanner;
use Tests\TestCase;

final class CareerDeltaRolloutManifestPlannerTest extends TestCase
{
    public function test_generates_51_delta_manifest_with_80_total_accounting(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->targetDeltaPlan($this->slugs('baseline', 29), $this->slugs('delta', 51)),
        )->toArray();

        $this->assertSame('career_delta_rollout_manifest.v1', $result['schema_version']);
        $this->assertSame('pass', $result['status']);
        $this->assertSame(80, $result['target_public_total']);
        $this->assertSame(29, $result['published_baseline_count']);
        $this->assertSame(51, $result['delta_slug_count']);
        $this->assertSame(102, $result['expected_delta_locale_rows']);
        $this->assertSame('career_80_delta_canonical_001', $result['batch_id']);
        $this->assertSame($this->slugs('delta', 51), $result['slugs']);
        $this->assertSame($result['slugs'], $result['rollback_group']);
        $this->assertFalse($result['apply_allowed']);
        $this->assertTrue($result['dry_run_allowed']);
        $this->assertFalse($result['writes_database']);
    }

    public function test_generates_220_delta_manifest_for_300_total_progressive_cohort(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->progressiveTargetDeltaPlan($this->slugs('current', 80), $this->slugs('delta', 220), 300),
            targetPublicTotal: 300,
            expectedDeltaCount: 220,
            batchId: 'career_80_to_300_canonical_001',
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertSame('career_80_to_300_delta', $result['target']);
        $this->assertSame(300, $result['target_public_total']);
        $this->assertSame(80, $result['published_baseline_count']);
        $this->assertSame(220, $result['delta_slug_count']);
        $this->assertSame(440, $result['expected_delta_locale_rows']);
        $this->assertSame(220, $result['validation']['rollback_group_count']);
        $this->assertSame($result['slugs'], $result['rollback_group']);
        $this->assertTrue($result['dry_run_allowed']);
        $this->assertFalse($result['apply_allowed']);
        $this->assertSame('PROGRESSIVE_ROLLOUT_DRY_RUN', $result['next_required_action']);
    }

    public function test_generates_500_and_1986_delta_progressive_manifests(): void
    {
        $eightHundred = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->progressiveTargetDeltaPlan($this->slugs('current', 300), $this->slugs('delta', 500), 800),
            targetPublicTotal: 800,
            expectedDeltaCount: 500,
        )->toArray();

        $this->assertSame('career_300_to_800_delta', $eightHundred['target']);
        $this->assertSame(500, $eightHundred['delta_slug_count']);
        $this->assertSame(1000, $eightHundred['expected_delta_locale_rows']);

        $full = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->progressiveTargetDeltaPlan($this->slugs('current', 800), $this->slugs('delta', 1986), 2786),
            targetPublicTotal: 2786,
            expectedDeltaCount: 1986,
        )->toArray();

        $this->assertSame('career_800_to_2786_delta', $full['target']);
        $this->assertSame(1986, $full['delta_slug_count']);
        $this->assertSame(3972, $full['expected_delta_locale_rows']);
    }

    public function test_blocks_when_target_delta_is_not_passed(): void
    {
        $plan = $this->targetDeltaPlan(['baseline-001'], ['delta-001'], target: 2);
        $plan['status'] = 'blocked';

        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $plan,
            targetPublicTotal: 2,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('target_delta_not_passed', array_column($result['blockers'], 'reason'));
        $this->assertFalse($result['dry_run_allowed']);
        $this->assertFalse($result['apply_allowed']);
    }

    public function test_blocks_when_baseline_slug_is_in_delta_list(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->targetDeltaPlan(['shared-slug'], ['shared-slug'], target: 2),
            targetPublicTotal: 2,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('baseline_slug_in_delta_manifest', array_column($result['blockers'], 'reason'));
    }

    public function test_expected_locale_rows_use_delta_only_count(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->targetDeltaPlan(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            targetPublicTotal: 3,
            expectedDeltaCount: 2,
            locales: ['en', 'zh', 'es'],
        )->toArray();

        $this->assertSame('pass', $result['status']);
        $this->assertSame(6, $result['expected_delta_locale_rows']);
        $this->assertSame(6, $result['batches'][0]['expected_delta_locale_rows']);
    }

    public function test_candidate_prep_plan_mismatch_blocks(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->targetDeltaPlan(['baseline-001'], ['delta-001', 'delta-002'], target: 3),
            candidatePrepPlan: [
                'schema_version' => 'career_runtime_candidate_prep_plan.v1',
                'status' => 'planned',
                'delta_slug_count' => 1,
            ],
            targetPublicTotal: 3,
            expectedDeltaCount: 2,
        )->toArray();

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('candidate_prep_delta_count_mismatch', array_column($result['blockers'], 'reason'));
    }

    public function test_schema_is_stable(): void
    {
        $result = (new CareerDeltaRolloutManifestPlanner)->plan(
            targetDeltaPlan: $this->targetDeltaPlan(['baseline-001'], ['delta-001'], target: 2),
            targetPublicTotal: 2,
            expectedDeltaCount: 1,
        )->toArray();

        $this->assertSame([
            'schema_version',
            'status',
            'target',
            'target_public_total',
            'published_baseline_count',
            'delta_slug_count',
            'selected_count',
            'expected_delta_locale_rows',
            'batch_id',
            'locales',
            'published_baseline_slugs',
            'slugs',
            'rollback_group',
            'read_only',
            'writes_database',
            'rollout_allowed',
            'dry_run_allowed',
            'apply_allowed',
            'rollout_dry_run_executed',
            'rollout_apply_executed',
            'source_target_delta',
            'source_candidate_prep_plan',
            'validation',
            'batches',
            'blockers',
            'sidecars',
            'next_required_action',
        ], array_keys($result));
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
     * @param  list<string>  $baseline
     * @param  list<string>  $delta
     * @return array<string, mixed>
     */
    private function targetDeltaPlan(array $baseline, array $delta, int $target = 80): array
    {
        sort($baseline);
        sort($delta);

        return [
            'schema_version' => 'career_80_target_delta.v1',
            'status' => 'pass',
            'target_public_total' => $target,
            'published_baseline_count' => count($baseline),
            'delta_promotion_count' => count($delta),
            'published_baseline_slugs' => $baseline,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
            'rollout' => [
                'delta_manifest_allowed' => true,
                'apply_allowed' => false,
            ],
        ];
    }

    /**
     * @param  list<string>  $current
     * @param  list<string>  $delta
     * @return array<string, mixed>
     */
    private function progressiveTargetDeltaPlan(array $current, array $delta, int $target): array
    {
        sort($current);
        sort($delta);

        return [
            'schema_version' => 'career_progressive_cohort_delta_plan.v1',
            'status' => 'pass',
            'read_only' => true,
            'writes_database' => false,
            'current_public_total' => count($current),
            'target_public_total' => $target,
            'delta_slug_count' => count($delta),
            'expected_delta_locale_rows' => count($delta) * 2,
            'current_public_slugs' => $current,
            'published_baseline_slugs' => $current,
            'delta_promotion_slugs' => $delta,
            'recommended_rollout_delta_slugs' => $delta,
            'rollout' => [
                'delta_manifest_allowed' => true,
                'apply_allowed' => false,
            ],
        ];
    }
}
