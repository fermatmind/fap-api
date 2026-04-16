<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\Domain\Career\Publish\CareerFirstWavePromotionCandidateEngine;
use App\DTO\Career\CareerAssetBatchManifest;

final class CareerAssetBatchPublishCandidateService
{
    /**
     * @param  array<string, array<string, mixed>>  $truthBySlug
     * @param  array<string, array<string, mixed>>  $promotionBySlug
     * @return array<string, mixed>
     */
    public function project(
        CareerAssetBatchManifest $manifest,
        array $truthBySlug,
        array $promotionBySlug,
    ): array {
        $candidateReadySet = [];
        $holdBackSet = [];
        $rejectionSet = [];
        $members = [];
        $trackSummary = [
            'stable' => 0,
            'candidate' => 0,
            'hold' => 0,
        ];
        $decisionCounts = [
            CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE => 0,
            CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY => 0,
            CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE => 0,
        ];

        foreach ($manifest->members as $member) {
            $truth = $truthBySlug[$member->canonicalSlug] ?? null;
            $promotion = $promotionBySlug[$member->canonicalSlug] ?? null;

            if (! is_array($truth)) {
                $decision = CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE;
                $reasons = ['missing_backend_truth'];
            } else {
                $decision = (string) ($promotion['engine_decision'] ?? CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE);
                $reasons = is_array($promotion['decision_reasons'] ?? null)
                    ? array_values($promotion['decision_reasons'])
                    : ['promotion_decision_missing'];
            }

            if (! isset($decisionCounts[$decision])) {
                $decision = CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE;
                $reasons[] = 'promotion_decision_unknown';
            }

            $decisionCounts[$decision]++;

            if ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE) {
                $candidateReadySet[] = $member->canonicalSlug;
                $trackSummary[$member->expectedPublishTrack] = ($trackSummary[$member->expectedPublishTrack] ?? 0) + 1;
            } elseif ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY) {
                $holdBackSet[] = $member->canonicalSlug;
                $trackSummary['hold']++;
            } else {
                $rejectionSet[] = [
                    'canonical_slug' => $member->canonicalSlug,
                    'reasons' => array_values(array_unique($reasons)),
                ];
                $trackSummary['hold']++;
            }

            $members[] = [
                'canonical_slug' => $member->canonicalSlug,
                'engine_decision' => $decision,
                'decision_reasons' => array_values(array_unique($reasons)),
                'expected_publish_track' => $member->expectedPublishTrack,
            ];
        }

        return [
            'stage' => 'publish_candidate',
            'passed' => true,
            'counts' => $decisionCounts,
            'candidate_ready_set' => array_values($candidateReadySet),
            'hold_back_set' => array_values($holdBackSet),
            'rejection_set' => $rejectionSet,
            'publish_track_summary' => $trackSummary,
            'members' => $members,
        ];
    }
}
