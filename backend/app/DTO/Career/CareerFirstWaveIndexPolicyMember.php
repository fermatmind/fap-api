<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveIndexPolicyMember
{
    /**
     * @param  list<string>  $policyReasons
     * @param  array{
     *   confidence_score:?int,
     *   reviewer_status:?string,
     *   crosswalk_mode:?string,
     *   allow_strong_claim:bool,
     *   blocked_governance_status:?string,
     *   next_step_links_count:int,
     *   trust_freshness:array{review_due_known:bool,review_staleness_state:string}
     * }  $policyEvidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $currentIndexState,
        public readonly string $publicIndexState,
        public readonly bool $indexEligible,
        public readonly string $policyState,
        public readonly array $policyReasons,
        public readonly array $policyEvidence,
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
            'index_eligible' => $this->indexEligible,
            'policy_state' => $this->policyState,
            'policy_reasons' => $this->policyReasons,
            'policy_evidence' => $this->policyEvidence,
        ];
    }
}
