<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ContentReleaseRevalidateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_article_paths_without_posting_or_outputting_token(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake();

        $article = $this->articleWithSeoMeta('zh-CN', [
            'target_topics' => ['mbti'],
        ]);

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article',
            '--article-id' => (string) $article->id,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $rawOutput = Artisan::output();
        $payload = $this->jsonOutput($rawOutput);

        $this->assertSame(0, $exitCode, $rawOutput);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame('would_revalidate_content_release_paths', $payload['action'] ?? null);
        $this->assertContains('/zh/articles/content-release-article', $payload['paths'] ?? []);
        $this->assertContains('/zh/topics/mbti', $payload['paths'] ?? []);
        $this->assertContains('/llms.txt', $payload['paths'] ?? []);
        $this->assertContains('/llms-full.txt', $payload['paths'] ?? []);
        $this->assertSame(1, $payload['endpoint_count'] ?? null);
        $this->assertTrue((bool) ($payload['token_present'] ?? false));
        $this->assertFalse((bool) ($payload['token_output'] ?? true));
        $this->assertFalse((bool) ($payload['search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['live_submission_attempted'] ?? true));
        $this->assertStringNotContainsString('release-secret', $rawOutput);
        $this->assertStringNotContainsString('cache.example.test', $rawOutput);

        Http::assertNothingSent();
    }

    public function test_execute_dispatches_revalidation_without_outputting_token(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $article = $this->articleWithSeoMeta('en', [
            'target_topics' => ['big-five'],
        ]);

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article',
            '--article-id' => (string) $article->id,
            '--source' => 'manual_revalidate',
            '--execute' => true,
            '--json' => true,
        ]);

        $rawOutput = Artisan::output();
        $payload = $this->jsonOutput($rawOutput);

        $this->assertSame(0, $exitCode, $rawOutput);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['dry_run'] ?? true));
        $this->assertSame('revalidation_dispatched', $payload['action'] ?? null);
        $this->assertSame(1, $payload['endpoint_count'] ?? null);
        $this->assertTrue((bool) ($payload['token_present'] ?? false));
        $this->assertFalse((bool) ($payload['token_output'] ?? true));
        $this->assertStringNotContainsString('release-secret', $rawOutput);
        $this->assertStringNotContainsString('cache.example.test', $rawOutput);

        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://cache.example.test/api/content-release/revalidate'
                && $request->hasHeader('X-FM-Content-Release-Token', 'release-secret')
                && in_array('/en/articles/content-release-article', $paths, true)
                && in_array('/en/topics/big-five', $paths, true)
                && in_array('/llms-full.txt', $paths, true);
        });
    }

    public function test_dry_run_plans_article_taxonomy_paths_without_broad_article_planner_paths(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake();

        $first = $this->articleWithSeoMeta('zh-CN', [
            'target_topics' => ['mbti'],
            'target_tests' => ['mbti-personality-test-16-personality-types'],
        ], 'taxonomy-first');
        $second = $this->articleWithSeoMeta('zh-CN', [
            'target_topics' => ['riasec'],
            'target_tests' => ['holland-career-interest-test-riasec'],
        ], 'taxonomy-second');

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => $first->id.','.$second->id,
            '--expected-slugs' => 'taxonomy-first,taxonomy-second',
            '--include-index' => '/zh/articles',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $rawOutput = Artisan::output();
        $payload = $this->jsonOutput($rawOutput);

        $this->assertSame(0, $exitCode, $rawOutput);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['dry_run'] ?? false));
        $this->assertSame('article-taxonomy', $payload['type'] ?? null);
        $this->assertSame('taxonomy_only', $payload['allowed_path_scope'] ?? null);
        $this->assertSame([
            '/zh/articles',
            '/zh/articles/taxonomy-first',
            '/zh/articles/taxonomy-second',
        ], $payload['paths'] ?? []);
        $this->assertContains('llms', $payload['excluded_path_classes'] ?? []);
        $this->assertContains('topics', $payload['excluded_path_classes'] ?? []);
        $this->assertContains('tests', $payload['excluded_path_classes'] ?? []);
        $this->assertNotContains('/zh', $payload['paths'] ?? []);
        $this->assertNotContains('/llms.txt', $payload['paths'] ?? []);
        $this->assertNotContains('/llms-full.txt', $payload['paths'] ?? []);
        $this->assertNotContains('/zh/topics/mbti', $payload['paths'] ?? []);
        $this->assertNotContains('/zh/tests/mbti-personality-test-16-personality-types', $payload['paths'] ?? []);
        $this->assertFalse((bool) ($payload['external_search_submission_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['schema_hreflang_write_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['sitemap_llms_mutation_attempted'] ?? true));
        $this->assertFalse((bool) ($payload['token_output'] ?? true));
        $this->assertStringNotContainsString('release-secret', $rawOutput);
        $this->assertStringNotContainsString('cache.example.test', $rawOutput);

        Http::assertNothingSent();
    }

    public function test_execute_dispatches_article_taxonomy_paths_without_broad_article_planner_paths(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $article = $this->articleWithSeoMeta('zh-CN', [
            'target_topics' => ['mbti'],
        ], 'taxonomy-execute');

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-execute',
            '--include-index' => '/zh/articles',
            '--source' => 'article_taxonomy_hygiene_20260618',
            '--execute' => true,
            '--json' => true,
        ]);

        $rawOutput = Artisan::output();
        $payload = $this->jsonOutput($rawOutput);

        $this->assertSame(0, $exitCode, $rawOutput);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertFalse((bool) ($payload['dry_run'] ?? true));
        $this->assertSame('taxonomy_only_revalidation_dispatched', $payload['action'] ?? null);
        $this->assertSame([
            '/zh/articles',
            '/zh/articles/taxonomy-execute',
        ], $payload['paths'] ?? []);
        $this->assertStringNotContainsString('release-secret', $rawOutput);
        $this->assertStringNotContainsString('cache.example.test', $rawOutput);

        Http::assertSent(function ($request) use ($article): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://cache.example.test/api/content-release/revalidate'
                && $request->hasHeader('X-FM-Content-Release-Token', 'release-secret')
                && data_get($request->data(), 'event') === 'content_release_revalidate'
                && data_get($request->data(), 'content.type') === 'article-taxonomy'
                && data_get($request->data(), 'content.article_ids') === [(int) $article->id]
                && data_get($request->data(), 'content.path_scope') === 'taxonomy_only'
                && $paths === ['/zh/articles', '/zh/articles/taxonomy-execute'];
        });
    }

    public function test_article_taxonomy_blocks_slug_lock_mismatch_without_posting(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake();

        $article = $this->articleWithSeoMeta('zh-CN', [], 'taxonomy-lock');

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'wrong-slug',
            '--include-index' => '/zh/articles',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame('will_skip', $payload['action'] ?? null);
        $this->assertContains('expected_slug_mismatch', $payload['issues'] ?? []);

        Http::assertNothingSent();
    }

    public function test_execute_blocks_when_revalidation_token_or_endpoint_config_is_missing(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', []);
        config()->set('ops.content_release_observability.cache_invalidation_secret', '');
        Http::fake();

        $article = $this->articleWithSeoMeta('zh-CN');

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article',
            '--article-id' => (string) $article->id,
            '--execute' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('cache_invalidation_urls_missing', $payload['issues'] ?? []);
        $this->assertContains('cache_invalidation_secret_missing', $payload['issues'] ?? []);

        Http::assertNothingSent();
    }

    /**
     * @param  array<string,mixed>  $editorialMetadata
     */
    private function articleWithSeoMeta(string $locale, array $editorialMetadata = [], string $slug = 'content-release-article'): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => $slug,
            'locale' => $locale,
            'title' => 'Content Release Article',
            'excerpt' => 'Release excerpt',
            'content_md' => 'Release body',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => now(),
        ]);

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => $locale,
            'seo_title' => 'Release SEO title',
            'seo_description' => 'Release SEO description',
            'canonical_url' => 'https://fermatmind.com/'.(str_starts_with($locale, 'zh') ? 'zh' : 'en').'/articles/'.$slug,
            'robots' => 'index,follow',
            'schema_json' => [
                'editorial_package_v1' => $editorialMetadata,
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['seoMeta']) ?? $article;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(string $rawOutput): array
    {
        $payload = json_decode(trim($rawOutput), true);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }
}
