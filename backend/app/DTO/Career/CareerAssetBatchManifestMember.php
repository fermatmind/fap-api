<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerAssetBatchManifestMember
{
    public function __construct(
        public readonly string $occupationUuid,
        public readonly string $canonicalSlug,
        public readonly string $canonicalTitleEn,
        public readonly string $familySlug,
        public readonly string $crosswalkMode,
        public readonly string $batchRole,
        public readonly bool $stableSeed,
        public readonly bool $candidateSeed,
        public readonly bool $holdSeed,
        public readonly string $expectedPublishTrack,
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
            'family_slug' => $this->familySlug,
            'crosswalk_mode' => $this->crosswalkMode,
            'batch_role' => $this->batchRole,
            'stable_seed' => $this->stableSeed,
            'candidate_seed' => $this->candidateSeed,
            'hold_seed' => $this->holdSeed,
            'expected_publish_track' => $this->expectedPublishTrack,
        ];
    }
}
