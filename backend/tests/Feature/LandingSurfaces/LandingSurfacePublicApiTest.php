<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\LandingSurface;
use App\Models\PageBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LandingSurfacePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_import_creates_home_tests_and_career_surfaces(): void
    {
        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])
            ->expectsOutputToContain('files_found=10')
            ->expectsOutputToContain('surfaces_found=10')
            ->expectsOutputToContain('will_create=10')
            ->assertExitCode(0);

        $this->assertSame(10, LandingSurface::query()->withoutGlobalScopes()->count());
        $this->assertGreaterThan(0, PageBlock::query()->count());

        $home = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'home')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('published', (string) $home->status);
        $this->assertSame('FermatMind / 费马测试', (string) data_get($home->payload_json, 'hero.brand'));
    }

    public function test_public_and_internal_api_return_surface_payloads(): void
    {
        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('surface.surface_key', 'home')
            ->assertJsonPath('surface.locale', 'zh-CN')
            ->assertJsonPath('surface.payload_json.hero.brand', 'FermatMind / 费马测试');

        $this->putJson('/api/v0.5/internal/landing-surfaces/home', [
            'locale' => 'zh-CN',
            'title' => '首页更新',
            'description' => '后台更新首页。',
            'schema_version' => 'home.v1',
            'payload_json' => [
                'seo' => ['title' => '首页更新'],
                'hero' => ['title' => '后台首页'],
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'page_blocks' => [
                [
                    'block_key' => 'hero',
                    'block_type' => 'homepage_section',
                    'title' => '后台首页',
                    'payload_json' => ['title' => '后台首页'],
                    'sort_order' => 0,
                    'is_enabled' => true,
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('surface.title', '首页更新')
            ->assertJsonPath('surface.page_blocks.0.block_key', 'hero');
    }
}
