<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\IntegrityStateResolver;
use App\Domain\Career\Scoring\StrainScoreCalculator;

final class StrainScoreCalculatorTest extends CareerScoringTestCase
{
    public function test_it_builds_an_explicit_strain_score_result(): void
    {
        $context = $this->sampleContext();
        $integrity = (new IntegrityStateResolver)->resolve('strain_score', $context);

        $result = (new StrainScoreCalculator)->calculate($context, $integrity, new DegradationPolicy);

        $this->assertSame('career.strain_v1.2', $result->formulaRef);
        $this->assertArrayHasKey('people_friction', $result->componentBreakdown['inputs']);
        $this->assertArrayHasKey('context_switch_load', $result->componentBreakdown['inputs']);
        $this->assertGreaterThanOrEqual(0, $result->value);
    }
}
