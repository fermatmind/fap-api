<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\ConfidenceScoreCalculator;
use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\IntegrityStateResolver;

final class ConfidenceScoreCalculatorTest extends CareerScoringTestCase
{
    public function test_low_quality_and_review_pending_reduce_confidence_score(): void
    {
        $highTrustContext = $this->sampleContext();
        $lowTrustContext = $this->sampleContext([
            'reviewer_status' => 'pending',
            'quality_confidence' => 0.54,
        ]);

        $resolver = new IntegrityStateResolver;
        $calculator = new ConfidenceScoreCalculator;
        $policy = new DegradationPolicy;

        $highTrust = $calculator->calculate($highTrustContext, $resolver->resolve('confidence_score', $highTrustContext), $policy);
        $lowTrust = $calculator->calculate($lowTrustContext, $resolver->resolve('confidence_score', $lowTrustContext), $policy);

        $this->assertGreaterThan($lowTrust->value, $highTrust->value);
        $this->assertSame('career.confidence_v1.2', $highTrust->formulaRef);
    }
}
