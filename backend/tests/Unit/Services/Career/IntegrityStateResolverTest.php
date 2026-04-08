<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Scoring\IntegrityState;
use App\Domain\Career\Scoring\IntegrityStateResolver;

final class IntegrityStateResolverTest extends CareerScoringTestCase
{
    public function test_it_resolves_restricted_when_multiple_critical_fields_are_missing(): void
    {
        $context = $this->sampleContext([
            'skill_overlap' => null,
            'task_overlap' => null,
            'crosswalk_confidence' => null,
        ]);

        $resolved = (new IntegrityStateResolver)->resolve('mobility_score', $context);

        $this->assertSame(IntegrityState::BLOCKED, $resolved['integrity_state']);
        $this->assertContains('skill_overlap', $resolved['critical_missing_fields']);
        $this->assertContains('task_overlap', $resolved['critical_missing_fields']);
        $this->assertContains('crosswalk_confidence', $resolved['critical_missing_fields']);
    }
}
