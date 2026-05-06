<?php

declare(strict_types=1);

namespace App\Models;

final readonly class BigFiveV2EditorialAssetIndexEntry
{
    /**
     * @param  list<string>  $linkedReleaseSnapshotIds
     */
    public function __construct(
        public string $assetKey,
        public string $assetType,
        public string $relativePath,
        public string $package,
        public string $version,
        public string $mode,
        public string $runtimeUse,
        public bool $productionUseAllowed,
        public bool $readyForProduction,
        public bool $productionRolloutEnabled,
        public bool $immutable,
        public string $sha256,
        public array $linkedReleaseSnapshotIds,
    ) {}

    public function isReadOnly(): bool
    {
        return true;
    }

    public function publishActionAllowed(): bool
    {
        return false;
    }

    public function runtimeMutationAllowed(): bool
    {
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_key' => $this->assetKey,
            'asset_type' => $this->assetType,
            'relative_path' => $this->relativePath,
            'package' => $this->package,
            'version' => $this->version,
            'mode' => $this->mode,
            'runtime_use' => $this->runtimeUse,
            'production_use_allowed' => $this->productionUseAllowed,
            'ready_for_production' => $this->readyForProduction,
            'production_rollout_enabled' => $this->productionRolloutEnabled,
            'immutable' => $this->immutable,
            'sha256' => $this->sha256,
            'linked_release_snapshot_ids' => $this->linkedReleaseSnapshotIds,
            'read_only' => $this->isReadOnly(),
            'publish_action_allowed' => $this->publishActionAllowed(),
            'runtime_mutation_allowed' => $this->runtimeMutationAllowed(),
        ];
    }
}
