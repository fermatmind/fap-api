<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFamilyHubBundle
{
    /**
     * @param  array<string, mixed>  $family
     * @param  list<array<string, mixed>>  $visibleChildren
     * @param  array<string, int>  $counts
     * @param  array<string, mixed>  $seoContract
     */
    public function __construct(
        public readonly array $family,
        public readonly array $visibleChildren,
        public readonly array $counts,
        public readonly array $seoContract,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bundle_kind' => 'career_family_hub',
            'bundle_version' => 'career.protocol.family_hub.v1',
            'family' => $this->family,
            'visible_children' => $this->visibleChildren,
            'counts' => $this->counts,
            'seo_contract' => $this->seoContract,
        ];
    }
}
