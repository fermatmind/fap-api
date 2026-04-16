<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveIndexPolicyEngine;
use App\Domain\Career\Publish\CareerIndexLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerFirstWaveIndexPolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_a_first_wave_index_policy_authority_with_formalized_states_counts_reasons_and_evidence(): void
    {
        $authority = app(CareerFirstWaveIndexPolicyEngine::class)->build([
            [
                'canonical_slug' => 'registered-nurses',
                'current_index_state' => CareerIndexLifecycleState::INDEXED,
                'public_index_state' => 'indexable',
                'index_eligible' => true,
                'reviewer_status' => 'approved',
                'crosswalk_mode' => 'exact',
                'allow_strong_claim' => true,
                'confidence_score' => 81,
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
                'index_eligible' => false,
                'reviewer_status' => 'pending',
                'crosswalk_mode' => 'unmapped',
                'allow_strong_claim' => false,
                'confidence_score' => 55,
                'blocked_governance_status' => 'blocked_not_safely_remediable',
                'next_step_links_count' => 0,
                'trust_freshness' => [
                    'review_due_known' => false,
                    'review_staleness_state' => 'unknown_due_date',
                ],
            ],
            [
                'canonical_slug' => 'management-analysts',
                'current_index_state' => CareerIndexLifecycleState::DEMOTED,
                'public_index_state' => 'trust_limited',
                'index_eligible' => false,
                'reviewer_status' => 'changes_required',
                'crosswalk_mode' => 'local_heavy_interpretation',
                'allow_strong_claim' => true,
                'confidence_score' => 64,
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_due',
                ],
            ],
            [
                'canonical_slug' => 'data-scientists',
                'current_index_state' => CareerIndexLifecycleState::PROMOTION_CANDIDATE,
                'public_index_state' => 'trust_limited',
                'index_eligible' => false,
                'reviewer_status' => 'reviewed',
                'crosswalk_mode' => 'direct_match',
                'allow_strong_claim' => true,
                'confidence_score' => 73,
                'blocked_governance_status' => null,
                'next_step_links_count' => 1,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_due',
                ],
            ],
        ]);

        $payload = $authority->toArray();
        $members = collect($payload['members'] ?? [])->keyBy('canonical_slug');

        $this->assertSame('career_first_wave_index_policy_authority', $payload['policy_kind']);
        $this->assertSame('career.index_policy.first_wave.v1', $payload['policy_version']);
        $this->assertSame(CareerFirstWaveIndexPolicyEngine::SCOPE, $payload['scope']);
        $this->assertSame([
            CareerIndexLifecycleState::NOINDEX => 1,
            CareerIndexLifecycleState::PROMOTION_CANDIDATE => 1,
            CareerIndexLifecycleState::INDEXED => 1,
            CareerIndexLifecycleState::DEMOTED => 1,
        ], $payload['counts']);

        $indexed = $members->get('registered-nurses');
        $this->assertIsArray($indexed);
        $this->assertSame('career_job_detail', $indexed['member_kind']);
        $this->assertSame(CareerIndexLifecycleState::INDEXED, $indexed['policy_state']);
        $this->assertSame('indexable', $indexed['public_index_state']);
        $this->assertContains('reviewer_approved', $indexed['policy_reasons']);
        $this->assertContains('safe_crosswalk', $indexed['policy_reasons']);
        $this->assertContains('strong_claim_allowed', $indexed['policy_reasons']);
        $this->assertContains('next_step_links_present', $indexed['policy_reasons']);
        $this->assertContains('indexed_ready', $indexed['policy_reasons']);
        $this->assertSame(81, data_get($indexed, 'policy_evidence.confidence_score'));
        $this->assertSame('approved', data_get($indexed, 'policy_evidence.reviewer_status'));
        $this->assertTrue((bool) data_get($indexed, 'policy_evidence.trust_freshness.review_due_known'));
        $this->assertSame('review_scheduled', data_get($indexed, 'policy_evidence.trust_freshness.review_staleness_state'));

        $noindex = $members->get('software-developers');
        $this->assertIsArray($noindex);
        $this->assertSame(CareerIndexLifecycleState::NOINDEX, $noindex['policy_state']);
        $this->assertContains('reviewer_not_approved', $noindex['policy_reasons']);
        $this->assertContains('crosswalk_unmapped', $noindex['policy_reasons']);
        $this->assertContains('strong_claim_blocked', $noindex['policy_reasons']);
        $this->assertContains('blocked_governance', $noindex['policy_reasons']);
        $this->assertContains('insufficient_next_step_links', $noindex['policy_reasons']);
        $this->assertContains('confidence_below_threshold', $noindex['policy_reasons']);
        $this->assertContains('publish_gate_hold', $noindex['policy_reasons']);
        $this->assertSame('unknown_due_date', data_get($noindex, 'policy_evidence.trust_freshness.review_staleness_state'));

        $demoted = $members->get('management-analysts');
        $this->assertIsArray($demoted);
        $this->assertSame(CareerIndexLifecycleState::DEMOTED, $demoted['policy_state']);
        $this->assertContains('demoted_review_regression', $demoted['policy_reasons']);
        $this->assertContains('demoted_trust_regression', $demoted['policy_reasons']);

        $candidate = $members->get('data-scientists');
        $this->assertIsArray($candidate);
        $this->assertSame(CareerIndexLifecycleState::PROMOTION_CANDIDATE, $candidate['policy_state']);
        $this->assertContains('publish_gate_candidate', $candidate['policy_reasons']);
        $this->assertContains('trust_limited', $candidate['policy_reasons']);
    }

    public function test_it_keeps_trust_freshness_as_evidence_only_without_changing_policy_state(): void
    {
        $authority = app(CareerFirstWaveIndexPolicyEngine::class)->build([
            [
                'canonical_slug' => 'data-scientists',
                'current_index_state' => CareerIndexLifecycleState::INDEXED,
                'public_index_state' => 'indexable',
                'index_eligible' => true,
                'reviewer_status' => 'approved',
                'crosswalk_mode' => 'exact',
                'allow_strong_claim' => true,
                'confidence_score' => 82,
                'blocked_governance_status' => null,
                'next_step_links_count' => 2,
                'trust_freshness' => [
                    'review_due_known' => true,
                    'review_staleness_state' => 'review_due',
                ],
            ],
        ])->toArray();

        $member = $authority['members'][0] ?? [];

        $this->assertSame(CareerIndexLifecycleState::INDEXED, $member['policy_state'] ?? null);
        $this->assertSame('review_due', data_get($member, 'policy_evidence.trust_freshness.review_staleness_state'));
        $this->assertNotContains('review_due', $member['policy_reasons'] ?? []);
        $this->assertNotContains('unknown_due_date', $member['policy_reasons'] ?? []);
    }
}
