<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Support\ContentReleaseFollowUp;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Cms\ArticlePublishService;
use App\Services\Cms\ContentReleasePathPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ContentReleasePathPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_zh_article_default_and_graph_driven_paths(): void
    {
        $article = $this->articleWithSeoMeta('zh-CN', [
            'target_topics' => ['mbti'],
            'target_tests' => ['mbti-personality-test-16-personality-types'],
            'target_personality_pages' => ['infj-a', '/zh/personality/infj-t'],
            'target_career_pages' => ['/zh/career/guides/how-to-find-right-career-direction'],
            'graph_edges' => [
                'from_article_to_topic' => ['big-five'],
                'from_article_to_test' => ['big-five-personality-test'],
                'from_article_to_career' => ['career-decision-guide'],
            ],
            'recommended_reverse_links' => [
                'career_guide' => ['how-to-find-right-career-direction'],
                'homepage' => ['recommended_articles'],
            ],
        ]);

        $paths = app(ContentReleasePathPlanner::class)->paths('article', $article);

        $this->assertSame($paths, array_values(array_unique($paths)));
        $this->assertContains('/zh', $paths);
        $this->assertContains('/zh/articles', $paths);
        $this->assertContains('/zh/articles/content-release-article', $paths);
        $this->assertContains('/zh/topics/mbti', $paths);
        $this->assertContains('/zh/topics/big-five', $paths);
        $this->assertContains('/zh/tests/mbti-personality-test-16-personality-types', $paths);
        $this->assertContains('/zh/tests/big-five-personality-test', $paths);
        $this->assertContains('/zh/personality/infj-a', $paths);
        $this->assertContains('/zh/personality/infj-t', $paths);
        $this->assertContains('/zh/career/guides/how-to-find-right-career-direction', $paths);
        $this->assertContains('/zh/career/guides/career-decision-guide', $paths);
        $this->assertContains('/llms.txt', $paths);
        $this->assertContains('/llms-full.txt', $paths);
    }

    public function test_plans_en_article_default_paths(): void
    {
        $article = $this->articleWithSeoMeta('en', []);

        $paths = app(ContentReleasePathPlanner::class)->paths('article', $article);

        $this->assertContains('/en', $paths);
        $this->assertContains('/en/articles', $paths);
        $this->assertContains('/en/articles/content-release-article', $paths);
        $this->assertContains('/llms.txt', $paths);
        $this->assertContains('/llms-full.txt', $paths);
        $this->assertNotContains('/zh/articles/content-release-article', $paths);
    }

    public function test_follow_up_audits_missing_cache_config_instead_of_silent_skip(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', []);
        config()->set('ops.content_release_observability.cache_invalidation_secret', '');
        Http::fake();

        $article = $this->persistedArticleWithSeoMeta('zh-CN');

        ContentReleaseFollowUp::dispatch('article', $article, 'test_publish', Request::create('/ops/content-release', 'POST'));

        Http::assertNothingSent();

        $audit = AuditLog::query()
            ->withoutGlobalScopes()
            ->where('action', 'content_release_cache_config_missing')
            ->where('target_type', 'article')
            ->where('target_id', (string) $article->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('failed', $audit->result);
        $this->assertTrue((bool) data_get($audit->meta_json, 'missing_cache_invalidation_urls'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'missing_cache_invalidation_secret'));
        $this->assertContains('/zh/articles/content-release-article', (array) data_get($audit->meta_json, 'planned_paths', []));
    }

    public function test_follow_up_posts_planned_paths_to_each_configured_endpoint(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache-one.example.test/api/content-release/revalidate',
            'https://cache-two.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache-one.example.test/*' => Http::response(['ok' => true], 200),
            'https://cache-two.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $article = $this->persistedArticleWithSeoMeta('en', [
            'target_topics' => ['mbti'],
        ]);

        ContentReleaseFollowUp::dispatch('article', $article, 'test_publish', Request::create('/ops/content-release', 'POST'));

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return in_array($request->url(), [
                'https://cache-one.example.test/api/content-release/revalidate',
                'https://cache-two.example.test/api/content-release/revalidate',
            ], true)
                && in_array('/en/articles/content-release-article', $paths, true)
                && in_array('/en/topics/mbti', $paths, true)
                && in_array('/llms.txt', $paths, true)
                && $request->hasHeader('X-FM-Content-Release-Token');
        });
    }

    public function test_article_publish_service_triggers_content_release_follow_up(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $article = $this->persistedArticleWithSeoMeta('zh-CN');
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'title' => 'Content Release Article',
            'excerpt' => 'Release excerpt',
            'content_md' => 'Release body',
            'seo_title' => 'Release SEO title',
            'seo_description' => 'Release SEO description',
        ]);
        $article->forceFill(['working_revision_id' => (int) $revision->id])->save();

        app(ArticlePublishService::class)->publishArticle((int) $article->id);

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame((int) $revision->id, (int) $article->published_revision_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'content_release_publish',
            'target_type' => 'article',
            'target_id' => (string) $article->id,
        ]);

        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://cache.example.test/api/content-release/revalidate'
                && in_array('/zh/articles/content-release-article', $paths, true)
                && in_array('/llms-full.txt', $paths, true);
        });
    }

    /**
     * @param  array<string, mixed>  $editorialMetadata
     */
    private function articleWithSeoMeta(string $locale, array $editorialMetadata): Article
    {
        $article = new Article([
            'org_id' => 0,
            'slug' => 'content-release-article',
            'locale' => $locale,
            'title' => 'Content Release Article',
            'excerpt' => 'Release excerpt',
            'content_md' => 'Release body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $article->setRelation('seoMeta', new ArticleSeoMeta([
            'schema_json' => [
                'editorial_package_v1' => $editorialMetadata,
            ],
        ]));

        return $article;
    }

    /**
     * @param  array<string, mixed>  $editorialMetadata
     */
    private function persistedArticleWithSeoMeta(string $locale, array $editorialMetadata = []): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => 'content-release-article',
            'locale' => $locale,
            'title' => 'Content Release Article',
            'excerpt' => 'Release excerpt',
            'content_md' => 'Release body',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
        ]);

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => 'Release SEO title',
            'seo_description' => 'Release SEO description',
            'canonical_url' => "https://example.test/{$locale}/articles/content-release-article",
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => $editorialMetadata,
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['seoMeta']) ?? $article;
    }
}
