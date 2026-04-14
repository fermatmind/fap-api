<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFirstWaveDatasetMember
{
    public function __construct(
        public readonly string $occupationUuid,
        public readonly string $canonicalSlug,
        public readonly string $canonicalTitleEn,
        public readonly string $canonicalPath,
        public readonly string $launchTier,
        public readonly string $discoverabilityState,
        public readonly bool $indexEligible,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'occupation_uuid' => $this->occupationUuid,
            'canonical_slug' => $this->canonicalSlug,
            'canonical_title_en' => $this->canonicalTitleEn,
            'canonical_path' => $this->canonicalPath,
            'launch_tier' => $this->launchTier,
            'discoverability_state' => $this->discoverabilityState,
            'index_eligible' => $this->indexEligible,
        ];
    }
}
