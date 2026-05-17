<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;

final class UrlTruthInventoryRecord
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $canonicalUrl,
        public readonly string $locale,
        public readonly string $pageEntityType,
        public readonly ?string $entityIdOrSlug,
        public readonly string $sourceAuthority,
        public readonly string $indexabilityState = 'indexable',
        public readonly ?Carbon $lastmodAt = null,
        public readonly ?string $lastmodSource = null,
        public readonly ?string $cluster = null,
        public readonly string $entitySource = 'backend_authority',
        public readonly string $authorityStatus = 'observed',
        public readonly ?Carbon $sourceUpdatedAt = null,
        public readonly bool $isPrivateFlow = false,
        public readonly array $metadata = [],
        public readonly array $attributes = [],
    ) {}

    public function canonicalUrlHash(): string
    {
        return hash('sha256', $this->canonicalUrl);
    }
}
