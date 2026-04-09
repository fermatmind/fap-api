<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\Publish\FirstWaveManifestReader;
use App\Domain\Career\Publish\FirstWavePublishGate;
use App\Domain\Career\Publish\PublishReasonCode;
use App\Domain\Career\Publish\WaveClassification;
use App\Domain\Career\ReviewerStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FirstWavePublishGateTest extends TestCase
{
    #[Test]
    public function it_keeps_the_manifest_and_publish_gate_aligned_without_silent_drift(): void
    {
        $reader = app(FirstWaveManifestReader::class);
        $gate = app(FirstWavePublishGate::class);
        $manifest = $reader->read();

        foreach ($manifest['occupations'] as $occupation) {
            $result = $gate->evaluate($occupation);

            $this->assertSame($occupation['wave_classification'], $result['classification']);
            $this->assertSame($occupation['publish_reason_codes'], $result['reasons']);
            $this->assertTrue($result['publishable']);
        }
    }

    #[Test]
    public function it_accepts_exact_and_trust_inheritance_as_stable_when_publish_seeds_are_ready(): void
    {
        $gate = app(FirstWavePublishGate::class);

        foreach (['exact', 'trust_inheritance'] as $crosswalkMode) {
            $result = $gate->evaluate([
                'crosswalk_mode' => $crosswalkMode,
                'trust_seed' => ['confidence_score' => 81],
                'reviewer_seed' => ['status' => ReviewerStatus::APPROVED],
                'index_seed' => ['state' => IndexStateValue::INDEXABLE, 'index_eligible' => true],
                'claim_seed' => ['allow_strong_claim' => true],
            ]);

            $this->assertSame(WaveClassification::STABLE, $result['classification']);
            $this->assertContains(PublishReasonCode::CROSSWALK_MODE_ALLOWED, $result['reasons']);
            $this->assertContains(PublishReasonCode::STABLE_PUBLISH_READY, $result['reasons']);
        }
    }

    #[Test]
    public function it_classifies_candidate_cases_deterministically_with_explicit_reason_codes(): void
    {
        $gate = app(FirstWavePublishGate::class);

        $result = $gate->evaluate([
            'crosswalk_mode' => 'functional_equivalent',
            'trust_seed' => ['confidence_score' => 68],
            'reviewer_seed' => ['status' => ReviewerStatus::IN_REVIEW],
            'index_seed' => ['state' => IndexStateValue::NOINDEX, 'index_eligible' => false],
            'claim_seed' => ['allow_strong_claim' => false],
        ]);

        $this->assertSame(WaveClassification::CANDIDATE, $result['classification']);
        $this->assertContains(PublishReasonCode::CROSSWALK_MODE_CANDIDATE_ONLY, $result['reasons']);
        $this->assertContains(PublishReasonCode::CONFIDENCE_BORDERLINE, $result['reasons']);
        $this->assertContains(PublishReasonCode::REVIEWER_PENDING, $result['reasons']);
        $this->assertContains(PublishReasonCode::INDEX_INELIGIBLE, $result['reasons']);
        $this->assertContains(PublishReasonCode::STRONG_CLAIM_BLOCKED, $result['reasons']);
        $this->assertContains(PublishReasonCode::CANDIDATE_REVIEW_REQUIRED, $result['reasons']);
    }

    #[Test]
    public function it_marks_disallowed_modes_and_low_confidence_subjects_as_hold(): void
    {
        $gate = app(FirstWavePublishGate::class);

        $disallowedModeResult = $gate->evaluate([
            'crosswalk_mode' => 'unmapped',
            'trust_seed' => ['confidence_score' => 82],
            'reviewer_seed' => ['status' => ReviewerStatus::APPROVED],
            'index_seed' => ['state' => IndexStateValue::INDEXABLE, 'index_eligible' => true],
            'claim_seed' => ['allow_strong_claim' => true],
        ]);

        $lowConfidenceResult = $gate->evaluate([
            'crosswalk_mode' => 'exact',
            'trust_seed' => ['confidence_score' => 54],
            'reviewer_seed' => ['status' => ReviewerStatus::CHANGES_REQUIRED],
            'index_seed' => ['state' => IndexStateValue::UNAVAILABLE, 'index_eligible' => false],
            'claim_seed' => ['allow_strong_claim' => false],
        ]);

        $this->assertSame(WaveClassification::HOLD, $disallowedModeResult['classification']);
        $this->assertContains(PublishReasonCode::CROSSWALK_MODE_DISALLOWED, $disallowedModeResult['reasons']);
        $this->assertContains(PublishReasonCode::HOLD_SCOPE_RESTRICTED, $disallowedModeResult['reasons']);

        $this->assertSame(WaveClassification::HOLD, $lowConfidenceResult['classification']);
        $this->assertContains(PublishReasonCode::CONFIDENCE_TOO_LOW, $lowConfidenceResult['reasons']);
        $this->assertContains(PublishReasonCode::REVIEWER_BLOCKED, $lowConfidenceResult['reasons']);
        $this->assertContains(PublishReasonCode::INDEX_INELIGIBLE, $lowConfidenceResult['reasons']);
        $this->assertContains(PublishReasonCode::HOLD_SCOPE_RESTRICTED, $lowConfidenceResult['reasons']);
    }
}
