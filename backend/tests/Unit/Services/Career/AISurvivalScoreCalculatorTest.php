<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\AISurvivalScoreCalculator;
use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\IntegrityState;
use App\Domain\Career\Scoring\IntegrityStateResolver;

final class AISurvivalScoreCalculatorTest extends CareerScoringTestCase
{
    public function test_missing_ai_exposure_blocks_ai_survival_integrity(): void
    {
        $context = $this->sampleContext([
            'ai_exposure' => null,
        ]);
        $integrity = (new IntegrityStateResolver)->resolve('ai_survival_score', $context);

        $result = (new AISurvivalScoreCalculator)->calculate($context, $integrity, new DegradationPolicy);

        $this->assertSame(IntegrityState::BLOCKED, $result->integrityState);
        $this->assertContains('ai_exposure', $result->criticalMissingFields);
        $this->assertSame('career.ai_survival_v1.2', $result->formulaRef);
    }
}
