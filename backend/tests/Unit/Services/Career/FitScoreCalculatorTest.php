<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\FitScoreCalculator;
use App\Domain\Career\Scoring\IntegrityStateResolver;

final class FitScoreCalculatorTest extends CareerScoringTestCase
{
    public function test_it_builds_an_explicit_fit_score_result(): void
    {
        $context = $this->sampleContext();
        $integrity = (new IntegrityStateResolver)->resolve('fit_score', $context);

        $result = (new FitScoreCalculator)->calculate($context, $integrity, new DegradationPolicy);

        $this->assertGreaterThanOrEqual(0, $result->value);
        $this->assertSame('career.fit_v1.2', $result->formulaRef);
        $this->assertArrayHasKey('cognitive_fit', $result->componentBreakdown['inputs']);
        $this->assertArrayHasKey('hard_conflict', $result->componentBreakdown['penalty_inputs']);
        $this->assertSame($integrity['integrity_state'], $result->integrityState);
    }
}
