<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWavePromotionCandidateEngine;
use App\Domain\Career\Publish\CareerIndexLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerFirstWavePromotionCandidateEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_conservative_first_wave_promotion_candidate_decisions_and_counts(): void
    {
        $payload = app(CareerFirstWavePromotionCandidateEngine::class)->build([
            [
                'canonical_slug' => 'registered-nurses',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => true,
                'confidence_score' => 81,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'exact',
                'blocked_governance_status' => null,
                'next_step_links_count' => 3,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_scheduled',
                ],
            ],
            [
                'canonical_slug' => 'software-developers',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => true,
                'confidence_score' => 79,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'functional_equivalent',
                'blocked_governance_status' => null,
                'next_step_links_count' => 3,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_scheduled',
                ],
            ],
            [
                'canonical_slug' => 'management-analysts',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => true,
                'confidence_score' => 82,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'trust_inheritance',
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_due',
                ],
            ],
            [
                'canonical_slug' => 'data-scientists',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => false,
                'confidence_score' => 86,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'exact',
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_scheduled',
                ],
            ],
            [
                'canonical_slug' => 'web-developers',
                'current_index_state' => CareerIndexLifecycleState::INDEXED,
                'public_index_state' => 'indexable',
                'index_eligible' => true,
                'confidence_score' => 87,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'exact',
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_scheduled',
                ],
            ],
            [
                'canonical_slug' => 'ux-designers',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => true,
                'confidence_score' => 66,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'exact',
                'blocked_governance_status' => null,
                'next_step_links_count' => 3,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_scheduled',
                ],
            ],
        ])->toArray();

        $members = collect($payload['members'] ?? [])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_promotion_candidate_engine', $payload['engine_kind']);
        $this->assertSame('career.promotion_candidate.first_wave.v1', $payload['engine_version']);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::SCOPE, $payload['scope']);
        $this->assertSame([
            CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE => 1,
            CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY => 2,
            CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE => 3,
        ], $payload['counts']);

        $auto = $members->get('registered-nurses');
        $this->assertIsArray($auto);
        $this->assertSame('career_job_detail', $auto['member_kind']);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE, $auto['engine_decision']);
        $this->assertTrue((bool) ($auto['auto_nomination_eligible'] ?? false));
        $this->assertFalse((bool) ($auto['manual_review_only'] ?? true));
        $this->assertContains('index_eligible_true', $auto['decision_reasons']);
        $this->assertContains('confidence_at_or_above_threshold', $auto['decision_reasons']);
        $this->assertContains('reviewer_approved', $auto['decision_reasons']);
        $this->assertContains('safe_crosswalk', $auto['decision_reasons']);
        $this->assertContains('currently_noindex', $auto['decision_reasons']);

        $functionalEquivalent = $members->get('software-developers');
        $this->assertIsArray($functionalEquivalent);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY, $functionalEquivalent['engine_decision']);
        $this->assertContains('functional_equivalent_requires_manual_review', $functionalEquivalent['decision_reasons']);

        $reviewDue = $members->get('management-analysts');
        $this->assertIsArray($reviewDue);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY, $reviewDue['engine_decision']);
        $this->assertContains('trust_review_due_manual_review', $reviewDue['decision_reasons']);
        $this->assertSame('review_due', data_get($reviewDue, 'decision_evidence.trust_freshness.review_staleness_state'));

        $notEligibleFromIndex = $members->get('data-scientists');
        $this->assertIsArray($notEligibleFromIndex);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE, $notEligibleFromIndex['engine_decision']);
        $this->assertContains('index_eligible_false', $notEligibleFromIndex['decision_reasons']);

        $notEligibleFromCurrentState = $members->get('web-developers');
        $this->assertIsArray($notEligibleFromCurrentState);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE, $notEligibleFromCurrentState['engine_decision']);
        $this->assertContains('currently_not_noindex', $notEligibleFromCurrentState['decision_reasons']);

        $notEligibleFromConfidence = $members->get('ux-designers');
        $this->assertIsArray($notEligibleFromConfidence);
        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE, $notEligibleFromConfidence['engine_decision']);
        $this->assertContains('confidence_below_threshold', $notEligibleFromConfidence['decision_reasons']);

        $this->assertArrayNotHasKey('demand_signal', $auto);
        $this->assertArrayNotHasKey('novelty_score', $auto);
        $this->assertArrayNotHasKey('canonical_conflict', $auto);
    }

    public function test_it_keeps_trust_freshness_as_decision_evidence_without_hard_blocking_review_due(): void
    {
        $payload = app(CareerFirstWavePromotionCandidateEngine::class)->build([
            [
                'canonical_slug' => 'registered-nurses',
                'current_index_state' => CareerIndexLifecycleState::NOINDEX,
                'public_index_state' => 'noindex',
                'index_eligible' => true,
                'confidence_score' => 81,
                'reviewer_status' => 'approved',
                'allow_strong_claim' => true,
                'crosswalk_mode' => 'trust_inheritance',
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_due',
                ],
            ],
        ])->toArray();

        $member = $payload['members'][0] ?? [];

        $this->assertSame(CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY, $member['engine_decision'] ?? null);
        $this->assertContains('trust_review_due_manual_review', $member['decision_reasons'] ?? []);
        $this->assertNotContains('trust_review_due_hard_block', $member['decision_reasons'] ?? []);
        $this->assertSame('review_due', data_get($member, 'decision_evidence.trust_freshness.review_staleness_state'));
    }
}
