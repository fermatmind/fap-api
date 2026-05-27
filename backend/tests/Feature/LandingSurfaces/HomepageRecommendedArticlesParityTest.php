<?php

declare(strict_types=1);

namespace Tests\Feature\LandingSurfaces;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class HomepageRecommendedArticlesParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    private array $enSlugs = [
        'big-five-personality-test-vs-mbti',
        'mbti-personality-test-science-vs-pseudoscience',
        'holland-career-interest-test-can-and-cannot-tell-you',
        'best-valentines-date-by-personality-and-relationship-science',
        'are-infj-men-rare-or-socially-silenced',
        'which-love-script-fits-you-best',
    ];

    /**
     * @var list<string>
     */
    private array $zhSlugs = [
        'big-five-personality-test-vs-mbti',
        'mbti-personality-test-science-vs-pseudoscience',
        'holland-career-interest-test-can-and-cannot-tell-you',
        'which-love-script-fits-you-best',
        'how-personality-shapes-attitude-toward-ai',
        'how-16-personality-types-talk-to-an-ai-coach',
    ];

    public function test_en_and_zh_homepage_recommended_articles_have_six_render_eligible_items(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        Carbon::setTestNow(Carbon::create(2026, 5, 27, 0, 0, 0, 'UTC'));

        $this->createHomeSurface('en', $this->enSlugs);
        $this->createHomeSurface('zh-CN', $this->zhSlugs);

        foreach (array_slice($this->enSlugs, 0, 3) as $slug) {
            $this->createArticle($slug, 'en', true);
        }

        foreach (array_slice($this->enSlugs, 3) as $slug) {
            $this->createArticle($slug, 'en', false);
        }

        foreach ($this->zhSlugs as $slug) {
            $this->createArticle($slug, 'zh-CN', true);
        }

        $migration = require database_path('migrations/2026_05_27_000100_backfill_homepage_recommended_en_article_media_taxonomy.php');
        $migration->up();

        $this->assertRecommendedArticlesAreRenderEligible('en', $this->enSlugs);
        $this->assertRecommendedArticlesAreRenderEligible('zh-CN', $this->zhSlugs);

        $this->assertBackfilledEnglishArticle(
            'best-valentines-date-by-personality-and-relationship-science',
            'Relationships and Love',
            'Valentine\'s Day',
        );
        $this->assertBackfilledEnglishArticle(
            'are-infj-men-rare-or-socially-silenced',
            'Personality Psychology',
            'Self-Silencing',
        );
        $this->assertBackfilledEnglishArticle(
            'which-love-script-fits-you-best',
            'Relationships and Love',
            'Love Styles',
        );
    }

    /**
     * @param  list<string>  $slugs
     */
    private function createHomeSurface(string $locale, array $slugs): void
    {
        $surface = LandingSurface::query()->create([
            'org_id' => 0,
            'surface_key' => 'home',
            'locale' => $locale,
            'title' => $locale === 'zh-CN' ? '费马测试' : 'FermatMind',
            'description' => null,
            'schema_version' => 'home.v1',
            'payload_json' => [],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
        ]);

        $surface->blocks()->create([
            'block_key' => 'recommended_articles',
            'block_type' => 'articles',
            'title' => $locale === 'zh-CN' ? '推荐阅读' : 'Recommended reading',
            'payload_json' => [
                'items' => array_map(
                    static fn (string $slug): array => ['article' => ['slug' => $slug]],
                    $slugs,
                ),
                'limit' => 6,
            ],
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
    }

    private function createArticle(string $slug, string $locale, bool $renderEligible): Article
    {
        $category = $renderEligible ? $this->category($locale) : null;

        /** @var Article $article */
        $article = Article::query()->create([
            'org_id' => 0,
            'category_id' => $category?->id,
            'author_admin_user_id' => null,
            'author_name' => 'Fermat Institute',
            'slug' => $slug,
            'locale' => $locale,
            'title' => $this->titleFor($slug, $locale),
            'excerpt' => 'A short public excerpt for homepage recommendation rendering.',
            'content_md' => '# '.$this->titleFor($slug, $locale),
            'content_html' => null,
            'cover_image_url' => $renderEligible ? $this->coverUrl($slug) : null,
            'cover_image_alt' => $renderEligible ? $this->coverAlt($slug, $locale) : null,
            'cover_image_width' => $renderEligible ? 1200 : null,
            'cover_image_height' => $renderEligible ? 675 : null,
            'cover_image_variants' => $renderEligible ? $this->coverVariants($slug) : null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 5, 15, 0, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'translation_group_id' => 'article:'.$slug,
            'source_locale' => $locale === 'zh-CN' ? 'zh-CN' : 'zh-CN',
            'translation_status' => $locale === 'zh-CN'
                ? Article::TRANSLATION_STATUS_SOURCE
                : Article::TRANSLATION_STATUS_PUBLISHED,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => 'article:'.$slug,
            'locale' => $locale,
            'source_locale' => $locale === 'zh-CN' ? 'zh-CN' : 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => $this->titleFor($slug, $locale),
            'excerpt' => 'A short public excerpt for homepage recommendation rendering.',
            'content_md' => '# '.$this->titleFor($slug, $locale),
            'seo_title' => $this->titleFor($slug, $locale),
            'seo_description' => 'A short public excerpt for homepage recommendation rendering.',
            'published_at' => Carbon::create(2026, 5, 15, 0, 0, 0, 'UTC'),
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => $this->titleFor($slug, $locale),
            'seo_description' => 'A short public excerpt for homepage recommendation rendering.',
            'canonical_url' => 'https://fermatmind.com/'.($locale === 'zh-CN' ? 'zh' : 'en').'/articles/'.$slug,
            'og_title' => $this->titleFor($slug, $locale),
            'og_description' => 'A short public excerpt for homepage recommendation rendering.',
            'og_image_url' => $renderEligible ? $this->coverUrl($slug) : null,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        if ($renderEligible) {
            $article->tags()->sync([$this->tag($locale)->id => ['org_id' => 0]]);
        }

        return $article->fresh(['category', 'tags', 'seoMeta', 'publishedRevision']) ?? $article;
    }

    /**
     * @param  list<string>  $expectedSlugs
     */
    private function assertRecommendedArticlesAreRenderEligible(string $locale, array $expectedSlugs): void
    {
        $response = $this->getJson('/api/v0.5/landing-surfaces/home?locale='.$locale.'&org_id=0');
        $response->assertOk()->assertJsonPath('ok', true);

        $block = collect($response->json('surface.page_blocks') ?? [])->firstWhere('block_key', 'recommended_articles');
        $this->assertIsArray($block);

        $items = data_get($block, 'payload_json.items');
        $this->assertIsArray($items);
        $this->assertCount(6, $items);
        $this->assertEqualsCanonicalizing(
            $expectedSlugs,
            array_map(static fn (array $item): string => (string) data_get($item, 'article.slug'), $items),
        );

        foreach ($items as $item) {
            $article = data_get($item, 'article');
            $this->assertIsArray($article);
            $this->assertSame('published', data_get($article, 'status'));
            $this->assertTrue((bool) data_get($article, 'is_public'));
            $this->assertTrue((bool) data_get($article, 'is_indexable'));
            $this->assertNotEmpty(data_get($article, 'published_revision_id'));
            $this->assertNotEmpty(data_get($article, 'excerpt'));
            $this->assertNotEmpty(data_get($article, 'cover_image_url'));
            $this->assertNotEmpty(data_get($article, 'cover_image_alt'));
            $this->assertNotEmpty(data_get($article, 'cover_image_width'));
            $this->assertNotEmpty(data_get($article, 'cover_image_height'));
            $this->assertNotEmpty(data_get($article, 'cover_image_variants.hero'));
            $this->assertNotEmpty(data_get($article, 'category.name'));
            $this->assertNotEmpty(data_get($article, 'tags.0.name'));
        }
    }

    private function assertBackfilledEnglishArticle(string $slug, string $categoryName, string $expectedTagName): void
    {
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'category' => fn ($query) => $query->withoutGlobalScopes(),
                'tags' => fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta',
            ])
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('slug', $slug)
            ->firstOrFail();

        $this->assertSame($this->coverUrl($slug), (string) $article->cover_image_url);
        $this->assertNotEmpty((string) $article->cover_image_alt);
        $this->assertSame(1200, (int) $article->cover_image_width);
        $this->assertSame(675, (int) $article->cover_image_height);
        $this->assertSame($this->coverUrl($slug), data_get($article->cover_image_variants, 'hero'));
        $this->assertSame($categoryName, (string) $article->category?->name);
        $this->assertContains($expectedTagName, $article->tags->pluck('name')->all());
        $this->assertSame($this->coverUrl($slug), (string) $article->seoMeta?->og_image_url);
    }

    private function category(string $locale): ArticleCategory
    {
        return ArticleCategory::query()->firstOrCreate([
            'org_id' => 0,
            'slug' => $locale === 'zh-CN' ? 'zh-category' : 'en-category',
        ], [
            'name' => $locale === 'zh-CN' ? '中文分类' : 'English Category',
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }

    private function tag(string $locale): ArticleTag
    {
        return ArticleTag::query()->firstOrCreate([
            'org_id' => 0,
            'slug' => $locale === 'zh-CN' ? 'zh-tag' : 'en-tag',
        ], [
            'name' => $locale === 'zh-CN' ? '中文标签' : 'English Tag',
            'is_active' => true,
        ]);
    }

    private function coverUrl(string $slug): string
    {
        return 'https://api.fermatmind.com/static/articles/covers/'.$slug.'.svg';
    }

    private function coverAlt(string $slug, string $locale): string
    {
        return ($locale === 'zh-CN' ? '中文封面 ' : 'English cover ').$slug;
    }

    /**
     * @return array<string, string>
     */
    private function coverVariants(string $slug): array
    {
        $url = $this->coverUrl($slug);

        return [
            'hero' => $url,
            'card' => $url,
            'thumbnail' => $url,
            'og' => $url,
            'preload' => $url,
        ];
    }

    private function titleFor(string $slug, string $locale): string
    {
        return ($locale === 'zh-CN' ? '中文文章 ' : 'English Article ').$slug;
    }
}
