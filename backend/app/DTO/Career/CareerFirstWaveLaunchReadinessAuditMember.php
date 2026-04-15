<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveLaunchReadinessAuditMember
{
    /**
     * @param  list<string>  $blockers
     * @param  array<string, array<string, string>>  $evidenceRefs
     */
    public function __construct(
        public readonly string $occupationUuid,
        public readonly string $canonicalSlug,
        public readonly string $canonicalTitleEn,
        public readonly string $launchTier,
        public readonly string $readinessStatus,
        public readonly string $lifecycleState,
        public readonly string $publicIndexState,
        public readonly bool $indexEligible,
        public readonly ?string $reviewerStatus,
        public readonly ?string $crosswalkMode,
        public readonly bool $allowStrongClaim,
        public readonly ?int $confidenceScore,
        public readonly ?string $blockedGovernanceStatus,
        public readonly int $nextStepLinksCount,
        public readonly bool $familyHubSupportingRoute,
        public readonly array $blockers,
        public readonly array $evidenceRefs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => 'career_job_detail',
            'occupation_uuid' => $this->occupationUuid,
            'canonical_slug' => $this->canonicalSlug,
            'canonical_title_en' => $this->canonicalTitleEn,
            'launch_tier' => $this->launchTier,
            'readiness_status' => $this->readinessStatus,
            'lifecycle_state' => $this->lifecycleState,
            'public_index_state' => $this->publicIndexState,
            'index_eligible' => $this->indexEligible,
            'reviewer_status' => $this->reviewerStatus,
            'crosswalk_mode' => $this->crosswalkMode,
            'allow_strong_claim' => $this->allowStrongClaim,
            'confidence_score' => $this->confidenceScore,
            'blocked_governance_status' => $this->blockedGovernanceStatus,
            'next_step_links_count' => $this->nextStepLinksCount,
            'family_hub_supporting_route' => $this->familyHubSupportingRoute,
            'blockers' => $this->blockers,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }
}
