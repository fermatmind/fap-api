<?php

declare(strict_types=1);

namespace Tests\Feature\MediaLibrary;

use App\Models\MediaAsset;
use App\Models\MediaVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaLibraryPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_import_creates_media_assets_and_variants(): void
    {
        $this->artisan('media-assets:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/media_assets',
        ])
            ->expectsOutputToContain('files_found=1')
            ->expectsOutputToContain('assets_found=3')
            ->expectsOutputToContain('will_create=3')
            ->assertExitCode(0);

        $this->assertSame(3, MediaAsset::query()->withoutGlobalScopes()->count());
        $this->assertSame(9, MediaVariant::query()->count());
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
            ->assertJsonPath('asset.variants.0.variant_key', 'card');

        $this->putJson('/api/v0.5/internal/media-assets/share.mbti.default', [
            'path' => '/static/share/mbti_wide_1200x630.png',
            'url' => 'https://api.fermatmind.com/static/share/mbti_wide_1200x630.png',
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
                    'url' => 'https://api.fermatmind.com/static/share/mbti_wide_1200x630.png',
                    'mime_type' => 'image/jpeg',
                    'width' => 1200,
                    'height' => 630,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('asset.alt', 'Updated share image')
            ->assertJsonPath('asset.variants.0.variant_key', 'og');
    }

    public function test_internal_upload_generates_standard_media_variants(): void
    {
        Storage::fake('public');

        $this->post('/api/v0.5/internal/media-assets/articles.hero/upload', [
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
        $this->assertSame(6, $asset->variants()->count());

        foreach (['hero', 'card', 'thumbnail', 'og', 'preload'] as $variantKey) {
            $variant = MediaVariant::query()
                ->where('media_asset_id', $asset->id)
                ->where('variant_key', $variantKey)
                ->firstOrFail();

            $this->assertSame('image/jpeg', (string) $variant->mime_type);
            $this->assertNotEmpty($variant->path);
            Storage::disk('public')->assertExists((string) $variant->path);
        }
    }
}
