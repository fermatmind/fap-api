<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerTrustFreshnessMember
{
    /**
     * @param  array<string, bool>  $signals
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly ?string $reviewerStatus,
        public readonly ?string $reviewedAt,
        public readonly ?string $lastSubstantiveUpdateAt,
        public readonly ?string $nextReviewDueAt,
        public readonly ?string $truthLastReviewedAt,
        public readonly bool $reviewDueKnown,
        public readonly string $reviewFreshnessBasis,
        public readonly string $reviewStalenessState,
        public readonly array $signals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => 'career_job_detail',
            'canonical_slug' => $this->canonicalSlug,
            'reviewer_status' => $this->reviewerStatus,
            'reviewed_at' => $this->reviewedAt,
            'last_substantive_update_at' => $this->lastSubstantiveUpdateAt,
            'next_review_due_at' => $this->nextReviewDueAt,
            'truth_last_reviewed_at' => $this->truthLastReviewedAt,
            'review_due_known' => $this->reviewDueKnown,
            'review_freshness_basis' => $this->reviewFreshnessBasis,
            'review_staleness_state' => $this->reviewStalenessState,
            'signals' => $this->signals,
        ];
    }
}
