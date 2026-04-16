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
            'explorer_only' => 0,
            'review_needed' => 0,
        ];
        $productionStateSummary = [
            'stable' => 0,
            'candidate' => 0,
            'hold' => 0,
            'explorer_only' => 0,
            'review_needed' => 0,
        ];
        $policyFlags = [
            'default_noindex' => false,
            'manual_review_bias' => false,
            'review_queue_only' => false,
        ];
        $familyHandoffCount = 0;
        $unmappedCount = 0;
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
            $productionState = 'hold';
            $crosswalkMode = $member->crosswalkMode;
            $familyHandoff = false;
            $conservativeNoindex = false;

            if ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_4) {
                $policyFlags['review_queue_only'] = true;
                if ($crosswalkMode === 'family_proxy') {
                    $productionState = 'explorer_only';
                    $familyHandoff = true;
                    $familyHandoffCount++;
                    $reasons[] = 'family_proxy_handoff_required';
                } elseif ($crosswalkMode === 'unmapped') {
                    $productionState = 'review_needed';
                    $unmappedCount++;
                    $reasons[] = 'unmapped_review_required';
                } else {
                    $productionState = 'review_needed';
                    $reasons[] = 'editorial_review_required';
                }
            } elseif ($manifest->batchKind === CareerAssetBatchManifestBuilder::BATCH_KIND_3) {
                $policyFlags['default_noindex'] = true;
                $policyFlags['manual_review_bias'] = true;
                $conservativeNoindex = true;
                if ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE) {
                    $productionState = 'candidate';
                    $candidateReadySet[] = $member->canonicalSlug;
                    $trackSummary['candidate']++;
                    $reasons[] = 'batch3_conservative_noindex';
                } else {
                    $productionState = 'hold';
                    $holdBackSet[] = $member->canonicalSlug;
                    $trackSummary['hold']++;
                    if ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_NOT_ELIGIBLE) {
                        $rejectionSet[] = [
                            'canonical_slug' => $member->canonicalSlug,
                            'reasons' => array_values(array_unique($reasons)),
                        ];
                    }
                }
            } elseif ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_AUTO_NOMINATE) {
                $candidateReadySet[] = $member->canonicalSlug;
                $trackSummary[$member->expectedPublishTrack] = ($trackSummary[$member->expectedPublishTrack] ?? 0) + 1;
                $productionState = $member->expectedPublishTrack;
            } elseif ($decision === CareerFirstWavePromotionCandidateEngine::DECISION_MANUAL_REVIEW_ONLY) {
                $holdBackSet[] = $member->canonicalSlug;
                $trackSummary['hold']++;
                $productionState = 'hold';
            } else {
                $rejectionSet[] = [
                    'canonical_slug' => $member->canonicalSlug,
                    'reasons' => array_values(array_unique($reasons)),
                ];
                $trackSummary['hold']++;
                $productionState = 'hold';
            }

            $productionStateSummary[$productionState] = ($productionStateSummary[$productionState] ?? 0) + 1;

            $members[] = [
                'canonical_slug' => $member->canonicalSlug,
                'engine_decision' => $decision,
                'decision_reasons' => array_values(array_unique($reasons)),
                'expected_publish_track' => $member->expectedPublishTrack,
                'crosswalk_mode' => $crosswalkMode,
                'production_state' => $productionState,
                'family_handoff' => $familyHandoff,
                'default_noindex' => $conservativeNoindex,
            ];
        }

        return [
            'stage' => 'publish_candidate',
            'passed' => true,
            'counts' => $decisionCounts,
            'production_state_summary' => $productionStateSummary,
            'candidate_ready_set' => array_values($candidateReadySet),
            'hold_back_set' => array_values($holdBackSet),
            'rejection_set' => $rejectionSet,
            'publish_track_summary' => $trackSummary,
            'policy_flags' => $policyFlags,
            'family_handoff_count' => $familyHandoffCount,
            'unmapped_count' => $unmappedCount,
            'members' => $members,
        ];
    }
}
