<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFullDatasetMember
{
    /**
     * @param  list<string>  $exclusionReasons
     * @param  array<string, mixed>  $publicFacets
     */
    public function __construct(
        public readonly string $memberKind,
        public readonly string $canonicalSlug,
        public readonly ?string $canonicalTitleEn,
        public readonly ?string $canonicalTitleZh,
        public readonly ?string $familySlug,
        public readonly ?string $publishTrack,
        public readonly ?string $batchOrigin,
        public readonly ?string $releaseCohort,
        public readonly ?string $publicIndexState,
        public readonly ?string $strongIndexDecision,
        public readonly bool $includedInPublicDataset,
        public readonly array $exclusionReasons,
        public readonly array $publicFacets,
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
            'canonical_title_zh' => $this->canonicalTitleZh,
            'family_slug' => $this->familySlug,
            'publish_track' => $this->publishTrack,
            'batch_origin' => $this->batchOrigin,
            'release_cohort' => $this->releaseCohort,
            'public_index_state' => $this->publicIndexState,
            'strong_index_decision' => $this->strongIndexDecision,
            'included_in_public_dataset' => $this->includedInPublicDataset,
            'exclusion_reasons' => $this->exclusionReasons,
            'public_facets' => $this->publicFacets,
        ];
    }
}
