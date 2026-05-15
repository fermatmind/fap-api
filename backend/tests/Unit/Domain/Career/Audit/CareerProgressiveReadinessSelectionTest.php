<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerProgressiveCohortDeltaPlanner;
use App\Domain\Career\Audit\CareerProgressiveReadinessSelector;
use App\Domain\Career\Audit\CareerPublicResolutionPlan;
use App\Domain\Career\Audit\CareerPublicResolutionPlanRow;
use PHPUnit\Framework\TestCase;

final class CareerProgressiveReadinessSelectionTest extends TestCase
{
    public function test_80_to_300_selects_220_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $this->assertSame('career_progressive_readiness_selection.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame($delta, $payload['selected_slugs']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['apply_allowed']);
    }

    public function test_300_to_800_selects_500_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 300);
        $delta = $this->slugs('delta', 500);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 300, 800);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(500, $payload['delta_slug_count']);
        $this->assertSame(500, $payload['selected_count']);
        $this->assertSame(1000, $payload['expected_delta_locale_rows']);
    }

    public function test_800_to_2786_selects_1986_delta_slugs(): void
    {
        $baseline = $this->slugs('current', 800);
        $delta = $this->slugs('delta', 1986);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 800, 2786);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(1986, $payload['delta_slug_count']);
        $this->assertSame(1986, $payload['selected_count']);
        $this->assertSame(3972, $payload['expected_delta_locale_rows']);
    }

    public function test_excludes_baseline_slugs_from_delta_selection(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);

        $payload = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($delta, $payload['selected_slugs']);
        $this->assertSame([], array_values(array_intersect($baseline, $payload['selected_slugs'])));
        $this->assertSame(80, $payload['excluded']['excluded_by_reason']['already_public_baseline']);
    }

    public function test_duplicate_source_slugs_block(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002', 'career-002'], 1, 2);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('duplicate_source_slugs', array_column($payload['blockers'], 'reason'));
    }

    public function test_target_less_than_or_equal_to_current_blocks(): void
    {
        $payload = $this->select(['career-001'], ['career-001'], 1, 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_greater_than_current', array_column($payload['blockers'], 'reason'));
    }

    public function test_blocks_when_insufficient_source_ready_slugs(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002'], 1, 300);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('insufficient_source_ready_delta_slugs', array_column($payload['blockers'], 'reason'));
    }

    public function test_preserves_deterministic_source_order(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $source = [$delta[2], ...$baseline, $delta[0], $delta[1], ...array_slice($delta, 3)];
        $payload = $this->select($baseline, $source, 80, 300);

        $this->assertSame('pass', $payload['status']);
        $this->assertSame($source[0], $payload['selected_slugs'][0]);
        $this->assertSame($delta[0], $payload['selected_slugs'][1]);
        $this->assertSame($delta[1], $payload['selected_slugs'][2]);
    }

    public function test_output_can_feed_progressive_target_delta_planner(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $selection = $this->select($baseline, [...$baseline, ...$delta], 80, 300);

        $deltaPlan = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(80),
            currentPublicSlugs: $baseline,
            targetSelection: $selection,
            targetPublicTotal: 300,
            locales: ['en', 'zh'],
        )->toArray();

        $this->assertSame('pass', $deltaPlan['status']);
        $this->assertSame(220, $deltaPlan['delta_slug_count']);
        $this->assertSame($delta, $deltaPlan['recommended_rollout_delta_slugs']);
    }

    public function test_entity_context_occupation_exists_filter_replaces_missing_occupations(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 225);
        $missing = array_slice($delta, 0, 5);
        $occupationExisting = array_values(array_diff($delta, $missing));

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$delta],
            currentTotal: 80,
            targetTotal: 300,
            occupationExistingSlugs: $occupationExisting,
        );

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(220, $payload['selected_count']);
        $this->assertSame($occupationExisting, $payload['selected_slugs']);
        $this->assertSame(225, $payload['source_ready_count']);
        $this->assertSame(220, $payload['candidate_count']);
        $this->assertSame(5, $payload['excluded']['excluded_by_reason']['occupation_missing']);
        $this->assertTrue($payload['entity_context']['required_for_selection']);
        $this->assertSame(220, $payload['entity_context']['occupation_exists_count']);
        $this->assertSame(5, $payload['entity_context']['occupation_missing_excluded_count']);
    }

    public function test_entity_context_blocks_when_not_enough_occupation_present_candidates(): void
    {
        $baseline = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);
        $occupationExisting = array_slice($delta, 0, 219);

        $payload = $this->select(
            baseline: $baseline,
            sourceSlugs: [...$baseline, ...$delta],
            currentTotal: 80,
            targetTotal: 300,
            occupationExistingSlugs: $occupationExisting,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(219, $payload['selected_count']);
        $this->assertContains('insufficient_source_ready_delta_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertSame(1, $payload['blockers'][0]['evidence']['occupation_missing_excluded_count']);
        $this->assertSame(219, $payload['blockers'][0]['evidence']['selectable_candidate_count']);
    }

    public function test_readiness_selection_does_not_require_rollout_candidate_runtime_state(): void
    {
        $payload = $this->select(['career-001'], ['career-001', 'career-002'], 1, 300);

        $this->assertSame('blocked', $payload['status']);
        $this->assertNotContains('insufficient_rollout_candidate_eligible_slugs', array_column($payload['blockers'], 'reason'));
        $this->assertSame(1, $payload['candidate_count']);
    }

    /**
     * @param  list<string>  $baseline
     * @param  list<string>  $sourceSlugs
     * @param  list<string>|null  $occupationExistingSlugs
     * @return array<string, mixed>
     */
    private function select(
        array $baseline,
        array $sourceSlugs,
        int $currentTotal,
        int $targetTotal,
        ?array $occupationExistingSlugs = null,
    ): array {
        return (new CareerProgressiveReadinessSelector)->select(
            sourcePlan: $this->sourcePlan($sourceSlugs),
            currentCloseout: $this->closeout($currentTotal),
            currentPublicSlugs: $baseline,
            currentPublicTotal: $currentTotal,
            targetPublicTotal: $targetTotal,
            locales: ['en', 'zh'],
            occupationExistingSlugs: $occupationExistingSlugs,
        )->toArray();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function sourcePlan(array $slugs): CareerPublicResolutionPlan
    {
        $rows = [];
        foreach ($slugs as $index => $slug) {
            $rows[] = CareerPublicResolutionPlanRow::fromRaw([
                'row_number' => $index + 1,
                'canonical_slug' => $slug,
                'status' => 'ready_for_pilot',
                'canonical_public_type' => 'public_canonical_job',
                'locales' => ['en', 'zh'],
            ]);
        }

        return new CareerPublicResolutionPlan('/tmp/synthetic-career-source-plan.json', null, $rows);
    }

    /**
     * @return list<string>
     */
    private function slugs(string $prefix, int $count): array
    {
        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slugs[] = sprintf('%s-%04d', $prefix, $i);
        }

        return $slugs;
    }

    /**
     * @return array<string, mixed>
     */
    private function closeout(int $total): array
    {
        return [
            'schema_version' => 'career_progressive_cohort_closeout.v1',
            'status' => 'complete',
            'accepted' => true,
            'target_public_total' => $total,
            'total_slug_count' => $total,
        ];
    }
}
