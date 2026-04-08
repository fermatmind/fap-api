<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Scoring\CareerScoreResult;
use App\Domain\Career\Scoring\ClaimPermissionsCompiler;
use App\Domain\Career\Scoring\IntegrityState;

final class ClaimPermissionsCompilerTest extends CareerScoringTestCase
{
    public function test_it_compiles_explicit_claim_permissions_without_field_presence_guessing(): void
    {
        $permissions = (new ClaimPermissionsCompiler)->compile(
            [
                'fit_score' => new CareerScoreResult(74, IntegrityState::FULL, [], 84, 'fit', [], [], 1.0),
                'strain_score' => new CareerScoreResult(52, IntegrityState::FULL, [], 82, 'strain', [], [], 1.0),
                'ai_survival_score' => new CareerScoreResult(46, IntegrityState::PROVISIONAL, [], 74, 'ai', [], [], 0.88),
                'mobility_score' => new CareerScoreResult(67, IntegrityState::PROVISIONAL, [], 78, 'mobility', [], [], 0.88),
                'confidence_score' => new CareerScoreResult(72, IntegrityState::PROVISIONAL, [], 78, 'confidence', [], [], 0.88),
            ],
            [
                'red_flags' => [],
                'amber_flags' => ['cross_market_mismatch'],
                'blocked_claims' => ['salary_comparison', 'cross_market_pay_copy'],
            ],
            $this->sampleContext([
                'cross_market_mismatch' => true,
                'allow_pay_direct_inheritance' => false,
            ]),
        );

        $this->assertTrue($permissions['allow_strong_claim']);
        $this->assertFalse($permissions['allow_salary_comparison']);
        $this->assertTrue($permissions['allow_ai_strategy']);
        $this->assertTrue($permissions['allow_transition_recommendation']);
        $this->assertFalse($permissions['allow_cross_market_pay_copy']);
        $this->assertNotEmpty($permissions['reason_codes']);
    }

    public function test_it_blocks_strong_claims_when_index_state_is_not_exposure_eligible(): void
    {
        $permissions = (new ClaimPermissionsCompiler)->compile(
            [
                'fit_score' => new CareerScoreResult(81, IntegrityState::FULL, [], 90, 'fit', [], [], 1.0),
                'strain_score' => new CareerScoreResult(36, IntegrityState::FULL, [], 90, 'strain', [], [], 1.0),
                'ai_survival_score' => new CareerScoreResult(64, IntegrityState::FULL, [], 88, 'ai', [], [], 1.0),
                'mobility_score' => new CareerScoreResult(69, IntegrityState::FULL, [], 88, 'mobility', [], [], 1.0),
                'confidence_score' => new CareerScoreResult(79, IntegrityState::FULL, [], 88, 'confidence', [], [], 1.0),
            ],
            [
                'red_flags' => [],
                'amber_flags' => ['index_state_restricted'],
                'blocked_claims' => ['strong_claim'],
            ],
            $this->sampleContext([
                'index_state' => IndexStateValue::TRUST_LIMITED,
                'index_eligible' => false,
            ]),
        );

        $this->assertFalse($permissions['allow_strong_claim']);
        $this->assertTrue($permissions['allow_ai_strategy']);
        $this->assertContains('index_state_restricted', $permissions['reason_codes']);
    }
}
