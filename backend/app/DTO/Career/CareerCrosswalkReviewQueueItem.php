<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerCrosswalkReviewQueueItem
{
    /**
     * @param  list<string>  $queueReasons
     * @param  list<string>  $blockingFlags
     */
    public function __construct(
        public readonly string $subjectSlug,
        public readonly string $currentCrosswalkMode,
        public readonly array $queueReasons,
        public readonly ?string $candidateTargetKind,
        public readonly ?string $candidateTargetSlug,
        public readonly bool $requiresEditorialPatch,
        public readonly ?string $batchOrigin,
        public readonly ?string $publishTrack,
        public readonly array $blockingFlags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_slug' => $this->subjectSlug,
            'current_crosswalk_mode' => $this->currentCrosswalkMode,
            'queue_reason' => $this->queueReasons,
            'candidate_target_kind' => $this->candidateTargetKind,
            'candidate_target_slug' => $this->candidateTargetSlug,
            'requires_editorial_patch' => $this->requiresEditorialPatch,
            'batch_origin' => $this->batchOrigin,
            'publish_track' => $this->publishTrack,
            'blocking_flags' => $this->blockingFlags,
        ];
    }
}
