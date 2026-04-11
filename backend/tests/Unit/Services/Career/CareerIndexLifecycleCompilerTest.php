<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\CareerIndexLifecycleCompiler;
use App\Domain\Career\Publish\CareerIndexLifecycleState;
use App\Domain\Career\ReviewerStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerIndexLifecycleCompilerTest extends TestCase
{
    #[Test]
    public function it_marks_stable_public_safe_rows_as_indexed(): void
    {
        $payload = app(CareerIndexLifecycleCompiler::class)->compile([
            'crosswalk_mode' => 'exact',
            'confidence_score' => 82,
            'reviewer_status' => ReviewerStatus::APPROVED,
            'raw_index_state' => IndexStateValue::INDEXABLE,
            'index_eligible' => true,
            'allow_strong_claim' => true,
            'reason_codes' => ['publish_ready'],
        ]);

        $this->assertSame(CareerIndexLifecycleState::INDEXED, $payload['index_state']);
        $this->assertTrue($payload['index_eligible']);
        $this->assertContains('career_index_lifecycle_indexed', $payload['reason_codes']);
    }

    #[Test]
    public function it_marks_candidate_grade_rows_as_promotion_candidates(): void
    {
        $payload = app(CareerIndexLifecycleCompiler::class)->compile([
            'crosswalk_mode' => 'functional_equivalent',
            'confidence_score' => 68,
            'reviewer_status' => ReviewerStatus::IN_REVIEW,
            'raw_index_state' => IndexStateValue::NOINDEX,
            'index_eligible' => false,
            'allow_strong_claim' => false,
            'reason_codes' => ['candidate_review_required'],
        ]);

        $this->assertSame(CareerIndexLifecycleState::PROMOTION_CANDIDATE, $payload['index_state']);
        $this->assertFalse($payload['index_eligible']);
        $this->assertContains('career_index_lifecycle_promotion_candidate', $payload['reason_codes']);
    }

    #[Test]
    public function it_marks_regressed_previously_indexed_rows_as_demoted(): void
    {
        $payload = app(CareerIndexLifecycleCompiler::class)->compile([
            'crosswalk_mode' => 'exact',
            'confidence_score' => 74,
            'reviewer_status' => ReviewerStatus::PENDING,
            'raw_index_state' => IndexStateValue::TRUST_LIMITED,
            'index_eligible' => false,
            'allow_strong_claim' => false,
            'previous_index_state' => CareerIndexLifecycleState::INDEXED,
            'reason_codes' => ['index_state_restricted'],
        ]);

        $this->assertSame(CareerIndexLifecycleState::DEMOTED, $payload['index_state']);
        $this->assertFalse($payload['index_eligible']);
        $this->assertContains('career_index_lifecycle_demoted', $payload['reason_codes']);
        $this->assertContains('career_index_lifecycle_regressed', $payload['reason_codes']);
    }

    #[Test]
    public function it_marks_non_promotable_hold_rows_as_noindex(): void
    {
        $payload = app(CareerIndexLifecycleCompiler::class)->compile([
            'crosswalk_mode' => 'unmapped',
            'confidence_score' => 52,
            'reviewer_status' => ReviewerStatus::CHANGES_REQUIRED,
            'raw_index_state' => IndexStateValue::UNAVAILABLE,
            'index_eligible' => false,
            'allow_strong_claim' => false,
            'reason_codes' => ['hold_scope_restricted'],
        ]);

        $this->assertSame(CareerIndexLifecycleState::NOINDEX, $payload['index_state']);
        $this->assertFalse($payload['index_eligible']);
        $this->assertContains('career_index_lifecycle_noindex', $payload['reason_codes']);
    }
}
