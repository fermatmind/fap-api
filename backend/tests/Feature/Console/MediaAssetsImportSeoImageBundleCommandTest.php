<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaAssetsImportSeoImageBundleCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSLATION_GROUP_ID = 'tg_article_image_bundle_2026v1';

    public function test_valid_image_manifest_dry_run_passes_without_database_or_storage_writes(): void
    {
        Storage::fake('public');
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame('would_import_media_assets', $payload['action']);
        $this->assertSame(1, $payload['would_create']);
        $this->assertSame(1, $payload['would_generate_variants']);
        $this->assertSame('article.daily.seo.cover.v1', $payload['resolved_metadata']['cover_media_asset_key']);
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MediaVariant::query()->count());
        $this->assertFalse(Storage::disk('public')->exists('media-library'));
    }

    public function test_manifest_accepts_localized_alt_text_and_extension_format_allowed(): void
    {
        Storage::fake('public');
        $package = $this->writeImageBundlePackage(static function (array &$manifest): void {
            $manifest['assets'][0]['alt_text'] = [
                'zh-CN' => '结构化职业决策地图封面图',
                'en' => 'SEO cover image showing a structured career decision map',
            ];
            $manifest['assets'][0]['format_allowed'] = ['png', 'jpg', 'webp'];
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('结构化职业决策地图封面图', $payload['resolved_metadata']['cover_image_alt']);
        $this->assertSame('结构化职业决策地图封面图', $payload['assets'][0]['alt_text']);
    }

    public function test_seo_release_preflight_fails_when_media_runtime_disabled_without_writes(): void
    {
        Storage::fake('public');
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:seo-release-preflight', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('media_runtime_not_ready', $payload['action']);
        $this->assertFalse($payload['would_write']);
        $this->assertErrorCode($payload, 'media_runtime_not_ready');
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MediaVariant::query()->count());
        $this->assertArrayNotHasKey('AWS_SECRET_ACCESS_KEY', $payload['runtime']);
    }

    public function test_seo_release_preflight_passes_with_production_media_runtime(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        config([
            'app.env' => 'production',
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:seo-release-preflight', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('seo_release_media_ready', $payload['action']);
        $this->assertFalse($payload['would_write']);
        $this->assertFalse($payload['resume_required']);
        $this->assertStringContainsString('media-assets:import-seo-image-bundle', $payload['next_command']);
    }

    public function test_seo_release_preflight_existing_skipped_asset_requires_resume(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        config([
            'app.env' => 'production',
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'article.daily.seo.cover.v1',
            'disk' => 'public',
            'path' => 'media-library/source/old.png',
            'url' => 'https://assets.fermatmind.com/storage/media-library/source/old.png',
            'mime_type' => 'image/png',
            'width' => 1600,
            'height' => 900,
            'bytes' => 100,
            'alt' => 'Old image',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SKIPPED,
            'cdn_status' => MediaAsset::CDN_SKIPPED,
        ]);
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:seo-release-preflight', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('existing_asset_not_ready', $payload['action']);
        $this->assertTrue($payload['resume_required']);
        $this->assertTrue($payload['needs_allow_update_existing']);
        $this->assertStringContainsString('--allow-update-existing', $payload['next_command']);
        $this->assertErrorCode($payload, 'existing_asset_not_ready');
    }

    public function test_missing_manifest_fails_when_image_bundle_required(): void
    {
        $package = $this->writeImageBundlePackage();
        unlink($package.'/media/IMAGE_ASSET_MANIFEST.json');

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'image_asset_manifest_missing');
    }

    public function test_missing_file_fails(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest): void {
            $manifest['assets'][0]['source_file'] = 'media/missing.png';
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'source_file_missing');
    }

    public function test_invalid_mime_and_svg_fail(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest, string $root): void {
            file_put_contents($root.'/media/cover_source_1600x900.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
            $manifest['assets'][0]['source_file'] = 'media/cover_source_1600x900.svg';
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'image_extension_not_allowed');
        $this->assertErrorCode($payload, 'svg_not_allowed');
    }

    public function test_oversize_file_fails(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest, string $root): void {
            file_put_contents($root.'/media/cover_source_1600x900.png', str_repeat('x', 10485761));
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'image_file_too_large');
    }

    public function test_missing_alt_fails(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest): void {
            $manifest['assets'][0]['alt_text'] = '';
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'alt_text_invalid');
    }

    public function test_invalid_asset_key_fails(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest): void {
            $manifest['assets'][0]['asset_key'] = 'bad-key';
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'asset_key_invalid');
    }

    public function test_competitor_asset_true_fails(): void
    {
        $package = $this->writeImageBundlePackage(static function (array &$manifest): void {
            $manifest['assets'][0]['provenance']['competitor_asset'] = true;
        });

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'competitor_asset_not_allowed');
    }

    public function test_non_dry_run_creates_media_asset_and_variants_without_cms_article_mutation(): void
    {
        Storage::fake('public');
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('imported_media_assets', $payload['action']);
        $this->assertSame(1, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(6, MediaVariant::query()->count());
        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());

        $asset = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.daily.seo.cover.v1')->firstOrFail();
        $this->assertSame(MediaAsset::STATUS_PUBLISHED, (string) $asset->status);
        $this->assertTrue((bool) $asset->is_public);
        $this->assertSame(MediaAsset::CDN_SKIPPED, (string) $asset->cdn_status);
        $this->assertNull($payload['resolved_metadata']['cover_image_url']);
        $this->assertNull($payload['resolved_metadata']['cover_image_variants']['hero']['url']);
        $this->assertNull($payload['resolved_metadata']['og_image_url']);
        $this->assertNull($payload['resolved_metadata']['twitter_image_url']);
        Storage::disk('public')->assertExists((string) $asset->path);
    }

    public function test_non_dry_run_does_not_emit_app_storage_urls_when_cdn_sync_is_skipped(): void
    {
        config(['app.url' => 'https://ops.fermatmind.com']);
        Storage::fake('public');
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertNull($payload['resolved_metadata']['cover_image_url']);
        $this->assertNull($payload['resolved_metadata']['og_image_url']);
        $this->assertNull($payload['resolved_metadata']['cover_image_variants']['hero']['url']);
    }

    public function test_write_resolved_package_fails_before_writes_when_media_runtime_is_not_ready(): void
    {
        config(['app.url' => 'https://ops.fermatmind.com']);
        Storage::fake('public');
        $package = $this->writeImageBundlePackage();
        $output = sys_get_temp_dir().'/fm-image-bundle-not-ready-'.Str::random(12);

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
            '--write-resolved-package' => true,
            '--resolved-output-dir' => $output,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('media_runtime_not_ready', $payload['action']);
        $this->assertErrorCode($payload, 'media_runtime_not_ready');
        $this->assertDirectoryDoesNotExist($output);
        $this->assertSame(0, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, MediaVariant::query()->count());
    }

    public function test_write_resolved_package_requires_and_outputs_canonical_assets_origin_urls(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config([
            'app.url' => 'https://ops.fermatmind.com',
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        $package = $this->writeImageBundlePackage();
        $output = sys_get_temp_dir().'/fm-image-bundle-canonical-'.Str::random(12);

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
            '--write-resolved-package' => true,
            '--resolved-output-dir' => $output,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/variants/', $payload['resolved_metadata']['cover_image_url']);
        $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/variants/', $payload['resolved_metadata']['og_image_url']);
        $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/variants/', $payload['resolved_metadata']['cover_image_variants']['hero']['url']);
        $this->assertStringNotContainsString('ops.fermatmind.com', json_encode($payload['resolved_metadata'], JSON_THROW_ON_ERROR));

        $draft = json_decode((string) file_get_contents($output.'/cms/CMS_IMPORT_DRAFT_en_demo.json'), true);
        $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/variants/', $draft['cover_image_url']);
        $this->assertStringNotContainsString('ops.fermatmind.com', json_encode($draft, JSON_THROW_ON_ERROR));
    }

    public function test_write_resolved_package_resumes_existing_asset_with_allow_update_existing(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'article.daily.seo.cover.v1',
            'disk' => 'public',
            'path' => 'media-library/source/old.png',
            'url' => 'https://assets.fermatmind.com/storage/media-library/source/old.png',
            'mime_type' => 'image/png',
            'width' => 1600,
            'height' => 900,
            'bytes' => 100,
            'alt' => 'Old image',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SKIPPED,
            'cdn_status' => MediaAsset::CDN_SKIPPED,
        ]);
        $package = $this->writeImageBundlePackage();
        $output = sys_get_temp_dir().'/fm-image-bundle-resume-'.Str::random(12);

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
            '--write-resolved-package' => true,
            '--resolved-output-dir' => $output,
            '--allow-update-existing' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(1, MediaAsset::query()->withoutGlobalScopes()->count());

        $asset = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.daily.seo.cover.v1')->firstOrFail();
        $this->assertSame('SEO cover image showing a structured career decision map', (string) $asset->alt);
        $this->assertSame(MediaAsset::SYNC_SYNCED, (string) $asset->sync_status);
        $this->assertSame(MediaAsset::CDN_VERIFIED, (string) $asset->cdn_status);
        $this->assertSame(6, $asset->variants()->count());
        $this->assertFileExists($output.'/cms/CMS_IMPORT_DRAFT_en_demo.json');
    }

    public function test_seo_release_cleanup_dry_run_reports_half_failed_assets(): void
    {
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 404, ['Content-Type' => 'text/html']),
        ]);
        Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'demo',
            'locale' => 'en',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'title' => 'Demo',
            'excerpt' => 'Demo',
            'content_md' => 'Body',
            'cover_image_url' => 'https://assets.fermatmind.com/storage/media-library/variants/articledailyseocoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'Demo',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => [
                'editorial_package_v1' => [
                    'cover_media_asset_key' => 'article.daily.seo.cover.v1',
                ],
            ],
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
        ]);
        MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'article.daily.seo.cover.v1',
            'disk' => 'public',
            'path' => 'media-library/source/cover.png',
            'url' => 'https://assets.fermatmind.com/storage/media-library/source/cover.png',
            'mime_type' => 'image/png',
            'width' => 1600,
            'height' => 900,
            'bytes' => 100,
            'alt' => 'Demo',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SKIPPED,
            'cdn_status' => MediaAsset::CDN_SKIPPED,
        ]);

        $exitCode = Artisan::call('media-assets:seo-release-cleanup', [
            '--asset-prefix' => 'article.daily.seo',
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('cleanup_dry_run', $payload['action']);
        $this->assertTrue($payload['deletion_held']);
        $this->assertSame(1, $payload['not_ready_count']);
        $this->assertFalse($payload['assets'][0]['ready_for_cms']);
        $this->assertTrue($payload['assets'][0]['cms_reference_status']['referenced']);
    }

    public function test_seo_release_cleanup_resync_moves_half_failed_assets_to_verified(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config([
            'fap.media.oss_sync_enabled' => false,
            'fap.media.cdn_verify_enabled' => false,
        ]);
        $package = $this->writeImageBundlePackage();

        $createExitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
        ]));
        $this->assertSame(0, $createExitCode);
        $asset = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.daily.seo.cover.v1')->firstOrFail();
        $this->assertSame(MediaAsset::SYNC_SKIPPED, (string) $asset->sync_status);

        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);

        $exitCode = Artisan::call('media-assets:seo-release-cleanup', [
            '--asset-prefix' => 'article.daily.seo',
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--resync' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('resynced_media_assets', $payload['action']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame(0, $payload['not_ready_count']);
        $this->assertTrue($payload['assets'][0]['ready_for_cms']);

        $fresh = MediaAsset::query()->withoutGlobalScopes()->where('asset_key', 'article.daily.seo.cover.v1')->firstOrFail();
        $this->assertSame(MediaAsset::SYNC_SYNCED, (string) $fresh->sync_status);
        $this->assertSame(MediaAsset::CDN_VERIFIED, (string) $fresh->cdn_status);
    }

    public function test_dry_run_does_not_write_existing_asset_or_storage(): void
    {
        Storage::fake('public');
        MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'article.daily.seo.cover.v1',
            'disk' => 'public_static',
            'path' => '/static/old.jpg',
            'url' => 'https://assets.fermatmind.com/static/old.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'bytes' => 100,
            'alt' => 'Old image',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
        ]);
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
            '--allow-update-existing' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['would_update']);
        $this->assertSame('Old image', (string) MediaAsset::query()->withoutGlobalScopes()->firstOrFail()->alt);
        $this->assertSame(0, MediaVariant::query()->count());
        $this->assertFalse(Storage::disk('public')->exists('media-library'));
    }

    public function test_duplicate_recent_cover_emits_warning(): void
    {
        Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'recent-article',
            'locale' => 'en',
            'translation_group_id' => 'tg_recent',
            'title' => 'Recent article',
            'excerpt' => 'Recent article',
            'content_md' => 'Body',
            'cover_image_url' => 'https://assets.fermatmind.com/storage/media-library/variants/articledailyseocoverv1/hero_1600x900.jpg',
            'cover_image_alt' => 'Recent cover',
            'cover_image_width' => 1600,
            'cover_image_height' => 900,
            'cover_image_variants' => [
                'editorial_package_v1' => [
                    'cover_media_asset_key' => 'article.daily.seo.cover.v1',
                ],
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ]);
        $package = $this->writeImageBundlePackage();

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--dry-run' => true,
            '--json' => true,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertWarningCode($payload, 'duplicate_recent_cover_asset');
    }

    public function test_write_resolved_package_outputs_safe_copy_with_cms_metadata(): void
    {
        Storage::fake('public');
        Storage::fake('s3');
        Http::fake([
            'assets.fermatmind.com/*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config([
            'fap.media.oss_sync_enabled' => true,
            'fap.media.oss_disk' => 's3',
            'fap.media.oss_key_prefix' => 'storage',
            'fap.media.cdn_verify_enabled' => true,
        ]);
        $package = $this->writeImageBundlePackage();
        $output = sys_get_temp_dir().'/fm-image-bundle-resolved-'.Str::random(12);

        $exitCode = Artisan::call('media-assets:import-seo-image-bundle', $this->commandOptions($package, [
            '--json' => true,
            '--write-resolved-package' => true,
            '--resolved-output-dir' => $output,
        ]));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertSame($output, $payload['resolved_package_dir']);
        $this->assertFileExists($output.'/cms/CMS_IMPORT_DRAFT_en_demo.json');

        $draft = json_decode((string) file_get_contents($output.'/cms/CMS_IMPORT_DRAFT_en_demo.json'), true);
        $this->assertSame('article.daily.seo.cover.v1', $draft['cover_media_asset_key']);
        $this->assertSame('SEO cover image showing a structured career decision map', $draft['cover_image_alt']);
        $this->assertArrayHasKey('hero', $draft['cover_image_variants']);
        $this->assertSame($draft['og_image_url'], $draft['twitter_image_url']);

        $original = json_decode((string) file_get_contents($package.'/cms/CMS_IMPORT_DRAFT_en_demo.json'), true);
        $this->assertSame('__CMS_MEDIA_LIBRARY_PLACEHOLDER__', $original['cover_media_asset_key']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandOptions(string $package, array $overrides = []): array
    {
        return array_replace([
            '--package' => $package,
            '--translation-group-id' => self::TRANSLATION_GROUP_ID,
            '--locales' => 'zh-CN,en',
            '--expected-asset-prefix' => 'article.daily.seo',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertWarningCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $warning): string => (string) ($warning['code'] ?? ''),
            $payload['warnings'] ?? []
        ));
    }

    /**
     * @param  callable(array<string,mixed>&, string):void|null  $mutate
     */
    private function writeImageBundlePackage(?callable $mutate = null): string
    {
        $root = sys_get_temp_dir().'/fm-image-bundle-'.Str::random(12);
        mkdir($root.'/media', 0777, true);
        mkdir($root.'/cms', 0777, true);

        $this->writePng($root.'/media/cover_source_1600x900.png', 1600, 900);

        $manifest = [
            'schema_version' => 'image_asset_manifest_v1',
            'package_id' => 'daily-seo-demo',
            'translation_group_id' => self::TRANSLATION_GROUP_ID,
            'locale_scope' => ['zh-CN', 'en'],
            'assets' => [
                [
                    'asset_key' => 'article.daily.seo.cover.v1',
                    'role' => 'cover',
                    'source_file' => 'media/cover_source_1600x900.png',
                    'alt_text' => 'SEO cover image showing a structured career decision map',
                    'locale_strategy' => 'shared_for_zh_cn_and_en',
                    'intended_usage' => ['cover', 'hero', 'card', 'thumbnail', 'og', 'twitter'],
                    'dimensions_expected' => ['width' => 1600, 'height' => 900, 'exact' => true],
                    'format_allowed' => ['image/jpeg', 'image/png', 'image/webp'],
                    'max_bytes' => 10485760,
                    'fallback_allowed' => false,
                    'provenance' => [
                        'source' => 'gpt_generated_for_fermatmind',
                        'prompt_file' => 'media/IMAGE_PROMPTS.md',
                        'competitor_asset' => false,
                    ],
                ],
            ],
            'qa_gates' => [
                'file_exists' => true,
                'dimensions' => true,
                'format' => true,
                'file_size' => true,
                'alt_text' => true,
                'no_competitor_image' => true,
                'no_private_asset' => true,
                'no_placeholder' => true,
                'media_library_public' => true,
                'cdn_200' => true,
                'variants_present' => true,
                'cms_payload_backfilled' => true,
                'preview_rendered' => true,
                'recent_article_card_duplicate_check' => true,
            ],
        ];

        if ($mutate !== null) {
            $mutate($manifest, $root);
        }

        file_put_contents($root.'/media/IMAGE_ASSET_MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($root.'/media/IMAGE_PROMPTS.md', "# Prompt\nGenerated for FermatMind only.\n");
        file_put_contents($root.'/cms/CMS_IMPORT_DRAFT_en_demo.json', json_encode([
            'locale' => 'en',
            'slug' => 'demo',
            'cover_media_asset_key' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
            'cover_image_url' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
            'cover_image_alt' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
            'og_image_url' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
            'twitter_image_url' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($root.'/cms/CMS_FIELDS_en_demo.json', json_encode([
            'locale' => 'en',
            'slug' => 'demo',
            'cover_media_asset_key' => '__CMS_MEDIA_LIBRARY_PLACEHOLDER__',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $root;
    }

    private function writePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 247, 244));
        imagefilledrectangle($image, 40, 40, $width - 40, $height - 40, imagecolorallocate($image, 8, 99, 116));
        imagepng($image, $path);
        imagedestroy($image);
    }
}
