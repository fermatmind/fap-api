<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Domain\Career\IndexStateValue;
use App\Domain\Career\ReviewerStatus;
use Illuminate\Support\Arr;

final class FirstWavePublishGate
{
    /**
     * @return array{classification:string,reasons:list<string>,publishable:bool}
     */
    public function evaluate(array $subject): array
    {
        $crosswalkMode = strtolower(trim((string) ($subject['crosswalk_mode'] ?? '')));
        $confidenceScore = (int) round((float) Arr::get($subject, 'trust_seed.confidence_score', $subject['confidence_score'] ?? 0));
        $reviewerStatus = strtolower(trim((string) Arr::get($subject, 'reviewer_seed.status', $subject['reviewer_status'] ?? '')));
        $indexState = strtolower(trim((string) Arr::get($subject, 'index_seed.state', $subject['index_state'] ?? '')));
        $indexEligible = (bool) Arr::get($subject, 'index_seed.index_eligible', $subject['index_eligible'] ?? false);
        $allowStrongClaim = (bool) Arr::get($subject, 'claim_seed.allow_strong_claim', $subject['allow_strong_claim'] ?? false);

        $reasons = [PublishReasonCode::MANIFEST_FIRST_WAVE_SELECTED];

        if (in_array($crosswalkMode, ['local_heavy_interpretation', 'family_proxy', 'unmapped'], true)) {
            $reasons[] = PublishReasonCode::CROSSWALK_MODE_DISALLOWED;
            $reasons[] = PublishReasonCode::HOLD_SCOPE_RESTRICTED;

            return [
                'classification' => WaveClassification::HOLD,
                'reasons' => $reasons,
                'publishable' => false,
            ];
        }

        if (in_array($crosswalkMode, ['exact', 'trust_inheritance'], true)) {
            $reasons[] = PublishReasonCode::CROSSWALK_MODE_ALLOWED;
        } else {
            $reasons[] = PublishReasonCode::CROSSWALK_MODE_CANDIDATE_ONLY;
        }

        if ($confidenceScore >= 75) {
            $reasons[] = PublishReasonCode::CONFIDENCE_READY;
        } elseif ($confidenceScore >= 60) {
            $reasons[] = PublishReasonCode::CONFIDENCE_BORDERLINE;
        } else {
            $reasons[] = PublishReasonCode::CONFIDENCE_TOO_LOW;
        }

        if ($reviewerStatus === ReviewerStatus::APPROVED) {
            $reasons[] = PublishReasonCode::REVIEWER_APPROVED;
        } elseif (in_array($reviewerStatus, [ReviewerStatus::PENDING, ReviewerStatus::IN_REVIEW], true)) {
            $reasons[] = PublishReasonCode::REVIEWER_PENDING;
        } else {
            $reasons[] = PublishReasonCode::REVIEWER_BLOCKED;
        }

        if ($indexEligible && $indexState === IndexStateValue::INDEXABLE) {
            $reasons[] = PublishReasonCode::INDEX_ELIGIBLE;
        } else {
            $reasons[] = PublishReasonCode::INDEX_INELIGIBLE;
        }

        if ($allowStrongClaim) {
            $reasons[] = PublishReasonCode::STRONG_CLAIM_ALLOWED;
        } else {
            $reasons[] = PublishReasonCode::STRONG_CLAIM_BLOCKED;
        }

        if (
            in_array($crosswalkMode, ['exact', 'trust_inheritance'], true)
            && $confidenceScore >= 75
            && $reviewerStatus === ReviewerStatus::APPROVED
            && $indexEligible
            && $indexState === IndexStateValue::INDEXABLE
            && $allowStrongClaim
        ) {
            $reasons[] = PublishReasonCode::STABLE_PUBLISH_READY;

            return [
                'classification' => WaveClassification::STABLE,
                'reasons' => array_values(array_unique($reasons)),
                'publishable' => true,
            ];
        }

        if (
            $confidenceScore < 60
            || $reviewerStatus === ReviewerStatus::CHANGES_REQUIRED
            || $indexState === IndexStateValue::UNAVAILABLE
        ) {
            $reasons[] = PublishReasonCode::HOLD_SCOPE_RESTRICTED;

            return [
                'classification' => WaveClassification::HOLD,
                'reasons' => array_values(array_unique($reasons)),
                'publishable' => false,
            ];
        }

        $reasons[] = PublishReasonCode::CANDIDATE_REVIEW_REQUIRED;

        return [
            'classification' => WaveClassification::CANDIDATE,
            'reasons' => array_values(array_unique($reasons)),
            'publishable' => false,
        ];
    }
}
