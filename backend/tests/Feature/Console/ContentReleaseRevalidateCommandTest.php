<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
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
                && $this->hasValidRevalidationSignature($request, 'release-secret')
                && ! $request->hasHeader('X-FM-Content-Release-Token')
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
                && $this->hasValidRevalidationSignature($request, 'release-secret')
                && ! $request->hasHeader('X-FM-Content-Release-Token')
                && data_get($request->data(), 'event') === 'content_release_revalidate'
                && data_get($request->data(), 'content.type') === 'article-taxonomy'
                && data_get($request->data(), 'content.article_ids') === [(int) $article->id]
                && data_get($request->data(), 'content.path_scope') === 'taxonomy_only'
                && $paths === ['/zh/articles', '/zh/articles/taxonomy-execute'];
        });
    }

    public function test_state_locked_taxonomy_revalidation_binds_published_revision_and_content_hashes(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        config()->set('ops.content_release_observability.broadcast_webhook', 'https://broadcast.example.test/hook');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => true], 200),
            'https://broadcast.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $article = $this->articleWithSeoMeta('zh-CN', [], 'taxonomy-locked');
        $revision = $this->attachPublishedRevision($article);

        $preflightExit = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-locked',
            '--expected-published-revision-ids' => (string) $revision->id,
            '--require-state-lock' => true,
            '--include-index' => '/zh/articles',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $preflight = $this->jsonOutput(Artisan::output());

        $this->assertSame(0, $preflightExit, Artisan::output());
        $this->assertTrue((bool) ($preflight['state_lock_required'] ?? false));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($preflight['state_sha256'] ?? ''));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($preflight['content_set_sha256'] ?? ''));
        $this->assertSame(0, $preflight['cms_authority_write_count'] ?? null);
        Http::assertNothingSent();

        $applyExit = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-locked',
            '--expected-published-revision-ids' => (string) $revision->id,
            '--expected-state-sha256' => (string) $preflight['state_sha256'],
            '--expected-content-set-sha256' => (string) $preflight['content_set_sha256'],
            '--require-state-lock' => true,
            '--include-index' => '/zh/articles',
            '--execute' => true,
            '--json' => true,
        ]);
        $apply = $this->jsonOutput(Artisan::output());

        $this->assertSame(0, $applyExit, Artisan::output());
        $this->assertSame('taxonomy_only_revalidation_dispatched', $apply['action'] ?? null);
        $this->assertSame((string) $preflight['state_sha256'], $apply['state_sha256'] ?? null);
        $this->assertSame((string) $preflight['content_set_sha256'], $apply['content_set_sha256'] ?? null);
        Http::assertSentCount(1);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'broadcast.example.test'));
    }

    public function test_state_locked_taxonomy_revalidation_fails_closed_on_projection_drift(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake();

        $article = $this->articleWithSeoMeta('zh-CN', [], 'taxonomy-drift');
        $revision = $this->attachPublishedRevision($article);

        Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-drift',
            '--expected-published-revision-ids' => (string) $revision->id,
            '--require-state-lock' => true,
            '--include-index' => '/zh/articles',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $preflight = $this->jsonOutput(Artisan::output());

        $article->forceFill(['content_md' => 'drifted public projection'])->save();

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-drift',
            '--expected-published-revision-ids' => (string) $revision->id,
            '--expected-state-sha256' => (string) $preflight['state_sha256'],
            '--expected-content-set-sha256' => (string) $preflight['content_set_sha256'],
            '--require-state-lock' => true,
            '--include-index' => '/zh/articles',
            '--execute' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertContains('published_projection_content_mismatch', $payload['issues'] ?? []);
        $this->assertContains('expected_state_sha256_mismatch', $payload['issues'] ?? []);
        $this->assertContains('expected_content_set_sha256_mismatch', $payload['issues'] ?? []);
        Http::assertNothingSent();
    }

    public function test_taxonomy_revalidation_fails_closed_when_frontend_rejects_the_signal(): void
    {
        config()->set('ops.content_release_observability.cache_invalidation_urls', [
            'https://cache.example.test/api/content-release/revalidate',
        ]);
        config()->set('ops.content_release_observability.cache_invalidation_secret', 'release-secret');
        Http::fake([
            'https://cache.example.test/*' => Http::response(['ok' => false], 503),
        ]);

        $article = $this->articleWithSeoMeta('zh-CN', [], 'taxonomy-rejected');

        $exitCode = Artisan::call('content-release:revalidate', [
            '--type' => 'article-taxonomy',
            '--article-ids' => (string) $article->id,
            '--expected-slugs' => 'taxonomy-rejected',
            '--include-index' => '/zh/articles',
            '--execute' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput(Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame('will_skip', $payload['action'] ?? null);
        $this->assertContains('revalidation_dispatch_failed', $payload['issues'] ?? []);
        Http::assertSentCount(1);
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

    private function attachPublishedRevision(Article $article): ArticleTranslationRevision
    {
        $seoMeta = $article->seoMeta()->firstOrFail();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) $article->locale,
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => (string) $seoMeta->seo_title,
            'seo_description' => (string) $seoMeta->seo_description,
            'published_at' => now(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        return $revision;
    }

    private function hasValidRevalidationSignature(mixed $request, string $secret): bool
    {
        $timestamp = (string) ($request->header('X-FM-Content-Release-Timestamp')[0] ?? '');
        $nonce = (string) ($request->header('X-FM-Content-Release-Nonce')[0] ?? '');
        $signature = (string) ($request->header('X-FM-Content-Release-Signature')[0] ?? '');

        if (! preg_match('/^\d{10}$/', $timestamp) || ! preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), $secret);

        return hash_equals($expected, $signature);
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
