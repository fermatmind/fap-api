<?php

declare(strict_types=1);

namespace Tests\Feature\MediaLibrary;

use App\Filament\Ops\Resources\ArticleResource;
use App\Filament\Ops\Resources\MediaAssetResource\Pages\CreateMediaAsset;
use App\Models\AdminUser;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Cms\MediaVariantGenerator;
use App\Support\Rbac\PermissionNames;
use Filament\Facades\Filament;
use Filament\PanelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

final class MediaLibraryPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(app(PanelRegistry::class)->get('ops'));
    }

    public function test_baseline_import_creates_media_assets_and_variants(): void
    {
        $this->artisan('media-assets:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/media_assets',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('assets_found=230')
            ->expectsOutputToContain('will_create=230')
            ->assertExitCode(0);

        $this->assertSame(230, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(239, MediaVariant::query()->count());
    }

    public function test_public_and_internal_api_return_media_variant_metadata(): void
    {
        $this->artisan('media-assets:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/media_assets',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/media-assets/share.mbti.default?org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'share.mbti.default')
            ->assertJsonPath('asset.url', 'https://assets.fermatmind.com/static/share/mbti_cover_source.png')
            ->assertJsonPath('asset.variants.0.variant_key', 'card');

        $this->actingAsContentWriter()->putJson('/api/v0.5/internal/media-assets/share.mbti.default', [
            'path' => '/static/share/mbti_wide_1200x630.png',
            'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/static/share/mbti_wide_1200x630.png',
            'mime_type' => 'image/jpeg',
            'width' => 1200,
            'height' => 630,
            'alt' => 'Updated share image',
            'status' => 'published',
            'is_public' => true,
            'variants' => [
                [
                    'variant_key' => 'og',
                    'path' => '/static/share/mbti_wide_1200x630.png',
                    'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/static/share/mbti_wide_1200x630.png',
                    'mime_type' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 630,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.alt', 'Updated share image')
            ->assertJsonPath('asset.url', 'https://assets.fermatmind.com/static/share/mbti_wide_1200x630.png')
            ->assertJsonPath('asset.variants.0.url', 'https://assets.fermatmind.com/static/share/mbti_wide_1200x630.png')
            ->assertJsonPath('asset.variants.0.variant_key', 'og');
    }

    public function test_wechat_qr_baseline_assets_resolve_to_committed_static_media(): void
    {
        $this->artisan('media-assets:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/media_assets',
        ])->assertExitCode(0);

        $this->assertFileExists(base_path('public/static/social/wechat-qr-official-258.jpg'));
        $this->assertFileExists(base_path('public/static/social/wechat-qr.jpg'));

        $this->getJson('/api/v0.5/media-assets/social.wechat.official_qr?org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'social.wechat.official_qr')
            ->assertJsonPath('asset.path', '/static/social/wechat-qr-official-258.jpg')
            ->assertJsonPath('asset.url', 'https://assets.fermatmind.com/static/social/wechat-qr-official-258.jpg')
            ->assertJsonPath('asset.variants.0.variant_key', 'original')
            ->assertJsonPath('asset.variants.0.path', '/static/social/wechat-qr-official-258.jpg');

        $this->getJson('/api/v0.5/media-assets/social.wechat.qr?org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'social.wechat.qr')
            ->assertJsonPath('asset.path', '/static/social/wechat-qr.jpg')
            ->assertJsonPath('asset.url', 'https://assets.fermatmind.com/static/social/wechat-qr.jpg')
            ->assertJsonPath('asset.variants.0.variant_key', 'original')
            ->assertJsonPath('asset.variants.0.path', '/static/social/wechat-qr.jpg');
    }

    public function test_internal_upload_generates_standard_media_variants(): void
    {
        Storage::fake('public');

        $this->actingAsContentWriter()->post('/api/v0.5/internal/media-assets/articles.hero/upload', [
            'file' => UploadedFile::fake()->image('source.jpg', 1800, 1200),
            'alt' => 'Article hero image',
            'caption' => 'Generated through Media Library.',
            'credit' => 'FermatMind',
            'status' => 'published',
            'is_public' => true,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.asset_key', 'articles.hero')
            ->assertJsonPath('asset.disk', 'public')
            ->assertJsonPath('asset.alt', 'Article hero image')
            ->assertJsonFragment(['variant_key' => 'hero'])
            ->assertJsonFragment(['variant_key' => 'card'])
            ->assertJsonFragment(['variant_key' => 'thumbnail'])
            ->assertJsonFragment(['variant_key' => 'og'])
            ->assertJsonFragment(['variant_key' => 'preload'])
            ->assertJsonFragment(['variant_key' => 'original']);

        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('asset_key', 'articles.hero')
            ->firstOrFail();

        $this->assertSame(1800, (int) $asset->width);
        $this->assertSame(1200, (int) $asset->height);
        $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/sources/articleshero/', (string) $asset->url);
        $this->assertSame(6, $asset->variants()->count());

        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $variantKey) {
            $variant = MediaVariant::query()
                ->where('media_asset_id', $asset->id)
                ->where('variant_key', $variantKey)
                ->firstOrFail();

            $this->assertSame('image/jpeg', (string) $variant->mime_type);
            $this->assertNotEmpty($variant->path);
            $this->assertStringStartsWith('https://assets.fermatmind.com/storage/media-library/variants/articleshero/', (string) $variant->url);
            Storage::disk('public')->assertExists((string) $variant->path);
        }
    }

    public function test_filament_upload_generates_public_media_path_instead_of_temporary_path(): void
    {
        Storage::fake('public');

        $this->actingAsContentWriter();

        Livewire::test(CreateMediaAsset::class)
            ->fillForm([
                'org_id' => 0,
                'uploaded_source' => UploadedFile::fake()->image('receipt.png', 1239, 1280),
                'asset_key' => 'daily-giving-unicef-receipt-2026-06-05',
                'disk' => 'public_static',
                'path' => null,
                'url' => null,
                'alt' => 'UNICEF donation receipt proof image',
                'caption' => null,
                'credit' => null,
                'status' => MediaAsset::STATUS_DRAFT,
                'is_public' => true,
                'payload_json' => [],
                'variants' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = MediaAsset::query()
            ->withoutGlobalScopes()
            ->where('asset_key', 'daily-giving-unicef-receipt-2026-06-05')
            ->firstOrFail();

        $this->assertSame('public', (string) $asset->disk);
        $this->assertStringStartsWith(
            'media-library/sources/daily-giving-unicef-receipt-2026-06-05/source-',
            (string) $asset->path
        );
        $this->assertFalse(str_starts_with((string) $asset->path, '/tmp/'));
        $this->assertStringStartsWith(
            'https://assets.fermatmind.com/storage/media-library/sources/daily-giving-unicef-receipt-2026-06-05/source-',
            (string) $asset->url
        );
        $this->assertSame(1239, (int) $asset->width);
        $this->assertSame(1280, (int) $asset->height);
        $this->assertSame('image/png', (string) $asset->mime_type);
        $this->assertSame(MediaAsset::SYNC_SKIPPED, (string) $asset->sync_status);
        $this->assertSame(MediaAsset::CDN_SKIPPED, (string) $asset->cdn_status);
        $this->assertSame(6, $asset->variants()->count());

        Storage::disk('public')->assertExists((string) $asset->path);
    }

    public function test_article_cover_binding_requires_verified_public_standard_variants(): void
    {
        $ready = $this->createReadyArticleCoverAsset('articles.ready-cover');

        $payload = $this->articleCoverPayload($ready);
        $this->assertSame(
            'https://assets.fermatmind.com/storage/media-library/variants/articles-ready-cover/hero_1600x900.jpg',
            $payload['cover_image_url']
        );
        $expectedVariantKeys = MediaVariantGenerator::variantKeys();
        $actualVariantKeys = array_keys($payload['cover_image_variants']);
        sort($expectedVariantKeys);
        sort($actualVariantKeys);
        $this->assertSame($expectedVariantKeys, $actualVariantKeys);

        $options = $this->mediaAssetOptions('ready-cover');
        $this->assertArrayHasKey((int) $ready->id, $options);

        $missingVariant = $this->createReadyArticleCoverAsset('articles.missing-variant');
        $missingVariant->variants()->where('variant_key', 'og')->delete();
        $missingVariant = $missingVariant->fresh('variants') ?? $missingVariant;

        $this->assertNull($this->articleCoverPayload($missingVariant)['cover_image_url']);
        $this->assertArrayNotHasKey((int) $missingVariant->id, $this->mediaAssetOptions('missing-variant'));

        $unsafeDisk = $this->createReadyArticleCoverAsset('articles.ops-only-cover');
        $unsafeDisk->forceFill([
            'disk' => 'local',
            'path' => 'ops-only/internal-cover.jpg',
        ])->save();

        $this->assertNull($this->articleCoverPayload($unsafeDisk)['cover_image_url']);
        $this->assertArrayNotHasKey((int) $unsafeDisk->id, $this->mediaAssetOptions('ops-only-cover'));
    }

    public function test_media_library_filters_legacy_url_when_no_path_can_be_canonicalized(): void
    {
        $this->actingAsContentWriter()->putJson('/api/v0.5/internal/media-assets/articles.legacy', [
            'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/article.jpg',
            'alt' => 'Legacy article image',
            'status' => 'published',
            'is_public' => true,
            'variants' => [
                [
                    'variant_key' => 'card',
                    'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/card.jpg',
                    'mime_type' => 'image/jpeg',
                    'width' => 800,
                    'height' => 450,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.url', null)
            ->assertJsonPath('asset.variants.0.url', null);

        $this->getJson('/api/v0.5/media-assets/articles.legacy?org_id=0')
            ->assertOk()
            ->assertJsonPath('asset.url', null)
            ->assertJsonPath('asset.variants.0.url', null);
    }

    public function test_legacy_media_url_audit_reports_findings_without_writes(): void
    {
        $asset = MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'articles.legacy-audit',
            'disk' => 'public_static',
            'path' => null,
            'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/article.jpg',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'payload_json' => [],
        ]);

        $asset->variants()->create([
            'variant_key' => 'card',
            'path' => null,
            'url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/card.jpg',
            'payload_json' => [],
        ]);

        $this->artisan('media-assets:audit-legacy-urls')
            ->expectsOutputToContain('dry_run=1')
            ->expectsOutputToContain('media_asset_legacy_url_count=1')
            ->expectsOutputToContain('media_variant_legacy_url_count=1')
            ->expectsOutputToContain('legacy_url_count=2')
            ->assertExitCode(0);

        $this->artisan('media-assets:audit-legacy-urls', ['--fail-on-findings' => true])
            ->assertExitCode(1);

        $asset->refresh();
        $this->assertSame('https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/article.jpg', (string) $asset->url);
    }

    public function test_legacy_media_url_audit_passes_when_no_findings_exist(): void
    {
        MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => 'articles.clean-audit',
            'disk' => 'public_static',
            'path' => '/static/articles/clean.jpg',
            'url' => 'https://assets.fermatmind.com/static/articles/clean.jpg',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'payload_json' => [],
        ]);

        $this->artisan('media-assets:audit-legacy-urls', ['--fail-on-findings' => true])
            ->expectsOutputToContain('legacy_url_count=0')
            ->expectsOutputToContain('no legacy media URLs found')
            ->assertExitCode(0);
    }

    private function createReadyArticleCoverAsset(string $assetKey): MediaAsset
    {
        $directory = str_replace('.', '-', $assetKey);

        $asset = MediaAsset::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'asset_key' => $assetKey,
            'disk' => 'public',
            'path' => 'media-library/sources/'.$directory.'/source.jpg',
            'url' => 'https://assets.fermatmind.com/storage/media-library/sources/'.$directory.'/source.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 1600,
            'height' => 900,
            'bytes' => 1000,
            'alt' => 'Ready article cover',
            'status' => MediaAsset::STATUS_PUBLISHED,
            'is_public' => true,
            'sync_status' => MediaAsset::SYNC_SYNCED,
            'cdn_status' => MediaAsset::CDN_VERIFIED,
            'payload_json' => [],
        ]);

        foreach (MediaVariantGenerator::DEFAULT_VARIANTS as $variantKey => $spec) {
            $asset->variants()->create([
                'variant_key' => $variantKey,
                'path' => sprintf(
                    'media-library/variants/%s/%s_%sx%s.jpg',
                    $directory,
                    $variantKey,
                    (string) $spec['width'],
                    (string) $spec['height']
                ),
                'url' => sprintf(
                    'https://assets.fermatmind.com/storage/media-library/variants/%s/%s_%sx%s.jpg',
                    $directory,
                    $variantKey,
                    (string) $spec['width'],
                    (string) $spec['height']
                ),
                'mime_type' => 'image/jpeg',
                'width' => (int) $spec['width'],
                'height' => (int) $spec['height'],
                'bytes' => 1000,
                'sync_status' => MediaAsset::SYNC_SYNCED,
                'cdn_status' => MediaAsset::CDN_VERIFIED,
                'payload_json' => [],
            ]);
        }

        return $asset->fresh('variants') ?? $asset;
    }

    /**
     * @return array{cover_image_url: ?string, cover_image_alt: ?string, cover_image_width: ?int, cover_image_height: ?int, cover_image_variants: array<string, array<string, mixed>>}
     */
    private function articleCoverPayload(MediaAsset $asset): array
    {
        $method = new ReflectionMethod(ArticleResource::class, 'articleCoverPayload');
        $method->setAccessible(true);

        /** @var array{cover_image_url: ?string, cover_image_alt: ?string, cover_image_width: ?int, cover_image_height: ?int, cover_image_variants: array<string, array<string, mixed>>} */
        return $method->invoke(null, $asset);
    }

    /**
     * @return array<int, string>
     */
    private function mediaAssetOptions(string $search): array
    {
        $method = new ReflectionMethod(ArticleResource::class, 'mediaAssetOptions');
        $method->setAccessible(true);

        /** @var array<int, string> */
        return $method->invoke(null, $search);
    }

    private function actingAsContentWriter(): self
    {
        $admin = AdminUser::query()->create([
            'name' => 'admin_'.Str::lower(Str::random(6)),
            'email' => 'admin_'.Str::lower(Str::random(6)).'@example.test',
            'password' => bcrypt('secret'),
            'is_active' => 1,
        ]);

        $role = Role::query()->create([
            'name' => 'role_'.Str::lower(Str::random(10)),
            'description' => null,
        ]);

        $permission = Permission::query()->firstOrCreate(
            ['name' => PermissionNames::ADMIN_CONTENT_WRITE],
            ['description' => null]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $this
            ->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'));
    }
}
