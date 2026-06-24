<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
            '/tests/enneagram-personality-test-nine-types',
            array_column(data_get($home->payload_json, 'quickStart.items'), 'href')
        );
        $this->assertContains(
            '/tests/holland-career-interest-test-riasec',
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
            '/tests/enneagram-personality-test-nine-types',
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );
        $this->assertContains(
            '/tests/holland-career-interest-test-riasec',
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );
        $this->assertContains(
            '/tests/clinical-depression-anxiety-assessment-professional-edition',
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );
        $this->assertSame(
            [
                '/tests/mbti-personality-test-16-personality-types',
                '/tests/big-five-personality-test-ocean-model',
                '/tests/enneagram-personality-test-nine-types',
                '/tests/holland-career-interest-test-riasec',
                '/tests/iq-test-intelligence-quotient-assessment',
                '/tests/clinical-depression-anxiety-assessment-professional-edition',
            ],
            array_column(data_get($quickStartBlock->payload_json, 'items'), 'href')
        );

        $recommendedArticlesBlock = PageBlock::query()
            ->where('landing_surface_id', $home->id)
            ->where('block_key', 'recommended_articles')
            ->firstOrFail();
        $recommendedArticleSlugs = array_map(
            static fn ($item): string => (string) data_get($item, 'article.slug'),
            data_get($recommendedArticlesBlock->payload_json, 'items', [])
        );

        $this->assertSame(
            [
                'which-love-script-fits-you-best',
                'how-personality-shapes-attitude-toward-ai',
                'how-16-personality-types-talk-to-an-ai-coach',
                'childhood-dream-job-still-shapes-career-choice',
                'best-valentines-date-by-personality-and-relationship-science',
                'are-infj-men-rare-or-socially-silenced',
            ],
            $recommendedArticleSlugs
        );
        $this->assertNotContains('mbti-basics', $recommendedArticleSlugs);
        $this->assertNotContains('big-five-tool-guide', $recommendedArticleSlugs);

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

        $personalityFamily = collect(data_get($tests->payload_json, 'families.items'))
            ->firstWhere('id', 'family-personality-style');

        $enneagramCard = collect(data_get($personalityFamily, 'tests'))
            ->firstWhere('key', 'enneagram-personality-test-nine-types');

        $this->assertIsArray($enneagramCard);
        $this->assertSame('/zh/tests/enneagram-personality-test-nine-types', (string) data_get($enneagramCard, 'href'));
        $this->assertSame('/zh/tests/enneagram-personality-test-nine-types', (string) data_get($enneagramCard, 'detailsHref'));
        $this->assertSame('105 / 144 题', (string) data_get($enneagramCard, 'questionsLabel'));
        $this->assertSame('约 15 / 40 分钟', (string) data_get($enneagramCard, 'durationLabel'));
        $this->assertSame(
            '/zh/tests/enneagram-personality-test-nine-types/take?form=enneagram_likert_105',
            (string) data_get($enneagramCard, 'primaryActions.0.href')
        );
        $this->assertSame(
            '/zh/tests/enneagram-personality-test-nine-types/take?form=enneagram_forced_choice_144',
            (string) data_get($enneagramCard, 'primaryActions.1.href')
        );

        $careerFamily = collect(data_get($tests->payload_json, 'families.items'))
            ->firstWhere('id', 'family-career-direction');
        $riasecCard = collect(data_get($careerFamily, 'tests'))
            ->firstWhere('key', 'holland-career-interest-test-riasec');

        $this->assertIsArray($riasecCard);
        $this->assertSame('/zh/tests/holland-career-interest-test-riasec', (string) data_get($riasecCard, 'href'));
        $this->assertSame('/zh/tests/holland-career-interest-test-riasec', (string) data_get($riasecCard, 'detailsHref'));
        $this->assertSame('60 / 140 题', (string) data_get($riasecCard, 'questionsLabel'));
        $this->assertSame('约 8 / 18 分钟', (string) data_get($riasecCard, 'durationLabel'));
        $this->assertSame(
            '/zh/tests/holland-career-interest-test-riasec/take?form=riasec_60',
            (string) data_get($riasecCard, 'primaryActions.0.href')
        );
        $this->assertSame(
            '/zh/tests/holland-career-interest-test-riasec/take?form=riasec_140',
            (string) data_get($riasecCard, 'primaryActions.1.href')
        );
    }

    public function test_baseline_import_accepts_absolute_source_directory(): void
    {
        $sourceDir = realpath(base_path('../content_baselines/landing_surfaces'));
        $this->assertIsString($sourceDir);

        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => $sourceDir,
        ])
            ->expectsOutputToContain('baseline_source_dir='.$sourceDir)
            ->expectsOutputToContain('files_found=10')
            ->expectsOutputToContain('will_create=10')
            ->assertExitCode(0);

        $this->assertSame(10, LandingSurface::query()->withoutGlobalScopes()->count());
    }

    public function test_iq_landing_baseline_is_cms_authoritative_and_claim_safe(): void
    {
        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])->assertExitCode(0);

        $home = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'home')
            ->where('locale', 'en')
            ->firstOrFail();
        $homeIqCard = collect(data_get($home->payload_json, 'quickStart.items'))
            ->firstWhere('key', 'iq-test-intelligence-quotient-assessment');

        $this->assertIsArray($homeIqCard);
        $this->assertSame('IQ Reasoning Practice', (string) data_get($homeIqCard, 'title'));
        $this->assertSame('30 questions', (string) data_get($homeIqCard, 'questionsLabel'));
        $this->assertSame('IQ_OWNER_ORIGINAL_30', (string) data_get($homeIqCard, 'primaryActions.0.form_code'));
        $this->assertSame('iq-owner-original-30-card', (string) data_get($homeIqCard, 'media.asset_key'));
        $this->assertSame('media_library_required', (string) data_get($homeIqCard, 'media.source'));
        $this->assertSame('backend_cms_media_library', (string) data_get($homeIqCard, 'media.authority'));
        $this->assertSame('metadata_only_no_frontend_asset', (string) data_get($homeIqCard, 'media.status'));
        $this->assertFalse((bool) data_get($homeIqCard, 'media.fallback_allowed', true));
        $this->assertSame('iq-owner-original-30-og', (string) data_get($homeIqCard, 'media.variants.og_asset_key'));
        $this->assertTrue((bool) data_get($homeIqCard, 'claim_policy.norm_authority_required'));
        $this->assertFalse((bool) data_get($homeIqCard, 'claim_policy.iq_estimate_claims_enabled'));
        $this->assertFalse((bool) data_get($homeIqCard, 'claim_policy.percentile_claims_enabled'));
        $this->assertStringNotContainsString('official IQ', json_encode($homeIqCard, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('percentile ranking', json_encode($homeIqCard, JSON_THROW_ON_ERROR));

        $tests = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('surface_key', 'tests')
            ->where('locale', 'zh-CN')
            ->firstOrFail();
        $cognitiveFamily = collect(data_get($tests->payload_json, 'families.items'))
            ->firstWhere('id', 'family-cognitive-ability');
        $iqCard = collect(data_get($cognitiveFamily, 'tests'))
            ->firstWhere('key', 'iq-test-intelligence-quotient-assessment');

        $this->assertIsArray($iqCard);
        $this->assertSame('IQ 推理练习', (string) data_get($iqCard, 'title'));
        $this->assertSame('30 题', (string) data_get($iqCard, 'questionsLabel'));
        $this->assertSame('约 20 分钟', (string) data_get($iqCard, 'durationLabel'));
        $this->assertSame('/zh/tests/iq-test-intelligence-quotient-assessment/take', (string) data_get($iqCard, 'href'));
        $this->assertSame('IQ_OWNER_ORIGINAL_30', (string) data_get($iqCard, 'primaryActions.0.form_code'));
        $this->assertSame('iq-owner-original-30-card', (string) data_get($iqCard, 'media.asset_key'));
        $this->assertSame('media_library_required', (string) data_get($iqCard, 'media.source'));
        $this->assertSame('backend_cms_media_library', (string) data_get($iqCard, 'media.authority'));
        $this->assertFalse((bool) data_get($iqCard, 'media.fallback_allowed', true));
        $this->assertTrue((bool) data_get($iqCard, 'claim_policy.norm_authority_required'));
        $this->assertFalse((bool) data_get($iqCard, 'claim_policy.iq_estimate_claims_enabled'));
        $this->assertFalse((bool) data_get($iqCard, 'claim_policy.percentile_claims_enabled'));
        $this->assertStringContainsString('不输出正式 IQ 或百分位', (string) data_get($iqCard, 'outputLabel'));
        $this->assertStringNotContainsString('官方智商', json_encode($iqCard, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('人群百分位', json_encode($iqCard, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $mediaRows = collect(json_decode(
            (string) file_get_contents(base_path('../content_baselines/media_assets/default_media_assets.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        ))->keyBy(fn (array $row): string => (string) ($row['asset_key'] ?? ''));

        foreach (['iq-owner-original-30-card', 'iq-owner-original-30-og', 'iq-full-report-cover'] as $assetKey) {
            $asset = $mediaRows->get($assetKey);
            $this->assertIsArray($asset, $assetKey.' media asset baseline is missing');
            $this->assertSame('media_library', (string) data_get($asset, 'disk'));
            $this->assertSame('backend_cms_media_library', (string) data_get($asset, 'payload_json.authority'));
            $this->assertFalse((bool) data_get($asset, 'payload_json.frontend_fallback_allowed', true));
            $this->assertSame('IQ_OWNER_ORIGINAL_30', (string) data_get($asset, 'payload_json.applies_to.scale_code.0'));
            $this->assertStringNotContainsString('/public/', json_encode($asset, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('fap-web', json_encode($asset, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $this->assertContains('seo_og', data_get($mediaRows->get('iq-owner-original-30-og'), 'payload_json.render_surface'));
        $this->assertContains('paid_report', data_get($mediaRows->get('iq-full-report-cover'), 'payload_json.render_surface'));
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
            ->assertJsonPath('surface.payload_json.quickStart.items.0.href', '/tests/mbti-personality-test-16-personality-types')
            ->assertJsonPath('surface.payload_json.quickStart.items.1.href', '/tests/big-five-personality-test-ocean-model')
            ->assertJsonPath('surface.payload_json.quickStart.items.2.title', '九型人格测试')
            ->assertJsonPath('surface.payload_json.quickStart.items.2.href', '/tests/enneagram-personality-test-nine-types')
            ->assertJsonPath('surface.payload_json.quickStart.items.3.title', '霍兰德职业兴趣测试')
            ->assertJsonPath('surface.payload_json.quickStart.items.3.href', '/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('surface.payload_json.quickStart.items.4.href', '/tests/iq-test-intelligence-quotient-assessment')
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
            ->assertJsonPath(
                'surface.payload_json.families.items.0.tests.2.href',
                '/zh/tests/enneagram-personality-test-nine-types'
            )
            ->assertJsonPath(
                'surface.payload_json.families.items.0.tests.2.questionsLabel',
                '105 / 144 题'
            )
            ->assertJsonPath(
                'surface.payload_json.families.items.0.tests.2.durationLabel',
                '约 15 / 40 分钟'
            )
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

        $this->getJson('/api/v0.5/landing-surfaces/tests_category_personality?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('surface.surface_key', 'tests_category_personality')
            ->assertJsonPath('surface.locale', 'zh-CN')
            ->assertJsonPath('surface.payload_json.featured.items.2.key', 'enneagram-personality-test-nine-types')
            ->assertJsonPath('surface.payload_json.featured.items.2.href', '/zh/tests/enneagram-personality-test-nine-types')
            ->assertJsonPath('surface.payload_json.featured.items.2.questionsLabel', '105 / 144 题')
            ->assertJsonPath('surface.payload_json.featured.items.2.durationLabel', '约 15 / 40 分钟')
            ->assertJsonPath(
                'surface.payload_json.featured.items.2.primaryActions.0.href',
                '/zh/tests/enneagram-personality-test-nine-types/take?form=enneagram_likert_105'
            )
            ->assertJsonPath(
                'surface.payload_json.featured.items.2.primaryActions.1.href',
                '/zh/tests/enneagram-personality-test-nine-types/take?form=enneagram_forced_choice_144'
            )
            ->assertJsonPath('surface.payload_json.allTests.items.2.key', 'enneagram-personality-test-nine-types')
            ->assertJsonPath('surface.payload_json.allTests.items.2.href', '/zh/tests/enneagram-personality-test-nine-types');

        $admin = $this->createCmsAdminWithPermissions([PermissionNames::ADMIN_CONTENT_WRITE]);

        $this->withSession(['ops_org_id' => 0])
            ->actingAs($admin, (string) config('admin.guard', 'admin'))
            ->putJson('/api/v0.5/internal/landing-surfaces/home', [
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

    public function test_public_api_enriches_recommended_articles_with_published_revision_pointer(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $surface = LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => 'home',
            'locale' => 'zh-CN',
            'title' => '首页',
            'description' => '首页',
            'schema_version' => 'v1',
            'payload_json' => [],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 4, 25, 0, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ]);

        $surface->blocks()->create([
            'block_key' => 'recommended_articles',
            'block_type' => 'json',
            'title' => '推荐阅读',
            'payload_json' => [
                'items' => [
                    [
                        'display_order' => 1,
                        'article' => [
                            'slug' => 'recommended-article',
                            'locale' => 'zh-CN',
                            'title' => '推荐文章',
                            'status' => 'published',
                            'is_public' => true,
                        ],
                    ],
                ],
            ],
            'sort_order' => 0,
            'is_enabled' => true,
        ]);

        $article = $this->createArticle([
            'slug' => 'recommended-article',
            'locale' => 'zh-CN',
            'title' => '推荐文章 legacy',
        ], [
            'title' => '推荐文章 published',
        ]);

        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');

        $response->assertOk()
            ->assertJsonPath(
                'surface.page_blocks.0.payload_json.items.0.article.published_revision_id',
                (int) $article->published_revision_id
            )
            ->assertJsonPath(
                'surface.page_blocks.0.payload_json.items.0.article.published_revision.id',
                (int) $article->published_revision_id
            )
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.title', '推荐文章 published')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.cover_image_url', 'https://api.fermatmind.com/static/articles/covers/recommended-article.svg')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.cover_image_alt', '推荐文章封面')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.category.name', '推荐分类')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.tags.0.name', '推荐标签')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.status', 'published')
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.is_indexable', true)
            ->assertJsonPath('surface.page_blocks.0.payload_json.items.0.article.canonical_url', 'https://fermatmind.com/zh/articles/recommended-article');
    }

    public function test_home_recommended_articles_auto_sync_latest_articles_and_preserve_explicit_pins(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $surface = LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => 'home',
            'locale' => 'zh-CN',
            'title' => '首页',
            'description' => '首页',
            'schema_version' => 'v1',
            'payload_json' => [],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 4, 25, 0, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ]);

        $surface->blocks()->create([
            'block_key' => 'recommended_articles',
            'block_type' => 'json',
            'title' => '推荐阅读',
            'payload_json' => [
                'items' => [
                    [
                        'is_pinned' => true,
                        'article' => ['slug' => 'editor-pinned-article'],
                    ],
                    ['article' => ['slug' => 'older-configured-article']],
                    ['article' => ['slug' => 'missing-configured-article']],
                ],
            ],
            'sort_order' => 0,
            'is_enabled' => true,
        ]);

        $this->createArticle([
            'slug' => 'editor-pinned-article',
            'locale' => 'zh-CN',
            'title' => '编辑置顶文章',
            'published_at' => Carbon::create(2026, 4, 1, 8, 0, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'older-configured-article',
            'locale' => 'zh-CN',
            'title' => '旧配置文章',
            'published_at' => Carbon::create(2026, 4, 2, 8, 0, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'newest-uploaded-article',
            'locale' => 'zh-CN',
            'title' => '最新上传文章',
            'published_at' => Carbon::create(2026, 5, 14, 8, 0, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'second-newest-uploaded-article',
            'locale' => 'zh-CN',
            'title' => '第二新文章',
            'published_at' => Carbon::create(2026, 5, 13, 8, 0, 0, 'UTC'),
        ]);

        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');

        $response->assertOk();
        $items = $response->json('surface.page_blocks.0.payload_json.items');
        $this->assertIsArray($items);
        $this->assertSame(
            [
                'editor-pinned-article',
                'newest-uploaded-article',
                'second-newest-uploaded-article',
            ],
            array_map(static fn (array $item): string => (string) data_get($item, 'article.slug'), $items)
        );
        $this->assertTrue((bool) data_get($items[0], 'is_pinned'));
        $this->assertSame('最新上传文章', (string) data_get($items[1], 'article.title'));
        $this->assertSame('第二新文章', (string) data_get($items[2], 'article.title'));
    }

    public function test_public_api_skips_malformed_recommended_article_slugs_without_crashing(): void
    {
        $surface = LandingSurface::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'surface_key' => 'home',
            'locale' => 'zh-CN',
            'title' => '首页',
            'description' => '首页',
            'schema_version' => 'v1',
            'payload_json' => [],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 4, 25, 0, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ]);

        $surface->blocks()->create([
            'block_key' => 'recommended_articles',
            'block_type' => 'json',
            'title' => '推荐阅读',
            'payload_json' => [
                'items' => [
                    ['article' => ['slug' => ['not' => 'scalar']]],
                    ['slug' => '../bad-slug'],
                    ['article' => ['slug' => 'recommended-article']],
                ],
            ],
            'sort_order' => 0,
            'is_enabled' => true,
        ]);

        $article = $this->createArticle([
            'slug' => 'recommended-article',
            'locale' => 'zh-CN',
            'title' => '推荐文章 legacy',
        ], [
            'title' => '推荐文章 published',
        ]);

        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath(
                'surface.page_blocks.0.payload_json.items.0.article.published_revision_id',
                (int) $article->published_revision_id
            );

        $this->assertCount(1, $response->json('surface.page_blocks.0.payload_json.items'));
    }

    public function test_home_recommended_articles_are_enriched_from_article_authority_baseline(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
            '--locale' => 'zh-CN',
            '--article' => [
                'how-personality-shapes-attitude-toward-ai',
                'which-love-script-fits-you-best',
                'are-infj-men-rare-or-socially-silenced',
                'best-valentines-date-by-personality-and-relationship-science',
                'how-16-personality-types-talk-to-an-ai-coach',
                'childhood-dream-job-still-shapes-career-choice',
            ],
        ])->assertExitCode(0);

        $this->artisan('landing-surfaces:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/landing_surfaces',
        ])->assertExitCode(0);

        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');
        $response->assertOk()
            ->assertJsonPath('ok', true);

        $recommendedBlock = collect($response->json('surface.page_blocks') ?? [])
            ->firstWhere('block_key', 'recommended_articles');
        $this->assertIsArray($recommendedBlock);

        $items = data_get($recommendedBlock, 'payload_json.items');
        $this->assertIsArray($items);
        $this->assertCount(6, $items);

        $slugs = array_map(static fn (array $item): string => (string) data_get($item, 'article.slug'), $items);
        $this->assertSame(
            [
                'which-love-script-fits-you-best',
                'how-personality-shapes-attitude-toward-ai',
                'how-16-personality-types-talk-to-an-ai-coach',
                'childhood-dream-job-still-shapes-career-choice',
                'best-valentines-date-by-personality-and-relationship-science',
                'are-infj-men-rare-or-socially-silenced',
            ],
            $slugs
        );

        foreach ($items as $item) {
            $article = data_get($item, 'article');
            $this->assertIsArray($article);
            $this->assertSame('published', (string) data_get($article, 'status'));
            $this->assertTrue((bool) data_get($article, 'is_public'));
            $this->assertTrue((bool) data_get($article, 'is_indexable'));
            $this->assertNotEmpty(data_get($article, 'published_revision_id'));
            $this->assertNotEmpty(data_get($article, 'title'));
            $this->assertNotEmpty(data_get($article, 'excerpt'));
            $this->assertNotEmpty(data_get($article, 'cover_image_url'));
            $this->assertNotEmpty(data_get($article, 'cover_image_alt'));
            $this->assertNotEmpty(data_get($article, 'cover_image_width'));
            $this->assertNotEmpty(data_get($article, 'cover_image_height'));
            $this->assertNotEmpty(data_get($article, 'cover_image_variants.hero'));
            $this->assertNotEmpty(data_get($article, 'category.name'));
            $this->assertNotEmpty(data_get($article, 'tags.0.name'));
            $this->assertStringStartsWith('https://fermatmind.com/zh/articles/', (string) data_get($article, 'canonical_url'));
        }

        $noindex = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'which-love-script-fits-you-best')
            ->firstOrFail();
        $noindex->forceFill(['is_indexable' => false])->save();

        $afterNoindex = $this->getJson('/api/v0.5/landing-surfaces/home?locale=zh-CN&org_id=0');
        $afterNoindex->assertOk();
        $afterNoindexRecommendedBlock = collect($afterNoindex->json('surface.page_blocks') ?? [])
            ->firstWhere('block_key', 'recommended_articles');
        $this->assertIsArray($afterNoindexRecommendedBlock);
        $filteredSlugs = array_map(
            static fn (array $item): string => (string) data_get($item, 'article.slug'),
            data_get($afterNoindexRecommendedBlock, 'payload_json.items') ?? []
        );
        $this->assertNotContains('which-love-script-fits-you-best', $filteredSlugs);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $revisionOverrides
     */
    private function createArticle(
        array $overrides = [],
        array $revisionOverrides = [],
        bool $withPublishedRevision = true
    ): Article {
        /** @var Article $article */
        $category = ArticleCategory::query()->firstOrCreate([
            'org_id' => 0,
            'slug' => 'recommended-category',
        ], [
            'name' => '推荐分类',
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $article = Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_admin_user_id' => null,
            'author_name' => 'Fermat Institute',
            'slug' => 'article-slug',
            'locale' => 'en',
            'title' => 'Article Title',
            'excerpt' => 'Article excerpt.',
            'content_md' => '# Article body',
            'content_html' => null,
            'cover_image_url' => 'https://api.fermatmind.com/static/articles/covers/recommended-article.svg',
            'cover_image_alt' => '推荐文章封面',
            'cover_image_width' => 1200,
            'cover_image_height' => 675,
            'cover_image_variants' => [
                'hero' => ['url' => 'https://api.fermatmind.com/static/articles/covers/recommended-article.svg', 'width' => 1200, 'height' => 675],
                'card' => ['url' => 'https://api.fermatmind.com/static/articles/covers/recommended-article.svg', 'width' => 1200, 'height' => 675],
                'og' => ['url' => 'https://api.fermatmind.com/static/articles/covers/recommended-article.svg', 'width' => 1200, 'height' => 675],
            ],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 9, 0, 0, 'UTC'),
        ], $overrides));

        if ($withPublishedRevision) {
            $revision = $this->createRevision($article, $revisionOverrides);
            $article->forceFill(['published_revision_id' => $revision->id])->save();
        }

        $tag = ArticleTag::query()->firstOrCreate([
            'org_id' => 0,
            'slug' => 'recommended-tag',
        ], [
            'name' => '推荐标签',
            'is_active' => true,
        ]);
        $article->tags()->syncWithoutDetaching([
            (int) $tag->id => ['org_id' => 0],
        ]);

        ArticleSeoMeta::query()->updateOrCreate([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
        ], [
            'seo_title' => (string) ($revisionOverrides['seo_title'] ?? $article->title),
            'seo_description' => (string) ($revisionOverrides['seo_description'] ?? $article->excerpt),
            'canonical_url' => 'https://fermatmind.com/'.((string) $article->locale === 'zh-CN' ? 'zh' : (string) $article->locale).'/articles/'.(string) $article->slug,
            'og_title' => (string) ($revisionOverrides['seo_title'] ?? $article->title),
            'og_description' => (string) ($revisionOverrides['seo_description'] ?? $article->excerpt),
            'og_image_url' => (string) $article->cover_image_url,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        return $article->fresh(['category', 'tags', 'seoMeta', 'publishedRevision']) ?? $article;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRevision(Article $article, array $overrides = []): ArticleTranslationRevision
    {
        /** @var ArticleTranslationRevision $revision */
        $revision = ArticleTranslationRevision::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->translated_from_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash ?: $article->source_version_hash,
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => null,
            'seo_description' => null,
            'published_at' => $article->published_at,
        ], $overrides));

        return $revision;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createCmsAdminWithPermissions(array $permissions): AdminUser
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

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => null]
            );

            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }
}
