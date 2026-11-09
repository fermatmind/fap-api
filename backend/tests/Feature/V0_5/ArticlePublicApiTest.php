<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTestEdge;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\TraditionalArticlePublication;
use App\Services\ContentPromotion\TraditionalArticleSourcePreflight;
use App\Services\SEO\SitemapGenerator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ArticlePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_truth_revision_uses_public_projection_and_ignores_working_copy_and_clocks(): void
    {
        $article = $this->createArticle(['sitemap_eligible' => true, 'llms_eligible' => true]);
        $seo = $this->createSeoMeta($article, ['schema_json' => ['editorial_package_v1' => [
            'answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible',
            'answer_surface_v1' => ['faq_items' => [['question' => 'Question?', 'answer' => 'Original answer.']]],
        ]]]);
        $reader = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        $original = $reader->publicAuthorityRevision($article->fresh());
        $projection = $reader->publicDetailProjection($article->fresh());
        $this->getJson('/api/v0.5/articles/article-slug?locale=en')->assertExactJson($projection);
        $working = $this->createRevision($article, [
            'revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
            'title' => 'Private draft', 'content_md' => 'Private working body',
        ]);
        $article->forceFill(['working_revision_id' => $working->id, 'title' => 'Private title',
            'content_md' => 'Private article projection', 'updated_at' => now()->addDay()])->saveQuietly();
        $working->forceFill(['content_md' => 'Changed private draft', 'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW])->save();
        $this->assertSame($original, $reader->publicAuthorityRevision($article->fresh()));
        $seo->forceFill(['updated_at' => now()->addDays(2)])->saveQuietly();
        $this->assertSame($original, $reader->publicAuthorityRevision($article->fresh()));
        $schema = $seo->schema_json;
        $schema['editorial_package_v1']['answer_surface_v1']['faq_items'][0]['answer'] = 'Changed public answer.';
        $seo->forceFill(['schema_json' => $schema])->save();
        $faqRevision = $reader->publicAuthorityRevision($article->fresh());
        $this->assertNotSame($original, $faqRevision);
        $seo->forceFill(['og_description' => 'Changed public SEO'])->save();
        $this->assertNotSame($faqRevision, $reader->publicAuthorityRevision($article->fresh()));
        $beforePublish = $reader->publicAuthorityRevision($article->fresh());
        $working->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'content_md' => 'New published body'])->save();
        $article->forceFill(['published_revision_id' => $working->id])->saveQuietly();
        $this->assertNotSame($beforePublish, $reader->publicAuthorityRevision($article->fresh()));
        $candidates = app(\App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource::class)->candidates();
        $record = collect($candidates)->first(fn ($record) => $record->pageEntityType === 'article' && $record->entityIdOrSlug === (string) $article->id);
        $this->assertNotNull($record);
        $this->assertSame($reader->publicAuthorityRevision($article->fresh()), $record->attributes['authority_revision']);
    }

    public function test_public_taxonomy_model_and_pivot_clocks_do_not_change_truth_revision(): void
    {
        $category = ArticleCategory::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'public-category', 'name' => 'Public category']);
        $tag = ArticleTag::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'public-tag', 'name' => 'Public tag']);
        $article = $this->createArticle(['category_id' => $category->id, 'sitemap_eligible' => true, 'llms_eligible' => true]);
        $article->tags()->attach($tag->id, ['org_id' => 0]);
        $reader = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        $before = $reader->publicAuthorityRevision($article->fresh());
        $category->forceFill(['updated_at' => now()->addDay()])->saveQuietly();
        $tag->forceFill(['updated_at' => now()->addDays(2)])->saveQuietly();
        $article->tags()->updateExistingPivot($tag->id, ['updated_at' => now()->addDays(3)]);
        $this->assertSame($before, $reader->publicAuthorityRevision($article->fresh()));
        $category->forceFill(['name' => 'Changed public category'])->save();
        $changed = $reader->publicAuthorityRevision($article->fresh());
        $this->assertNotSame($before, $changed);
        $tag->forceFill(['name' => 'Changed public tag'])->save();
        $this->assertNotSame($changed, $reader->publicAuthorityRevision($article->fresh()));
    }

    public function test_taxonomy_events_wait_for_commit_and_only_target_qualified_global_articles(): void
    {
        $category = ArticleCategory::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'event-category', 'name' => 'Category']);
        $tag = ArticleTag::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'event-tag', 'name' => 'Tag']);
        $article = $this->createArticle(['category_id' => $category->id, 'sitemap_eligible' => true, 'llms_eligible' => true]);
        $article->tags()->attach($tag->id, ['org_id' => 0]);
        $this->createArticle(['org_id' => 7, 'slug' => 'tenant-article', 'category_id' => $category->id,
            'sitemap_eligible' => true, 'llms_eligible' => true]);
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false]);
        $reader = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        foreach ([$category, $tag] as $taxonomy) {
            \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class]);
            $before = $reader->publicAuthorityRevision($article->fresh());
            $taxonomy->forceFill(['updated_at' => now()->addDay()])->save();
            \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
            DB::beginTransaction();
            DB::transaction(fn () => $taxonomy->forceFill(['name' => 'Rolled back taxonomy'])->save());
            \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
            DB::rollBack();
            $this->assertSame($before, $reader->publicAuthorityRevision($article->fresh()));
            DB::beginTransaction();
            DB::transaction(fn () => $taxonomy->refresh()->forceFill(['name' => 'Committed taxonomy'])->save());
            \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
            DB::commit();
            \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class, 1);
            \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class,
                fn ($job) => $job->scopedWrite && $job->entityIdentity === (string) $article->id);
            $this->assertNotSame($before, $reader->publicAuthorityRevision($article->fresh()));
            \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class]);
            DB::beginTransaction();
            $taxonomy->delete();
            \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
            DB::commit();
            \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class, 1);
        }
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class]);
        $private = ArticleCategory::withoutGlobalScopes()->create(['org_id' => 7, 'slug' => 'private-category', 'name' => 'Private']);
        $private->forceFill(['name' => 'Private change'])->save();
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
    }

    public function test_public_seo_event_waits_for_outer_commit_and_rollback_keeps_projection(): void
    {
        $article = $this->createArticle();
        $seo = $this->createSeoMeta($article);
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class]);
        $reader = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        $before = $reader->publicAuthorityRevision($article->fresh());
        DB::beginTransaction();
        DB::transaction(fn () => $seo->forceFill(['og_description' => 'Rolled back SEO'])->save());
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
        DB::rollBack();
        $this->assertSame($before, $reader->publicAuthorityRevision($article->fresh()));
        DB::beginTransaction();
        DB::transaction(fn () => $seo->refresh()->forceFill(['og_description' => 'Committed SEO'])->save());
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
        DB::commit();
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class,
            fn ($job) => $job->scopedWrite && $job->entityIdentity === (string) $article->id);
        $this->assertNotSame($before, $reader->publicAuthorityRevision($article->fresh()));
    }

    public function test_article_updates_emit_only_when_the_public_projection_changes(): void
    {
        $article = $this->createArticle(['content_md' => 'Public body without H1']);
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class]);
        $service = app(\App\Services\Cms\ArticleService::class);
        $reader = app(\App\Http\Controllers\API\V0_5\Cms\ArticleController::class);
        $before = $reader->publicAuthorityRevision($article->fresh());
        $service->updateArticle((int) $article->id, ['title' => 'Private working title', 'content_md' => 'Private working body']);
        $this->assertSame($before, $reader->publicAuthorityRevision($article->fresh()));
        \Illuminate\Support\Facades\Bus::assertNotDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class);
        $service->updateArticle((int) $article->id, ['cover_image_alt' => 'New public cover description']);
        $this->assertNotSame($before, $reader->publicAuthorityRevision($article->fresh()));
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SeoIntel\SyncPublicAuthorityUrlTruth::class,
            fn ($job) => $job->scopedWrite && $job->entityIdentity === (string) $article->id);
    }

    public function test_public_review_contract_redacts_private_article_reviewer_identity(): void
    {
        $this->createArticle([
            'slug' => 'reviewed-public-article',
            'reviewer_name' => 'Private Reviewer Name',
        ], [
            'reviewed_by' => 4242,
            'reviewed_at' => Carbon::create(2026, 7, 18, 12, 0, 0, 'UTC'),
            'approved_at' => Carbon::create(2026, 7, 18, 12, 5, 0, 'UTC'),
        ]);

        foreach ([
            $this->getJson('/api/v0.5/articles?locale=en'),
            $this->getJson('/api/v0.5/articles/reviewed-public-article?locale=en'),
        ] as $response) {
            $response->assertOk();
            $payload = $response->json('article') ?? $response->json('items.0');
            $this->assertSame('approved', data_get($payload, 'review_state'));
            $this->assertSame('2026-07-18T12:00:00.000000Z', data_get($payload, 'last_reviewed_at'));
            $this->assertNull(data_get($payload, 'reviewer'));
            $this->assertArrayNotHasKey('reviewer_name', $payload);
            $this->assertStringNotContainsString('Private Reviewer Name', (string) $response->getContent());
            $this->assertStringNotContainsString('4242', (string) $response->getContent());
        }
    }

    public function test_traditional_readers_use_the_exact_published_revision_and_canonical(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $slug = 'article-language-reader';
        $path = '/api/v0.5/articles/'.$slug;
        $this->createArticle(['slug' => $slug, 'locale' => 'zh-CN', 'title' => '简体正文']);

        $this->getJson($path.'?locale=zh-TW')->assertNotFound();
        $this->getJson($path.'/seo?locale=zh-TW')->assertNotFound();

        $article = $this->createArticle([
            'slug' => $slug,
            'locale' => 'zh-TW',
            'title' => '臺灣繁體文章',
            'content_md' => '## 繁體正文',
            'is_indexable' => false,
        ]);
        $canonical = 'https://fermatmind.com/zh-TW/articles/'.$slug;

        $this->getJson('/api/v0.5/articles?locale=zh-TW&include_blog=1')->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.locale', 'zh-TW')
            ->assertJsonPath('landing_surface_v1.discoverability_items.0.href', '/zh-TW/articles/'.$slug)
            ->assertJsonPath('blog_v1.configuration_state', 'unconfigured');
        $this->getJson($path.'?locale=zh-TW')->assertOk()
            ->assertJsonPath('article.published_revision_id', $article->published_revision_id)
            ->assertJsonPath('article.content_md', '## 繁體正文')
            ->assertJsonPath('seo_surface_v1.canonical_url', $canonical);
        $this->getJson($path.'/seo?locale=zh-TW')->assertOk()
            ->assertJsonPath('meta.canonical', $canonical)
            ->assertJsonPath('seo_surface_v1.canonical_url', $canonical);
        $this->getJson($path.'?locale=zh-CN')->assertOk()
            ->assertJsonPath('article.title', '简体正文');
    }

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

    public function test_list_honors_per_page_and_rejects_out_of_range_values(): void
    {
        foreach (range(1, 8) as $index) {
            $this->createArticle([
                'slug' => sprintf('paginated-article-%02d', $index),
                'title' => sprintf('Paginated Article %02d', $index),
            ]);
        }

        $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=6')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 6)
            ->assertJsonPath('pagination.total', 8)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonCount(6, 'items');

        $this->getJson('/api/v0.5/articles?per_page=0')->assertStatus(422);
        $this->getJson('/api/v0.5/articles?per_page=101')->assertStatus(422);
    }

    public function test_list_returns_lightweight_card_payload_with_bounded_excerpt(): void
    {
        foreach (range(1, 6) as $index) {
            $this->createArticle([
                'slug' => sprintf('lightweight-article-%02d', $index),
                'title' => sprintf('Lightweight Article %02d', $index),
                'excerpt' => null,
                'content_md' => '# Heading '.str_repeat('Long article body content. ', 1000),
                'author_name' => 'FermatMind Editorial',
                'reading_minutes' => 8,
            ]);
        }

        $response = $this->getJson('/api/v0.5/articles?locale=en&per_page=6');

        $response->assertOk()
            ->assertJsonCount(6, 'items')
            ->assertJsonPath('items.0.author_name', 'FermatMind Editorial')
            ->assertJsonPath('items.0.reading_minutes', 8)
            ->assertJsonMissingPath('items.0.content_md')
            ->assertJsonMissingPath('items.0.content_html')
            ->assertJsonMissingPath('items.0.body_visual')
            ->assertJsonMissingPath('items.0.seo_meta');

        $this->assertNotSame('', trim((string) $response->json('items.0.excerpt')));
        $this->assertLessThanOrEqual(241, mb_strlen((string) $response->json('items.0.excerpt')));
        $this->assertLessThan(75 * 1024, strlen((string) $response->getContent()));
    }

    public function test_list_cold_path_has_four_bounded_queries_and_cache_hit_has_zero_queries(): void
    {
        $category = ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'cache-category',
            'name' => 'Cache Category',
            'is_active' => true,
        ]);
        $tag = ArticleTag::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'cache-tag',
            'name' => 'Cache Tag',
            'is_active' => true,
        ]);
        $article = $this->createArticle([
            'slug' => 'cache-query-budget',
            'category_id' => (int) $category->id,
        ]);
        $article->tags()->attach((int) $tag->id, [
            'org_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ArticleTestEdge::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'en',
            'test_slug' => 'mbti-personality-test-16-personality-types',
            'role' => ArticleTestEdge::ROLE_PRIMARY,
            'sort_order' => 10,
            'safety_level' => ArticleTestEdge::SAFETY_NORMAL,
            'visibility' => ArticleTestEdge::VISIBILITY_PUBLIC,
            'source' => 'test',
        ]);

        Cache::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $first = $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=6&org_id=0');
        $firstQueries = DB::getQueryLog();

        $first->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'miss')
            ->assertJsonPath('items.0.category.name', 'Cache Category')
            ->assertJsonPath('items.0.tags.0.name', 'Cache Tag')
            ->assertJsonPath('items.0.test_edges.0.test_slug', 'mbti-personality-test-16-personality-types');
        $this->assertCount(4, $firstQueries);

        DB::flushQueryLog();
        $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=6&org_id=0')
            ->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'hit')
            ->assertJsonPath('items.0.slug', 'cache-query-budget');
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_list_cache_partitions_canonical_queries_and_bypasses_filtered_or_noncanonical_reads(): void
    {
        $this->createArticle(['slug' => 'cache-partition-en']);
        $this->createArticle([
            'slug' => 'cache-partition-zh',
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
        ]);
        Cache::flush();

        $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=6')
            ->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'miss');
        $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=6')
            ->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'hit');
        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1&per_page=6')
            ->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'miss');
        $this->getJson('/api/v0.5/articles?locale=en&page=1&per_page=20')
            ->assertOk()
            ->assertHeader('X-FM-Article-List-Cache', 'miss');

        foreach ([
            '/api/v0.5/articles?locale=en&voice=editorial',
            '/api/v0.5/articles?locale=en&related_test_slug=mbti-personality-test-16-personality-types',
            '/api/v0.5/articles?locale=en&org_id=1',
            '/api/v0.5/articles?locale=en&page=51',
        ] as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertHeader('X-FM-Article-List-Cache', 'bypass');
        }
    }

    public function test_detail_keeps_full_article_payload_after_list_optimization(): void
    {
        $article = $this->createArticle(
            [
                'slug' => 'full-detail-contract',
                'content_md' => '# Full detail body',
            ],
            ['seo_title' => 'Article Title']
        );
        $this->createSeoMeta($article);

        $this->getJson('/api/v0.5/articles/full-detail-contract?locale=en')
            ->assertOk()
            ->assertJsonPath('article.content_md', '## Full detail body')
            ->assertJsonPath('article.seo_meta.seo_title', 'Article Title');
    }

    public function test_list_orders_newest_articles_before_legacy_voice_order(): void
    {
        $this->createArticle([
            'slug' => 'older-voice-ordered-article',
            'locale' => 'zh-CN',
            'title' => 'Older voice ordered article',
            'voice_order' => 1,
            'published_at' => Carbon::create(2026, 4, 18, 8, 0, 0, 'UTC'),
        ]);

        $this->createArticle([
            'slug' => 'newest-uploaded-article',
            'locale' => 'zh-CN',
            'title' => 'Newest uploaded article',
            'voice_order' => null,
            'published_at' => Carbon::create(2026, 5, 14, 8, 0, 0, 'UTC'),
        ]);

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'newest-uploaded-article')
            ->assertJsonPath('items.1.slug', 'older-voice-ordered-article');
    }

    public function test_public_article_payload_exposes_sitemap_and_llms_eligibility_gates(): void
    {
        $this->createArticle([
            'slug' => 'indexable-held-from-enumeration',
            'locale' => 'en',
            'title' => 'Indexable held from enumeration',
            'is_indexable' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
        ]);

        $this->getJson('/api/v0.5/articles?locale=en&page=1')
            ->assertOk()
            ->assertJsonPath('items.0.slug', 'indexable-held-from-enumeration')
            ->assertJsonPath('items.0.is_indexable', true)
            ->assertJsonPath('items.0.sitemap_eligible', false)
            ->assertJsonPath('items.0.llms_eligible', false);

        $this->getJson('/api/v0.5/articles/indexable-held-from-enumeration?locale=en')
            ->assertOk()
            ->assertJsonPath('article.is_indexable', true)
            ->assertJsonPath('article.sitemap_eligible', false)
            ->assertJsonPath('article.llms_eligible', false);
    }

    public function test_article_seo_detail_returns_localized_frontend_canonical_alternates_and_jsonld_urls(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $articleEn = $this->createArticle([
            'slug' => 'mbti-basics',
            'translation_group_id' => 'mbti-basics-bilingual',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
        ]);
        $this->createArticle([
            'slug' => 'mbti-basics',
            'translation_group_id' => 'mbti-basics-bilingual',
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
            ->assertJsonPath('jsonld.@type', 'Article')
            ->assertJsonPath('meta.alternates.en', $canonical)
            ->assertJsonPath('meta.alternates.zh', $zhCanonical)
            ->assertJsonPath('meta.alternates.zh-CN', $zhCanonical)
            ->assertJsonPath('meta.article_authority_v1.contract_version', 'article.seo.authority.v1')
            ->assertJsonPath('meta.article_authority_v1.published_revision_backed', true)
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN'])
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.alternates.en', $canonical)
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.alternates.zh-CN', $zhCanonical)
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.article.enabled', false)
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.breadcrumb_list.enabled', false)
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article', null)
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.breadcrumb_list', null)
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical.'#webpage')
            ->assertJsonPath('jsonld.publisher', [
                '@type' => 'Organization',
                'name' => 'FermatMind',
                'url' => 'https://staging.fermatmind.com/en/brand',
            ])
            ->assertJsonMissingPath('jsonld.license')
            ->assertJsonMissingPath('jsonld.distribution')
            ->assertJsonMissingPath('jsonld.downloadUrl');

        $this->assertSame($canonical.'#article', data_get($response->json(), 'jsonld.@id'));
        $this->assertStringNotContainsString($legacyCanonical, (string) $response->getContent());
    }

    public function test_article_seo_alternates_use_translation_group_id_for_different_slugs(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $groupId = 'article_mbti_vs_holland_career_choice_v1';
        $this->createArticle([
            'slug' => 'mbti-vs-holland-code-career-choice',
            'locale' => 'en',
            'title' => 'MBTI vs Holland Code career choice',
            'translation_group_id' => $groupId,
        ]);
        $this->createArticle([
            'slug' => 'mbti-vs-holland-career-choice',
            'locale' => 'zh-CN',
            'title' => 'MBTI vs Holland career choice',
            'translation_group_id' => $groupId,
        ]);

        $response = $this->getJson('/api/v0.5/articles/mbti-vs-holland-code-career-choice/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/mbti-vs-holland-code-career-choice')
            ->assertJsonPath('meta.alternates.zh', 'https://staging.fermatmind.com/zh/articles/mbti-vs-holland-career-choice')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/mbti-vs-holland-career-choice')
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN'])
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/mbti-vs-holland-career-choice');

        $this->assertNull(data_get($response->json(), 'meta.alternates.x-default'));
    }

    public function test_traditional_alternates_require_published_group_lineage_and_seo_eligibility(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $group = 'three-language-article';
        $source = $this->createArticle(['slug' => 'chinese-source', 'locale' => 'zh-CN', 'translation_group_id' => $group]);
        $english = $this->createArticle(['slug' => 'english-sibling', 'locale' => 'en', 'translation_group_id' => $group]);
        $traditional = $this->createArticle([
            'slug' => 'traditional-sibling', 'locale' => 'zh-TW', 'translation_group_id' => $group,
            'source_article_id' => $source->id, 'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
        ]);
        $canonical = 'https://fermatmind.com/zh-TW/articles/traditional-sibling';
        $seo = $this->createSeoMeta($traditional, ['canonical_url' => $canonical, 'robots' => 'noindex,follow',
            'schema_json' => ['editorial_package_v1' => ['hreflang_gate_v1' => ['enabled' => true]]]]);
        $sourcePath = '/api/v0.5/articles/'.$source->slug.'/seo?locale=zh-CN';
        $this->getJson($sourcePath)->assertOk()->assertJsonMissingPath('meta.alternates.zh-TW');

        $seo->update(['robots' => 'index,follow']);
        foreach ([$source, $english, $traditional] as $article) {
            $this->getJson('/api/v0.5/articles/'.$article->slug.'/seo?locale='.$article->locale)->assertOk()
                ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN', 'zh-TW'])
                ->assertJsonPath('meta.alternates.zh-TW', $canonical);
        }

        $traditional->forceFill(['translation_group_id' => 'other-group'])->saveQuietly();
        $this->getJson($sourcePath)->assertOk()->assertJsonMissingPath('meta.alternates.zh-TW');
    }

    public function test_article_reader_links_noindex_traditional_siblings_by_published_lineage(): void
    {
        $group = 'reader-language-group';
        $source = $this->createArticle(['slug' => 'reader-source', 'locale' => 'zh-CN', 'translation_group_id' => $group]);
        $identity = [
            'translation_group_id' => $group, 'source_locale' => 'zh-CN', 'source_article_id' => $source->id,
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
        ];
        $english = $this->createArticle($identity + ['slug' => 'reader-english', 'locale' => 'en']);
        $traditional = $this->createArticle($identity + [
            'slug' => 'reader-traditional', 'locale' => 'zh-TW', 'is_indexable' => false,
        ]);
        $unrelated = $this->createArticle(['slug' => $source->slug, 'locale' => 'en', 'translation_group_id' => 'other-group']);
        $links = ['zh-CN' => $source->slug, 'zh-TW' => $traditional->slug, 'en' => $english->slug];

        foreach ([$source, $english, $traditional] as $article) {
            $path = '/api/v0.5/articles/'.$article->slug;
            $this->getJson($path.'?locale='.$article->locale)->assertOk()->assertJsonPath('reader_language_slugs', $links);
            $this->getJson($path.'/seo?locale='.$article->locale)->assertOk()->assertJsonMissingPath('meta.alternates.zh-TW');
        }

        $this->getJson('/api/v0.5/articles/'.$unrelated->slug.'?locale=en')->assertOk()->assertJsonMissingPath('reader_language_slugs');

        $counterparts = app(\App\Services\Cms\ArticleReviewedCounterpartService::class);
        $binding = $counterparts->createBinding([$source, $unrelated]);
        foreach ([$source, $unrelated] as $article) {
            $this->createSeoMeta($article, [
                'canonical_url' => $counterparts->canonical($article),
                'schema_json' => ['editorial_package_v1' => ['hreflang_gate_v1' => [
                    'enabled' => true, 'policy' => 'reciprocal_counterparts_verified', 'counterpart_binding_v1' => $binding,
                ]]],
            ]);
        }
        $links['en'] = $unrelated->slug;
        foreach ([$source, $unrelated, $traditional] as $article) {
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale)->assertOk()
                ->assertJsonPath('reader_language_slugs', $links);
        }
        $this->getJson('/api/v0.5/articles/'.$english->slug.'?locale=en')->assertOk()->assertJsonMissingPath('reader_language_slugs');
        $unrelated->publishedRevision->updateQuietly(['content_md' => 'Changed after counterpart review']);
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()
            ->assertJsonMissingPath('reader_language_slugs.en')->assertJsonPath('reader_language_slugs.zh-TW', $traditional->slug);

        $traditional->publishedRevision->forceFill(['content_md' => '  '])->saveQuietly();
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->assertJsonMissingPath('reader_language_slugs');
        $traditional->publishedRevision->forceFill(['content_md' => '繁體正文', 'revision_status' => 'draft'])->saveQuietly();
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->assertJsonMissingPath('reader_language_slugs');
        $this->getJson('/api/v0.5/articles/'.$traditional->slug.'?locale=zh-TW')->assertNotFound();
    }

    public function test_reader_links_existing_independent_roots_in_the_same_group(): void
    {
        $group = 'legacy-reader-group';
        $source = $this->createArticle(['slug' => 'traditional-source', 'locale' => 'zh-CN', 'translation_group_id' => $group]);
        $english = $this->createArticle(['slug' => 'legacy-english-root', 'locale' => 'en', 'translation_group_id' => $group]);
        $traditional = $this->createArticle([
            'slug' => $source->slug, 'locale' => 'zh-TW', 'translation_group_id' => $group,
            'source_article_id' => $source->id, 'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED, 'is_indexable' => false,
        ]);
        $links = ['zh-CN' => $source->slug, 'zh-TW' => $traditional->slug, 'en' => $english->slug];
        foreach ([$source, $english, $traditional] as $article) {
            $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale)->assertOk()
                ->assertJsonPath('reader_language_slugs', $links);
        }

        $otherEnglish = $this->createArticle(['slug' => 'other-english-root', 'locale' => 'en', 'translation_group_id' => $group]);
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()
            ->assertJsonMissingPath('reader_language_slugs.en');
        $this->getJson('/api/v0.5/articles/'.$english->slug.'?locale=en')->assertOk()
            ->assertJsonMissingPath('reader_language_slugs');
        $otherEnglish->delete();

        $this->createArticle(['slug' => 'other-chinese-root', 'locale' => 'zh-CN', 'translation_group_id' => $group]);
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()
            ->assertJsonMissingPath('reader_language_slugs.en');
        $this->getJson('/api/v0.5/articles/'.$english->slug.'?locale=en')->assertOk()
            ->assertJsonMissingPath('reader_language_slugs');
    }

    public function test_same_slug_in_other_group_cannot_replace_a_verified_locale_sibling(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);
        $this->createArticle(['slug' => 'paired-en', 'locale' => 'en', 'translation_group_id' => 'verified-pair']);
        $this->createArticle(['slug' => 'paired-zh', 'locale' => 'zh-CN', 'translation_group_id' => 'verified-pair']);
        $this->createArticle(['slug' => 'paired-en', 'locale' => 'zh-CN', 'translation_group_id' => 'independent-root']);

        $this->getJson('/api/v0.5/articles/paired-en/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/paired-zh');
    }

    public function test_independent_same_slug_roots_do_not_become_bilingual_alternates(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);
        $this->createArticle(['slug' => 'independent-copy', 'locale' => 'en', 'translation_group_id' => 'independent-en']);
        $this->createArticle(['slug' => 'independent-copy', 'locale' => 'zh-CN', 'translation_group_id' => 'independent-zh']);

        foreach (['en', 'zh-CN'] as $locale) {
            $this->getJson('/api/v0.5/articles/independent-copy/seo?locale='.$locale)
                ->assertOk()
                ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', [$locale]);
        }
    }

    public function test_legacy_ungrouped_same_slug_pair_does_not_include_a_grouped_root(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);
        foreach (['en', 'zh-CN'] as $locale) {
            $article = $this->createArticle(['slug' => 'legacy-pair', 'locale' => $locale]);
            $article->forceFill(['translation_group_id' => ''])->saveQuietly();
        }
        $this->createArticle(['slug' => 'legacy-independent', 'locale' => 'en', 'translation_group_id' => 'independent-root']);
        $ungrouped = $this->createArticle(['slug' => 'legacy-independent', 'locale' => 'zh-CN']);
        $ungrouped->forceFill(['translation_group_id' => ''])->saveQuietly();

        $this->getJson('/api/v0.5/articles/legacy-pair/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN']);
        $this->getJson('/api/v0.5/articles/legacy-independent/seo?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['zh-CN']);
    }

    public function test_source_revision_is_public_and_restores_bilingual_alternates(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $groupId = 'article_source_revision_public_read_v1';
        $source = $this->createArticle([
            'slug' => 'source-revision-zh',
            'locale' => 'zh-CN',
            'title' => '公开源文章',
            'translation_group_id' => $groupId,
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'source_locale' => 'zh-CN',
        ], [
            'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
        ]);
        $this->createArticle([
            'slug' => 'source-revision-en',
            'locale' => 'en',
            'title' => 'Public source translation',
            'translation_group_id' => $groupId,
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'source_locale' => 'zh-CN',
            'source_article_id' => (int) $source->id,
            'translated_from_article_id' => (int) $source->id,
        ], [
            'source_article_id' => (int) $source->id,
            'source_locale' => 'zh-CN',
        ]);

        $this->getJson('/api/v0.5/articles/source-revision-zh?locale=zh-CN')->assertOk();
        $this->getJson('/api/v0.5/articles/source-revision-en?locale=en')->assertOk();

        $this->getJson('/api/v0.5/articles/source-revision-zh/seo?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('meta.article_authority_v1.published_revision_backed', true)
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN'])
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.alternates.en', 'https://staging.fermatmind.com/en/articles/source-revision-en')
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/source-revision-zh');

        $this->getJson('/api/v0.5/articles/source-revision-en/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/source-revision-en')
            ->assertJsonPath('meta.alternates.zh', 'https://staging.fermatmind.com/zh/articles/source-revision-zh')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/articles/source-revision-zh')
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en', 'zh-CN']);
    }

    public function test_article_seo_alternates_exclude_draft_and_noindex_siblings(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $draftGroup = 'article_draft_sibling_exclusion_v1';
        $this->createArticle([
            'slug' => 'draft-sibling-source',
            'locale' => 'en',
            'translation_group_id' => $draftGroup,
        ]);
        $this->createArticle([
            'slug' => 'draft-sibling-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $draftGroup,
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
        ], [], false);

        $draftResponse = $this->getJson('/api/v0.5/articles/draft-sibling-source/seo?locale=en');
        $draftResponse->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/draft-sibling-source');
        $this->assertNull(data_get($draftResponse->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($draftResponse->json(), 'meta.alternates.zh-CN'));

        $noindexGroup = 'article_noindex_sibling_exclusion_v1';
        $this->createArticle([
            'slug' => 'noindex-sibling-source',
            'locale' => 'en',
            'translation_group_id' => $noindexGroup,
        ]);
        $this->createArticle([
            'slug' => 'noindex-sibling-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $noindexGroup,
            'is_indexable' => false,
        ]);

        $noindexResponse = $this->getJson('/api/v0.5/articles/noindex-sibling-source/seo?locale=en');
        $noindexResponse->assertOk()
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/noindex-sibling-source');
        $this->assertNull(data_get($noindexResponse->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($noindexResponse->json(), 'meta.alternates.zh-CN'));
    }

    public function test_article_detail_includes_landing_and_answer_surfaces(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $article = $this->createArticle([
            'slug' => 'career-fit-guide',
            'locale' => 'en',
            'title' => 'Legacy Career Fit Guide',
            'excerpt' => 'Legacy article-level insight.',
        ], [
            'title' => 'Career Fit Guide',
            'excerpt' => 'Use article-level insight to continue into tests and public hubs.',
            'content_md' => 'Revision-backed public article body.',
        ]);

        $this->createSeoMeta($article, [
            'seo_title' => 'Career Fit Guide | FermatMind',
            'seo_description' => 'Use article-level insight to continue into tests and public hubs.',
            'canonical_url' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
            'schema_json' => [
                'url' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
                'mainEntityOfPage' => 'https://www.fermatmind.com/en/articles/career-fit-guide',
            ],
        ]);

        $response = $this->getJson('/api/v0.5/articles/career-fit-guide?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'article_detail')
            ->assertJsonPath('landing_surface_v1.entry_type', 'editorial_article')
            ->assertJsonPath('seo_surface_v1.canonical_url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('seo_surface_v1.alternates.en', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('seo_surface_v1.og_payload.url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.surface_type', 'article_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'article_summary')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.title', 'Career Fit Guide')
            ->assertJsonPath('article.title', 'Career Fit Guide')
            ->assertJsonPath('article.excerpt', 'Use article-level insight to continue into tests and public hubs.')
            ->assertJsonPath('article.content_md', 'Revision-backed public article body.')
            ->assertJsonPath('article.seo_meta.canonical_url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('article.seo_meta.schema_json.url', 'https://fermatmind.com/en/articles/career-fit-guide')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/articles');

        $this->assertStringNotContainsString('www.fermatmind.com', (string) $response->getContent());
    }

    public function test_article_detail_downgrades_legacy_published_revision_body_h1(): void
    {
        $this->createArticle([
            'slug' => 'legacy-body-h1-article',
            'locale' => 'en',
            'title' => 'Legacy Body H1 Article',
        ], [
            'title' => 'Legacy Body H1 Article',
            'content_md' => "# Legacy duplicate title\n\nBody\n\n## Existing section",
        ]);

        $response = $this->getJson('/api/v0.5/articles/legacy-body-h1-article?locale=en');

        $response->assertOk()
            ->assertJsonPath('article.content_md', "## Legacy duplicate title\n\nBody\n\n## Existing section");

        $this->assertStringStartsWith('## Legacy duplicate title', (string) $response->json('article.content_md'));
    }

    public function test_article_detail_projects_public_schema_and_hreflang_gates_without_editorial_package(): void
    {
        $article = $this->createArticle([
            'slug' => 'schema-gated-article',
            'locale' => 'en',
            'title' => 'Schema Gated Article',
            'excerpt' => 'Article with controlled public schema gates.',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => [
                '@type' => 'Article',
                'url' => 'https://www.fermatmind.com/en/articles/schema-gated-article',
                'editorial_package_v1' => [
                    'article_schema_enabled' => true,
                    'breadcrumb_schema_enabled' => true,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => [
                        'enabled' => false,
                        'policy' => 'no_hreflang',
                        'reason' => 'no_direct_english_counterpart_approved',
                        'recorded_by' => 'articles:seo-gate-rollout',
                    ],
                    'private_operator_note' => 'do not expose this package field',
                ],
            ],
        ]);

        $response = $this->getJson('/api/v0.5/articles/schema-gated-article?locale=en');

        $response->assertOk()
            ->assertJsonPath('article.seo_meta.schema_json.@type', 'Article')
            ->assertJsonPath('article.seo_meta.schema_gates_v1.article_schema_gate_v1.enabled', true)
            ->assertJsonPath('article.seo_meta.schema_gates_v1.breadcrumb_schema_gate_v1.enabled', true)
            ->assertJsonPath('article.seo_meta.schema_gates_v1.faq_schema_gate_v1.enabled', false)
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.enabled', false)
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.policy', 'no_hreflang')
            ->assertJsonPath('article.seo_meta.hreflang_gate_v1.reason', 'no_direct_english_counterpart_approved')
            ->assertJsonMissingPath('article.seo_meta.hreflang_gate_v1.recorded_by')
            ->assertJsonMissingPath('article.seo_meta.schema_json.editorial_package_v1');

        $this->assertStringNotContainsString('private_operator_note', (string) $response->getContent());

        $canonical = 'https://fermatmind.com/en/articles/schema-gated-article';
        $seo = $this->getJson('/api/v0.5/articles/schema-gated-article/seo?locale=en');

        $seo->assertOk()
            ->assertJsonPath('meta.article_authority_v1.published_revision_backed', true)
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.article.enabled', true)
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.breadcrumb_list.enabled', true)
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article.@type', 'Article')
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article.headline', 'Schema Gated Article')
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article.url', $canonical)
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.breadcrumb_list.itemListElement.0.item', 'https://fermatmind.com/en/articles')
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.breadcrumb_list.itemListElement.1.item', $canonical);

        $this->assertStringNotContainsString('private_operator_note', (string) $seo->getContent());
    }

    public function test_article_detail_projects_cms_cta_slots_and_visible_faq_without_private_targets(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);
        $this->ensurePublicRiasecRegistry();

        $editorialPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'visible_faq', 'question' => 'Visible FAQ?', 'answer' => 'Visible answer.'],
                    ['key' => 'hidden_faq', 'question' => 'Hidden FAQ?', 'answer' => 'Hidden answer.', 'visibility' => 'hidden'],
                ],
            ],
            'cta_slots' => [
                ['slot_id' => 'primary_riasec', 'label' => 'Start RIASEC', 'href' => '/en/tests/holland-career-interest-test-riasec'],
                ['slot_id' => 'secondary_mbti', 'label' => 'Start MBTI', 'href' => '/en/tests/mbti-personality-test-16-personality-types'],
                ['slot_id' => 'tertiary_big_five', 'label' => 'Start Big Five', 'href' => '/en/tests/big-five-personality-test'],
                ['slot_id' => 'private_result', 'label' => 'Private result', 'href' => '/en/result/private-attempt'],
            ],
        ];

        $article = $this->createArticle([
            'slug' => 'cms-cta-faq-article',
            'locale' => 'en',
            'title' => 'CMS CTA FAQ Article',
            'cover_image_variants' => ['editorial_package_v1' => $editorialPackage],
            'related_test_slug' => 'mbti-personality-test-16-personality-types',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => ['editorial_package_v1' => $editorialPackage],
        ]);

        $detail = $this->getJson('/api/v0.5/articles/cms-cta-faq-article?locale=en');

        $detail->assertOk()
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.key', 'primary_riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.href', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.1.key', 'secondary_mbti')
            ->assertJsonPath('landing_surface_v1.cta_bundle.2.key', 'tertiary_big_five')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.key', 'visible_faq')
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'Visible FAQ?')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', '/en/tests/holland-career-interest-test-riasec');

        $this->assertContains('FAQPage', $detail->json('seo_surface_v1.structured_data_keys'));

        $this->assertStringNotContainsString('private-attempt', (string) $detail->getContent());
        $this->assertStringNotContainsString('Hidden FAQ?', (string) $detail->getContent());

        $seo = $this->getJson('/api/v0.5/articles/cms-cta-faq-article/seo?locale=en');
        $seo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.@type', 'FAQPage')
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'Visible FAQ?');

        $this->assertStringNotContainsString('Hidden FAQ?', (string) $seo->getContent());
    }

    public function test_traditional_article_projects_reviewed_ctas_to_its_published_source_locale(): void
    {
        $this->ensurePublicRiasecRegistry();
        $source = $this->createArticle(['slug' => 'traditional-cta-source', 'locale' => 'zh-CN']);
        $target = $this->createArticle([
            'slug' => $source->slug, 'locale' => 'zh-TW',
            'source_article_id' => $source->id, 'translated_from_article_id' => $source->id,
            'translation_group_id' => $source->translation_group_id, 'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
        ]);
        $path = '/zh/tests/holland-career-interest-test-riasec';
        $this->createSeoMeta($target, ['schema_json' => ['editorial_package_v1' => ['cta_slots' => [
            ['key' => 'reviewed', 'label' => '查看霍蘭德職業興趣測驗', 'href' => $path],
            ['key' => 'private', 'label' => 'Private result', 'href' => $path.'/result'],
            ['key' => 'different_locale', 'label' => 'Different source', 'href' => '/en/tests/holland-career-interest-test-riasec'],
            ['key' => 'query', 'label' => 'Private query', 'href' => $path.'?attempt=fixture'],
        ]]]]);

        $this->getJson('/api/v0.5/articles/'.$target->slug.'?locale=zh-TW')
            ->assertOk()
            ->assertJsonCount(1, 'landing_surface_v1.cta_bundle')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.label', '查看霍蘭德職業興趣測驗')
            ->assertJsonPath('landing_surface_v1.cta_bundle.0.href', $path)
            ->assertJsonPath('landing_surface_v1.start_test_target', $path)
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.href', $path);
    }

    public function test_article_detail_uses_locale_specific_cms_faq_blocks(): void
    {
        $groupId = 'article_locale_specific_faq_v1';
        $enPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'en_visible_faq', 'question' => 'English visible FAQ?', 'answer' => 'English visible answer.'],
                ],
            ],
        ];
        $zhPackage = [
            'answer_surface_policy' => 'editor_supplied',
            'answer_surface_visibility' => 'below_intro',
            'answer_surface_v1' => [
                'faq_items' => [
                    ['key' => 'zh_visible_faq', 'question' => 'Chinese visible FAQ?', 'answer' => 'Chinese visible answer.'],
                ],
            ],
        ];

        $enArticle = $this->createArticle([
            'slug' => 'locale-specific-faq-en',
            'locale' => 'en',
            'translation_group_id' => $groupId,
            'cover_image_variants' => ['editorial_package_v1' => $enPackage],
        ]);
        $this->createSeoMeta($enArticle, [
            'schema_json' => ['editorial_package_v1' => $enPackage],
        ]);

        $zhArticle = $this->createArticle([
            'slug' => 'locale-specific-faq-zh',
            'locale' => 'zh-CN',
            'translation_group_id' => $groupId,
            'cover_image_variants' => ['editorial_package_v1' => $zhPackage],
        ]);
        $this->createSeoMeta($zhArticle, [
            'schema_json' => ['editorial_package_v1' => $zhPackage],
        ]);

        $enDetail = $this->getJson('/api/v0.5/articles/locale-specific-faq-en?locale=en');
        $enDetail->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'English visible FAQ?');
        $this->assertStringNotContainsString('Chinese visible FAQ?', (string) $enDetail->getContent());

        $zhDetail = $this->getJson('/api/v0.5/articles/locale-specific-faq-zh?locale=zh-CN');
        $zhDetail->assertOk()
            ->assertJsonPath('answer_surface_v1.faq_blocks.0.question', 'Chinese visible FAQ?');
        $this->assertStringNotContainsString('English visible FAQ?', (string) $zhDetail->getContent());

        $enSeo = $this->getJson('/api/v0.5/articles/locale-specific-faq-en/seo?locale=en');
        $enSeo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'English visible FAQ?');
        $this->assertStringNotContainsString('Chinese visible FAQ?', (string) $enSeo->getContent());

        $zhSeo = $this->getJson('/api/v0.5/articles/locale-specific-faq-zh/seo?locale=zh-CN');
        $zhSeo->assertOk()
            ->assertJsonPath('jsonld.hasPart.0.mainEntity.0.name', 'Chinese visible FAQ?');
        $this->assertStringNotContainsString('English visible FAQ?', (string) $zhSeo->getContent());
    }

    public function test_article_detail_uses_explicit_related_public_test_without_unrelated_default(): void
    {
        $this->ensurePublicRiasecRegistry();
        $this->createArticle([
            'slug' => 'riasec-fallback-article',
            'locale' => 'en',
            'related_test_slug' => 'holland-career-interest-test-riasec',
        ]);

        $response = $this->getJson('/api/v0.5/articles/riasec-fallback-article?locale=en');

        $response->assertOk()
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('landing_surface_v1.cta_bundle.2.href', '/en/tests/holland-career-interest-test-riasec')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.2.href', '/en/tests/holland-career-interest-test-riasec');
    }

    public function test_public_reads_require_published_revision_and_hide_human_review(): void
    {
        $visible = $this->createArticle([
            'slug' => 'visible-article',
            'locale' => 'en',
            'title' => 'Legacy visible article',
        ], [
            'title' => 'Published visible article',
        ]);

        $missingPointer = $this->createArticle([
            'slug' => 'missing-published-revision',
            'locale' => 'en',
            'title' => 'Missing published revision',
        ], [], false);

        $humanReview = $this->createArticle([
            'slug' => 'human-review-leak-guard',
            'locale' => 'en',
            'title' => 'Human review canonical',
        ], [], false);
        $humanReviewRevision = $this->createRevision($humanReview, [
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'Human review title must not leak',
            'published_at' => null,
        ]);
        $humanReview->forceFill(['published_revision_id' => $humanReviewRevision->id])->save();

        $response = $this->getJson('/api/v0.5/articles?locale=en&page=1');

        $response->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', (string) $visible->slug)
            ->assertJsonPath('items.0.title', 'Published visible article');

        $this->getJson('/api/v0.5/articles/'.$missingPointer->slug.'?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/'.$humanReview->slug.'?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles/'.$humanReview->slug.'/seo?locale=en')
            ->assertNotFound();
    }

    public function test_detail_does_not_fallback_to_source_locale_when_translation_unpublished(): void
    {
        $source = $this->createArticle([
            'slug' => 'shared-translation-slug',
            'locale' => 'zh-CN',
            'title' => '中文源文 legacy',
        ], [
            'title' => '中文源文 published revision',
            'excerpt' => '中文公开摘要',
            'content_md' => '中文公开正文',
        ]);

        $translation = $this->createArticle([
            'slug' => 'shared-translation-slug',
            'locale' => 'en',
            'title' => 'English canonical human review',
            'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
            'translated_from_article_id' => $source->id,
            'source_article_id' => $source->id,
            'translated_from_version_hash' => $source->source_version_hash,
        ], [], false);
        $this->createRevision($translation, [
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'Human review English draft',
            'published_at' => null,
        ]);

        $this->getJson('/api/v0.5/articles/shared-translation-slug?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('article.title', '中文源文 published revision');

        $this->getJson('/api/v0.5/articles/shared-translation-slug?locale=en')
            ->assertNotFound();
        $this->getJson('/api/v0.5/articles?locale=en&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_public_seo_uses_published_revision_and_excludes_unpublished_alternates(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $articleEn = $this->createArticle([
            'slug' => 'revision-seo-source',
            'locale' => 'en',
            'title' => 'Legacy SEO title',
            'excerpt' => 'Legacy SEO excerpt.',
        ], [
            'title' => 'Published revision SEO title',
            'excerpt' => 'Published revision excerpt.',
            'content_md' => 'Published revision body.',
            'seo_title' => 'Published Revision SEO | FermatMind',
            'seo_description' => 'Published revision SEO description.',
        ]);
        $this->createSeoMeta($articleEn, [
            'seo_title' => 'Legacy Article SEO | FermatMind',
            'seo_description' => 'Legacy article SEO description.',
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => true,
                    'breadcrumb_schema_enabled' => true,
                ],
            ],
        ]);

        $this->createArticle([
            'slug' => 'revision-seo-source',
            'locale' => 'zh-CN',
            'title' => '未发布中文 sibling',
        ], [], false);

        $response = $this->getJson('/api/v0.5/articles/revision-seo-source/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.title', 'Published Revision SEO | FermatMind')
            ->assertJsonPath('meta.description', 'Published revision SEO description.')
            ->assertJsonPath('jsonld.headline', 'Published Revision SEO | FermatMind')
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/articles/revision-seo-source')
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en'])
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.article.enabled', true)
            ->assertJsonPath('meta.article_authority_v1.structured_data_eligibility.breadcrumb_list.enabled', true)
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article.headline', 'Published Revision SEO | FermatMind')
            ->assertJsonPath('meta.article_authority_v1.structured_data_fragments.article.description', 'Published revision SEO description.');

        $this->assertNull(data_get($response->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($response->json(), 'meta.alternates.zh-CN'));
        $this->assertStringNotContainsString('Legacy Article SEO', (string) $response->getContent());
    }

    public function test_current_17_to_29_draft_and_human_review_samples_are_not_public(): void
    {
        $slugs = [
            17 => 'how-personality-shapes-attitude-toward-ai',
            18 => 'which-love-script-fits-you-best',
            19 => 'are-infj-men-rare-or-socially-silenced',
            20 => 'best-valentines-date-by-personality-and-relationship-science',
            21 => 'how-16-personality-types-talk-to-an-ai-coach',
            22 => 'childhood-dream-job-still-shapes-career-choice',
        ];

        Article::unguarded(function () use ($slugs): void {
            foreach ($slugs as $id => $slug) {
                $source = $this->createArticle([
                    'id' => $id,
                    'slug' => $slug,
                    'locale' => 'zh-CN',
                    'title' => '中文源文 '.$id,
                    'status' => 'draft',
                    'is_public' => false,
                    'published_at' => null,
                    'translation_group_id' => 'article-group-'.$id,
                ], [], false);
                $this->createRevision($source, [
                    'revision_status' => ArticleTranslationRevision::STATUS_SOURCE,
                    'title' => '中文源文 revision '.$id,
                    'published_at' => null,
                ]);

                $translation = $this->createArticle([
                    'id' => $id + 7,
                    'slug' => $slug,
                    'locale' => 'en',
                    'title' => 'English human review '.$id,
                    'status' => 'draft',
                    'is_public' => false,
                    'published_at' => null,
                    'translation_group_id' => $source->translation_group_id,
                    'source_locale' => 'zh-CN',
                    'translation_status' => Article::TRANSLATION_STATUS_HUMAN_REVIEW,
                    'translated_from_article_id' => $source->id,
                    'source_article_id' => $source->id,
                    'translated_from_version_hash' => $source->source_version_hash,
                ], [], false);
                $this->createRevision($translation, [
                    'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                    'title' => 'English human review revision '.$id,
                    'published_at' => null,
                ]);
            }
        });

        $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');
        $this->getJson('/api/v0.5/articles?locale=en&page=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonCount(0, 'items');

        foreach ($slugs as $slug) {
            $this->getJson('/api/v0.5/articles/'.$slug.'?locale=zh-CN')->assertNotFound();
            $this->getJson('/api/v0.5/articles/'.$slug.'?locale=en')->assertNotFound();
        }
    }

    public function test_sitemap_source_uses_public_readable_indexable_and_sitemap_eligible_article_gate(): void
    {
        Cache::flush();
        app(PublicCareerAuthorityResponseCache::class)->warm();
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $this->createArticle([
            'slug' => 'sitemap-visible-article',
            'locale' => 'en',
            'sitemap_eligible' => true,
            'llms_eligible' => true,
        ]);
        $this->createArticle([
            'slug' => 'sitemap-draft-article',
            'locale' => 'en',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => false,
            'published_at' => null,
        ], [], false);
        $this->createArticle([
            'slug' => 'sitemap-noindex-article',
            'locale' => 'en',
            'is_indexable' => false,
        ]);

        $locations = collect(app(SitemapGenerator::class)->generateUrls())->pluck('loc')->all();

        $this->assertContains('https://staging.fermatmind.com/en/articles/sitemap-visible-article', $locations);
        $this->assertNotContains('https://staging.fermatmind.com/en/articles/sitemap-draft-article', $locations);
        $this->assertNotContains('https://staging.fermatmind.com/en/articles/sitemap-noindex-article', $locations);
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
            ->assertJsonPath('meta.article_authority_v1.alternate_eligibility.eligible_locales', ['en'])
            ->assertJsonPath('jsonld.url', $canonical)
            ->assertJsonPath('jsonld.mainEntityOfPage', $canonical);

        $this->assertNull(data_get($response->json(), 'meta.alternates.zh'));
        $this->assertNull(data_get($response->json(), 'meta.alternates.zh-CN'));
    }

    public function test_detail_and_seo_null_blocked_media_urls(): void
    {
        $article = $this->createArticle([
            'slug' => 'guarded-article',
            'locale' => 'en',
            'title' => 'Guarded article',
            'cover_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/article.png',
        ]);
        $this->createSeoMeta($article, [
            'og_image_url' => 'https://ci.example.test/article.png?ci-process=cover',
        ]);

        $this->getJson('/api/v0.5/articles/guarded-article?locale=en')
            ->assertOk()
            ->assertJsonPath('article.cover_image_url', null)
            ->assertJsonPath('article.seo_meta.og_image_url', null);

        $this->getJson('/api/v0.5/articles/guarded-article/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.og.image', null)
            ->assertJsonPath('meta.twitter.image', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ensurePublicRiasecRegistry(): void
    {
        \App\Models\ScaleRegistry::withoutGlobalScopes()->updateOrCreate(['code' => 'RIASEC', 'org_id' => 0], [
            'primary_slug' => 'holland-career-interest-test-riasec', 'driver_type' => 'riasec',
            'slugs_json' => ['holland-career-interest-test-riasec'],
            'is_public' => true, 'is_active' => true,
        ]);
        \App\Models\ScaleSlug::withoutGlobalScopes()->updateOrCreate([
            'org_id' => 0, 'slug' => 'holland-career-interest-test-riasec',
        ], ['scale_code' => 'RIASEC', 'is_primary' => true]);
    }

    public static function traditionalArticleTaxonomyCases(): array
    {
        return ['same as source' => [false], 'staging with an unclassified source' => [true]];
    }

    public function test_traditional_article_package_binds_an_independent_english_root_with_its_own_slug(): void
    {
        $root = base_path('content_assets/public_content_53/');
        $package = json_decode(file_get_contents($root.'traditional-article.json'), true, flags: JSON_THROW_ON_ERROR);
        $review = file_get_contents($root.'traditional-article-review.json');
        $origin = &$package['mapping']['environment_source_preflight']['staging']['existing_english'];
        $proof = &$package['source_expectations']['staging'];
        $proof['english_relationship'] = 'independent_published_english';
        $peer = ['slug' => 'different-english-slug', 'translation_group_id' => 'independent-english-root',
            'source_locale' => 'en', 'source_article_id' => null];
        $proof['existing_english'] = array_replace($proof['existing_english'], $peer);
        $origin = array_replace($origin, $peer);
        $result = TraditionalArticlePublication::package(json_encode($package, JSON_THROW_ON_ERROR), $review, 'staging');
        $this->assertSame('different-english-slug', $result['candidates'][0]['source_expectations']['existing_english']['slug']);
        $this->assertNull(TraditionalArticlePublication::englishSourceId($result['candidates'][0]['source_expectations']));

        $proof['existing_english']['source_article_id'] = $proof['source']['id'];
        $origin['source_article_id'] = $proof['source']['id'];
        $this->expectExceptionMessage('traditional_article_english_relationship_invalid');
        TraditionalArticlePublication::package(json_encode($package, JSON_THROW_ON_ERROR), $review, 'staging');
    }

    public function test_traditional_article_package_binds_staging_english_absence_to_the_production_slug(): void
    {
        $root = base_path('content_assets/public_content_53/');
        $package = json_decode(file_get_contents($root.'traditional-article.json'), true, flags: JSON_THROW_ON_ERROR);
        $review = file_get_contents($root.'traditional-article-review.json');
        $package['mapping']['environment_source_preflight']['staging']['existing_english'] = null;
        $proof = &$package['source_expectations']['staging'];
        $proof['existing_english'] = null;
        $proof['unpublished_english_slug'] = $package['source_expectations']['production']['existing_english']['slug'];

        $encode = static fn (array $candidate): string => json_encode($candidate, JSON_THROW_ON_ERROR);
        $row = TraditionalArticlePublication::package($encode($package), $review, 'staging')['candidates'][0];
        $this->assertNull($row['source_expectations']['existing_english']);
        $production = TraditionalArticlePublication::package($encode($package), $review, 'production')['candidates'][0];
        $this->assertSame($proof['unpublished_english_slug'], $production['source_expectations']['existing_english']['slug']);

        $proof['unpublished_english_slug'] = 'different-english-slug';
        $this->expectExceptionMessage('traditional_article_staging_english_absence_invalid');
        TraditionalArticlePublication::package($encode($package), $review, 'staging');
    }

    public function test_traditional_article_package_requires_exact_english_source_and_explicit_chinese_absence(): void
    {
        $root = base_path('content_assets/public_content_53/');
        $package = json_decode(file_get_contents($root.'traditional-article.json'), true, flags: JSON_THROW_ON_ERROR);
        $review = file_get_contents($root.'traditional-article-review.json');
        $reviewedFields = json_decode($review, true, flags: JSON_THROW_ON_ERROR)['fields'];
        $package['mapping']['target_identity']['source_locale'] = 'en';
        foreach ($package['source_expectations'] as $environment => &$proof) {
            $origins = &$package['mapping']['environment_source_preflight'][$environment];
            unset($proof['existing_english'], $origins['existing_english']);
            $proof['existing_chinese'] = $origins['existing_chinese'] = null;
            foreach (['locale' => 'en', 'source_locale' => 'en', 'source_article_id' => null] as $key => $value) {
                $proof['source'][$key] = $origins['source'][$key] = $value;
            }
            if ($environment === 'production') {
                foreach ($proof['source']['snapshot_sha256'] as $key => &$hash) {
                    $path = '/article/'.(str_starts_with($key, 'seo_') || str_starts_with($key, 'og_') ? 'seo_meta/' : '').$key;
                    $hash = $reviewedFields[$path]['sourceSha256'];
                }
                unset($hash);
                $origins['source']['public_body_sha256'] = $proof['source']['snapshot_sha256']['content_md'];
            }
        }
        unset($proof, $origins);
        $rows = TraditionalArticlePublication::package(json_encode($package, JSON_THROW_ON_ERROR), $review, 'production')['candidates'];
        $this->assertSame('en', $rows[0]['source_identity']['source_locale']);
        $this->assertNull($rows[0]['source_expectations']['existing_chinese']);
        $stageId = $rows[0]['source_identity']['source_article_id'] + 1000;
        $package['source_expectations']['staging']['source']['id'] = $stageId;
        $package['mapping']['environment_source_preflight']['staging']['source']['id'] = $stageId;
        $stage = TraditionalArticlePublication::package(json_encode($package, JSON_THROW_ON_ERROR), $review, 'staging')['candidates'][0];
        $this->assertSame($stageId, $stage['source_identity']['source_article_id']);
        $this->assertSame($rows[0]['source_identity']['source_article_id'], $package['mapping']['target_identity']['source_article_id']);

        $split = $package;
        $split['mapping']['target_identity']['slug'] .= '-english-source';
        foreach ($split['source_expectations'] as $environment => &$proof) {
            $origins = &$split['mapping']['environment_source_preflight'][$environment];
            $proof['existing_chinese'] = array_replace($proof['source'], [
                'id' => 999, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'translation_group_id' => 'independent-chinese-source',
            ]);
            $proof['existing_chinese']['snapshot_sha256']['content_md'] = str_repeat('b', 64);
            $origins['existing_chinese'] = array_replace($origins['source'], [
                'id' => 999, 'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'translation_group_id' => 'independent-chinese-source',
                'public_body_sha256' => str_repeat('b', 64),
            ]);
        }
        unset($proof, $origins);
        foreach (['staging', 'production'] as $environment) {
            $row = TraditionalArticlePublication::package(json_encode($split, JSON_THROW_ON_ERROR), $review, $environment)['candidates'][0];
            $this->assertSame($split['mapping']['target_identity']['slug'], $row['identity']['slug']);
            $this->assertSame(999, $row['source_expectations']['existing_chinese']['id']);
        }
        $package['source_expectations']['staging']['existing_chinese'] = ['id' => 1];

        $this->expectExceptionMessage('traditional_article_english_source_proof_invalid');
        TraditionalArticlePublication::package(json_encode($package, JSON_THROW_ON_ERROR), $review, 'production');
    }

    public static function englishSourceRouteCases(): array
    {
        return ['same slug' => [false], 'independent Chinese source' => [true]];
    }

    #[DataProvider('englishSourceRouteCases')]
    public function test_english_root_publishes_traditional_reader_links_and_rollback_leaves_source_intact(bool $independentChinese): void
    {
        $this->ensurePublicRiasecRegistry();
        $relatedTest = 'holland-career-interest-test-riasec';
        $source = $this->createArticle(['locale' => 'en', 'slug' => 'independent-english-root', 'related_test_slug' => $relatedTest]);
        $source->forceFill(['working_revision_id' => $source->published_revision_id])->saveQuietly();
        $this->createSeoMeta($source, ['seo_title' => 'Mutable SEO row title']);
        $proof = ['source' => $this->traditionalArticleReaderProof($source), 'existing_chinese' => null];
        $targetSlug = $source->slug;
        if ($independentChinese) {
            $chinese = $this->createArticle(['locale' => 'zh-CN', 'slug' => $source->slug, 'content_md' => '## 中文原文']);
            $this->createSeoMeta($chinese);
            $proof['existing_chinese'] = $this->traditionalArticleReaderProof($chinese);
            $stale = $proof;
            $stale['existing_chinese']['published_revision_id']++;
            try {
                app(TraditionalArticleSourcePreflight::class)->assertSource($stale);
                $this->fail('An outdated Chinese revision was accepted.');
            } catch (DomainException $error) {
                $this->assertSame('traditional_article_source_identity_changed', $error->getMessage());
            }
            $existing = $this->createArticle(['locale' => 'zh-TW', 'slug' => $source->slug,
                'source_article_id' => $chinese->id, 'translated_from_article_id' => $chinese->id,
                'source_locale' => 'zh-CN', 'translation_group_id' => $chinese->translation_group_id]);
            $targetSlug .= '-english-source';
        }
        $sha = str_repeat('a', 64);
        $row = [
            'identity' => ['org_id' => 0, 'locale' => 'zh-TW', 'slug' => $targetSlug],
            'source_identity' => ['source_article_id' => $source->id],
            'source_expectations' => $proof,
            'package_sha256' => $sha, 'page_id' => 'TRADITIONAL-ARTICLE-english-root',
            'snapshot' => ['title' => '繁體標題', 'excerpt' => '繁體摘要', 'content_md' => '## 繁體正文',
                'author_name' => '費馬測試編輯部', 'cover_image_alt' => '封面',
                'seo_text' => ['seo_title' => '繁體標題', 'seo_description' => '繁體摘要']],
            'article_metadata' => ['related_test_slug' => $relatedTest],
            'taxonomy_display' => ['category' => ['id' => null], 'tags' => []],
        ];
        $sourceBefore = $source->fresh()->getAttributes();
        $context = new PromotionContext('test', $sha, 'W3', 'PUBLIC-CONTENT-53', str_repeat('b', 40),
            $sha, $sha, '1', 1, $sha, 1, $sha);
        $writer = app(TraditionalArticlePublication::class);
        DB::transaction(fn () => $this->assertTrue($writer->publish($row, $writer->read($row, true), $context)));

        $path = '/api/v0.5/articles/'.$source->slug;
        $targetPath = '/api/v0.5/articles/'.$targetSlug;
        $links = ['en' => $source->slug, 'zh-TW' => $targetSlug];
        $this->getJson($targetPath.'?locale=zh-TW')->assertOk()->assertJsonPath('article.source_locale', 'en')
            ->assertJsonPath('article.is_indexable', false)->assertJsonPath('reader_language_slugs', $links)
            ->assertJsonPath('landing_surface_v1.start_test_target', '/en/tests/'.$relatedTest)
            ->assertJsonPath('landing_surface_v1.cta_bundle.2.label', '開始測驗');
        $this->getJson($path.'?locale=en')->assertOk()->assertJsonPath('reader_language_slugs', $links);
        $this->getJson($path.'/seo?locale=en')->assertOk()->assertJsonMissingPath('meta.alternates.zh-TW');
        DB::transaction(fn () => $writer->restore($row, $writer->read($row, true)));
        $this->getJson($path.'?locale=en')->assertOk()->assertJsonMissingPath('reader_language_slugs');
        $this->getJson($targetPath.'?locale=zh-TW')->assertNotFound();
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());

        if ($independentChinese) {
            $this->getJson($path.'?locale=zh-CN')->assertOk()->assertJsonPath('article.id', $chinese->id);
            $this->getJson($path.'?locale=zh-TW')->assertOk()->assertJsonPath('article.id', $existing->id);
            $proof['existing_chinese'] = null;
        } else {
            $this->createArticle(['locale' => 'zh-CN', 'slug' => $source->slug]);
        }
        $this->expectExceptionMessage('traditional_article_english_source_chinese_counterpart');
        app(TraditionalArticleSourcePreflight::class)->assertSource($proof);
    }

    #[DataProvider('traditionalArticleTaxonomyCases')]
    public function test_traditional_article_preflight_locks_native_source_and_rejects_source_drift_or_target_collision(bool $useStagingTaxonomy): void
    {
        $source = $this->createArticle(['locale' => 'zh-CN']);
        $source->forceFill(['working_revision_id' => $source->published_revision_id])->saveQuietly();
        $english = $this->createArticle([
            'slug' => $source->slug,
            'source_locale' => 'zh-CN',
            'source_article_id' => $source->id,
            'translated_from_article_id' => $source->id,
            'translation_group_id' => $source->translation_group_id,
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
        ]);
        $proof = [];
        foreach (['source' => $source, 'existing_english' => $english] as $key => $article) {
            $seo = $this->createSeoMeta($article);
            $proof[$key] = [
                'slug' => $article->slug,
                'id' => $article->id,
                'published_revision_id' => $article->published_revision_id,
                'translation_group_id' => $article->translation_group_id,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'hreflang_gate' => null,
                'snapshot_sha256' => array_map(
                    static fn (?string $value): ?string => $value === null ? null : hash('sha256', $value),
                    $article->only(['title', 'excerpt', 'content_md', 'author_name', 'cover_image_alt'])
                        + $seo->only(['seo_title', 'seo_description', 'og_title', 'og_description']),
                ),
            ];
        }

        $guard = app(TraditionalArticleSourcePreflight::class);
        DB::transaction(function () use ($guard, $proof, $source): void {
            $actual = $guard->assertSource($proof, true);
            $this->assertSame($source->id, $actual->id);
            $guard->assertTargetVacant($actual, true);
        });

        if ($useStagingTaxonomy) {
            $noEnglish = $proof;
            $noEnglish['existing_english'] = null;
            $noEnglish['unpublished_english_slug'] = 'independent-english-version';
            foreach ([false => 'traditional_article_staging_english_absence_invalid', true => 'traditional_article_staging_english_now_published'] as $staging => $message) {
                try {
                    $guard->assertSource($noEnglish, allowStagingPreparedRoot: (bool) $staging);
                    $this->fail('The guard accepted an undeclared absence.');
                } catch (DomainException $exception) {
                    $this->assertSame($message, $exception->getMessage());
                }
            }
            $english->forceFill(['is_public' => false])->saveQuietly();
            DB::transaction(fn () => $this->assertSame($source->id, $guard->assertSource($noEnglish, true, true)->id));
            $independent = $this->createArticle(['slug' => $noEnglish['unpublished_english_slug'], 'locale' => 'en']);
            try {
                $guard->assertSource($noEnglish, allowStagingPreparedRoot: true);
                $this->fail('The guard accepted a newly published independent English version.');
            } catch (DomainException $exception) {
                $this->assertSame('traditional_article_staging_english_now_published', $exception->getMessage());
            }
            $independent->delete();
            $english->forceFill(['is_public' => true])->saveQuietly();
            $english->refresh();

            $withoutSeo = $proof;
            $withoutSeo['existing_english']['seo_present'] = false;
            try {
                $guard->assertSource($withoutSeo);
                $this->fail('The guard accepted a changed English SEO record.');
            } catch (DomainException $exception) {
                $this->assertSame('traditional_article_source_seo_state_changed', $exception->getMessage());
            }
            $seo->delete();
            foreach (['seo_title', 'seo_description', 'og_title', 'og_description'] as $field) {
                $withoutSeo['existing_english']['snapshot_sha256'][$field] = null;
            }
            $this->assertSame($source->id, $guard->assertSource($withoutSeo)->id);
            $proof = $withoutSeo;
        }

        $drifted = $proof;
        $drifted['source']['snapshot_sha256']['title'] = hash('sha256', 'Unexpected source title');
        try {
            $guard->assertSource($drifted);
            $this->fail('The guard accepted a different source title.');
        } catch (DomainException $exception) {
            $this->assertSame('traditional_article_source_payload_changed', $exception->getMessage());
        }

        $source->forceFill(['translation_status' => Article::TRANSLATION_STATUS_APPROVED])->saveQuietly();
        try {
            $guard->assertSource($proof);
            $this->fail('The production guard accepted a prepared source.');
        } catch (DomainException $exception) {
            $this->assertSame('traditional_article_source_working_state_changed', $exception->getMessage());
        }
        $this->assertSame($source->id, $guard->assertSource($proof, allowStagingPreparedRoot: true)->id);

        $candidate = app(TraditionalArticlePublication::class);
        $sha = str_repeat('a', 64);
        $context = new PromotionContext('test', $sha, 'W3', 'PUBLIC-CONTENT-53', str_repeat('b', 40),
            $sha, $sha, '1', 1, $sha, 1, $sha);
        $row = [
            'identity' => ['org_id' => 0, 'locale' => 'zh-TW', 'slug' => $source->slug],
            'source_identity' => ['source_article_id' => $source->id],
            'source_expectations' => $proof,
            'allow_staging_prepared_root' => true,
            'package_sha256' => $sha,
            'page_id' => 'TRADITIONAL-ARTICLE-test',
            'snapshot' => ['title' => '繁體標題', 'excerpt' => '繁體摘要', 'content_md' => '## 繁體正文',
                'author_name' => '費馬測試編輯部', 'cover_image_alt' => '封面', 'seo_text' => ['seo_title' => '繁體標題', 'seo_description' => '繁體摘要']],
            'article_metadata' => [],
            'taxonomy_display' => ['category' => ['id' => null], 'tags' => []],
        ];
        if ($useStagingTaxonomy) {
            $category = ArticleCategory::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'career-exploration', 'name' => '職業探索', 'is_active' => true]);
            $tag = ArticleTag::withoutGlobalScopes()->create(['org_id' => 0, 'slug' => 'riasec', 'name' => 'RIASEC', 'is_active' => true]);
            $row['staging_source_taxonomy'] = ['category_id' => null, 'tag_ids' => []];
            $row['taxonomy_display'] = ['category' => $category->only(['id', 'slug']), 'tags' => [$tag->only(['id', 'slug'])]];
            $this->ensurePublicRiasecRegistry();
            $faq = [['key' => 'reviewed_faq', 'question' => '如何理解興趣？', 'answer' => '先觀察自己偏好的活動。']];
            $ctas = [['key' => 'reviewed_cta', 'kind' => 'start_test', 'href' => '/zh/tests/holland-career-interest-test-riasec', 'label' => '開始職涯興趣測驗']];
            $row['snapshot']['author_name'] = null;
            $row['snapshot']['editorial_projection'] = ['faq' => $faq, 'ctas' => $ctas];
            $row['editorial_package_v1'] = [
                'answer_surface_policy' => 'editor_supplied',
                'answer_surface_visibility' => 'below_intro',
                'answer_surface_v1' => ['faq_items' => $faq],
                'cta_slots' => $ctas,
            ];
            $tag->update(['is_active' => false]);
            try {
                DB::transaction(fn () => $candidate->publish($row, $candidate->read($row, true), $context));
                $this->fail('The guard accepted an inactive staging tag.');
            } catch (DomainException $exception) {
                $this->assertSame('traditional_article_target_tag_unavailable', $exception->getMessage());
            }
            $tag->update(['is_active' => true]);
        }
        $sourceBefore = $source->getAttributes();
        $englishBefore = $english->getAttributes();
        DB::transaction(function () use ($candidate, $row, $context): void {
            $before = $candidate->read($row, true);
            $this->assertTrue($before['missing']);
            $this->assertTrue($candidate->publish($row, $before, $context));
        });
        $published = $this->getJson('/api/v0.5/articles/'.$source->slug.'?org_id=0&locale=zh-TW')
            ->assertOk()->assertJsonPath('article.locale', 'zh-TW')
            ->assertJsonPath('article.content_md', '## 繁體正文')->assertJsonPath('article.is_indexable', false);
        if ($useStagingTaxonomy) {
            $published->assertJsonPath('article.category.slug', 'career-exploration')
                ->assertJsonPath('article.tags.0.slug', 'riasec')
                ->assertJsonPath('article.author_name', null)
                ->assertJsonPath('answer_surface_v1.faq_blocks', $row['snapshot']['editorial_projection']['faq'])
                ->assertJsonCount(1, 'landing_surface_v1.cta_bundle');
            foreach ($row['snapshot']['editorial_projection']['ctas'][0] as $key => $value) {
                $published->assertJsonPath('landing_surface_v1.cta_bundle.0.'.$key, $value);
            }
        }
        DB::transaction(function () use ($candidate, $row): void {
            $candidate->restore($row, $candidate->read($row, true));
            $this->assertTrue($candidate->read($row, true)['retained_draft']);
        });
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?org_id=0&locale=zh-TW')->assertNotFound();

        $target = Article::withoutGlobalScopes()->where($row['identity'])->sole();
        $retained = $target->workingRevision;
        $retained->updateQuietly(['title' => '另有編輯的標題']);
        try {
            DB::transaction(fn () => $candidate->publish($row, $candidate->read($row, true), $context));
            $this->fail('The publisher accepted an edited retained draft.');
        } catch (DomainException $exception) {
            $this->assertSame('traditional_article_retained_draft_changed', $exception->getMessage());
        }
        $this->assertSame('另有編輯的標題', $retained->fresh()->title);
        $retained->updateQuietly(['title' => $row['snapshot']['title']]);
        $this->assertFalse(DB::transaction(fn () => $candidate->publish($row, $candidate->read($row, true), $context)));
        $this->assertSame($retained->id, $target->fresh()->published_revision_id);
        $this->assertSame(1, ArticleTranslationRevision::withoutGlobalScopes()->where('article_id', $target->id)->count());
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?org_id=0&locale=zh-TW')->assertOk();
        DB::transaction(fn () => $candidate->restore($row, $candidate->read($row, true)));
        $this->getJson('/api/v0.5/articles/'.$source->slug.'?org_id=0&locale=zh-TW')->assertNotFound();

        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($englishBefore, $english->fresh()->getAttributes());

        $this->createArticle(['locale' => 'zh-TW', 'slug' => $source->slug.'-collision',
            'translation_group_id' => $source->translation_group_id], withPublishedRevision: false);
        $this->expectExceptionMessage('target_identity_collision');
        $guard->assertTargetVacant($source);
    }

    private function traditionalArticleReaderProof(Article $source): array
    {
        $public = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale='.$source->locale)->assertOk()->json('article');

        return [
            'slug' => $source->slug, 'locale' => $source->locale, 'id' => $source->id,
            'published_revision_id' => $source->published_revision_id, 'translation_group_id' => $source->translation_group_id,
            'is_indexable' => (bool) $source->is_indexable, 'sitemap_eligible' => (bool) $source->sitemap_eligible,
            'hreflang_gate' => null,
            'snapshot_sha256' => array_map(
                static fn (?string $value): ?string => $value === null ? null : hash('sha256', $value),
                array_intersect_key($public, array_flip(['title', 'excerpt', 'content_md', 'author_name', 'cover_image_alt']))
                    + array_intersect_key($public['seo_meta'], array_flip(['seo_title', 'seo_description', 'og_title', 'og_description'])),
            ),
        ];
    }

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
