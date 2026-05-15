<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MediaAsset;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class MediaAssetStorageSyncService
{
    public function syncAndVerify(MediaAsset $asset): MediaAsset
    {
        $asset = $this->sync($asset);

        return $this->verifyCdn($asset);
    }

    public function sync(MediaAsset $asset): MediaAsset
    {
        $asset->loadMissing('variants');

        if (! $this->syncEnabled()) {
            $this->markAsset($asset, MediaAsset::SYNC_SKIPPED, MediaAsset::CDN_SKIPPED, 'media OSS sync disabled');
            $this->markVariants($asset, MediaAsset::SYNC_SKIPPED, MediaAsset::CDN_SKIPPED, 'media OSS sync disabled');

            return $asset->fresh('variants') ?? $asset;
        }

        $sourceDisk = trim((string) $asset->disk);
        $targetDisk = $this->targetDisk();
        if ($sourceDisk === '' || $targetDisk === '') {
            $this->markAsset($asset, MediaAsset::SYNC_FAILED, MediaAsset::CDN_NOT_VERIFIED, 'missing source or target disk');

            return $asset->fresh('variants') ?? $asset;
        }

        $failures = [];
        $assetSynced = $this->syncPath($sourceDisk, $targetDisk, $asset->path, $failures);

        if ($assetSynced) {
            $asset->forceFill([
                'url' => PublicMediaUrlGuard::publicMediaUrlForPath($asset->disk, $asset->path),
                'sync_status' => MediaAsset::SYNC_SYNCED,
                'synced_at' => now(),
                'last_error' => null,
                'payload_json' => array_merge($asset->payload_json ?? [], [
                    'oss_sync' => [
                        'target_disk' => $targetDisk,
                        'target_prefix' => $this->targetPrefix(),
                    ],
                ]),
            ])->save();
        }

        foreach ($asset->variants as $variant) {
            $variantSynced = $this->syncPath($sourceDisk, $targetDisk, $variant->path, $failures);
            $variant->forceFill([
                'url' => PublicMediaUrlGuard::publicMediaUrlForPath($asset->disk, $variant->path),
                'sync_status' => $variantSynced ? MediaAsset::SYNC_SYNCED : MediaAsset::SYNC_FAILED,
                'synced_at' => $variantSynced ? now() : null,
                'last_error' => $variantSynced ? null : $this->summarizeFailures($failures),
            ])->save();
        }

        if ($failures !== []) {
            $this->markAsset($asset, MediaAsset::SYNC_FAILED, MediaAsset::CDN_NOT_VERIFIED, $this->summarizeFailures($failures));
        }

        return $asset->fresh('variants') ?? $asset;
    }

    public function verifyCdn(MediaAsset $asset): MediaAsset
    {
        $asset->loadMissing('variants');

        if (! $this->cdnVerifyEnabled()) {
            $this->markAsset($asset, (string) $asset->sync_status, MediaAsset::CDN_SKIPPED, $asset->last_error);
            $this->markVariants($asset, null, MediaAsset::CDN_SKIPPED, null);

            return $asset->fresh('variants') ?? $asset;
        }

        $failures = [];
        $assetVerified = $this->verifyUrl($asset->url, $failures);

        foreach ($asset->variants as $variant) {
            $variantVerified = $this->verifyUrl($variant->url, $failures);
            $variant->forceFill([
                'cdn_status' => $variantVerified ? MediaAsset::CDN_VERIFIED : MediaAsset::CDN_FAILED,
                'verified_at' => $variantVerified ? now() : null,
                'last_error' => $variantVerified ? null : $this->summarizeFailures($failures),
            ])->save();
        }

        $this->markAsset(
            $asset,
            (string) $asset->sync_status,
            $assetVerified && $failures === [] ? MediaAsset::CDN_VERIFIED : MediaAsset::CDN_FAILED,
            $failures === [] ? null : $this->summarizeFailures($failures),
            setVerifiedAt: $assetVerified && $failures === []
        );

        return $asset->fresh('variants') ?? $asset;
    }

    /**
     * @param  list<string>  $failures
     */
    private function syncPath(string $sourceDisk, string $targetDisk, mixed $path, array &$failures): bool
    {
        $sourcePath = trim((string) $path);
        if ($sourcePath === '') {
            $failures[] = 'empty source path';

            return false;
        }

        try {
            if (! Storage::disk($sourceDisk)->exists($sourcePath)) {
                $failures[] = 'source missing: '.$sourceDisk.':'.$sourcePath;

                return false;
            }

            Storage::disk($targetDisk)->put(
                $this->targetPath($sourcePath),
                Storage::disk($sourceDisk)->get($sourcePath),
                'public'
            );

            return true;
        } catch (\Throwable $throwable) {
            $failures[] = $sourcePath.': '.$throwable->getMessage();

            return false;
        }
    }

    /**
     * @param  list<string>  $failures
     */
    private function verifyUrl(mixed $url, array &$failures): bool
    {
        $candidate = PublicMediaUrlGuard::sanitizeNullableUrl($url);
        if ($candidate === null) {
            $failures[] = 'missing or blocked CDN URL';

            return false;
        }

        try {
            $response = Http::timeout($this->cdnVerifyTimeoutSeconds())->get($candidate);
            $contentType = strtolower((string) $response->header('Content-Type', ''));
            if (! $response->successful() || ! str_starts_with($contentType, 'image/')) {
                $failures[] = sprintf('%s returned %s %s', $candidate, (string) $response->status(), $contentType);

                return false;
            }

            return true;
        } catch (\Throwable $throwable) {
            $failures[] = $candidate.': '.$throwable->getMessage();

            return false;
        }
    }

    private function markAsset(
        MediaAsset $asset,
        string $syncStatus,
        string $cdnStatus,
        ?string $error,
        bool $setVerifiedAt = false
    ): void {
        $asset->forceFill([
            'sync_status' => $syncStatus,
            'cdn_status' => $cdnStatus,
            'synced_at' => $syncStatus === MediaAsset::SYNC_SYNCED ? ($asset->synced_at ?? now()) : $asset->synced_at,
            'verified_at' => $setVerifiedAt ? now() : $asset->verified_at,
            'last_error' => $error,
        ])->save();
    }

    private function markVariants(MediaAsset $asset, ?string $syncStatus, string $cdnStatus, ?string $error): void
    {
        foreach ($asset->variants as $variant) {
            $variant->forceFill(array_filter([
                'sync_status' => $syncStatus,
                'cdn_status' => $cdnStatus,
                'last_error' => $error,
            ], static fn (mixed $value): bool => $value !== null))->save();
        }
    }

    private function syncEnabled(): bool
    {
        return (bool) config('fap.media.oss_sync_enabled', false);
    }

    private function cdnVerifyEnabled(): bool
    {
        return (bool) config('fap.media.cdn_verify_enabled', false);
    }

    private function targetDisk(): string
    {
        return trim((string) config('fap.media.oss_disk', 's3'));
    }

    private function targetPrefix(): string
    {
        return trim((string) config('fap.media.oss_key_prefix', 'storage'), '/');
    }

    private function targetPath(string $sourcePath): string
    {
        $prefix = $this->targetPrefix();

        return ($prefix !== '' ? $prefix.'/' : '').ltrim($sourcePath, '/');
    }

    private function cdnVerifyTimeoutSeconds(): int
    {
        return max(1, (int) config('fap.media.cdn_verify_timeout_seconds', 5));
    }

    /**
     * @param  list<string>  $failures
     */
    private function summarizeFailures(array $failures): string
    {
        return mb_substr(implode('; ', array_values(array_unique($failures))), 0, 4000);
    }
}
