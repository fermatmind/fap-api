<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveLandingSurfaceMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SURFACE_KEY = 'test_detail_big_five_personality_test_ocean_model';

    public function test_missing_reviewed_surface_is_created_without_creating_english_or_tenant_content(): void
    {
        LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', self::SURFACE_KEY)
            ->where('locale', 'zh-CN')
            ->delete();

        LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 1,
            'surface_key' => self::SURFACE_KEY,
            'locale' => 'zh-CN',
            'title' => 'Tenant authority',
            'schema_version' => 'v1',
            'payload_json' => [],
            'status' => LandingSurface::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
        ]);

        $this->migration()->up();

        $surface = $this->surface();
        $this->assertSame('免费大五人格测试（OCEAN）', $surface->title);
        $this->assertSame('seo-top100-frozen.v1', $surface->schema_version);
        $this->assertSame(LandingSurface::STATUS_PUBLISHED, $surface->status);
        $this->assertTrue($surface->is_public);
        $this->assertFalse($surface->is_indexable);
        $this->assertNull($surface->published_at);
        $this->assertNull($surface->scheduled_at);
        $this->assertSame($this->expectedPayload(), $surface->payload_json);
        $this->assertFalse(LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('surface_key', self::SURFACE_KEY)->where('locale', 'en')->exists());
        $this->assertSame('Tenant authority', LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 1)->where('surface_key', self::SURFACE_KEY)->value('title'));
    }

    public function test_repeated_execution_is_a_no_op_and_down_preserves_publication(): void
    {
        $before = $this->surface()->getRawOriginal();

        $this->migration()->up();
        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame($before, $this->surface()->getRawOriginal());
        $this->assertSame(1, LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('surface_key', self::SURFACE_KEY)->where('locale', 'zh-CN')->count());
    }

    public function test_conflicting_authority_fails_closed_without_overwriting(): void
    {
        $surface = $this->surface();
        $surface->forceFill(['title' => 'Owner-authored conflicting title'])->saveQuietly();

        try {
            $this->migration()->up();
            $this->fail('Expected the migration to reject conflicting authority.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refusing to overwrite', $exception->getMessage());
        }

        $this->assertSame('Owner-authored conflicting title', $this->surface()->title);
    }

    public function test_public_api_projects_every_reviewed_field(): void
    {
        $this->getJson('/api/v0.5/landing-surfaces/'.self::SURFACE_KEY.'?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('surface.surface_key', self::SURFACE_KEY)
            ->assertJsonPath('surface.locale', 'zh-CN')
            ->assertJsonPath('surface.title', '免费大五人格测试（OCEAN）')
            ->assertJsonPath('surface.description', '约15分钟完成120题大五人格测试，查看开放性、尽责性、外倾性、宜人性与神经质结果。用于自我理解，不作诊断或筛选。')
            ->assertJsonPath('surface.schema_version', 'seo-top100-frozen.v1')
            ->assertJsonPath('surface.status', 'published')
            ->assertJsonPath('surface.is_public', true)
            ->assertJsonPath('surface.is_indexable', false)
            ->assertJsonPath('surface.published_at', null)
            ->assertJsonPath('surface.payload_json', $this->expectedPayload())
            ->assertJsonPath('surface.page_blocks', []);

        $this->getJson('/api/v0.5/landing-surfaces/'.self::SURFACE_KEY.'?locale=en&org_id=0')
            ->assertNotFound();
    }

    public function test_surface_copy_is_exactly_bound_to_the_frozen_big_five_row(): void
    {
        $package = json_decode(file_get_contents(
            base_path('content_assets/seo-top100/SEO-TOP100-FROZEN-20260812-v1/targets.json'),
        ), true, 512, JSON_THROW_ON_ERROR);
        $row = collect($package['targets'])->firstWhere('priority', 23);

        $this->assertSame('https://fermatmind.com/zh/tests/big-five-personality-test-ocean-model', $row['url']);
        $this->assertSame($row['proposed_title'], $this->expectedPayload()['seo_title']);
        $this->assertSame($row['proposed_description'], $this->expectedPayload()['seo_description']);
        $this->assertSame($row['proposed_H1_or_KEEP'], $this->expectedPayload()['h1_or_hero_title']);
        $this->assertSame($row['proposed_intro_or_exact_action'], $this->expectedPayload()['intro']);
        $this->assertSame($row['proposed_intro_or_exact_action'], $this->expectedPayload()['hero_copy']);
        $this->assertSame([], $this->expectedPayload()['internal_links']);
    }

    private function surface(): LandingSurface
    {
        return LandingSurface::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', self::SURFACE_KEY)
            ->where('locale', 'zh-CN')
            ->firstOrFail();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_06_180000_publish_big_five_landing_surface_zh.php');
    }

    /** @return array<string, mixed> */
    private function expectedPayload(): array
    {
        return [
            'seo_title' => '免费大五人格测试（OCEAN）：120题完整结果 | FermatMind',
            'seo_description' => '约15分钟完成120题大五人格测试，查看开放性、尽责性、外倾性、宜人性与神经质结果。用于自我理解，不作诊断或筛选。',
            'h1_or_hero_title' => '免费大五人格测试（OCEAN）',
            'intro' => '用约15分钟完成120题，获得开放性、尽责性、外倾性、宜人性与神经质五个维度的结果。结果是自我观察线索，不是诊断、能力判断或职业保证。',
            'hero_copy' => '用约15分钟完成120题，获得开放性、尽责性、外倾性、宜人性与神经质五个维度的结果。结果是自我观察线索，不是诊断、能力判断或职业保证。',
            'internal_links' => [],
        ];
    }
}
