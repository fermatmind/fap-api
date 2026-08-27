<?php

namespace Tests\Unit\SeoIntel;

use App\Services\SeoIntel\Decision\SeoNullablePriorityEvaluator;
use App\Services\SeoIntel\Decision\SeoStablePriorityRanker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeoStablePriorityRankerTest extends TestCase
{
    #[Test]
    public function every_allowed_profile_is_bounded_and_sums_to_one_hundred(): void
    {
        foreach (SeoStablePriorityRanker::PROFILES as $profile => $weights) {
            $this->assertSame(100, array_sum($weights), $profile);
            $this->assertGreaterThanOrEqual(25, $weights['evidence_strength'], $profile);
            $this->assertSame(
                SeoNullablePriorityEvaluator::REQUIRED_INPUTS,
                array_keys($weights),
                $profile,
            );
        }
    }

    #[Test]
    public function direct_verified_p0_and_p1_always_rank_first_in_severity_order(): void
    {
        $ranker = new SeoStablePriorityRanker;
        $candidates = [
            $this->evaluation('c', 'P2', 'verified', 100, true),
            $this->evaluation('b', 'P1', 'verified', 1, true),
            $this->evaluation('a', 'P0', 'verified', 1, true),
        ];

        foreach (array_keys(SeoStablePriorityRanker::PROFILES) as $profile) {
            $this->assertSame(
                [$this->uid('a'), $this->uid('b'), $this->uid('c')],
                array_column($ranker->rank($candidates, $profile), 'cluster_uid'),
                $profile,
            );
        }
    }

    #[Test]
    public function p0_or_p1_without_direct_verified_evidence_fails_closed(): void
    {
        $ranked = (new SeoStablePriorityRanker)->rank([
            $this->evaluation('a', 'P1', 'observed', 100, false),
            $this->evaluation('b', 'P2', 'verified', 1, true),
        ]);

        $this->assertSame($this->uid('b'), $ranked[0]['cluster_uid']);
        $this->assertSame('MEASUREMENT_HOLD', $ranked[1]['state']);
        $this->assertNull($ranked[1]['priority_score']);
        $this->assertFalse($ranked[1]['ranking_eligible']);
        $this->assertSame(['direct_verified_evidence_required_for_P1'], $ranked[1]['hold_reasons']);
    }

    #[Test]
    public function low_evidence_cannot_jump_higher_evidence_under_any_allowed_weights(): void
    {
        $ranker = new SeoStablePriorityRanker;
        $candidates = [
            $this->evaluation('c', 'P2', 'inferred', 100, false),
            $this->evaluation('b', 'P2', 'observed', 1, false),
            $this->evaluation('a', 'P2', 'verified', 1, false),
        ];
        $sensitivity = $ranker->sensitivity($candidates);

        $this->assertTrue($sensitivity['guardrails_passed']);
        $this->assertFalse($sensitivity['randomness_used']);
        $this->assertFalse($sensitivity['missing_values_filled']);
        $this->assertFalse($sensitivity['evidence_threshold_lowered']);
        foreach ($sensitivity['profiles'] as $order) {
            $this->assertSame([$this->uid('a'), $this->uid('b'), $this->uid('c')], $order);
        }
    }

    #[Test]
    public function missing_scores_remain_held_and_exact_ties_use_cluster_uid(): void
    {
        $ranker = new SeoStablePriorityRanker;
        $tiedB = $this->evaluation('b', 'P2', 'verified', 50, false);
        $tiedA = $this->evaluation('a', 'P2', 'verified', 50, false);
        $held = (new SeoNullablePriorityEvaluator)->evaluate([]);
        $ranked = $ranker->rank([$held, $tiedB, $tiedA]);

        $this->assertSame([$this->uid('a'), $this->uid('b'), null], array_column($ranked, 'cluster_uid'));
        $this->assertNull($ranked[2]['priority_score']);
        $this->assertFalse($ranked[2]['ranking_eligible']);
        $this->assertSame($ranked, $ranker->rank([$held, $tiedB, $tiedA]));
    }

    /** @return array<string, mixed> */
    private function evaluation(
        string $clusterCharacter,
        string $severity,
        string $evidence,
        int $impact,
        bool $direct,
    ): array {
        $input = [
            'cluster_uid' => $this->uid($clusterCharacter),
            'impact_scope' => [
                'affected_unique_public_urls' => $impact,
                'family_scope' => 'personality_hub',
            ],
            'evidence_strength' => $evidence,
            'business_value' => 'L1',
            'risk' => [
                'severity' => $severity,
                'blast_radius' => 'medium',
                'direct_evidence' => $direct,
            ],
            'estimated_fix_cost' => 'bounded',
            'evidence_freshness' => [
                'observed_at' => '2026-08-27T00:00:00Z',
                'evaluated_at' => '2026-08-27T12:00:00Z',
                'max_age_seconds' => 86400,
            ],
            'measurement_state' => [
                'complete' => true,
                'quality_passed' => true,
                'comparable' => true,
                'lag_seconds' => 300,
                'max_lag_seconds' => 3600,
            ],
        ];

        return (new SeoNullablePriorityEvaluator)->evaluate($input);
    }

    private function uid(string $character): string
    {
        return 'seo_cluster_'.str_repeat($character, 48);
    }
}
