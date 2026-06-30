<?php

declare(strict_types=1);

namespace App\Services\Cms\SeoImageBundle;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Services\Cms\MediaAssetStorageSyncService;
use App\Services\Cms\MediaVariantGenerator;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class SeoImageBundleImporter
{
    private const MANIFEST_PATH = 'media/IMAGE_ASSET_MANIFEST.json';

    private const ALLOWED_ROLES = ['cover', 'body_visual', 'og_override', 'card_override', 'thumbnail_override'];

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const FORMAT_EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const MAX_BYTES = 10485760;

    private const RECOMMENDED_MAX_BYTES = 3145728;

    public function __construct(
        private readonly MediaVariantGenerator $variantGenerator,
        private readonly MediaAssetStorageSyncService $syncService,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function planFromDirectory(array $options): array
    {
        return $this->run($options, true);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function importFromDirectory(array $options): array
    {
        return $this->run($options, false);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function run(array $options, bool $dryRun): array
    {
        $package = $this->resolvePackagePath((string) ($options['package'] ?? ''));
        $translationGroupId = trim((string) ($options['translation_group_id'] ?? ''));
        $expectedPrefix = trim((string) ($options['expected_asset_prefix'] ?? ''));
        $allowUpdateExisting = (bool) ($options['allow_update_existing'] ?? false);
        $writeResolvedPackage = (bool) ($options['write_resolved_package'] ?? false);
        $resolvedOutputDir = trim((string) ($options['resolved_output_dir'] ?? ''));
        $locales = $this->locales((array) ($options['locales'] ?? []));

        $errors = [];
        $warnings = [];
        $manifestPath = $package.'/'.self::MANIFEST_PATH;

        if (! is_file($manifestPath)) {
            return $this->summary(false, $dryRun, 'will_skip', $package, $translationGroupId, [], [], [[
                'field' => self::MANIFEST_PATH,
                'code' => 'image_asset_manifest_missing',
                'message' => 'media/IMAGE_ASSET_MANIFEST.json is required for SEO image bundle import.',
            ]], []);
        }

        $manifest = $this->readJsonFile($manifestPath, self::MANIFEST_PATH);
        if ($translationGroupId !== '' && trim((string) ($manifest['translation_group_id'] ?? '')) !== $translationGroupId) {
            $errors[] = $this->issue('translation_group_id', 'translation_group_id_mismatch', 'Manifest translation_group_id does not match expected value.');
        }

        $assets = $this->normalizeAssets($manifest, $package, $expectedPrefix, $errors, $warnings);
        $plans = [];
        foreach ($assets as $asset) {
            $plans[] = $this->assetPlan($asset, $allowUpdateExisting, $errors, $warnings);
        }

        $this->duplicateRecentCoverCheck($assets, $warnings);

        if ($errors !== []) {
            return $this->summary(false, $dryRun, 'will_skip', $package, $translationGroupId, $plans, [], $errors, $warnings);
        }

        if ($dryRun) {
            return $this->summary(true, true, 'would_import_media_assets', $package, $translationGroupId, $plans, $this->resolvedMetadataFromPlans($plans, $locales, true), [], $warnings);
        }

        if ($writeResolvedPackage) {
            $this->validateMediaRuntimeReadyForResolvedPackage($errors);
            if ($errors !== []) {
                return $this->summary(false, false, 'media_runtime_not_ready', $package, $translationGroupId, $plans, [], $errors, $warnings);
            }
        }

        $writtenPlans = [];
        DB::transaction(function () use ($plans, &$writtenPlans): void {
            foreach ($plans as $plan) {
                $writtenPlans[] = $this->writeAsset($plan);
            }
        });

        $resolved = $this->resolvedMetadataFromPlans($writtenPlans, $locales, false);
        $resolvedPackage = null;
        if ($writeResolvedPackage) {
            $this->validateResolvedMetadataReadyForCms($resolved, $errors);
            if ($errors !== []) {
                return $this->summary(false, false, 'media_asset_cdn_not_ready', $package, $translationGroupId, $writtenPlans, $resolved, $errors, $warnings);
            }

            $resolvedPackage = $this->writeResolvedPackageCopy($package, $resolved, $resolvedOutputDir, $warnings);
        }

        $summary = $this->summary(true, false, 'imported_media_assets', $package, $translationGroupId, $writtenPlans, $resolved, [], $warnings);
        $summary['resolved_package_dir'] = $resolvedPackage;

        return $summary;
    }

    private function resolvePackagePath(string $package): string
    {
        $candidate = trim($package);
        if ($candidate === '') {
            throw new RuntimeException('--package is required.');
        }

        $real = realpath($candidate);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Package directory not found: '.$candidate);
        }

        return $real;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path, string $field): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: '.$field);
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function normalizeAssets(array $manifest, string $package, string $expectedPrefix, array &$errors, array &$warnings): array
    {
        $rows = $manifest['assets'] ?? null;
        if (! is_array($rows) || $rows === []) {
            $errors[] = $this->issue('assets', 'assets_missing', 'Manifest assets must be a non-empty array.');

            return [];
        }

        $assets = [];
        foreach (array_values($rows) as $index => $row) {
            $field = 'assets.'.$index;
            if (! is_array($row)) {
                $errors[] = $this->issue($field, 'asset_invalid', 'Asset row must be an object.');

                continue;
            }

            $assetKey = strtolower(trim((string) ($row['asset_key'] ?? '')));
            $role = trim((string) ($row['role'] ?? ''));
            $sourceFile = ltrim(trim((string) ($row['source_file'] ?? '')), '/');
            $altText = $this->normalizeAltText($row['alt_text'] ?? null);
            $path = $package.'/'.$sourceFile;

            if (! preg_match('/^article\.[a-z0-9][a-z0-9.-]*\.v[0-9]+$/', $assetKey)) {
                $errors[] = $this->issue($field.'.asset_key', 'asset_key_invalid', 'Asset key must match article.<topic>.<role>.vN.');
            }
            if ($expectedPrefix !== '' && ! str_starts_with($assetKey, $expectedPrefix)) {
                $errors[] = $this->issue($field.'.asset_key', 'asset_key_prefix_mismatch', 'Asset key does not match --expected-asset-prefix.');
            }
            if (! in_array($role, self::ALLOWED_ROLES, true)) {
                $errors[] = $this->issue($field.'.role', 'asset_role_invalid', 'Unsupported image asset role.');
            }
            if ($sourceFile === '' || str_contains($sourceFile, '..')) {
                $errors[] = $this->issue($field.'.source_file', 'source_file_invalid', 'Source file path is missing or unsafe.');
            }
            if ($altText === '' || mb_strlen($altText) > 255) {
                $errors[] = $this->issue($field.'.alt_text', 'alt_text_invalid', 'Alt text is required and must be <= 255 characters.');
            }

            $provenance = $row['provenance'] ?? null;
            if (! is_array($provenance)) {
                $errors[] = $this->issue($field.'.provenance', 'provenance_missing', 'Image provenance is required.');
            } elseif (($provenance['competitor_asset'] ?? null) !== false) {
                $errors[] = $this->issue($field.'.provenance.competitor_asset', 'competitor_asset_not_allowed', 'Competitor assets are not allowed.');
            }

            $dimensions = is_array($row['dimensions_expected'] ?? null) ? $row['dimensions_expected'] : [];
            $expectedWidth = (int) ($dimensions['width'] ?? 0);
            $expectedHeight = (int) ($dimensions['height'] ?? 0);
            if ($expectedWidth <= 0 || $expectedHeight <= 0) {
                $errors[] = $this->issue($field.'.dimensions_expected', 'dimensions_expected_invalid', 'Expected dimensions are required.');
            }

            $fileMeta = $this->validateImageFile($path, $field.'.source_file', $expectedWidth, $expectedHeight, (bool) ($dimensions['exact'] ?? false), $errors, $warnings);
            $this->validateManifestFileConstraints($row, $fileMeta, $field, $errors);

            $assets[] = [
                'field' => $field,
                'asset_key' => $assetKey,
                'role' => $role,
                'source_file' => $sourceFile,
                'path' => $path,
                'alt_text' => $altText,
                'caption' => $this->nullableString($row['caption'] ?? null),
                'credit' => $this->nullableString($row['credit'] ?? null),
                'intended_usage' => array_values(array_filter((array) ($row['intended_usage'] ?? []), 'is_string')),
                'provenance' => is_array($provenance) ? $provenance : [],
                'alt_text_localized' => is_array($row['alt_text'] ?? null) ? $row['alt_text'] : null,
                'file' => $fileMeta,
            ];
        }

        return $assets;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function validateImageFile(string $path, string $field, int $expectedWidth, int $expectedHeight, bool $exact, array &$errors, array &$warnings): array
    {
        if (! is_file($path)) {
            $errors[] = $this->issue($field, 'source_file_missing', 'Image source file does not exist.');

            return [];
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $errors[] = $this->issue($field, 'image_extension_not_allowed', 'Only jpg, jpeg, png, and webp image files are allowed.');
        }
        if ($extension === 'svg') {
            $errors[] = $this->issue($field, 'svg_not_allowed', 'SVG is not allowed for SEO image bundles.');
        }

        $bytes = filesize($path);
        if ($bytes === false) {
            $bytes = 0;
        }
        if ($bytes > self::MAX_BYTES) {
            $errors[] = $this->issue($field, 'image_file_too_large', 'Image file exceeds 10 MB.');
        } elseif ($bytes > self::RECOMMENDED_MAX_BYTES) {
            $warnings[] = $this->issue($field, 'image_file_above_recommended_size', 'Image file is above the recommended 3 MB daily SEO target.');
        }

        $imageSize = @getimagesize($path);
        if (! is_array($imageSize)) {
            $errors[] = $this->issue($field, 'image_unreadable', 'Image dimensions and MIME type could not be read.');

            return ['bytes' => $bytes];
        }

        $width = (int) ($imageSize[0] ?? 0);
        $height = (int) ($imageSize[1] ?? 0);
        $mime = strtolower((string) ($imageSize['mime'] ?? ''));
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            $errors[] = $this->issue($field, 'image_mime_not_allowed', 'Only image/jpeg, image/png, and image/webp are allowed.');
        }
        if ($exact && ($width !== $expectedWidth || $height !== $expectedHeight)) {
            $errors[] = $this->issue($field, 'image_dimensions_mismatch', 'Image dimensions do not exactly match manifest dimensions.');
        } elseif (! $exact && ($width < $expectedWidth || $height < $expectedHeight)) {
            $errors[] = $this->issue($field, 'image_dimensions_too_small', 'Image dimensions are smaller than manifest dimensions.');
        }

        $binary = (string) file_get_contents($path);
        if (($extension === 'webp' && str_contains($binary, 'ANMF')) || ($extension === 'png' && str_contains($binary, 'acTL'))) {
            $errors[] = $this->issue($field, 'animated_image_not_allowed', 'Animated images are not allowed.');
        }
        if (str_contains($binary, '__CMS_MEDIA_LIBRARY_PLACEHOLDER__')) {
            $errors[] = $this->issue($field, 'placeholder_not_allowed', 'Image file contains a CMS placeholder marker.');
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime_type' => $mime,
            'bytes' => $bytes,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $fileMeta
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateManifestFileConstraints(array $row, array $fileMeta, string $field, array &$errors): void
    {
        if ($fileMeta === []) {
            return;
        }

        $allowedFormats = array_values(array_filter(array_map(
            fn (mixed $format): string => $this->normalizeAllowedFormat($format),
            (array) ($row['format_allowed'] ?? [])
        ), static fn (string $format): bool => $format !== ''));
        if ($allowedFormats !== [] && ! in_array((string) ($fileMeta['mime_type'] ?? ''), $allowedFormats, true)) {
            $errors[] = $this->issue($field.'.format_allowed', 'image_mime_not_allowed_by_manifest', 'Image MIME type is not allowed by manifest format_allowed.');
        }

        $maxBytes = (int) ($row['max_bytes'] ?? self::MAX_BYTES);
        if ($maxBytes > 0 && (int) ($fileMeta['bytes'] ?? 0) > $maxBytes) {
            $errors[] = $this->issue($field.'.max_bytes', 'image_file_exceeds_manifest_max_bytes', 'Image file exceeds manifest max_bytes.');
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function assetPlan(array $asset, bool $allowUpdateExisting, array &$errors, array &$warnings): array
    {
        $existing = MediaAsset::query()
            ->withoutGlobalScopes()
            ->with('variants')
            ->where('org_id', 0)
            ->where('asset_key', $asset['asset_key'])
            ->first();

        if ($existing instanceof MediaAsset && ! $allowUpdateExisting) {
            $errors[] = $this->issue($asset['field'].'.asset_key', 'asset_exists_update_not_allowed', 'Media asset already exists; pass --allow-update-existing to update it.');
        }

        return [
            ...$asset,
            'existing_asset_id' => $existing?->id,
            'existing_sync_status' => $existing?->sync_status,
            'existing_cdn_status' => $existing?->cdn_status,
            'resume_required' => $existing instanceof MediaAsset
                && ((string) $existing->sync_status !== MediaAsset::SYNC_SYNCED || (string) $existing->cdn_status !== MediaAsset::CDN_VERIFIED),
            'would_create' => ! $existing instanceof MediaAsset,
            'would_update' => $existing instanceof MediaAsset,
            'would_generate_variants' => true,
            'would_patch_package' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @param  list<array<string,mixed>>  $warnings
     */
    private function duplicateRecentCoverCheck(array $assets, array &$warnings): void
    {
        $coverKeys = array_values(array_map(
            static fn (array $asset): string => (string) $asset['asset_key'],
            array_filter($assets, static fn (array $asset): bool => (string) $asset['role'] === 'cover')
        ));
        if ($coverKeys === []) {
            return;
        }

        $recent = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereIn('status', ['published', 'human_review', 'draft'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($recent as $article) {
            $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
            $recordedKey = trim((string) data_get($variants, 'editorial_package_v1.cover_media_asset_key'));
            foreach ($coverKeys as $coverKey) {
                if ($recordedKey === $coverKey || str_contains((string) $article->cover_image_url, str_replace('.', '', $coverKey))) {
                    $warnings[] = $this->issue('duplicate_recent_cover', 'duplicate_recent_cover_asset', 'A recent SEO article appears to use the same cover asset key: '.$coverKey);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function writeAsset(array $plan): array
    {
        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'org_id' => 0,
                'asset_key' => (string) $plan['asset_key'],
            ]);

        $asset->fill([
            'alt' => (string) $plan['alt_text'],
            'caption' => $this->nullableString($plan['caption'] ?? null),
            'credit' => $this->nullableString($plan['credit'] ?? null),
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'payload_json' => array_merge($asset->payload_json ?? [], [
                'seo_image_bundle_v1' => [
                    'role' => (string) $plan['role'],
                    'source_file' => (string) $plan['source_file'],
                    'intended_usage' => $plan['intended_usage'] ?? [],
                    'provenance' => $plan['provenance'] ?? [],
                    'alt_text_localized' => $plan['alt_text_localized'] ?? null,
                ],
            ]),
        ]);
        $asset->save();

        $uploaded = new UploadedFile(
            (string) $plan['path'],
            basename((string) $plan['source_file']),
            (string) data_get($plan, 'file.mime_type'),
            null,
            true
        );

        $asset = $this->syncService->syncAndVerify($this->variantGenerator->storeUploadAndGenerate($asset, $uploaded));
        $this->assertImportedAssetIsSafe($asset);

        return [
            ...$plan,
            'media_asset_id' => (int) $asset->id,
            'asset' => $this->assetPayload($asset),
            'would_create' => false,
            'would_update' => false,
            'would_generate_variants' => false,
            'would_patch_package' => true,
        ];
    }

    private function assertImportedAssetIsSafe(MediaAsset $asset): void
    {
        $asset->loadMissing('variants');
        if ((string) $asset->status !== MediaAsset::STATUS_PUBLISHED || ! (bool) $asset->is_public) {
            throw new RuntimeException('Imported media asset is not published and public: '.$asset->asset_key);
        }
        if ((string) $asset->cdn_status === MediaAsset::CDN_FAILED) {
            throw new RuntimeException('Imported media asset CDN verification failed: '.$asset->asset_key);
        }
        if (PublicMediaUrlGuard::canonicalMediaUrl((string) $asset->disk, $asset->path, $asset->url) === null) {
            throw new RuntimeException('Imported media asset URL is not public-safe: '.$asset->asset_key);
        }

        $variants = $asset->variants->keyBy(fn ($variant): string => (string) $variant->variant_key);
        foreach (MediaVariantGenerator::variantKeys() as $variantKey) {
            $variant = $variants->get($variantKey);
            if (! $variant) {
                throw new RuntimeException('Imported media asset is missing variant '.$variantKey.': '.$asset->asset_key);
            }
            if ((string) $variant->cdn_status === MediaAsset::CDN_FAILED) {
                throw new RuntimeException('Imported media asset variant CDN verification failed: '.$asset->asset_key.' '.$variantKey);
            }
            if (PublicMediaUrlGuard::canonicalMediaUrl((string) $asset->disk, $variant->path, $variant->url) === null) {
                throw new RuntimeException('Imported media asset variant URL is not public-safe: '.$asset->asset_key.' '.$variantKey);
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     * @param  list<string>  $locales
     * @return array<string,mixed>
     */
    private function resolvedMetadataFromPlans(array $plans, array $locales, bool $dryRun): array
    {
        $cover = $this->firstRolePlan($plans, 'cover');
        $body = $this->firstRolePlan($plans, 'body_visual');

        $metadata = [
            'locales' => $locales,
            'cover_media_asset_key' => $cover ? (string) $cover['asset_key'] : null,
            'cover_image_url' => $cover ? $this->variantUrl($cover, 'hero', $dryRun) : null,
            'cover_image_alt' => $cover ? (string) $cover['alt_text'] : null,
            'cover_image_width' => $cover ? (int) data_get($cover, 'asset.width', data_get($cover, 'file.width', 0)) : null,
            'cover_image_height' => $cover ? (int) data_get($cover, 'asset.height', data_get($cover, 'file.height', 0)) : null,
            'cover_image_variants' => $cover ? $this->variantMap($cover) : [],
            'og_image_url' => $this->variantUrl($this->firstRolePlan($plans, 'og_override') ?? $cover, 'og', $dryRun),
            'twitter_image_url' => $this->variantUrl($this->firstRolePlan($plans, 'og_override') ?? $cover, 'og', $dryRun),
            'social_image_metadata' => $cover ? $this->socialMetadata($cover, $dryRun) : [],
            'body_visual_asset_key' => $body ? (string) $body['asset_key'] : null,
            'body_visual_image_url' => $body ? $this->variantUrl($body, 'hero', $dryRun) : null,
            'body_visual_fallback_authorized' => false,
        ];

        return $metadata;
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     */
    private function firstRolePlan(array $plans, string $role): ?array
    {
        foreach ($plans as $plan) {
            if ((string) ($plan['role'] ?? '') === $role) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $plan
     */
    private function variantUrl(?array $plan, string $variantKey, bool $dryRun): ?string
    {
        if ($plan === null) {
            return null;
        }

        $variant = data_get($plan, 'asset.variants.'.$variantKey);
        if (is_array($variant)) {
            return $this->nullableString($variant['url'] ?? null);
        }

        if ($dryRun) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,array<string,mixed>>
     */
    private function variantMap(array $plan): array
    {
        $assetVariants = data_get($plan, 'asset.variants');
        if (is_array($assetVariants)) {
            return $assetVariants;
        }

        return [
            'hero' => ['url' => null, 'width' => 1600, 'height' => 900, 'mime_type' => 'image/jpeg'],
            'card' => ['url' => null, 'width' => 800, 'height' => 450, 'mime_type' => 'image/jpeg'],
            'thumbnail' => ['url' => null, 'width' => 400, 'height' => 225, 'mime_type' => 'image/jpeg'],
            'og' => ['url' => null, 'width' => 1200, 'height' => 630, 'mime_type' => 'image/jpeg'],
            'preload' => ['url' => null, 'width' => 64, 'height' => 36, 'mime_type' => 'image/jpeg'],
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function socialMetadata(array $plan, bool $dryRun): array
    {
        return [
            'media_library_asset_key' => (string) $plan['asset_key'],
            'media_library_status' => 'published',
            'media_library_is_public' => true,
            'cover_image_url' => $this->variantUrl($plan, 'hero', $dryRun),
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'hero_variant' => $this->variantMap($plan)['hero'] ?? null,
            'og_image_url' => $this->variantUrl($plan, 'og', $dryRun),
            'og_image_width' => 1200,
            'og_image_height' => 630,
            'og_1200x630_variant' => $this->variantMap($plan)['og'] ?? null,
            'twitter_image_url' => $this->variantUrl($plan, 'og', $dryRun),
            'asset_provenance' => 'SEO image bundle import: '.(string) $plan['asset_key'],
            'alt_text' => (string) $plan['alt_text'],
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function assetPayload(MediaAsset $asset): array
    {
        $asset->loadMissing('variants');
        $variants = [];
        foreach ($asset->variants as $variant) {
            if ((string) $variant->variant_key === 'original') {
                continue;
            }
            $variants[(string) $variant->variant_key] = [
                'url' => $this->publicMediaUrlForImportedRecord(
                    (string) $asset->disk,
                    $variant->path,
                    $variant->url,
                    (string) $variant->sync_status,
                    (string) $variant->cdn_status,
                ),
                'width' => (int) $variant->width,
                'height' => (int) $variant->height,
                'mime_type' => (string) $variant->mime_type,
            ];
        }

        return [
            'id' => (int) $asset->id,
            'asset_key' => (string) $asset->asset_key,
            'url' => $this->publicMediaUrlForImportedRecord(
                (string) $asset->disk,
                $asset->path,
                $asset->url,
                (string) $asset->sync_status,
                (string) $asset->cdn_status,
            ),
            'mime_type' => (string) $asset->mime_type,
            'width' => (int) $asset->width,
            'height' => (int) $asset->height,
            'bytes' => (int) $asset->bytes,
            'alt' => (string) $asset->alt,
            'status' => (string) $asset->status,
            'is_public' => (bool) $asset->is_public,
            'sync_status' => (string) $asset->sync_status,
            'cdn_status' => (string) $asset->cdn_status,
            'variants' => $variants,
        ];
    }

    private function publicMediaUrlForImportedRecord(string $disk, mixed $path, mixed $url, string $syncStatus, string $cdnStatus): ?string
    {
        if ($syncStatus !== MediaAsset::SYNC_SYNCED || $cdnStatus !== MediaAsset::CDN_VERIFIED) {
            return null;
        }

        return PublicMediaUrlGuard::canonicalMediaUrl($disk, $path, $url);
    }

    /**
     * @param  array<string,mixed>  $resolved
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateResolvedMetadataReadyForCms(array $resolved, array &$errors): void
    {
        foreach (['cover_image_url', 'og_image_url', 'twitter_image_url'] as $field) {
            $this->assertCanonicalAssetUrl($resolved[$field] ?? null, $field, $errors);
        }

        if (filled($resolved['body_visual_asset_key'] ?? null)) {
            $this->assertCanonicalAssetUrl($resolved['body_visual_image_url'] ?? null, 'body_visual_image_url', $errors);
        }

        foreach ((array) ($resolved['cover_image_variants'] ?? []) as $variantKey => $variant) {
            if (! is_array($variant)) {
                $errors[] = $this->issue('cover_image_variants.'.$variantKey, 'media_asset_cdn_not_ready', 'Cover image variant metadata is not ready for CMS package use.');

                continue;
            }

            $this->assertCanonicalAssetUrl($variant['url'] ?? null, 'cover_image_variants.'.$variantKey.'.url', $errors);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateMediaRuntimeReadyForResolvedPackage(array &$errors): void
    {
        if (! (bool) config('fap.media.oss_sync_enabled', false)) {
            $errors[] = $this->issue('fap.media.oss_sync_enabled', 'media_runtime_not_ready', 'FAP_MEDIA_OSS_SYNC_ENABLED must be true before writing a CMS-ready resolved package.');
        }

        if (! (bool) config('fap.media.cdn_verify_enabled', false)) {
            $errors[] = $this->issue('fap.media.cdn_verify_enabled', 'media_runtime_not_ready', 'FAP_MEDIA_CDN_VERIFY_ENABLED must be true before writing a CMS-ready resolved package.');
        }

        $origin = PublicMediaUrlGuard::canonicalAssetOrigin();
        if ($origin !== PublicMediaUrlGuard::DEFAULT_ASSET_ORIGIN) {
            $errors[] = $this->issue('fap.media.asset_origin', 'media_runtime_not_ready', 'FAP_MEDIA_ASSET_ORIGIN must be https://assets.fermatmind.com for daily SEO resolved packages.');
        }

        if (trim((string) config('fap.media.oss_disk', '')) === '') {
            $errors[] = $this->issue('fap.media.oss_disk', 'media_runtime_not_ready', 'FAP_MEDIA_OSS_DISK must be configured before writing a CMS-ready resolved package.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function assertCanonicalAssetUrl(mixed $url, string $field, array &$errors): void
    {
        $candidate = PublicMediaUrlGuard::sanitizeNullableUrl($url);
        $canonicalOrigin = PublicMediaUrlGuard::canonicalAssetOrigin();

        if ($candidate === null || ! str_starts_with($candidate, $canonicalOrigin.'/')) {
            $errors[] = $this->issue($field, 'media_asset_cdn_not_ready', 'Media asset must be synced and CDN-verified on the canonical public asset origin before writing a CMS-ready resolved package.');
        }
    }

    /**
     * @param  array<string,mixed>  $resolved
     * @param  list<array<string,mixed>>  $warnings
     */
    private function writeResolvedPackageCopy(string $package, array $resolved, string $resolvedOutputDir, array &$warnings): string
    {
        $output = $resolvedOutputDir !== ''
            ? $resolvedOutputDir
            : dirname($package).'/resolved-package';

        if (realpath($output) === realpath($package)) {
            throw new RuntimeException('Resolved output directory must not be the original package directory.');
        }
        if (is_dir($output)) {
            File::deleteDirectory($output);
        }
        File::copyDirectory($package, $output);

        foreach (glob($output.'/cms/CMS_FIELDS_*.json') ?: [] as $file) {
            $this->patchJsonFile($file, $resolved);
        }
        foreach (glob($output.'/cms/CMS_IMPORT_DRAFT_*.json') ?: [] as $file) {
            $this->patchJsonFile($file, $resolved);
        }

        if (! is_dir($output.'/cms')) {
            $warnings[] = $this->issue('cms', 'cms_directory_missing_for_patch', 'Resolved package was copied, but cms/ directory was not present.');
        }

        return $output;
    }

    /**
     * @param  array<string,mixed>  $resolved
     */
    private function patchJsonFile(string $file, array $resolved): void
    {
        $payload = $this->readJsonFile($file, basename($file));
        foreach ([
            'cover_media_asset_key',
            'cover_image_url',
            'cover_image_alt',
            'cover_image_width',
            'cover_image_height',
            'cover_image_variants',
            'og_image_url',
            'twitter_image_url',
            'social_image_metadata',
            'body_visual_asset_key',
            'body_visual_image_url',
            'body_visual_fallback_authorized',
        ] as $key) {
            if (array_key_exists($key, $resolved) && $resolved[$key] !== null) {
                $payload[$key] = $resolved[$key];
            }
        }

        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
    }

    /**
     * @param  list<array<string,mixed>>  $plans
     * @param  array<string,mixed>  $resolved
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function summary(bool $ok, bool $dryRun, string $action, string $package, string $translationGroupId, array $plans, array $resolved, array $errors, array $warnings): array
    {
        return [
            'ok' => $ok,
            'dry_run' => $dryRun,
            'action' => $action,
            'would_write' => ! $dryRun && $ok,
            'package' => $package,
            'translation_group_id' => $translationGroupId,
            'manifest_path' => self::MANIFEST_PATH,
            'assets_count' => count($plans),
            'would_create' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['would_create'] ?? false))),
            'would_update' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['would_update'] ?? false))),
            'would_generate_variants' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['would_generate_variants'] ?? false))),
            'would_patch_package' => count(array_filter($plans, static fn (array $plan): bool => (bool) ($plan['would_patch_package'] ?? false))),
            'assets' => array_map(fn (array $plan): array => $this->safePlanPayload($plan), $plans),
            'resolved_metadata' => $resolved,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function safePlanPayload(array $plan): array
    {
        return [
            'asset_key' => (string) ($plan['asset_key'] ?? ''),
            'role' => (string) ($plan['role'] ?? ''),
            'source_file' => (string) ($plan['source_file'] ?? ''),
            'alt_text' => (string) ($plan['alt_text'] ?? ''),
            'file' => $plan['file'] ?? [],
            'existing_asset_id' => $plan['existing_asset_id'] ?? null,
            'existing_sync_status' => $plan['existing_sync_status'] ?? null,
            'existing_cdn_status' => $plan['existing_cdn_status'] ?? null,
            'resume_required' => (bool) ($plan['resume_required'] ?? false),
            'media_asset_id' => $plan['media_asset_id'] ?? null,
            'would_create' => (bool) ($plan['would_create'] ?? false),
            'would_update' => (bool) ($plan['would_update'] ?? false),
            'would_generate_variants' => (bool) ($plan['would_generate_variants'] ?? false),
            'would_patch_package' => (bool) ($plan['would_patch_package'] ?? false),
        ];
    }

    /**
     * @return list<string>
     */
    private function locales(array $locales): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $locale): string => trim((string) $locale),
            $locales
        ), static fn (string $locale): bool => $locale !== ''));

        return $normalized !== [] ? $normalized : ['zh-CN', 'en'];
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeAltText(mixed $value): string
    {
        if (is_array($value)) {
            foreach (['zh-CN', 'zh_cn', 'zh', 'en'] as $localeKey) {
                $candidate = $this->nullableString($value[$localeKey] ?? null);
                if ($candidate !== null) {
                    return $candidate;
                }
            }

            foreach ($value as $candidate) {
                $normalized = $this->nullableString($candidate);
                if ($normalized !== null) {
                    return $normalized;
                }
            }

            return '';
        }

        return trim((string) $value);
    }

    private function normalizeAllowedFormat(mixed $format): string
    {
        $normalized = strtolower(trim((string) $format));
        if ($normalized === '') {
            return '';
        }

        $normalized = ltrim($normalized, '.');

        return self::FORMAT_EXTENSION_TO_MIME[$normalized] ?? $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
