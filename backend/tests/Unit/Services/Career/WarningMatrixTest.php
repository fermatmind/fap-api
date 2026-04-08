<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Scoring\CareerScoreResult;
use App\Domain\Career\Scoring\IntegrityState;
use App\Domain\Career\Scoring\WarningMatrix;

final class WarningMatrixTest extends CareerScoringTestCase
{
    public function test_it_emits_explicit_red_and_amber_flags_and_blocked_claims(): void
    {
        $warnings = (new WarningMatrix)->build(
            $this->sampleContext([
                'median_pay_usd_annual' => null,
                'cross_market_mismatch' => true,
                'allow_pay_direct_inheritance' => false,
                'reviewer_status' => 'pending',
                'editorial_patch_required' => true,
                'editorial_patch_complete' => false,
            ]),
            [
                'fit_score' => new CareerScoreResult(72, IntegrityState::PROVISIONAL, [], 70, 'fit', [], [], 0.88),
                'strain_score' => new CareerScoreResult(48, IntegrityState::FULL, [], 82, 'strain', [], [], 1.0),
                'ai_survival_score' => new CareerScoreResult(22, IntegrityState::BLOCKED, ['ai_exposure'], 42, 'ai', [], [], 0.3),
                'mobility_score' => new CareerScoreResult(44, IntegrityState::RESTRICTED, ['crosswalk_confidence'], 58, 'mobility', [], [], 0.7),
                'confidence_score' => new CareerScoreResult(51, IntegrityState::PROVISIONAL, [], 60, 'confidence', [], [], 0.82),
            ]
        );

        $this->assertContains('missing_median_pay', $warnings['red_flags']);
        $this->assertContains('cross_market_mismatch', $warnings['amber_flags']);
        $this->assertContains('salary_comparison', $warnings['blocked_claims']);
        $this->assertContains('strong_claim', $warnings['blocked_claims']);
    }

    public function test_it_blocks_exposure_claims_for_noindex_or_unavailable_states(): void
    {
        $warnings = (new WarningMatrix)->build(
            $this->sampleContext([
                'index_state' => IndexStateValue::NOINDEX,
                'index_eligible' => false,
            ]),
            [
                'fit_score' => new CareerScoreResult(80, IntegrityState::FULL, [], 88, 'fit', [], [], 1.0),
                'strain_score' => new CareerScoreResult(32, IntegrityState::FULL, [], 88, 'strain', [], [], 1.0),
                'ai_survival_score' => new CareerScoreResult(61, IntegrityState::FULL, [], 88, 'ai', [], [], 1.0),
                'mobility_score' => new CareerScoreResult(66, IntegrityState::FULL, [], 88, 'mobility', [], [], 1.0),
                'confidence_score' => new CareerScoreResult(77, IntegrityState::FULL, [], 88, 'confidence', [], [], 1.0),
            ]
        );

        $this->assertContains('index_state_restricted', $warnings['red_flags']);
        $this->assertContains('strong_claim', $warnings['blocked_claims']);
        $this->assertContains('salary_comparison', $warnings['blocked_claims']);
        $this->assertContains('ai_strategy', $warnings['blocked_claims']);
        $this->assertContains('transition_recommendation', $warnings['blocked_claims']);
    }
}
