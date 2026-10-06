<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ArticleBlogPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_category_language_page_cache_and_unknown_category_are_isolated(): void
    {
        $category = $this->category();
        $this->createArticle(['slug' => 'older', 'category_id' => $category->id]);
        $this->createArticle(['slug' => 'newer', 'category_id' => $category->id, 'published_at' => now()->subDay()]);
        $this->createArticle(['slug' => 'chinese', 'locale' => 'zh-CN', 'category_id' => $category->id]);
        $this->createArticle(['slug' => 'uncategorized']);
        $base = '/api/v0.5/articles?locale=en&category=personality&per_page=1';
        $this->getJson($base)->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonPath('items.0.slug', 'newer');
        $this->getJson($base.'&page=2')->assertJsonPath('items.0.slug', 'older');
        $this->getJson($base)->assertHeader('X-FM-Article-List-Cache', 'hit')->assertJsonPath('items.0.slug', 'newer');
        $this->getJson(str_replace('locale=en', 'locale=zh-CN', $base))->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.slug', 'chinese');
        $this->getJson('/api/v0.5/articles?locale=en&category=unknown')->assertOk()->assertJsonPath('pagination.total', 0)->assertJsonCount(0, 'items');
        $this->getJson('/api/v0.5/articles?locale=en')->assertJsonPath('pagination.total', 3)->assertJsonMissingPath('blog_v1');
        $category->update(['is_active' => false]);
        $this->getJson($base)->assertJsonPath('pagination.total', 0);
    }

    public function test_featured_order_is_manual_separate_and_uses_same_public_qualification(): void
    {
        $category = $this->category();
        $older = $this->createArticle(['slug' => 'older', 'category_id' => $category->id]);
        $newer = $this->createArticle(['slug' => 'newer', 'category_id' => $category->id, 'published_at' => now()->subDay()]);
        $ids = [$older->id, $newer->id];
        foreach ([['is_public' => false], ['status' => 'draft'], ['published_at' => now()->addDay()], ['scheduled_at' => now()->addDay()], ['locale' => 'zh-CN'], ['org_id' => 9], ['lifecycle_state' => 'archived']] as $i => $overrides) {
            $ids[] = $this->createArticle(array_merge(['slug' => 'excluded-'.$i, 'category_id' => $category->id], $overrides))->id;
        }
        $ids[] = $this->createArticle(['slug' => 'future-revision', 'category_id' => $category->id], ['published_at' => now()->addDay()])->id;
        $ids[] = $this->createArticle(['slug' => 'draft-revision', 'category_id' => $category->id], ['revision_status' => 'machine_draft'])->id;
        $ids[] = $this->createArticle(['slug' => 'pointerless', 'category_id' => $category->id], [], false)->id;
        $this->surface('en', $ids);
        $this->getJson('/api/v0.5/articles?locale=en&include_blog=1&per_page=1')->assertOk()
            ->assertJsonPath('items.0.slug', 'newer')->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('blog_v1.configuration_state', 'published')
            ->assertJsonPath('blog_v1.featured_items.0.slug', 'older')->assertJsonPath('blog_v1.featured_items.1.slug', 'newer')
            ->assertJsonCount(2, 'blog_v1.featured_items')->assertJsonPath('blog_v1.categories.0.article_count', 2);
    }

    public function test_revision_binding_and_soft_deleted_lifecycle_exclude_all_blog_projections(): void
    {
        $category = $this->category();
        $valid = $this->createArticle(['slug' => 'qualified', 'category_id' => $category->id]);
        $ids = [$valid->id];
        foreach (['article', 'org', 'locale', 'soft_deleted'] as $mismatch) {
            $article = $this->createArticle(['slug' => 'mismatch-'.$mismatch, 'category_id' => $category->id]);
            if ($mismatch === 'article') {
                $donor = $this->createArticle(['slug' => 'private-donor', 'is_public' => false]);
                $revision = $donor->publishedRevision;
            } elseif ($mismatch === 'org' || $mismatch === 'locale') {
                $revision = $this->createRevision($article, array_merge(['revision_number' => 2],
                    $mismatch === 'org' ? ['org_id' => 9] : ['locale' => 'zh-CN']));
            } else {
                $revision = $article->publishedRevision;
                $article->forceFill(['lifecycle_state' => Article::LIFECYCLE_SOFT_DELETED])->saveQuietly();
            }
            $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $revision->revision_status);
            $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();
            $ids[] = $article->id;
        }
        $this->surface('en', $ids);
        $response = $this->getJson('/api/v0.5/articles?locale=en&include_blog=1&category=personality')->assertOk()
            ->assertJsonPath('pagination.total', 1)->assertJsonCount(1, 'items')
            ->assertJsonCount(1, 'blog_v1.featured_items')->assertJsonPath('blog_v1.categories.0.article_count', 1);
        $this->assertSame([$valid->id], array_column($response->json('items'), 'id'));
        $this->assertSame([$valid->id], array_column($response->json('blog_v1.featured_items'), 'id'));
    }

    public function test_actual_soft_deletes_with_active_or_null_lifecycle_are_excluded_from_every_public_projection(): void
    {
        // Simulate legacy nullable lifecycle rows only in this isolated test database.
        Schema::table('articles', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('lifecycle_state', 32)->nullable()->change();
        });
        $category = $this->category();
        $expectedByLocale = [];
        foreach (['en', 'zh-CN'] as $locale) {
            $featuredIds = [];
            foreach ([Article::LIFECYCLE_ACTIVE, null] as $lifecycle) {
                $suffix = $lifecycle ?? 'null';
                $attributes = ['locale' => $locale, 'category_id' => $category->id, 'lifecycle_state' => $lifecycle];
                $public = $this->createArticle(array_merge($attributes, ['slug' => 'public-'.$locale.'-'.$suffix]));
                $expectedByLocale[$locale][] = $public->id;
                $featuredIds[] = $public->id;
                $deleted = $this->createArticle(array_merge($attributes, ['slug' => 'deleted-'.$locale.'-'.$suffix]));
                $featuredIds[] = $deleted->id;
                $this->assertTrue($deleted->delete());
                $readback = Article::withoutGlobalScopes()->findOrFail($deleted->id);
                $this->assertTrue($readback->trashed());
                $this->assertNotNull($readback->deleted_at);
                $this->assertSame($lifecycle, $readback->lifecycle_state);
                $this->assertSame('published', $readback->status);
                $this->assertTrue($readback->is_public);
                $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $readback->publishedRevision->revision_status);
            }
            $this->surface($locale, $featuredIds);
        }

        foreach ($expectedByLocale as $locale => $expectedIds) {
            $feed = $this->getJson('/api/v0.5/articles-feed?locale='.$locale)->assertOk()->assertJsonCount(2, 'items');
            $list = $this->getJson('/api/v0.5/articles?locale='.$locale)->assertOk()
                ->assertJsonCount(2, 'items')->assertJsonPath('pagination.total', 2);
            $blog = $this->getJson('/api/v0.5/articles?locale='.$locale.'&include_blog=1&category=personality')->assertOk()
                ->assertJsonCount(2, 'items')->assertJsonPath('pagination.total', 2)
                ->assertJsonCount(2, 'blog_v1.featured_items')->assertJsonCount(1, 'blog_v1.categories')
                ->assertJsonPath('blog_v1.categories.0.article_count', 2);
            foreach ([$feed->json('items'), $list->json('items'), $blog->json('items'), $blog->json('blog_v1.featured_items')] as $items) {
                $actualIds = array_column($items, 'id');
                sort($actualIds);
                $this->assertSame($expectedIds, $actualIds);
            }
        }
    }

    public function test_actual_soft_deletes_with_active_or_null_lifecycle_are_not_public_detail_or_seo(): void
    {
        Schema::table('articles', static function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('lifecycle_state', 32)->nullable()->change();
        });
        foreach (['en', 'zh-CN'] as $locale) {
            foreach ([Article::LIFECYCLE_ACTIVE, null] as $lifecycle) {
                $suffix = $locale.'-'.($lifecycle ?? 'null');
                $public = $this->createArticle(['slug' => 'readable-'.$suffix, 'locale' => $locale, 'lifecycle_state' => $lifecycle]);
                $deleted = $this->createArticle(['slug' => 'deleted-detail-'.$suffix, 'locale' => $locale, 'lifecycle_state' => $lifecycle]);
                $this->assertTrue($deleted->delete());
                $readback = Article::withoutGlobalScopes()->findOrFail($deleted->id);
                $this->assertTrue($readback->trashed());
                $this->assertNotNull($readback->deleted_at);
                $this->assertSame($lifecycle, $readback->lifecycle_state);
                $this->assertSame('published', $readback->status);
                $this->assertTrue($readback->is_public);
                $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $readback->publishedRevision->revision_status);
                $this->getJson('/api/v0.5/articles/'.$public->slug.'?locale='.$locale)->assertOk()
                    ->assertJsonPath('article.id', $public->id);
                $this->getJson('/api/v0.5/articles/'.$public->slug.'/seo?locale='.$locale)->assertOk();
                $this->getJson('/api/v0.5/articles/'.$deleted->slug.'?locale='.$locale)->assertNotFound();
                $this->getJson('/api/v0.5/articles/'.$deleted->slug.'/seo?locale='.$locale)->assertNotFound();
            }
        }
    }

    public function test_real_unpublish_path_removes_cached_latest_and_featured_without_stale_fallback(): void
    {
        $category = $this->category();
        $article = $this->createArticle(['category_id' => $category->id]);
        $this->surface('en', [$article->id]);
        $url = '/api/v0.5/articles?locale=en&include_blog=1&category=personality';
        $this->getJson($url)->assertJsonCount(1, 'items')->assertJsonCount(1, 'blog_v1.featured_items');
        app(\App\Services\Cms\ArticlePublishService::class)->unpublishArticle($article->id);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'items')
            ->assertJsonCount(0, 'blog_v1.featured_items')->assertJsonCount(0, 'blog_v1.categories');
    }

    public function test_locale_copy_is_independent_and_config_changes_are_not_list_cache_stale(): void
    {
        $category = $this->category();
        $en = $this->createArticle(['slug' => 'english', 'category_id' => $category->id]);
        $zh = $this->createArticle(['slug' => 'chinese', 'locale' => 'zh-CN', 'category_id' => $category->id]);
        $surface = $this->surface('en', [$en->id]);
        $this->surface('zh-CN', [$zh->id], '费马博客', '人格与自我认识');
        $url = '/api/v0.5/articles?locale=en&include_blog=1';
        $this->getJson($url)->assertJsonPath('blog_v1.title', 'FermatMind Blog')->assertJsonPath('blog_v1.categories.0.name', 'Self-understanding');
        $this->getJson('/api/v0.5/articles?locale=zh-CN&include_blog=1')->assertJsonPath('blog_v1.title', '费马博客')->assertJsonPath('blog_v1.categories.0.name', '人格与自我认识');
        $payload = $surface->payload_json;
        $payload['blog_v1']['featured_article_ids'] = [];
        $surface->update(['payload_json' => $payload]);
        $this->getJson($url)->assertHeader('X-FM-Article-List-Cache', 'hit')->assertJsonCount(0, 'blog_v1.featured_items');
        $this->assertSame('Shared category', $category->fresh()->name);
    }

    public function test_private_future_missing_invalid_config_fail_closed_and_empty_lines_are_hidden(): void
    {
        $url = '/api/v0.5/articles?locale=en&include_blog=1';
        $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'unconfigured');
        $surface = $this->surface('en', []);
        foreach ([['status' => 'draft'], ['is_public' => false], ['published_at' => now()->addDay()], ['scheduled_at' => now()->addDay()]] as $override) {
            $surface->update(array_merge(['status' => 'published', 'is_public' => true, 'published_at' => null, 'scheduled_at' => null], $override));
            $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'unconfigured')->assertJsonPath('blog_v1.title', null);
        }
        $surface->update(['status' => 'published', 'is_public' => true, 'published_at' => null, 'scheduled_at' => null]);
        $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'published')->assertJsonCount(0, 'blog_v1.categories');
        $payload = $surface->payload_json;
        $payload['blog_v1']['categories'] = ['named' => $payload['blog_v1']['categories'][0]];
        $surface->update(['payload_json' => $payload]);
        $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'invalid');
        $surface->update(['payload_json' => ['blog_v1' => ['schema_version' => 1, 'categories' => [], 'featured_article_ids' => [1, 1]]]]);
        $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'invalid')->assertJsonPath('blog_v1.title', null);
        $this->getJson('/api/v0.5/articles?include_blog=1')->assertStatus(422);
        $this->getJson('/api/v0.5/articles?locale=en&category=../private')->assertStatus(422);
    }

    public function test_blog_indexability_comes_from_the_actual_cms_surface_even_with_a_cached_list(): void
    {
        $surface = $this->surface('en', []);
        $surface->update(['is_indexable' => false]);
        $url = '/api/v0.5/articles?locale=en&include_blog=1';
        $this->getJson($url)->assertJsonPath('blog_v1.is_indexable', false)
            ->assertJsonPath('landing_surface_v1.indexability_state', 'indexable');
        $surface->update(['is_indexable' => true]);
        $this->getJson($url)->assertHeader('X-FM-Article-List-Cache', 'hit')->assertJsonPath('blog_v1.is_indexable', true);
        $surface->update(['is_public' => false]);
        $this->getJson($url)->assertJsonPath('blog_v1.configuration_state', 'unconfigured')->assertJsonPath('blog_v1.is_indexable', false);
    }

    private function category(): ArticleCategory
    {
        return ArticleCategory::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'personality', 'name' => 'Shared category', 'is_active' => true]);
    }

    private function surface(string $locale, array $ids, string $title = 'FermatMind Blog', string $name = 'Self-understanding'): LandingSurface
    {
        return LandingSurface::withoutGlobalScopes()->create(['org_id' => 0, 'surface_key' => 'articles_index', 'locale' => $locale,
            'title' => $title, 'description' => 'A CMS-owned introduction.', 'status' => 'published', 'is_public' => true,
            'payload_json' => ['blog_v1' => ['schema_version' => 1, 'featured_article_ids' => $ids,
                'categories' => [['slug' => 'personality', 'line_key' => 'personality-and-self-understanding', 'name' => $name, 'description' => 'CMS-owned category description.']]]]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(
        array $overrides = [],
        array $revisionOverrides = [],
        bool $withPublishedRevision = true
    ): Article {
        /** @var Article */
        $article = Article::query()->create(array_merge([
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

        if ($withPublishedRevision) {
            $revision = $this->createRevision($article, $revisionOverrides);
            $article->forceFill(['published_revision_id' => $revision->id])->save();
        }

        return $article->fresh(['publishedRevision']) ?? $article;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRevision(Article $article, array $overrides = []): ArticleTranslationRevision
    {
        /** @var ArticleTranslationRevision */
        return ArticleTranslationRevision::query()->create(array_merge([
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
    }
}
