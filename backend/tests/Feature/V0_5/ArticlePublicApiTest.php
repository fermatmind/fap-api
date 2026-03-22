<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ArticlePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_locale_query_filters_items_and_pagination(): void
    {
        foreach (range(1, 21) as $index) {
            $this->createArticle([
                'slug' => sprintf('english-article-%02d', $index),
                'locale' => 'en',
                'title' => sprintf('English Article %02d', $index),
                'published_at' => Carbon::create(2026, 3, 10, 8, $index, 0, 'UTC'),
                'updated_at' => Carbon::create(2026, 3, 10, 9, $index, 0, 'UTC'),
            ]);
        }

        $zhArticle = $this->createArticle([
            'slug' => 'zh-only-article',
            'locale' => 'zh-CN',
            'title' => '中文文章',
            'published_at' => Carbon::create(2026, 3, 11, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 11, 9, 0, 0, 'UTC'),
        ]);

        $enPageOne = $this->getJson('/api/v0.5/articles?locale=en&page=1');

        $enPageOne->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 21)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(20, 'items')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'article_index')
            ->assertJsonPath('landing_surface_v1.entry_type', 'content_hub');

        $this->assertSame(
            ['en'],
            array_values(array_unique(array_column($enPageOne->json('items') ?? [], 'locale')))
        );

        $this->getJson('/api/v0.5/articles?locale=en&page=2')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.total', 21)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.locale', 'en');

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', (string) $zhArticle->slug)
            ->assertJsonPath('items.0.locale', 'zh-CN');
    }

    public function test_article_seo_detail_returns_localized_frontend_canonical_alternates_and_jsonld_urls(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $articleEn = $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
        ]);
        $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础',
            'excerpt' => '了解 MBTI 的核心概念。',
        ]);

        $legacyCanonical = 'https://api.staging.fermatmind.com/articles/mbti-basics';
        $this->createSeoMeta($articleEn, [
            'seo_title' => 'MBTI Basics | FermatMind',
            'seo_description' => 'Learn the core concepts behind MBTI.',
            'canonical_url' => $legacyCanonical,
            'schema_json' => [
                '@id' => $legacyCanonical.'#article',
                'url' => $legacyCanonical,
                'mainEntityOfPage' => $legacyCanonical.'#webpage',
            ],
        ]);

        $canonical = 'https://staging.fermatmind.com/en/articles/mbti-basics';
        $zhCanonical = 'https://staging.fermatmind.com/zh/articles/mbti-basics';

        $response = $this->getJson('/api/v0.5/articles/mbti-basics/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.canonical', $canonical)
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'article_public_detail')
            ->assertJsonPath('meta.alternates.en', $canonical)
            ->assertJsonPath('meta.alternates.zh', $zhCanonical)
            ->assertJsonPath('meta.alternates.zh-CN', $zhCanonical)
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical.'#webpage');

        $this->assertSame($canonical.'#article', data_get($response->json(), 'jsonld.@id'));
        $this->assertStringNotContainsString($legacyCanonical, (string) $response->getContent());
    }

    public function test_article_detail_includes_landing_and_answer_surfaces(): void
    {
        $article = $this->createArticle([
            'slug' => 'career-fit-guide',
            'locale' => 'en',
            'title' => 'Career Fit Guide',
            'excerpt' => 'Use article-level insight to continue into tests and public hubs.',
        ]);

        $this->createSeoMeta($article, [
            'seo_title' => 'Career Fit Guide | FermatMind',
            'seo_description' => 'Use article-level insight to continue into tests and public hubs.',
        ]);

        $response = $this->getJson('/api/v0.5/articles/career-fit-guide?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'article_detail')
            ->assertJsonPath('landing_surface_v1.entry_type', 'editorial_article')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.surface_type', 'article_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'article_summary')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/articles');
    }

    public function test_article_seo_does_not_fake_missing_locale_alternates(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $this->createArticle([
            'slug' => 'solo-article',
            'locale' => 'en',
            'title' => 'Solo Article',
            'excerpt' => 'Only one locale exists for this article.',
        ]);

        $canonical = 'https://staging.fermatmind.com/en/articles/solo-article';
        $response = $this->getJson('/api/v0.5/articles/solo-article/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.canonical', $canonical)
            ->assertJsonPath('meta.alternates.en', $canonical)
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical);

        $this->assertNull(data_get($response->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($response->json(), 'meta.alternates.zh-CN'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        /** @var Article */
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'article-slug',
            'locale' => 'en',
            'title' => 'Article Title',
            'excerpt' => 'Article excerpt.',
            'content_md' => '# Article body',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 9, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSeoMeta(Article $article, array $overrides = []): ArticleSeoMeta
    {
        /** @var ArticleSeoMeta */
        return ArticleSeoMeta::query()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) ($article->excerpt ?? ''),
            'canonical_url' => null,
            'og_title' => (string) $article->title,
            'og_description' => (string) ($article->excerpt ?? ''),
            'og_image_url' => null,
            'robots' => 'index,follow',
            'schema_json' => null,
            'is_indexable' => true,
        ], $overrides));
    }
}
