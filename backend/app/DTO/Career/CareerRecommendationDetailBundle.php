<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerRecommendationDetailBundle
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $recommendationSubjectMeta
     * @param  array<string, mixed>  $supportingTruthSummary
     * @param  array<string, mixed>  $scoreBundle
     * @param  array<string, mixed>  $whiteBoxScores
     * @param  array<string, mixed>  $warnings
     * @param  array<string, mixed>  $claimPermissions
     * @param  array<string, mixed>  $integritySummary
     * @param  array<string, mixed>  $trustManifest
     * @param  list<array<string, mixed>>  $matchedJobs
     * @param  array<string, mixed>  $seoContract
     * @param  array<string, mixed>  $provenanceMeta
     * @param  array<string, mixed>|null  $transitionPath
     * @param  array<string, mixed>|null  $feedbackCheckin
     * @param  array<string, mixed>  $projectionTimeline
     * @param  array<string, mixed>  $projectionDeltaSummary
     */
    public function __construct(
        public readonly array $identity,
        public readonly array $recommendationSubjectMeta,
        public readonly array $supportingTruthSummary,
        public readonly array $scoreBundle,
        public readonly array $whiteBoxScores,
        public readonly array $warnings,
        public readonly array $claimPermissions,
        public readonly array $integritySummary,
        public readonly array $trustManifest,
        public readonly array $matchedJobs,
        public readonly ?array $transitionPath,
        public readonly ?array $feedbackCheckin,
        public readonly array $projectionTimeline,
        public readonly array $projectionDeltaSummary,
        public readonly array $seoContract,
        public readonly array $provenanceMeta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'bundle_kind' => 'career_recommendation_detail',
            'bundle_version' => 'career.protocol.recommendation_detail.v1',
            'identity' => $this->identity,
            'recommendation_subject_meta' => $this->recommendationSubjectMeta,
            'supporting_truth_summary' => $this->supportingTruthSummary,
            'score_bundle' => $this->scoreBundle,
            'white_box_scores' => $this->whiteBoxScores,
            'warnings' => $this->warnings,
            'claim_permissions' => $this->claimPermissions,
            'integrity_summary' => $this->integritySummary,
            'trust_manifest' => $this->trustManifest,
            'matched_jobs' => $this->matchedJobs,
            'transition_path' => $this->transitionPath,
            'feedback_checkin' => $this->feedbackCheckin,
            'projection_timeline' => $this->projectionTimeline,
            'projection_delta_summary' => $this->projectionDeltaSummary,
            'seo_contract' => $this->seoContract,
            'provenance_meta' => $this->provenanceMeta,
        ];

        if ($payload['transition_path'] === null || $payload['transition_path'] === []) {
            unset($payload['transition_path']);
        }

        return $payload;
    }
}
