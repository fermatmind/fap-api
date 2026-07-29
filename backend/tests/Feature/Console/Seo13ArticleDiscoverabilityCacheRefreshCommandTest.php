<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\Seo13ArticleSchemaReleaseService;
use App\Services\SEO\SitemapCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class Seo13ArticleDiscoverabilityCacheRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = [
        [1, 'big-five-growth-guide', 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61', 446],
        [2, 'big-five-narrative-portrait', 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970', 445],
        [5, 'iq-test-growth-guide', 'article-5', 444],
        [6, 'iq-test-narrative-portrait', 'article-6', 443],
        [7, 'iq-test-tool-guide', 'article-7', 442],
        [9, 'mbti-growth-guide', 'article-9', 441],
        [10, 'mbti-narrative-portrait', 'article-10', 440],
        [11, 'are-infj-men-rare-or-socially-silenced', 'article-11', 436],
        [12, 'best-valentines-date-by-personality-and-relationship-science', 'article-12', 437],
        [13, 'childhood-dream-job-still-shapes-career-choice', 'article-13', 439],
        [14, 'how-16-personality-types-talk-to-an-ai-coach', 'article-14', 438],
        [15, 'how-personality-shapes-attitude-toward-ai', 'article-15', 434],
        [16, 'which-love-script-fits-you-best', 'article-16', 435],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'ops.content_release_observability.cache_invalidation_urls' => [
                'https://frontend.example.test/revalidate',
            ],
            'ops.content_release_observability.cache_invalidation_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://frontend.example.test/revalidate' => Http::response([
                'revalidated_paths' => ['/sitemap.xml', '/llms.txt', '/llms-full.txt'],
                'rejected_paths' => [],
            ]),
        ]);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('articles:seo13-discoverability-cache-refresh', Artisan::all());
    }

    public function test_preflight_requires_complete_schema_release_and_performs_zero_writes(): void
    {
        $this->createCohort();
        $this->publishEmptyCareerDirectories();
        Cache::put('seo:sitemap-source:v1:fresh', ['sentinel' => true]);

        Artisan::call('articles:seo13-discoverability-cache-refresh', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertFalse($payload['ok']);
        $this->assertContains('schema_release_incomplete', array_column($payload['errors'], 'code'));
        $this->assertSame(['sentinel' => true], Cache::get('seo:sitemap-source:v1:fresh'));
    }

    public function test_execute_refreshes_only_six_bounded_cache_keys_after_schema_release(): void
    {
        $this->createCohort();
        $this->releaseSchema();
        $this->publishEmptyCareerDirectories();
        $beforeAuthority = $this->authorityState();

        foreach ($this->cacheKeys() as $key) {
            Cache::put($key, 'stale-sentinel');
        }

        $preflight = $this->preflight();
        $this->assertTrue($preflight['ok']);
        $this->assertSame(13, $preflight['schema_released_count']);
        $this->assertTrue($preflight['readback_complete']);
        $this->assertTrue($preflight['apply_supported']);
        $this->assertSame(array_fill(0, 13, 1), array_column($preflight['rows'], 'sitemap_source_count'));
        $this->assertSame(array_fill(0, 13, 1), array_column($preflight['rows'], 'llms_source_count'));

        $exitCode = Artisan::call('articles:seo13-discoverability-cache-refresh', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-content-set-sha256' => $preflight['content_set_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--no-authority-change' => true,
            '--no-eligibility-change' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-deploy' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['production_write_execution']);
        $this->assertSame(6, $payload['cache_invalidation_count']);
        $this->assertSame(5, $payload['cache_warm_write_count']);
        $this->assertSame(4, $payload['sitemap_cache_refresh_count']);
        $this->assertSame(2, $payload['llms_cache_refresh_count']);
        foreach ([
            'cms_authority_write_count',
            'publication_write_count',
            'schema_write_count',
            'hreflang_write_count',
            'sitemap_eligibility_write_count',
            'llms_eligibility_write_count',
            'search_submission_count',
            'gsc_request_count',
            'url_inspection_count',
            'queue_dispatch_count',
            'deploy_count',
        ] as $field) {
            $this->assertSame(0, $payload[$field], $field);
        }
        $this->assertSame(3, $payload['frontend_revalidation_count']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $paths = (array) data_get($request->data(), 'cache_signal.paths', []);

            return $request->url() === 'https://frontend.example.test/revalidate'
                && $this->hasValidRevalidationSignature($request, 'test-secret')
                && ! $request->hasHeader('X-FM-Content-Release-Token')
                && data_get($request->data(), 'source') === 'seo13_article_discoverability_cache_refresh'
                && $paths === ['/sitemap.xml', '/llms.txt', '/llms-full.txt'];
        });
        $this->assertSame($beforeAuthority, $this->authorityState());
        $this->assertIsArray(Cache::get('seo:sitemap-source:v1:fresh'));
        $this->assertNull(Cache::get('seo:sitemap-source:v1:stale'));
        $this->assertIsString(Cache::get(SitemapCache::XML_CACHE_KEY));
        $this->assertIsString(Cache::get(SitemapCache::ETAG_CACHE_KEY));
        $this->assertIsString(Cache::get('seo:llms-txt:v1:body'));
        $this->assertIsString(Cache::get('seo:llms-full-txt:v1:body'));
    }

    public function test_execute_fails_before_cache_writes_when_authority_hash_drifts(): void
    {
        $this->createCohort();
        $this->releaseSchema();
        $this->publishEmptyCareerDirectories();
        $preflight = $this->preflight();
        Article::query()->withoutGlobalScopes()->whereKey(16)->update(['excerpt' => 'drift']);
        Cache::put('seo:llms-txt:v1:body', 'preserved-sentinel');

        $exitCode = Artisan::call('articles:seo13-discoverability-cache-refresh', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-content-set-sha256' => $preflight['content_set_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--no-authority-change' => true,
            '--no-eligibility-change' => true,
            '--no-hreflang' => true,
            '--no-search' => true,
            '--no-deploy' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('preserved-sentinel', Cache::get('seo:llms-txt:v1:body'));
    }

    private function createCohort(): void
    {
        foreach (self::TARGETS as [$articleId, $slug, $translationGroupId, $revisionId]) {
            $title = '文章 '.$articleId.' 标题';
            $excerpt = '文章 '.$articleId.' 摘要。';
            $body = $this->body($articleId);
            $publishedAt = Carbon::create(2026, 7, 27, 8, 0, 0, 'UTC');
            $reviewedAt = Carbon::create(2026, 7, 27, 7, 0, 0, 'UTC');

            $article = new Article;
            $article->forceFill([
                'id' => $articleId,
                'org_id' => 0,
                'author_admin_user_id' => 41,
                'author_name' => 'FermatMind Editorial',
                'reviewer_name' => 'Content Review Desk',
                'slug' => $slug,
                'locale' => 'zh-CN',
                'translation_group_id' => $translationGroupId,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => $title,
                'excerpt' => $excerpt,
                'content_md' => $body,
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => $publishedAt,
            ])->save();

            $revision = new ArticleTranslationRevision;
            $revision->forceFill([
                'id' => $revisionId,
                'org_id' => 0,
                'article_id' => $articleId,
                'source_article_id' => $articleId,
                'translation_group_id' => $translationGroupId,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 2,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'source_version_hash' => hash('sha256', $body),
                'translated_from_version_hash' => hash('sha256', $body),
                'title' => $title,
                'excerpt' => $excerpt,
                'content_md' => $body,
                'seo_title' => $title,
                'seo_description' => $excerpt,
                'reviewed_by' => 42,
                'reviewed_at' => $reviewedAt,
                'published_at' => $publishedAt,
            ])->save();

            $article->forceFill(['published_revision_id' => $revisionId])->saveQuietly();
            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => $articleId,
                'locale' => 'zh-CN',
                'seo_title' => $title,
                'seo_description' => $excerpt,
                'canonical_url' => 'https://fermatmind.com/zh/articles/'.$slug,
                'og_title' => $title,
                'og_description' => $excerpt,
                'robots' => 'index,follow',
                'schema_json' => null,
                'is_indexable' => true,
            ]);
        }
    }

    private function releaseSchema(): void
    {
        $service = app(Seo13ArticleSchemaReleaseService::class);
        $preflight = $service->preflight();
        $service->apply(
            (string) $preflight['state_sha256'],
            (string) $preflight['content_set_sha256'],
            (string) $preflight['target_set_sha256'],
        );
    }

    private function publishEmptyCareerDirectories(): void
    {
        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishDirectoryReadModel('en', ['items' => []]);
        $cache->publishDirectoryReadModel('zh-CN', ['items' => []]);
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(): array
    {
        Artisan::call('articles:seo13-discoverability-cache-refresh', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        return $this->jsonOutput();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function authorityState(): array
    {
        $state = [];
        foreach (self::TARGETS as [$articleId]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $seoMeta = ArticleSeoMeta::query()->withoutGlobalScopes()
                ->where('article_id', $articleId)
                ->firstOrFail();
            $state[$articleId] = [
                'published_revision_id' => (int) $article->published_revision_id,
                'body_sha256' => hash('sha256', (string) $article->content_md),
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'schema_sha256' => hash('sha256', json_encode($seoMeta->schema_json, JSON_THROW_ON_ERROR)),
            ];
        }

        return $state;
    }

    /**
     * @return list<string>
     */
    private function cacheKeys(): array
    {
        return [
            'seo:sitemap-source:v1:fresh',
            'seo:sitemap-source:v1:stale',
            SitemapCache::XML_CACHE_KEY,
            SitemapCache::ETAG_CACHE_KEY,
            'seo:llms-txt:v1:body',
            'seo:llms-full-txt:v1:body',
        ];
    }

    private function body(int $articleId): string
    {
        return <<<MARKDOWN
# 文章 {$articleId} 标题

## 快速答案

这是文章 {$articleId} 的快速答案。

## 正文

这是足够清楚的公开正文。

## 常见问题

### 问题一？

这是文章 {$articleId} 的可见回答一。

### 问题二？

这是文章 {$articleId} 的[可见回答二](https://fermatmind.com/zh/articles)。

### 问题三？

这是文章 {$articleId} 的**可见回答三**。

### 问题四？

这是文章 {$articleId} 的可见回答四。

## 参考来源

- 示例公开来源：https://example.com/source
MARKDOWN;
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
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }
}
