<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ArticleIqMethodPagesSeoGeoActivate;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class ArticleIqMethodPagesSeoGeoActivateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(ArticleIqMethodPagesSeoGeoActivate::class));
    }

    public function test_dry_run_plans_iq_method_discoverability_activation_without_writes(): void
    {
        $article = $this->createPublishedNoindexIqMethodArticle();

        [$exit, $payload] = $this->callActivate([
            '--article-id' => (string) $article->id,
            '--expected-slug' => 'what-is-iq-style-reasoning-test',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['would_write']);
        $this->assertSame('would_activate_iq_method_article_discoverability', $payload['action']);
        $this->assertFalse(data_get($payload, 'plan.before.is_indexable'));
        $this->assertSame('noindex,follow', data_get($payload, 'plan.before.seo_robots'));
        $this->assertTrue(data_get($payload, 'plan.after.is_indexable'));
        $this->assertSame('index,follow', data_get($payload, 'plan.after.seo_robots'));
        $this->assertFalse($payload['external_search_submission_attempted']);
        $this->assertFalse($payload['schema_hreflang_write_attempted']);

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
        $this->assertFalse((bool) $fresh->is_indexable);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
        $this->assertSame('noindex,follow', (string) $fresh->seoMeta?->robots);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_iq_method_pages_seo_geo_activate')->count());
    }

    public function test_execute_activates_only_indexability_sitemap_llms_and_robots(): void
    {
        $article = $this->createPublishedNoindexIqMethodArticle();
        $contentHash = hash('sha256', (string) $article->content_md);
        $publishedRevisionId = (int) $article->published_revision_id;
        $schemaHash = hash('sha256', (string) json_encode($article->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        [$exit, $payload] = $this->callActivate([
            '--article-id' => (string) $article->id,
            '--expected-slug' => 'what-is-iq-style-reasoning-test',
            '--confirm' => 'I explicitly approve articles:iq-method-pages-seo-geo-activate execute for article id '.(int) $article->id.' slug what-is-iq-style-reasoning-test after activation gate passes.',
            '--execute' => true,
            '--json' => true,
            '--no-content-change' => true,
            '--no-publish' => true,
            '--no-search' => true,
            '--no-schema-hreflang' => true,
            '--no-revalidation' => true,
        ]);

        $this->assertSame(0, $exit, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['would_write']);
        $this->assertSame('activated_iq_method_article_discoverability', $payload['action']);

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
        $this->assertSame('published', (string) $fresh->status);
        $this->assertTrue((bool) $fresh->is_public);
        $this->assertTrue((bool) $fresh->is_indexable);
        $this->assertTrue((bool) $fresh->sitemap_eligible);
        $this->assertTrue((bool) $fresh->llms_eligible);
        $this->assertSame('index,follow', (string) $fresh->seoMeta?->robots);
        $this->assertTrue((bool) $fresh->seoMeta?->is_indexable);
        $this->assertSame($contentHash, hash('sha256', (string) $fresh->content_md));
        $this->assertSame($publishedRevisionId, (int) $fresh->published_revision_id);
        $this->assertSame($schemaHash, hash('sha256', (string) json_encode($fresh->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_iq_method_pages_seo_geo_activate')->first();
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame('article', (string) $audit->target_type);
        $this->assertSame((string) $article->id, (string) $audit->target_id);
        $this->assertSame('articles:iq-method-pages-seo-geo-activate', data_get($audit->meta_json, 'command'));
        $this->assertSame([
            'articles.is_indexable',
            'articles.sitemap_eligible',
            'articles.llms_eligible',
            'article_seo_meta.is_indexable',
            'article_seo_meta.robots',
        ], data_get($audit->meta_json, 'updates_scope'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_search'));
    }

    public function test_execute_requires_confirmation_and_all_safety_flags(): void
    {
        $article = $this->createPublishedNoindexIqMethodArticle();

        [$exit, $payload] = $this->callActivate([
            '--article-id' => (string) $article->id,
            '--expected-slug' => 'what-is-iq-style-reasoning-test',
            '--execute' => true,
            '--json' => true,
            '--no-content-change' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        $this->assertErrorCode($payload, 'confirmation_mismatch');

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
        $this->assertFalse((bool) $fresh->is_indexable);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
        $this->assertSame('noindex,follow', (string) $fresh->seoMeta?->robots);
    }

    public function test_preflight_blocks_private_or_scoring_token_leaks_without_write(): void
    {
        $article = $this->createPublishedNoindexIqMethodArticle();
        Article::query()->withoutGlobalScopes()
            ->where('id', (int) $article->id)
            ->update(['content_md' => $this->body()."\n\nanswer_key should never appear."]);

        [$exit, $payload] = $this->callActivate([
            '--article-id' => (string) $article->id,
            '--expected-slug' => 'what-is-iq-style-reasoning-test',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'private_or_scoring_token_leak');

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);
        $this->assertFalse((bool) $fresh->is_indexable);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPublishedNoindexIqMethodArticle(array $overrides = []): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create(array_merge([
            'org_id' => 0,
            'slug' => 'what-is-iq-style-reasoning-test',
            'locale' => 'zh-CN',
            'title' => '什么是 IQ 风格推理测试？',
            'excerpt' => '解释 IQ 风格推理测试的任务形式和非官方、非临床、非认证边界。',
            'content_md' => $this->body(),
            'content_html' => '<h1>什么是 IQ 风格推理测试？</h1>',
            'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
            'status' => 'published',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => true,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => now()->subMinute(),
        ], $overrides));

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'reviewed_by' => 42,
            'reviewed_at' => now()->subMinutes(10),
            'approved_at' => now()->subMinutes(5),
            'published_at' => now()->subMinute(),
        ]);
        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => 'https://fermatmind.com/zh/articles/what-is-iq-style-reasoning-test',
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://fermatmind.com/storage/articles/iq-method.svg',
            'robots' => 'noindex,follow',
            'schema_json' => [
                'schema_gates_v1' => [
                    'article' => true,
                    'breadcrumb' => true,
                    'faq' => true,
                ],
                'editorial_package_v1' => [
                    'publish_v1' => [
                        'pr_id' => 'IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01',
                    ],
                ],
            ],
            'is_indexable' => false,
        ]);

        return $article->fresh(['publishedRevision', 'seoMeta']) ?? $article;
    }

    private function body(): string
    {
        return <<<'MARKDOWN'
# 什么是 IQ 风格推理测试？

这篇文章解释 FermatMind IQ V1 的方法边界。IQ 风格推理测试使用图形、矩阵、规律补全、空间关系和限时作答任务，帮助用户理解本次推理任务表现。

## 方法边界

FermatMind IQ V1 是非官方、非临床、非认证的在线推理任务。页面只解释本次 30 题任务里的原始分、正确率、完成时间和维度表现，不把结果扩展成人群排名、长期能力标签或任何诊断结论。

## FAQ

### 这是正式智力测评吗？

不是。它是 IQ 风格的推理任务说明，用于理解本次答题表现。
MARKDOWN;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array{0:int,1:array<string,mixed>}
     */
    private function callActivate(array $arguments): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('articles:iq-method-pages-seo-geo-activate', $arguments, $output);
        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertErrorCode(array $payload, string $code): void
    {
        $this->assertContains($code, array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            (array) ($payload['errors'] ?? [])
        ));
    }
}
