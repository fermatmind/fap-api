<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticleBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_database(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--dry-run' => true,
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('articles_found=45')
            ->expectsOutputToContain('will_create=45')
            ->assertExitCode(0);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_import_creates_and_updates_published_public_articles_for_mbti_minimum_set(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('articles_found=45')
            ->expectsOutputToContain('will_create=45')
            ->assertExitCode(0);

        $this->assertSame(45, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            45,
            Article::query()
                ->withoutGlobalScopes()
                ->where('status', 'published')
                ->where('is_public', true)
                ->count()
        );

        $enBasics = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('slug', 'mbti-basics')
            ->firstOrFail();
        $this->assertSame('MBTI Personality Test (16 Types) | Tool Guide', (string) $enBasics->title);
        $this->assertSame('FermatMind Editorial', (string) $enBasics->author_name);
        $this->assertSame(2, (int) $enBasics->reading_minutes);
        $this->assertSame('mbti-personality-test-16-personality-types', (string) $enBasics->related_test_slug);
        $this->assertSame('tool', (string) $enBasics->voice);
        $this->assertSame(1, (int) $enBasics->voice_order);

        $zhGrowth = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'mbti-growth-guide')
            ->firstOrFail();
        $this->assertSame('MBTI 性格测试（16型人格）｜成长引导版', (string) $zhGrowth->title);
        $this->assertSame('mbti-personality-test-16-personality-types', (string) $zhGrowth->related_test_slug);
        $this->assertSame('growth', (string) $zhGrowth->voice);
        $this->assertSame(2, (int) $zhGrowth->voice_order);

        $editorial = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'how-personality-shapes-attitude-toward-ai')
            ->firstOrFail();
        $this->assertSame('https://api.fermatmind.com/static/articles/covers/how-personality-shapes-attitude-toward-ai.svg', (string) $editorial->cover_image_url);
        $this->assertSame('人工智能与人格', (string) $editorial->category?->name);
        $this->assertContains('算法信任', $editorial->tags->pluck('name')->all());
        $this->assertSame(1200, (int) $editorial->cover_image_width);
        $this->assertSame(675, (int) $editorial->cover_image_height);
        $this->assertSame(
            'https://api.fermatmind.com/static/articles/covers/how-personality-shapes-attitude-toward-ai.svg',
            $editorial->cover_image_variants['hero'] ?? null
        );
        $this->assertSame('性格如何影响你对 AI 的态度？人格、焦虑与算法信任', $editorial->publishedRevision?->seo_title);
        $this->assertSame(
            '为什么有人信任 AI，有人却焦虑？本文用人格心理学解释 AI 态度、算法信任、控制感与职业判断边界。',
            $editorial->publishedRevision?->seo_description
        );
        $this->assertSame(
            '性格如何影响你对 AI 的态度？人格、焦虑与算法信任',
            $editorial->seoMeta?->seo_title
        );
        $this->assertSame(
            '冷静的学术编辑部封面，抽象人脸轮廓与蓝绿色 AI 节点网络交织，几何线条表现控制感、信任与认知边界，现代咨询报告风格，无真实人物照片，无标题文字，高级深蓝与青绿色调，minimal academic editorial design',
            data_get($editorial->cover_image_variants, 'editorial_metadata.cover_image_prompt')
        );
        $this->assertSame(
            'academic-editorial, ai-personality, muted-geometric',
            data_get($editorial->cover_image_variants, 'editorial_metadata.cover_image_style_tag')
        );
        $this->assertContains('/zh/tests/mbti-personality-test-16-personality-types', data_get($editorial->cover_image_variants, 'editorial_metadata.internal_links', []));
        $this->assertImportedArticleBodiesContainNoH1();

        $this->assertSixEditorialArticlesConvergeThroughSeoAuthority();

        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('articles_found=45')
            ->expectsOutputToContain('will_skip=45')
            ->assertExitCode(0);

        $this->assertSame(45, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_upsert_republishes_existing_published_article_revision_from_baseline(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $slug = 'which-love-script-fits-you-best';
        $baseline = $this->baselineArticle($slug);
        $oldPublishedAt = Carbon::create(2026, 4, 18, 0, 0, 0, 'UTC');

        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'category_id' => null,
            'author_name' => 'Fermat Institute',
            'reviewer_name' => null,
            'reading_minutes' => 10,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => (string) $baseline['title'],
            'excerpt' => '很多人以为自己在找“合适的人”，其实更常见的情况是：你还没搞清楚，自己到底在寻求哪一种关系结构。',
            'content_md' => "# 旧稿标题\n\n## 读完这篇文章，你会带走什么\n\n旧 published revision 正文。",
            'content_html' => null,
            'cover_image_url' => null,
            'cover_image_alt' => null,
            'cover_image_width' => null,
            'cover_image_height' => null,
            'cover_image_variants' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => $oldPublishedAt,
        ]);

        /** @var ArticleTranslationRevision $oldRevision */
        $oldRevision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => '旧 SEO 标题',
            'seo_description' => '旧 SEO 描述。',
            'published_at' => $oldPublishedAt,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $oldRevision->id,
            'published_revision_id' => (int) $oldRevision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => '旧 SEO 标题',
            'seo_description' => '旧 SEO 描述。',
            'canonical_url' => 'https://fermatmind.com/zh/articles/'.$slug,
            'og_title' => '旧 SEO 标题',
            'og_description' => '旧 SEO 描述。',
            'og_image_url' => null,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        $this->artisan('articles:import-local-baseline', [
            '--dry-run' => true,
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
            '--locale' => 'zh-CN',
            '--article' => [$slug],
        ])
            ->expectsOutputToContain('articles_found=1')
            ->expectsOutputToContain('will_update=1')
            ->expectsOutputToContain('article_action=zh-CN:'.$slug.':update')
            ->assertExitCode(0);

        $this->assertSame(
            (int) $oldRevision->id,
            (int) Article::query()->withoutGlobalScopes()->whereKey($article->id)->value('published_revision_id')
        );

        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
            '--locale' => 'zh-CN',
            '--article' => [$slug],
        ])
            ->expectsOutputToContain('articles_found=1')
            ->expectsOutputToContain('will_update=1')
            ->assertExitCode(0);

        $updated = Article::query()
            ->withoutGlobalScopes()
            ->with(['publishedRevision', 'seoMeta', 'category', 'tags'])
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', $slug)
            ->firstOrFail();

        $this->assertNotSame((int) $oldRevision->id, (int) $updated->published_revision_id);
        $this->assertSame((string) $baseline['content_md'], (string) $updated->publishedRevision?->content_md);
        $this->assertSame((string) $baseline['excerpt'], (string) $updated->publishedRevision?->excerpt);
        $this->assertSame((string) $baseline['seo_title'], (string) $updated->publishedRevision?->seo_title);
        $this->assertSame((string) $baseline['seo_description'], (string) $updated->publishedRevision?->seo_description);
        $this->assertSame((string) $baseline['cover_image_url'], (string) $updated->cover_image_url);
        $this->assertSame((string) $baseline['cover_image_alt'], (string) $updated->cover_image_alt);
        $this->assertSame((string) $baseline['category'], (string) $updated->category?->name);
        $expectedTags = $baseline['tags'];
        $actualTags = $updated->tags->pluck('name')->values()->all();
        sort($expectedTags);
        sort($actualTags);
        $this->assertSame($expectedTags, $actualTags);
        $this->assertSame((string) $baseline['seo_title'], (string) $updated->seoMeta?->seo_title);
        $this->assertSame((string) $baseline['cover_image_url'], (string) $updated->seoMeta?->og_image_url);

        $this->getJson('/api/v0.5/articles/'.$slug.'?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('article.content_md', (string) $baseline['content_md'])
            ->assertJsonPath('article.excerpt', (string) $baseline['excerpt'])
            ->assertJsonPath('article.cover_image_url', (string) $baseline['cover_image_url'])
            ->assertJsonPath('article.cover_image_alt', (string) $baseline['cover_image_alt'])
            ->assertJsonPath('article.category.name', (string) $baseline['category'])
            ->assertJsonPath('article.seo_meta.seo_title', (string) $baseline['seo_title']);

        $this->getJson('/api/v0.5/articles/'.$slug.'/seo?locale=zh-CN&org_id=0')
            ->assertOk()
            ->assertJsonPath('meta.title', (string) $baseline['seo_title'])
            ->assertJsonPath('meta.description', (string) $baseline['seo_description'])
            ->assertJsonPath('jsonld.image', (string) $baseline['cover_image_url'])
            ->assertJsonPath('jsonld.url', 'https://fermatmind.com/zh/articles/'.$slug);
    }

    public function test_import_rejects_overlong_translation_group_id_before_database_write(): void
    {
        $sourceDir = $this->writeBaselineSourceDir([
            [
                'slug' => 'overlong-translation-group',
                'title' => 'Overlong translation group',
                'excerpt' => 'Importer should reject overlong translation authority identifiers.',
                'content_md' => "# Overlong translation group\n\nBody.",
                'translation_group_id' => str_repeat('g', 65),
            ],
        ]);

        $this->artisan('articles:import-local-baseline', [
            '--dry-run' => true,
            '--source-dir' => $sourceDir,
            '--locale' => 'zh-CN',
        ])
            ->expectsOutputToContain('overlong translation_group_id')
            ->assertExitCode(1);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_import_keeps_generated_translation_group_id_within_database_limit(): void
    {
        $longSlug = 'career-'.str_repeat('translation-', 8).'authority';
        $sourceDir = $this->writeBaselineSourceDir([
            [
                'slug' => $longSlug,
                'title' => 'Generated translation group',
                'excerpt' => 'Importer should keep generated translation authority identifiers bounded.',
                'content_md' => "# Generated translation group\n\nBody.",
            ],
        ]);

        $this->artisan('articles:import-local-baseline', [
            '--source-dir' => $sourceDir,
            '--locale' => 'zh-CN',
            '--status' => 'draft',
        ])
            ->expectsOutputToContain('articles_found=1')
            ->assertExitCode(0);

        $article = Article::query()
            ->withoutGlobalScopes()
            ->where('slug', $longSlug)
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertLessThanOrEqual(64, mb_strlen((string) $article->translation_group_id));
        $this->assertStringStartsWith('article:', (string) $article->translation_group_id);
    }

    private function assertImportedArticleBodiesContainNoH1(): void
    {
        $guard = app(ArticleBodyHeadingGuard::class);
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->with('publishedRevision')
            ->get();

        foreach ($articles as $article) {
            $this->assertFalse(
                $guard->containsMarkdownH1((string) $article->content_md),
                sprintf('Article body contains H1 after baseline import: %s/%s', (string) $article->locale, (string) $article->slug)
            );

            if ($article->publishedRevision instanceof ArticleTranslationRevision) {
                $this->assertFalse(
                    $guard->containsMarkdownH1((string) $article->publishedRevision->content_md),
                    sprintf('Published revision body contains H1 after baseline import: %s/%s', (string) $article->locale, (string) $article->slug)
                );
            }
        }
    }

    private function assertSixEditorialArticlesConvergeThroughSeoAuthority(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        /** @var ArticleSeoService $seoService */
        $seoService = app(ArticleSeoService::class);
        $expected = [
            'how-personality-shapes-attitude-toward-ai' => [
                'title' => '你的性格如何塑造你对人工智能的态度：从好奇、焦虑到算法信任',
                'seo_title' => '性格如何影响你对 AI 的态度？人格、焦虑与算法信任',
                'seo_description' => '为什么有人信任 AI，有人却焦虑？本文用人格心理学解释 AI 态度、算法信任、控制感与职业判断边界。',
                'alt' => '抽象人脸轮廓与 AI 节点网络交织，表现人格差异如何影响算法信任',
            ],
            'which-love-script-fits-you-best' => [
                'title' => '你真正适合哪种亲密关系脚本？用七种爱情类型做一次更科学的匹配',
                'seo_title' => '哪种爱情类型适合你？七种关系脚本与人格匹配',
                'seo_description' => '什么样的关系更适合你？用爱情风格、亲密关系研究和人格差异，理解激情、稳定、承诺与长期相处。',
                'alt' => '抽象关系网络中呈现七种爱情脚本的几何路径',
            ],
            'are-infj-men-rare-or-socially-silenced' => [
                'title' => '“INFJ 男性很少见”还是“高敏感男性更容易学会沉默”？',
                'seo_title' => 'INFJ 男性真的少见吗？高敏感男性与自我沉默',
                'seo_description' => 'INFJ 男性为何常被认为少见？本文用男性规范、自我沉默与情绪抑制研究解释高敏感男性的可见性困境。',
                'alt' => '半透明男性轮廓与被压低的声波线条，象征高敏感男性的自我沉默',
            ],
            'best-valentines-date-by-personality-and-relationship-science' => [
                'title' => '情人节别再只追求“浪漫”：按人格与关系科学设计真正低摩擦的约会',
                'seo_title' => '情人节约会怎么安排？按人格和关系科学设计低摩擦约会',
                'seo_description' => '情人节约会不是越浪漫越好。用人格差异、亲密关系研究和低摩擦原则，设计更适合双方的约会体验。',
                'alt' => '抽象路径地图连接两个关系节点，表现低摩擦约会设计',
            ],
            'how-16-personality-types-talk-to-an-ai-coach' => [
                'title' => '当 16 型人格开始和 AI 教练对话：谁把它当镜子，谁把它当工具，谁最容易被顺着说',
                'seo_title' => '16 型人格如何使用 AI 教练？镜子、工具与判断风险',
                'seo_description' => '不同人格如何与 AI 教练对话？有人把它当工具，有人把它当情绪镜子，也有人最容易被顺着说。',
                'alt' => '抽象对话气泡与镜面结构表现人格与 AI 教练的互动',
            ],
            'childhood-dream-job-still-shapes-career-choice' => [
                'title' => '你小时候想做的工作，为什么还在影响你今天的职业判断？',
                'seo_title' => '小时候想做的工作，如何影响成年职业选择？',
                'seo_description' => '童年 dream job 不是职业预言，而是早期自我概念线索。理解它，能帮助你识别职业兴趣与工作结构偏好。',
                'alt' => '抽象轨迹从童年职业图标延伸到成年职业路径',
            ],
        ];

        foreach ($expected as $slug => $contract) {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['publishedRevision', 'seoMeta', 'category', 'tags'])
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->firstOrFail();

            $cover = 'https://api.fermatmind.com/static/articles/covers/'.$slug.'.svg';
            $canonical = 'https://fermatmind.com/zh/articles/'.$slug;
            $this->assertSame($contract['title'], (string) $article->title);
            $this->assertSame($contract['title'], (string) $article->publishedRevision?->title);
            $this->assertSame($contract['seo_title'], (string) $article->publishedRevision?->seo_title);
            $this->assertSame($contract['seo_description'], (string) $article->publishedRevision?->seo_description);
            $this->assertSame($contract['seo_title'], (string) $article->seoMeta?->seo_title);
            $this->assertSame($contract['seo_description'], (string) $article->seoMeta?->seo_description);
            $this->assertSame($canonical, (string) $article->seoMeta?->canonical_url);
            $this->assertSame('index,follow', (string) $article->seoMeta?->robots);
            $this->assertSame($cover, (string) $article->cover_image_url);
            $this->assertSame($contract['alt'], (string) $article->cover_image_alt);
            $this->assertSame($cover, $article->cover_image_variants['og'] ?? null);
            $this->assertNotEmpty(data_get($article->cover_image_variants, 'editorial_metadata.cover_image_prompt'));
            $this->assertNotEmpty(data_get($article->cover_image_variants, 'editorial_metadata.cover_image_style_tag'));
            $this->assertNotEmpty(data_get($article->cover_image_variants, 'editorial_metadata.references'));
            $this->assertStringContainsString('References', (string) $article->publishedRevision?->content_md);
            $this->assertTrue((bool) $article->is_public);
            $this->assertTrue((bool) $article->is_indexable);
            $this->assertSame('published', (string) $article->status);

            $seoPayload = $seoService->buildSeoPayload($article, $article->publishedRevision);
            $jsonLd = $seoService->generateJsonLd($article, $article->publishedRevision);
            $this->assertSame($contract['seo_title'], $seoPayload['title']);
            $this->assertSame($contract['seo_description'], $seoPayload['description']);
            $this->assertSame($canonical, $seoPayload['canonical']);
            $this->assertSame($cover, data_get($seoPayload, 'og.image'));
            $this->assertSame($canonical.'#article', data_get($jsonLd, '@id'));
            $this->assertSame($contract['seo_title'], data_get($jsonLd, 'headline'));
            $this->assertSame($contract['seo_description'], data_get($jsonLd, 'description'));
            $this->assertSame($cover, data_get($jsonLd, 'image'));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function baselineArticle(string $slug): array
    {
        $path = base_path('../content_baselines/articles/articles.zh-CN.json');
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        foreach ((array) ($payload['articles'] ?? []) as $article) {
            if (is_array($article) && (string) ($article['slug'] ?? '') === $slug) {
                return $article;
            }
        }

        $this->fail('Baseline article not found: '.$slug);
    }

    /**
     * @param  list<array<string,mixed>>  $articles
     */
    private function writeBaselineSourceDir(array $articles): string
    {
        $dir = sys_get_temp_dir().'/article-baseline-import-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        file_put_contents($dir.'/articles.zh-CN.json', json_encode([
            'meta' => ['locale' => 'zh-CN'],
            'articles' => $articles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $dir;
    }
}
