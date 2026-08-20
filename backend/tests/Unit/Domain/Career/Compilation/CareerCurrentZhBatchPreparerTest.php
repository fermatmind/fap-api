<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerCurrentZhBatchPreparer;
use Tests\TestCase;

final class CareerCurrentZhBatchPreparerTest extends TestCase
{
    public function test_it_selects_all_1046_canonical_slugs_without_maturity_filtering(): void
    {
        $slugs = array_map(static fn (int $index): string => sprintf('career-%04d', $index), range(1, 1046));
        $controls = [$slugs[0], $slugs[1], $slugs[2]];

        $plan = app(CareerCurrentZhBatchPreparer::class)->planBatches($slugs, $controls);

        self::assertCount(21, $plan['batches']);
        self::assertSame(array_merge(array_fill(0, 20, 50), [46]), array_column($plan['batches'], 'target_count'));
        self::assertSame(1046, $plan['target_union_count']);
        self::assertSame([], $plan['duplicate_target_slugs']);
        self::assertSame([], $plan['missing_target_slugs']);
        self::assertSame([], $plan['unexpected_target_slugs']);
        self::assertSame($controls, $plan['batches'][0]['control_slugs']);
        self::assertSame(array_values(array_unique(array_merge($controls, $plan['batches'][0]['target_slugs']))), $plan['batches'][1]['control_slugs']);
    }

    public function test_the_c3_preparer_has_no_ready_now_or_blocked_candidate_dependency(): void
    {
        $source = (string) file_get_contents(base_path('app/Domain/Career/Compilation/CareerCurrentZhBatchPreparer.php'));

        self::assertStringNotContainsString('READY_NOW', $source);
        self::assertStringNotContainsString('BLOCKED_NO_READY_CANDIDATE', $source);
    }
}
