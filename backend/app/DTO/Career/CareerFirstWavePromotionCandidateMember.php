<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWavePromotionCandidateMember
{
    /**
     * @param  list<string>  $decisionReasons
     * @param  array{
     *   index_eligible:bool,
     *   confidence_score:?int,
     *   reviewer_status:?string,
     *   allow_strong_claim:bool,
     *   crosswalk_mode:?string,
     *   blocked_governance_status:?string,
     *   next_step_links_count:int,
     *   trust_freshness:array{review_due_known:bool,review_staleness_state:string}
     * }  $decisionEvidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $currentIndexState,
        public readonly string $publicIndexState,
        public readonly string $engineDecision,
        public readonly bool $autoNominationEligible,
        public readonly bool $manualReviewOnly,
        public readonly array $decisionReasons,
        public readonly array $decisionEvidence,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => 'career_job_detail',
            'canonical_slug' => $this->canonicalSlug,
            'current_index_state' => $this->currentIndexState,
            'public_index_state' => $this->publicIndexState,
            'engine_decision' => $this->engineDecision,
            'auto_nomination_eligible' => $this->autoNominationEligible,
            'manual_review_only' => $this->manualReviewOnly,
            'decision_reasons' => $this->decisionReasons,
            'decision_evidence' => $this->decisionEvidence,
        ];
    }
}
