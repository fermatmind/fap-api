<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\DegradationPolicy;
use App\Domain\Career\Scoring\IntegrityState;

final class DegradationPolicyTest extends CareerScoringTestCase
{
    public function test_it_applies_stronger_penalties_to_blocked_outputs(): void
    {
        $policy = new DegradationPolicy;

        $full = $policy->apply('fit_score', 80, [
            'integrity_state' => IntegrityState::FULL,
            'critical_missing_fields' => [],
            'confidence_cap' => 90,
        ], $this->sampleContext());

        $blocked = $policy->apply('ai_survival_score', 80, [
            'integrity_state' => IntegrityState::BLOCKED,
            'critical_missing_fields' => ['ai_exposure'],
            'confidence_cap' => 40,
        ], $this->sampleContext(['ai_exposure' => null]));

        $this->assertGreaterThan($blocked['degradation_factor'], $full['degradation_factor']);
        $this->assertGreaterThan($blocked['value'], $full['value']);
        $this->assertNotEmpty($blocked['penalties']);
    }
}
