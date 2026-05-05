<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectedAssetRef;

final readonly class BigFiveV2ResolvedContentAsset
{
    /**
     * @param  array<string,mixed>  $publicContent
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public BigFiveV2SelectedAssetRef $selectedRef,
        public string $sourcePackage,
        public string $assetKey,
        public string $assetType,
        public array $publicContent,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'selected_ref' => $this->selectedRef->toArray(),
            'source_package' => $this->sourcePackage,
            'asset_key' => $this->assetKey,
            'asset_type' => $this->assetType,
            'public_content' => $this->publicContent,
            'metadata' => $this->metadata,
        ];
    }
}
