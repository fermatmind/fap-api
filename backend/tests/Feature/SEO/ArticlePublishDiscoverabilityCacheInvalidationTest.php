<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Http\Controllers\API\V0_5\SEO\SitemapSourceController;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticlePublishService;
use App\Services\SEO\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ArticlePublishDiscoverabilityCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_article_publish_flushes_sitemap_source_and_backend_xml_sitemap_caches(): void
    {
        $article = $this->createArticleWithRevision(ArticleTranslationRevision::STATUS_APPROVED);
        $this->seedDiscoverabilityCaches();

        app(ArticlePublishService::class)->publishArticle((int) $article->id, 'test_publish');

        $this->assertDiscoverabilityCachesFlushed();
    }

    public function test_article_unpublish_flushes_sitemap_source_and_backend_xml_sitemap_caches(): void
    {
        $article = $this->createArticleWithRevision(ArticleTranslationRevision::STATUS_PUBLISHED, published: true);
        $this->seedDiscoverabilityCaches();

        app(ArticlePublishService::class)->unpublishArticle((int) $article->id);

        $this->assertDiscoverabilityCachesFlushed();
    }

    private function seedDiscoverabilityCaches(): void
    {
        $payload = [
            'ok' => true,
            'source' => 'backend_sitemap_generator',
            'count' => 0,
            'items' => [],
        ];

        Cache::put(SitemapSourceController::CACHE_KEY_FRESH, $payload, SitemapSourceController::FRESH_TTL_SECONDS);
        Cache::put(SitemapSourceController::CACHE_KEY_STALE, $payload, SitemapSourceController::STALE_TTL_SECONDS);
        Cache::put(SitemapCache::XML_CACHE_KEY, '<urlset></urlset>', SitemapCache::TTL_SECONDS);
        Cache::put(SitemapCache::ETAG_CACHE_KEY, '"stale-etag"', SitemapCache::TTL_SECONDS);
    }

    private function assertDiscoverabilityCachesFlushed(): void
    {
        $this->assertNull(Cache::get(SitemapSourceController::CACHE_KEY_FRESH));
        $this->assertNull(Cache::get(SitemapSourceController::CACHE_KEY_STALE));
        $this->assertNull(Cache::get(SitemapCache::XML_CACHE_KEY));
        $this->assertNull(Cache::get(SitemapCache::ETAG_CACHE_KEY));
    }

    private function createArticleWithRevision(string $revisionStatus, bool $published = false): Article
    {
        $category = ArticleCategory::query()->create([
            'org_id' => 0,
            'slug' => 'career',
            'name' => 'Career',
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $publishedAt = Carbon::create(2026, 6, 8, 8, 0, 0, 'UTC');
        $article = Article::query()->create([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'FermatMind',
            'slug' => 'riasec-cache-invalidation-test',
            'locale' => 'en',
            'title' => 'RIASEC cache invalidation test',
            'excerpt' => 'Cache invalidation test excerpt.',
            'content_md' => 'Cache invalidation test body.',
            'status' => $published ? 'published' : 'draft',
            'is_public' => $published,
            'is_indexable' => true,
            'published_at' => $published ? $publishedAt : null,
        ]);

        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'en',
            'source_locale' => 'en',
            'revision_number' => 1,
            'revision_status' => $revisionStatus,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => 'RIASEC cache invalidation test',
            'excerpt' => 'Cache invalidation test excerpt.',
            'content_md' => 'Cache invalidation test body.',
            'seo_title' => 'RIASEC cache invalidation test',
            'seo_description' => 'Cache invalidation test description.',
            'published_at' => $published ? $publishedAt : null,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => $published ? (int) $revision->id : null,
        ])->save();

        return $article->fresh(['workingRevision', 'publishedRevision']) ?? $article;
    }
}
