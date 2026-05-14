<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Audit;

use App\Domain\Career\Audit\CareerProgressiveCohortDeltaPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CareerProgressiveCohortDeltaPlannerTest extends TestCase
{
    public function test_models_80_to_300_progressive_delta(): void
    {
        $current = $this->slugs('current', 80);
        $delta = $this->slugs('delta', 220);

        $payload = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(80),
            currentPublicSlugs: $current,
            targetSelection: $this->targetSelection([...$current, ...$delta]),
            targetPublicTotal: 300,
            locales: ['en', 'zh'],
        )->toArray();

        $this->assertSame('career_progressive_cohort_delta_plan.v1', $payload['schema_version']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(80, $payload['current_public_total']);
        $this->assertSame(300, $payload['target_public_total']);
        $this->assertSame(220, $payload['delta_slug_count']);
        $this->assertSame(440, $payload['expected_delta_locale_rows']);
        $this->assertSame(600, $payload['expected_total_locale_rows']);
        $this->assertSame($delta, $payload['recommended_rollout_delta_slugs']);
        $this->assertFalse($payload['writes_database']);
        $this->assertFalse($payload['rollout']['apply_allowed']);
    }

    public function test_models_300_to_800_progressive_delta(): void
    {
        $current = $this->slugs('current', 300);
        $delta = $this->slugs('delta', 500);

        $payload = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(300),
            currentPublicSlugs: $current,
            targetSelection: $this->targetSelection([...$current, ...$delta]),
            targetPublicTotal: 800,
        )->toArray();

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(500, $payload['delta_slug_count']);
        $this->assertSame(1000, $payload['expected_delta_locale_rows']);
        $this->assertSame(1600, $payload['expected_total_locale_rows']);
    }

    public function test_models_800_to_2786_progressive_delta(): void
    {
        $current = $this->slugs('current', 800);
        $delta = $this->slugs('delta', 1986);

        $payload = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(800),
            currentPublicSlugs: $current,
            targetSelection: $this->targetSelection([...$current, ...$delta]),
            targetPublicTotal: 2786,
        )->toArray();

        $this->assertSame('pass', $payload['status']);
        $this->assertSame(1986, $payload['delta_slug_count']);
        $this->assertSame(3972, $payload['expected_delta_locale_rows']);
        $this->assertSame(5572, $payload['expected_total_locale_rows']);
    }

    public function test_target_less_than_or_equal_to_current_blocks(): void
    {
        $current = $this->slugs('current', 80);

        $payload = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(80),
            currentPublicSlugs: $current,
            targetSelection: $this->targetSelection($current),
            targetPublicTotal: 80,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('target_not_greater_than_current', array_column($payload['blockers'], 'reason'));
    }

    public function test_current_slugs_must_remain_in_target_selection(): void
    {
        $current = ['current-001', 'current-002'];
        $target = ['current-001', 'delta-001', 'delta-002'];

        $payload = (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(2),
            currentPublicSlugs: $current,
            targetSelection: $this->targetSelection($target),
            targetPublicTotal: 3,
        )->toArray();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('current_public_missing_from_target_selection', array_column($payload['blockers'], 'reason'));
    }

    public function test_duplicate_target_selection_slug_blocks(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target_selection_slug_duplicate_delta-001');

        (new CareerProgressiveCohortDeltaPlanner)->plan(
            currentCloseout: $this->closeout(1),
            currentPublicSlugs: ['current-001'],
            targetSelection: $this->targetSelection(['current-001', 'delta-001', 'delta-001']),
            targetPublicTotal: 3,
        );
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

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function targetSelection(array $slugs): array
    {
        sort($slugs);

        return [
            'schema_version' => 'career_progressive_target_selection.v1',
            'status' => 'pass',
            'selection' => [
                'slugs' => $slugs,
            ],
        ];
    }
}
