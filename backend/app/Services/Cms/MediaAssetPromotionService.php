<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MediaAssetPromotionService
{
    public function markVerified(MediaAsset $asset): MediaAsset
    {
        $asset = $asset->fresh('variants') ?? $asset->load('variants');
        $this->assertStorageAndVariantsVerified($asset);

        $asset->forceFill([
            'status' => MediaAsset::STATUS_VERIFIED,
            'is_public' => false,
        ])->save();

        return $asset->fresh('variants') ?? $asset;
    }

    public function promote(MediaAsset $asset): MediaAsset
    {
        return DB::transaction(function () use ($asset): MediaAsset {
            $locked = MediaAsset::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($asset->getKey());
            $locked->load('variants');

            if ((string) $locked->status !== MediaAsset::STATUS_VERIFIED) {
                throw new RuntimeException('MEDIA_ASSET_NOT_VERIFIED');
            }

            $this->assertStorageAndVariantsVerified($locked);
            $locked->forceFill([
                'status' => MediaAsset::STATUS_PUBLISHED,
                'is_public' => true,
            ])->save();

            return $locked->fresh('variants') ?? $locked;
        });
    }

    private function assertStorageAndVariantsVerified(MediaAsset $asset): void
    {
        if ((string) $asset->sync_status !== MediaAsset::SYNC_SYNCED
            || (string) $asset->cdn_status !== MediaAsset::CDN_VERIFIED) {
            throw new RuntimeException('MEDIA_SOURCE_STORAGE_NOT_VERIFIED');
        }

        $variants = $asset->variants->keyBy('variant_key');
        foreach (array_merge(['original'], MediaVariantGenerator::variantKeys()) as $variantKey) {
            $variant = $variants->get($variantKey);
            if ($variant === null
                || (string) $variant->sync_status !== MediaAsset::SYNC_SYNCED
                || (string) $variant->cdn_status !== MediaAsset::CDN_VERIFIED) {
                throw new RuntimeException('MEDIA_VARIANT_NOT_VERIFIED:'.$variantKey);
            }
        }
    }
}
