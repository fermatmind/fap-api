<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFullReleaseLedgerMember
{
    /**
     * @param  list<string>  $blockerReasons
     * @param  array<string, mixed>  $evidenceRefs
     */
    public function __construct(
        public readonly string $memberKind,
        public readonly string $canonicalSlug,
        public readonly ?string $canonicalTitleEn,
        public readonly ?string $batchOrigin,
        public readonly ?string $currentCrosswalkMode,
        public readonly string $currentIndexState,
        public readonly string $publicIndexState,
        public readonly bool $indexEligible,
        public readonly string $releaseCohort,
        public readonly array $blockerReasons,
        public readonly array $evidenceRefs,
        public readonly ?string $resolvedTargetKind = null,
        public readonly ?string $resolvedTargetSlug = null,
        public readonly ?string $reviewQueueStatus = null,
        public readonly ?bool $overrideApplied = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'member_kind' => $this->memberKind,
            'canonical_slug' => $this->canonicalSlug,
            'canonical_title_en' => $this->canonicalTitleEn,
            'batch_origin' => $this->batchOrigin,
            'current_crosswalk_mode' => $this->currentCrosswalkMode,
            'current_index_state' => $this->currentIndexState,
            'public_index_state' => $this->publicIndexState,
            'index_eligible' => $this->indexEligible,
            'release_cohort' => $this->releaseCohort,
            'blocker_reasons' => $this->blockerReasons,
            'evidence_refs' => $this->evidenceRefs,
            'resolved_target_kind' => $this->resolvedTargetKind,
            'resolved_target_slug' => $this->resolvedTargetSlug,
            'review_queue_status' => $this->reviewQueueStatus,
            'override_applied' => $this->overrideApplied,
        ];
    }
}
