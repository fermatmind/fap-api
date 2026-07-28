<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\Seo13ArticleSchemaReleaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class Seo13ArticleReleaseCloseoutCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = [
        [1, 'big-five-growth-guide', 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61', 446, 341],
        [2, 'big-five-narrative-portrait', 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970', 445, 347],
        [5, 'iq-test-growth-guide', 'article-5', 444, 5],
        [6, 'iq-test-narrative-portrait', 'article-6', 443, 6],
        [7, 'iq-test-tool-guide', 'article-7', 442, 7],
        [9, 'mbti-growth-guide', 'article-9', 441, 9],
        [10, 'mbti-narrative-portrait', 'article-10', 440, 10],
        [11, 'are-infj-men-rare-or-socially-silenced', 'article-11', 436, 30],
        [12, 'best-valentines-date-by-personality-and-relationship-science', 'article-12', 437, 31],
        [13, 'childhood-dream-job-still-shapes-career-choice', 'article-13', 439, 32],
        [14, 'how-16-personality-types-talk-to-an-ai-coach', 'article-14', 438, 33],
        [15, 'how-personality-shapes-attitude-toward-ai', 'article-15', 434, 34],
        [16, 'which-love-script-fits-you-best', 'article-16', 435, 35],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'seo_intel.connection' => 'sqlite',
            'ops.content_release_observability.cache_invalidation_urls' => ['https://frontend.example.test/revalidate'],
            'ops.content_release_observability.cache_invalidation_secret' => 'test-secret',
        ]);
        $this->createSearchHoldTables();
        $this->createReleasedCohort();
    }

    public function test_command_emits_complete_read_only_batch_closeout(): void
    {
        $this->assertArrayHasKey('articles:seo13-release-closeout', Artisan::all());

        $exitCode = Artisan::call('articles:seo13-release-closeout', ['--json' => true]);
        $payload = $this->jsonOutput();

        $this->assertSame(
            0,
            $exitCode,
            (string) json_encode($payload['errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $this->assertTrue($payload['ok']);
        $this->assertSame('SEO13_RELEASE_CLOSEOUT_COMPLETE_MONITORING_PENDING', $payload['decision']);
        $this->assertSame(13, $payload['target_count']);
        $this->assertSame(13, $payload['schema_released_count']);
        $this->assertSame(13, $payload['hreflang_held_count']);
        $this->assertSame(['D1', 'D7', 'D14', 'D28'], $payload['monitoring_windows']);
        $this->assertTrue($payload['search_hold']['ok']);
        $this->assertTrue($payload['cannibalization']['ok']);
        $this->assertSame(array_fill(0, 13, true), array_column($payload['rows'], 'old_revision_traceable_and_stale'));
        $this->assertSame(array_fill(0, 13, true), array_column($payload['rows'], 'editorial_completeness_ok'));
        $this->assertSame(array_fill(0, 13, 'held'), array_column($payload['rows'], 'hreflang_state'));
        foreach ([
            'cms_authority_write_count',
            'database_authority_write_count',
            'publication_write_count',
            'schema_write_count',
            'hreflang_write_count',
            'revalidation_count',
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
    }

    public function test_closeout_fails_closed_when_old_revision_or_search_hold_drifts(): void
    {
        ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey(35)->update([
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
        ]);
        \DB::connection('sqlite')->table('seo_search_channel_queue_items')->insert([
            'canonical_url' => 'https://fermatmind.com/zh/articles/which-love-script-fits-you-best',
        ]);

        $exitCode = Artisan::call('articles:seo13-release-closeout', ['--json' => true]);
        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('old_published_revision_not_stale_or_traceable', $codes);
        $this->assertContains('seo_search_channel_queue_items_not_zero', $codes);
    }

    public function test_closeout_blocks_global_title_and_canonical_conflicts(): void
    {
        $source = Article::query()->withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail(16);
        $duplicate = $source->replicate();
        $duplicate->forceFill([
            'id' => 99,
            'slug' => 'conflicting-article',
            'translation_group_id' => 'article-99',
            'published_revision_id' => null,
        ])->save();
        $revision = $source->publishedRevision->replicate();
        $revision->forceFill([
            'id' => 999,
            'article_id' => 99,
            'source_article_id' => 99,
            'translation_group_id' => 'article-99',
        ])->save();
        $duplicate->forceFill(['published_revision_id' => 999])->saveQuietly();
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'article_id' => 99,
            'seo_title' => (string) $source->publishedRevision->seo_title,
            'seo_description' => (string) $source->publishedRevision->seo_description,
            'canonical_url' => (string) $source->seoMeta->canonical_url,
            'og_title' => (string) $source->publishedRevision->seo_title,
            'og_description' => (string) $source->publishedRevision->seo_description,
            'robots' => 'index,follow',
            'is_indexable' => true,
        ]);

        Artisan::call('articles:seo13-release-closeout', ['--json' => true]);
        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertFalse($payload['ok']);
        $this->assertContains('exact_title_duplicate', $codes);
        $this->assertContains('exact_seo_description_duplicate', $codes);
        $this->assertContains('canonical_conflict', $codes);
    }

    private function createSearchHoldTables(): void
    {
        Schema::connection('sqlite')->create('seo_search_channel_queue_items', function (Blueprint $table): void {
            $table->id();
            $table->text('canonical_url');
        });
        foreach (['seo_indexnow_submissions', 'seo_baidu_push_logs'] as $tableName) {
            Schema::connection('sqlite')->create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->char('canonical_url_hash', 64);
            });
        }
    }

    private function createReleasedCohort(): void
    {
        foreach (self::TARGETS as [$articleId, $slug, $translationGroupId, $revisionId, $oldRevisionId]) {
            $title = '独立主题 '.$slug.' 深度说明';
            $excerpt = '文章 '.$articleId.' 独立主题摘要。';
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

            foreach ([
                [$oldRevisionId, 1, ArticleTranslationRevision::STATUS_STALE, null],
                [$revisionId, 2, ArticleTranslationRevision::STATUS_PUBLISHED, $oldRevisionId],
            ] as [$id, $number, $status, $supersedes]) {
                $revision = new ArticleTranslationRevision;
                $revision->forceFill([
                    'id' => $id,
                    'org_id' => 0,
                    'article_id' => $articleId,
                    'source_article_id' => $articleId,
                    'translation_group_id' => $translationGroupId,
                    'locale' => 'zh-CN',
                    'source_locale' => 'zh-CN',
                    'revision_number' => $number,
                    'revision_status' => $status,
                    'supersedes_revision_id' => $supersedes,
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
            }

            $article->forceFill(['published_revision_id' => $revisionId])->saveQuietly();
            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'article_id' => $articleId,
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

        $service = app(Seo13ArticleSchemaReleaseService::class);
        $preflight = $service->preflight();
        $service->apply(
            (string) $preflight['state_sha256'],
            (string) $preflight['content_set_sha256'],
            (string) $preflight['target_set_sha256'],
        );
        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        $careerCache->publishDirectoryReadModel('en', ['items' => []]);
        $careerCache->publishDirectoryReadModel('zh-CN', ['items' => []]);
    }

    private function body(int $articleId): string
    {
        $paragraph = str_repeat(
            '这是一段用于验证公开文章完整性的中文内容，说明测量结果应作为可复盘的工作假设，并结合情境、行动和反馈持续修正。',
            45,
        );

        return <<<MARKDOWN
# 文章 {$articleId} 独立主题标题

## 快速答案

这是文章 {$articleId} 的快速答案。

## 正文

{$paragraph}

## 常见问题

### 问题一？

这是文章 {$articleId} 的可见回答一。

### 问题二？

这是文章 {$articleId} 的可见回答二。

### 问题三？

这是文章 {$articleId} 的可见回答三。

### 问题四？

这是文章 {$articleId} 的可见回答四。

## 参考来源

- 示例公开来源：https://example.com/source/{$articleId}
MARKDOWN;
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload, Artisan::output());

        return $payload;
    }
}
