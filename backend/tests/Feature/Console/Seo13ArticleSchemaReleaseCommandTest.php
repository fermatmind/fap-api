<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Cms\Seo13ArticleSchemaReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

final class Seo13ArticleSchemaReleaseCommandTest extends TestCase
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
        config(['app.frontend_url' => 'https://fermatmind.com']);
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('articles:seo13-schema-release', Artisan::all());
    }

    public function test_committed_package_markdown_exposes_bounded_visible_faq_for_all_thirteen_targets(): void
    {
        $root = dirname(__DIR__, 3).'/docs/seo/import-packages/seo-13-article-refresh-2026-07-26';
        $lock = json_decode((string) file_get_contents($root.'/cohort.lock.json'), true);
        $this->assertIsArray($lock);
        $this->assertCount(13, (array) ($lock['packages'] ?? []));

        $parser = new ReflectionMethod(Seo13ArticleSchemaReleaseService::class, 'visibleFaqItems');
        $service = app(Seo13ArticleSchemaReleaseService::class);
        $seen = [];
        foreach ((array) $lock['packages'] as $package) {
            $articleId = (int) ($package['article_id'] ?? 0);
            $page = collect((array) ($package['files'] ?? []))
                ->pluck('path')
                ->first(static fn (mixed $path): bool => is_string($path) && str_contains($path, '/pages/'));
            $this->assertIsString($page);
            $markdown = file_get_contents($root.'/'.$page);
            $this->assertIsString($markdown);
            $faqItems = $parser->invoke($service, $markdown);
            $this->assertIsArray($faqItems);
            $this->assertGreaterThanOrEqual(4, count($faqItems), 'article '.$articleId);
            $this->assertLessThanOrEqual(8, count($faqItems), 'article '.$articleId);
            foreach ($faqItems as $item) {
                $this->assertNotSame('', trim((string) ($item['question'] ?? '')));
                $this->assertNotSame('', trim((string) ($item['answer'] ?? '')));
            }
            $seen[] = $articleId;
        }
        sort($seen);
        $this->assertSame(array_column(self::TARGETS, 0), $seen);
    }

    public function test_dry_run_binds_exact_published_content_and_visible_faq_without_writes(): void
    {
        $this->createCohort();
        $before = $this->authorityState();

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['production_write_execution']);
        $this->assertSame(13, $payload['target_count']);
        $this->assertSame(13, $payload['held_count']);
        $this->assertSame(0, $payload['released_count']);
        $this->assertTrue($payload['apply_supported']);
        $this->assertFalse($payload['readback_complete']);
        foreach (['state_sha256', 'content_set_sha256', 'target_set_sha256'] as $field) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload[$field]);
        }
        $this->assertSame(array_fill(0, 13, 4), array_column($payload['rows'], 'faq_count'));
        $this->assertSame(array_fill(0, 13, 4), array_column($payload['rows'], 'planned_json_ld_faq_count'));
        $this->assertStringNotContainsString('可见回答', Artisan::output());
        $this->assertSame($before, $this->authorityState());
    }

    public function test_execute_releases_all_three_schema_gates_atomically_and_preserves_other_surfaces(): void
    {
        $this->createCohort();
        $before = $this->authorityState();
        $preflight = $this->preflight();

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-content-set-sha256' => $preflight['content_set_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--no-publish' => true,
            '--no-hreflang' => true,
            '--no-revalidation' => true,
            '--no-sitemap-llms-change' => true,
            '--no-search' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['production_write_execution']);
        $this->assertSame(13, $payload['schema_write_count']);
        $this->assertSame(2, $payload['revision_authority_write_count']);
        $this->assertSame(2, $payload['revision_write_count']);
        $this->assertSame(13, $payload['article_schema_enabled_count']);
        $this->assertSame(13, $payload['breadcrumb_schema_enabled_count']);
        $this->assertSame(13, $payload['faq_schema_enabled_count']);
        $this->assertSame(1, $payload['audit_write_count']);
        $this->assertSame(0, $payload['held_count']);
        $this->assertSame(13, $payload['released_count']);
        $this->assertTrue($payload['readback_complete']);
        foreach ([
            'article_body_write_count',
            'publication_write_count',
            'indexability_write_count',
            'hreflang_write_count',
            'revalidation_count',
            'sitemap_eligibility_write_count',
            'llms_eligibility_write_count',
            'sitemap_cache_refresh_count',
            'llms_cache_refresh_count',
            'search_submission_count',
            'gsc_request_count',
            'url_inspection_count',
            'queue_dispatch_count',
            'deploy_count',
        ] as $field) {
            $this->assertSame(0, $payload[$field], $field);
        }

        $after = $this->authorityState();
        foreach (self::TARGETS as [$articleId]) {
            $this->assertSame($before[$articleId]['body_sha256'], $after[$articleId]['body_sha256']);
            $this->assertSame($before[$articleId]['published_revision_id'], $after[$articleId]['published_revision_id']);
            $this->assertSame($before[$articleId]['sitemap_eligible'], $after[$articleId]['sitemap_eligible']);
            $this->assertSame($before[$articleId]['llms_eligible'], $after[$articleId]['llms_eligible']);
            $this->assertSame($before[$articleId]['is_indexable'], $after[$articleId]['is_indexable']);

            $seoMeta = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', $articleId)->firstOrFail();
            $this->assertTrue((bool) data_get($seoMeta->schema_json, 'editorial_package_v1.article_schema_enabled'));
            $this->assertTrue((bool) data_get($seoMeta->schema_json, 'editorial_package_v1.breadcrumb_schema_enabled'));
            $this->assertTrue((bool) data_get($seoMeta->schema_json, 'editorial_package_v1.faq_schema_enabled'));
            $this->assertSame('editor_supplied', data_get($seoMeta->schema_json, 'editorial_package_v1.answer_surface_policy'));
            $this->assertCount(4, (array) data_get($seoMeta->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
            $this->assertFalse((bool) data_get($seoMeta->schema_json, 'editorial_package_v1.hreflang_gate_v1.enabled', false));
        }
        foreach ([1, 2] as $articleId) {
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('article_id', $articleId)
                ->where('revision_status', ArticleTranslationRevision::STATUS_PUBLISHED)
                ->firstOrFail();
            $this->assertSame(42, (int) $revision->created_by);
            $this->assertSame(
                'admin_user:42',
                data_get($revision->authority_metadata_json, 'visible_provenance.author.identity'),
            );
            $this->assertSame(
                'published',
                data_get($revision->authority_metadata_json, 'visible_provenance.reviewer.review_state'),
            );
            $this->assertNotEmpty(data_get($revision->authority_metadata_json, 'visible_provenance.sources'));
        }
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_article_schema_release')
            ->count());
    }

    public function test_thirteenth_target_failure_keeps_first_twelve_unwritten(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        Article::query()->withoutGlobalScopes()->whereKey(16)->update(['slug' => 'drifted']);

        $exitCode = $this->execute($preflight);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()
            ->whereNotNull('schema_json')
            ->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_article_schema_release')
            ->count());
    }

    public function test_missing_visible_faq_blocks_entire_batch(): void
    {
        $this->createCohort();
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->findOrFail(435);
        $body = str_replace('### 问题四？', '#### 问题四？', (string) $revision->content_md);
        $revision->forceFill(['content_md' => $body])->saveQuietly();
        Article::query()->withoutGlobalScopes()->whereKey(16)->update(['content_md' => $body]);

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('visible_faq_count_out_of_bounds', array_column($payload['errors'], 'code'));
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->whereNotNull('schema_json')->count());
    }

    public function test_schema_state_drift_after_preflight_fails_closed(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 15)->update([
            'schema_json' => ['editorial_package_v1' => ['faq_schema_enabled' => false]],
        ]);

        $exitCode = $this->execute($preflight);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('expected_state_sha256_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_article_schema_release')
            ->count());
    }

    public function test_execute_requires_every_downstream_hold(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-content-set-sha256' => $preflight['content_set_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--no-publish' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('required_hold_missing', array_column($payload['errors'], 'code'));
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->whereNotNull('schema_json')->count());
    }

    public function test_completed_release_is_idempotent_but_not_applyable_again(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        $this->assertSame(0, $this->execute($preflight), Artisan::output());

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['held_count']);
        $this->assertSame(13, $payload['released_count']);
        $this->assertFalse($payload['apply_supported']);
        $this->assertTrue($payload['readback_complete']);
    }

    public function test_completed_release_fails_closed_when_big_five_authority_actor_drifts(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        $this->assertSame(0, $this->execute($preflight), Artisan::output());
        ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereKey(446)
            ->update(['created_by' => 99]);

        $exitCode = Artisan::call('articles:seo13-schema-release', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('big_five_authority_metadata_drift', array_column($payload['errors'], 'code'));
        $this->assertFalse($payload['readback_complete']);
    }

    /**
     * @param  array<string,mixed>  $preflight
     */
    private function execute(array $preflight): int
    {
        return Artisan::call('articles:seo13-schema-release', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-content-set-sha256' => $preflight['content_set_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--no-publish' => true,
            '--no-hreflang' => true,
            '--no-revalidation' => true,
            '--no-sitemap-llms-change' => true,
            '--no-search' => true,
            '--json' => true,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(): array
    {
        Artisan::call('articles:seo13-schema-release', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        return $this->jsonOutput();
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
                'category_id' => null,
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
                'content_html' => null,
                'cover_image_url' => 'https://fermatmind.com/media/article-'.$articleId.'.webp',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => $publishedAt,
                'scheduled_at' => null,
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
                'supersedes_revision_id' => null,
                'title' => $title,
                'excerpt' => $excerpt,
                'content_md' => $body,
                'seo_title' => $title,
                'seo_description' => $excerpt,
                'authority_metadata_json' => null,
                'created_by' => null,
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
                'og_image_url' => 'https://fermatmind.com/media/article-'.$articleId.'.webp',
                'robots' => 'index,follow',
                'schema_json' => null,
                'is_indexable' => true,
            ]);
        }
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function authorityState(): array
    {
        $state = [];
        foreach (self::TARGETS as [$articleId]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $state[$articleId] = [
                'body_sha256' => hash('sha256', (string) $article->content_md),
                'published_revision_id' => (int) $article->published_revision_id,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'schema_json' => ArticleSeoMeta::query()->withoutGlobalScopes()
                    ->where('article_id', $articleId)
                    ->value('schema_json'),
            ];
        }

        return $state;
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
