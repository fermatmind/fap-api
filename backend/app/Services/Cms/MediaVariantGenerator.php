<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MediaAsset;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class MediaVariantGenerator
{
    public const DEFAULT_VARIANTS = [
        'hero' => ['width' => 1600, 'height' => 900, 'quality' => 86],
        'card' => ['width' => 800, 'height' => 450, 'quality' => 84],
        'thumbnail' => ['width' => 400, 'height' => 225, 'quality' => 82],
        'og' => ['width' => 1200, 'height' => 630, 'quality' => 86],
        'preload' => ['width' => 64, 'height' => 36, 'quality' => 58],
    ];

    /**
     * @return list<string>
     */
    public static function variantKeys(): array
    {
        return array_keys(self::DEFAULT_VARIANTS);
    }

    public function storeUploadAndGenerate(MediaAsset $asset, UploadedFile $file): MediaAsset
    {
        $disk = 'public';
        $sourcePath = $file->storeAs(
            $this->sourceDirectory($asset),
            $this->sourceFilename($asset, $file),
            $disk
        );

        if (! is_string($sourcePath) || $sourcePath === '') {
            throw new RuntimeException('Unable to store uploaded media source.');
        }

        $asset->fill([
            'disk' => $disk,
            'path' => $sourcePath,
            'url' => $this->publicUrl($disk, $sourcePath),
            'mime_type' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'payload_json' => array_merge($asset->payload_json ?? [], [
                'source_original_name' => $file->getClientOriginalName(),
                'variant_pipeline' => 'gd-cover-v1',
            ]),
        ]);
        $asset->save();

        return $this->generate($asset);
    }

    public function generate(MediaAsset $asset): MediaAsset
    {
        $disk = trim((string) $asset->disk);
        $sourcePath = trim((string) $asset->path);

        if ($disk === '' || $sourcePath === '') {
            throw new RuntimeException('Media asset is missing disk or source path.');
        }

        if (! Storage::disk($disk)->exists($sourcePath)) {
            throw new RuntimeException('Media source file does not exist on disk: '.$disk.':'.$sourcePath);
        }

        $sourceBinary = Storage::disk($disk)->get($sourcePath);
        $sourceImage = @imagecreatefromstring($sourceBinary);
        if (! $sourceImage instanceof \GdImage) {
            throw new RuntimeException('Media source is not a supported image.');
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $sourceBytes = strlen($sourceBinary);
        $sourceMime = $this->detectMime($sourceBinary);

        $asset->fill([
            'url' => $asset->url ?: $this->publicUrl($disk, $sourcePath),
            'mime_type' => $sourceMime,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'bytes' => $sourceBytes,
            'payload_json' => array_merge($asset->payload_json ?? [], [
                'source_width' => $sourceWidth,
                'source_height' => $sourceHeight,
                'source_bytes' => $sourceBytes,
                'variant_pipeline' => 'gd-cover-v1',
            ]),
        ]);
        $asset->save();

        $asset->variants()->delete();
        $asset->variants()->create([
            'variant_key' => 'original',
            'path' => $sourcePath,
            'url' => $this->publicUrl($disk, $sourcePath),
            'mime_type' => $sourceMime,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'bytes' => $sourceBytes,
            'payload_json' => [
                'role' => 'source',
                'generated' => false,
            ],
        ]);

        foreach (self::DEFAULT_VARIANTS as $variantKey => $spec) {
            $variantPath = $this->variantPath($asset, $variantKey);
            $binary = $this->resizeCoverJpeg(
                $sourceImage,
                $sourceWidth,
                $sourceHeight,
                (int) $spec['width'],
                (int) $spec['height'],
                (int) $spec['quality']
            );

            Storage::disk($disk)->put($variantPath, $binary);

            $asset->variants()->create([
                'variant_key' => $variantKey,
                'path' => $variantPath,
                'url' => $this->publicUrl($disk, $variantPath),
                'mime_type' => 'image/jpeg',
                'width' => (int) $spec['width'],
                'height' => (int) $spec['height'],
                'bytes' => strlen($binary),
                'payload_json' => [
                    'role' => 'generated_variant',
                    'fit' => 'cover',
                    'quality' => (int) $spec['quality'],
                    'generated' => true,
                ],
            ]);
        }

        imagedestroy($sourceImage);

        return $asset->load('variants');
    }

    public function canGenerate(MediaAsset $asset): bool
    {
        $disk = trim((string) $asset->disk);
        $path = trim((string) $asset->path);

        return $disk !== ''
            && $path !== ''
            && config('filesystems.disks.'.$disk) !== null
            && Storage::disk($disk)->exists($path);
    }

    private function resizeCoverJpeg(
        \GdImage $sourceImage,
        int $sourceWidth,
        int $sourceHeight,
        int $targetWidth,
        int $targetHeight,
        int $quality
    ): string {
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));

        $sourceRatio = $sourceWidth / max(1, $sourceHeight);
        $targetRatio = $targetWidth / max(1, $targetHeight);

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        imagecopyresampled(
            $target,
            $sourceImage,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        ob_start();
        imagejpeg($target, null, max(1, min(100, $quality)));
        $binary = (string) ob_get_clean();
        imagedestroy($target);

        return $binary;
    }

    private function sourceDirectory(MediaAsset $asset): string
    {
        return 'media-library/sources/'.$this->assetDirectory($asset);
    }

    private function sourceFilename(MediaAsset $asset, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';

        return 'source-'.date('YmdHis').'.'.$extension;
    }

    private function variantPath(MediaAsset $asset, string $variantKey): string
    {
        $spec = self::DEFAULT_VARIANTS[$variantKey] ?? ['width' => 0, 'height' => 0];

        return sprintf(
            'media-library/variants/%s/%s_%sx%s.jpg',
            $this->assetDirectory($asset),
            $variantKey,
            (string) $spec['width'],
            (string) $spec['height']
        );
    }

    private function assetDirectory(MediaAsset $asset): string
    {
        return Str::slug((string) $asset->asset_key) ?: 'media-asset-'.$asset->getKey();
    }

    private function publicUrl(string $disk, string $path): ?string
    {
        $canonical = PublicMediaUrlGuard::publicMediaUrlForPath($disk, $path);
        if ($canonical !== null) {
            return $canonical;
        }

        $url = Storage::disk($disk)->url($path);

        return PublicMediaUrlGuard::sanitizeNullableUrl(is_string($url) ? $url : null);
    }

    private function detectMime(string $binary): ?string
    {
        $info = @getimagesizefromstring($binary);

        return is_array($info) && isset($info['mime']) ? (string) $info['mime'] : null;
    }
}
