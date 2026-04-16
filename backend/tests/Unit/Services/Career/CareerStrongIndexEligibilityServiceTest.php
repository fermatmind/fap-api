<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerStrongIndexEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerStrongIndexEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_full_342_internal_snapshot_with_safe_four_state_decisions(): void
    {
        $snapshot = app(CareerStrongIndexEligibilityService::class)->build()->toArray();

        $this->assertSame('career_strong_index_eligibility', $snapshot['snapshot_kind'] ?? null);
        $this->assertSame('career.strong_index.v1', $snapshot['snapshot_version'] ?? null);
        $this->assertSame('career_all_342', $snapshot['scope'] ?? null);
        $this->assertTrue((bool) ($snapshot['weak_signal_gate_deferred'] ?? false));
        $this->assertSame('deferred', $snapshot['derived_signal_gate_state'] ?? null);

        $counts = (array) ($snapshot['counts'] ?? []);
        $this->assertEqualsCanonicalizing([
            'strong_index_ready',
            'indexable_but_not_strong_ready',
            'manual_only',
            'not_eligible',
        ], array_keys($counts));

        $members = (array) ($snapshot['members'] ?? []);
        $this->assertCount(342, $members);
        $this->assertSame(342, array_sum(array_map(static fn ($value): int => (int) $value, $counts)));

        $allowedStates = [
            'strong_index_ready' => true,
            'indexable_but_not_strong_ready' => true,
            'manual_only' => true,
            'not_eligible' => true,
        ];

        foreach ($members as $member) {
            $decision = (string) ($member['strong_index_decision'] ?? '');
            $this->assertArrayHasKey($decision, $allowedStates);
            $this->assertArrayHasKey('decision_evidence', $member);
            $this->assertArrayNotHasKey('demand_signal', $member);
            $this->assertArrayNotHasKey('novelty_score', $member);
            $this->assertArrayNotHasKey('canonical_conflict', $member);

            $reasons = (array) ($member['decision_reasons'] ?? []);
            $this->assertNotContains('review_expired', $reasons);
            $this->assertNotContains('trust_expired', $reasons);
            $this->assertNotContains('review_stale', $reasons);
            $this->assertNotContains('trust_review_due_hard_block', $reasons);
        }

        $strongReadyFamilyMembers = array_filter($members, static function (array $member): bool {
            if (($member['strong_index_decision'] ?? null) !== 'strong_index_ready') {
                return false;
            }

            $releaseCohort = data_get($member, 'decision_evidence.release_cohort');
            $crosswalkMode = data_get($member, 'decision_evidence.crosswalk_mode');

            return $releaseCohort === 'family_handoff' || $crosswalkMode === 'family_proxy';
        });

        $this->assertCount(0, $strongReadyFamilyMembers);
    }

    public function test_it_keeps_trust_freshness_soft_and_family_handoff_outside_strong_ready(): void
    {
        $service = app(CareerStrongIndexEligibilityService::class);
        $resolver = new \ReflectionMethod($service, 'resolveDecision');
        $resolver->setAccessible(true);

        $manualEvidence = [
            'public_index_state' => 'indexable',
            'index_eligible' => true,
            'reviewer_status' => 'approved',
            'confidence_score' => 95,
            'allow_strong_claim' => true,
            'crosswalk_mode' => 'exact',
            'blocked_governance_status' => null,
            'next_step_links_count' => 3,
            'review_queue_status' => null,
            'override_applied' => null,
            'release_cohort' => 'public_detail_indexable',
            'resolved_target_kind' => null,
            'trust_freshness' => [
                'review_staleness_state' => 'review_due',
            ],
        ];
        $manual = $resolver->invoke($service, $manualEvidence, 75, 2);

        $this->assertSame('manual_only', $manual[0]);
        $this->assertContains('trust_freshness_manual_review', $manual[1]);
        $this->assertNotContains('trust_review_due_hard_block', $manual[1]);

        $familyEvidence = [
            'public_index_state' => 'indexable',
            'index_eligible' => true,
            'reviewer_status' => 'approved',
            'confidence_score' => 95,
            'allow_strong_claim' => true,
            'crosswalk_mode' => 'family_proxy',
            'blocked_governance_status' => null,
            'next_step_links_count' => 3,
            'review_queue_status' => 'queued',
            'override_applied' => false,
            'release_cohort' => 'family_handoff',
            'resolved_target_kind' => 'family',
            'trust_freshness' => [
                'review_staleness_state' => 'review_scheduled',
            ],
        ];
        $family = $resolver->invoke($service, $familyEvidence, 75, 2);

        $this->assertSame('not_eligible', $family[0]);
        $this->assertNotSame('strong_index_ready', $family[0]);
    }
}
