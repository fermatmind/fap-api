<?php

declare(strict_types=1);

namespace App\DTO\Career;

final class CareerFamilyHubBundle
{
    /**
     * @param  array<string, mixed>  $family
     * @param  list<array<string, mixed>>  $visibleChildren
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly array $family,
        public readonly array $visibleChildren,
        public readonly array $counts,
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
        ];
    }
}
