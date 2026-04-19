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
        $this->assertSame('看清自己，走好每一步', (string) data_get($home->payload_json, 'hero.title'));
        $this->assertSame(
            '费马测试把自我认知、职业探索与能力成长，做成可测量、可训练、可复盘的成长系统。',
            (string) data_get($home->payload_json, 'hero.subhead')
        );
        $this->assertCount(6, data_get($home->payload_json, 'quickStart.items'));
        $this->assertContains('霍兰德职业兴趣测试', array_column(data_get($home->payload_json, 'quickStart.items'), 'title'));
        $this->assertContains('抑郁焦虑综合症测试', array_column(data_get($home->payload_json, 'quickStart.items'), 'title'));
        $this->assertContains(
            '/career/tests/riasec',
            array_column(data_get($home->payload_json, 'quickStart.items'), 'href')
        );
        $this->assertContains(
            '/tests/clinical-depression-anxiety-assessment-professional-edition',
            array_column(data_get($home->payload_json, 'quickStart.items'), 'href')
        );
        $this->assertSame(
            ['免费测试、免费结果', '我们高度重视您的隐私。', '百万用户进行了多次测试'],
            array_column(data_get($home->payload_json, 'trust.items'), 'title')
        );
        $this->assertSame(
            [
                '核心测评围绕自我认知、职业判断与能力成长设计，并提供清晰结果。',
                '无需先注册账号，你可以先完成测试，再决定是否保存或继续深入。',
                '从人格、能力到情绪状态，多个入口帮助你持续复盘自己的变化。',
            ],
            array_column(data_get($home->payload_json, 'trust.items'), 'summary')
        );

        $quickStartBlock = PageBlock::query()
            ->where('landing_surface_id', $home->id)
            ->where('block_key', 'quickstart')
            ->firstOrFail();

        $this->assertCount(6, data_get($quickStartBlock->payload_json, 'items'));
        $this->assertContains('霍兰德职业兴趣测试', array_column(data_get($quickStartBlock->payload_json, 'items'), 'title'));
        $this->assertContains('抑郁焦虑综合症测试', array_column(data_get($quickStartBlock->payload_json, 'items'), 'title'));
        $this->assertContains(
            '/career/tests/riasec',
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );
        $this->assertContains(
            '/tests/clinical-depression-anxiety-assessment-professional-edition',
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );

        $tests = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'tests')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $emotionFamily = collect(data_get($tests->payload_json, 'families.items'))
            ->firstWhere('id', 'family-emotion-state');

        $this->assertContains(
            'depression-screening-test-standard-edition',
            array_column(data_get($emotionFamily, 'tests'), 'key')
        );
        $this->assertContains(
            '/zh/tests/depression-screening-test-standard-edition/take',
            array_column(data_get($emotionFamily, 'tests'), 'href')
        );
        $this->assertContains(
            'clinical-depression-anxiety-assessment-professional-edition',
            array_column(data_get($emotionFamily, 'tests'), 'key')
        );
        $this->assertContains(
            '/zh/tests/clinical-depression-anxiety-assessment-professional-edition/take',
            array_column(data_get($emotionFamily, 'tests'), 'href')
        );
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
            ->assertJsonPath('surface.payload_json.hero.brand', 'FermatMind / 费马测试')
            ->assertJsonPath('surface.payload_json.hero.title', '看清自己，走好每一步')
            ->assertJsonPath(
                'surface.payload_json.hero.subhead',
                '费马测试把自我认知、职业探索与能力成长，做成可测量、可训练、可复盘的成长系统。'
            )
            ->assertJsonCount(6, 'surface.payload_json.quickStart.items')
            ->assertJsonPath('surface.payload_json.quickStart.items.3.title', '霍兰德职业兴趣测试')
            ->assertJsonPath('surface.payload_json.quickStart.items.3.href', '/career/tests/riasec')
            ->assertJsonPath('surface.payload_json.quickStart.items.5.title', '抑郁焦虑综合症测试')
            ->assertJsonPath('surface.payload_json.quickStart.items.5.href', '/tests/clinical-depression-anxiety-assessment-professional-edition')
            ->assertJsonPath('surface.payload_json.trust.items.0.title', '免费测试、免费结果')
            ->assertJsonPath(
                'surface.payload_json.trust.items.0.summary',
                '核心测评围绕自我认知、职业判断与能力成长设计，并提供清晰结果。'
            )
            ->assertJsonPath('surface.payload_json.trust.items.1.title', '我们高度重视您的隐私。')
            ->assertJsonPath(
                'surface.payload_json.trust.items.1.summary',
                '无需先注册账号，你可以先完成测试，再决定是否保存或继续深入。'
            )
            ->assertJsonPath('surface.payload_json.trust.items.2.title', '百万用户进行了多次测试')
            ->assertJsonPath(
                'surface.payload_json.trust.items.2.summary',
                '从人格、能力到情绪状态，多个入口帮助你持续复盘自己的变化。'
            );

        $this->getJson('/api/v0.5/landing-surfaces/tests?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('surface.surface_key', 'tests')
            ->assertJsonPath('surface.locale', 'zh-CN')
            ->assertJsonCount(5, 'surface.payload_json.quickStart.items')
            ->assertJsonPath('surface.payload_json.quickStart.items.0.href', '/zh/tests/category/career')
            ->assertJsonPath('surface.payload_json.quickStart.items.2.href', '/zh/tests#family-emotion-state')
            ->assertJsonCount(5, 'surface.payload_json.families.items')
            ->assertJsonPath('surface.payload_json.families.items.2.id', 'family-emotion-state')
            ->assertJsonPath(
                'surface.payload_json.families.items.2.tests.0.href',
                '/zh/tests/depression-screening-test-standard-edition/take'
            )
            ->assertJsonPath(
                'surface.payload_json.families.items.2.tests.1.href',
                '/zh/tests/clinical-depression-anxiety-assessment-professional-edition/take'
            )
            ->assertJsonPath(
                'surface.payload_json.families.items.3.tests.0.href',
                '/zh/tests/iq-test-intelligence-quotient-assessment/take'
            );

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
