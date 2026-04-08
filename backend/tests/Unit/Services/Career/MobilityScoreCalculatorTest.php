<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\IntegrityStateResolver;
use App\Domain\Career\Scoring\MobilityScoreCalculator;

final class MobilityScoreCalculatorTest extends CareerScoringTestCase
{
    public function test_cross_market_salary_shock_lowers_mobility_score_components(): void
    {
        $baselineContext = $this->sampleContext();
        $crossMarketContext = $this->sampleContext([
            'cross_market_mismatch' => true,
            'allow_pay_direct_inheritance' => false,
        ]);

        $resolver = new IntegrityStateResolver;
        $calculator = new MobilityScoreCalculator;
        $policy = new DegradationPolicy;

        $baseline = $calculator->calculate($baselineContext, $resolver->resolve('mobility_score', $baselineContext), $policy);
        $crossMarket = $calculator->calculate($crossMarketContext, $resolver->resolve('mobility_score', $crossMarketContext), $policy);

        $this->assertGreaterThan($crossMarket->value, $baseline->value);
        $this->assertSame('career.mobility_v1.2', $baseline->formulaRef);
        $this->assertArrayHasKey('salary_shock', $crossMarket->componentBreakdown['inputs']);
    }
}
