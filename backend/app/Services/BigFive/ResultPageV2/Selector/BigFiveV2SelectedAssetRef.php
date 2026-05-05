<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Selector;

final readonly class BigFiveV2SelectedAssetRef
{
    public function __construct(
        public string $assetKey,
        public string $registryKey,
        public string $moduleKey,
        public string $blockKey,
        public string $slotKey,
        public int $priority,
        public string $contentSource,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_key' => $this->assetKey,
            'registry_key' => $this->registryKey,
            'module_key' => $this->moduleKey,
            'block_key' => $this->blockKey,
            'slot_key' => $this->slotKey,
            'priority' => $this->priority,
            'content_source' => $this->contentSource,
        ];
    }
}
