<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Rules\WeightCalculator;
use Tests\TestCase;

final class WeightCalculatorTest extends TestCase
{
    public function test_tension_and_distance_from_midpoint_increase_weight(): void
    {
        $calculator = new WeightCalculator;
        $formula = 'abs(O-50)+abs(C-50)+abs(O-C)*0.5';

        $mild = $calculator->calculate($formula, $this->context(['O' => 70, 'C' => 35]), 0);
        $strong = $calculator->calculate($formula, $this->context(['O' => 82, 'C' => 28]), 0);
        $extreme = $calculator->calculate($formula, $this->context(['O' => 92, 'C' => 12]), 0);

        $this->assertGreaterThan($mild, $strong);
        $this->assertGreaterThan($strong, $extreme);
    }

    public function test_invalid_formula_uses_fallback(): void
    {
        $this->assertSame(42.0, (new WeightCalculator)->calculate('not-a-formula', $this->context(['O' => 80]), 42));
    }

    /**
     * @param  array<string,int>  $scores
     */
    private function context(array $scores): ReportContext
    {
        $domains = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $traitCode) {
            $domains[$traitCode] = ['percentile' => $scores[$traitCode] ?? 50];
        }

        return new ReportContext('zh-CN', 'BIG5_OCEAN', 'big5_90', $domains, []);
    }
}
