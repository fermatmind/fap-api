<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleSeoGateRolloutCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_for_production_artisan_path(): void
    {
        $this->assertArrayHasKey('articles:seo-gate-rollout', Artisan::all());
    }

    public function test_dry_run_plans_article_breadcrumb_and_no_hreflang_policy_without_writing(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle([
            'id' => 8,
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-8',
            'title' => 'MBTI 基础',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => false,
                    'breadcrumb_schema_enabled' => false,
                    'faq_schema_enabled' => false,
                    'hreflang_gate_v1' => ['enabled' => false],
                ],
            ],
        ]);

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $article->id,
            '--translation-group-id' => 'article-8',
            '--expected-slugs' => 'mbti-basics',
            '--enable-article-schema' => true,
            '--enable-breadcrumb-schema' => true,
            '--hold-faq-schema' => true,
            '--no-hreflang-policy' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['would_write']);
        $this->assertFalse((bool) data_get($payload, 'before.0.schema_gates.article_schema_enabled'));
        $this->assertTrue((bool) data_get($payload, 'after.0.schema_gates.article_schema_enabled'));
        $this->assertTrue((bool) data_get($payload, 'after.0.schema_gates.breadcrumb_schema_enabled'));
        $this->assertFalse((bool) data_get($payload, 'after.0.schema_gates.faq_schema_enabled'));
        $this->assertSame('no_hreflang', data_get($payload, 'after.0.schema_gates.hreflang_gate_v1.policy'));

        $fresh = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->firstOrFail();
        $this->assertFalse((bool) data_get($fresh->schema_json, 'editorial_package_v1.article_schema_enabled'));
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_seo_gate_rollout')->count());
    }

    public function test_execute_writes_schema_policy_identity_cleanup_and_audit_without_revision_or_content_change(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle([
            'id' => 50,
            'slug' => 'iq-test-score-and-limits-explained',
            'locale' => 'zh-CN',
            'translation_group_id' => 'unknown_until_cms_import',
            'title' => '在线 IQ 测试准吗？',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => [
                'editorial_package_v1' => [
                    'article_schema_enabled' => false,
                ],
            ],
        ]);
        $revisionCount = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->count();
        $contentMd = (string) $article->content_md;

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $article->id,
            '--translation-group-id' => 'unknown_until_cms_import',
            '--expected-slugs' => 'iq-test-score-and-limits-explained',
            '--set-translation-group-id' => 'tg_article_iq_test_score_and_limits_explained_2026v1',
            '--no-hreflang-policy' => true,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
            '--no-search' => true,
            '--no-sitemap-llms-change' => true,
            '--no-content-change' => true,
            '--no-revalidation' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('rolled_out_article_seo_gates', $payload['action']);

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail((int) $article->id);
        $this->assertSame('tg_article_iq_test_score_and_limits_explained_2026v1', (string) $fresh->translation_group_id);
        $this->assertSame($contentMd, (string) $fresh->content_md);
        $this->assertSame($revisionCount, ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->count());
        $this->assertSame('no_hreflang', data_get($fresh->seoMeta?->schema_json, 'editorial_package_v1.hreflang_gate_v1.policy'));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_seo_gate_rollout')->first();
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame((string) $article->id, (string) $audit->target_id);
        $this->assertSame('articles:seo-gate-rollout', data_get($audit->meta_json, 'command'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_content_change'));
    }

    public function test_article_schema_enable_blocks_when_generated_jsonld_contains_faqpage_and_faq_gate_is_held(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle([
            'id' => 51,
            'slug' => 'enneagram-personality-test-explained',
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_enneagram_personality_test_explained_2026v1',
            'title' => '九型人格测试准吗？',
        ]);
        $this->createSeoMeta($article, [
            'schema_json' => [
                'editorial_package_v1' => [
                    'answer_surface_policy' => 'editor_supplied',
                    'answer_surface_visibility' => 'below_intro',
                    'answer_surface_v1' => [
                        'faq_items' => [
                            ['question' => '九型人格能诊断人格吗？', 'answer' => '不能，它只能用于自我理解。'],
                        ],
                    ],
                ],
            ],
        ]);

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $article->id,
            '--translation-group-id' => 'tg_article_enneagram_personality_test_explained_2026v1',
            '--expected-slugs' => 'enneagram-personality-test-explained',
            '--enable-article-schema' => true,
            '--enable-breadcrumb-schema' => true,
            '--hold-faq-schema' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertErrorCode($payload, 'json_ld_faq_gate_blocked');
        $this->assertContains('FAQPage', data_get($payload, 'before.0.json_ld_types'));
    }

    public function test_enable_hreflang_requires_reciprocal_counterpart(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle([
            'id' => 4,
            'slug' => 'eq-test-tool-guide',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-4',
            'title' => '情商测试指南',
        ]);
        $this->createSeoMeta($article);

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $article->id,
            '--translation-group-id' => 'article-4',
            '--expected-slugs' => 'eq-test-tool-guide',
            '--enable-hreflang' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'hreflang_missing_counterpart');
    }

    public function test_enable_hreflang_passes_for_reciprocal_public_counterparts(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $groupId = 'tg_article_eq_test_tool_guide_2026v1';
        $zh = $this->createArticle([
            'id' => 4,
            'slug' => 'eq-test-tool-guide',
            'locale' => 'zh-CN',
            'translation_group_id' => $groupId,
            'title' => '情商测试指南',
        ]);
        $en = $this->createArticle([
            'id' => 40,
            'slug' => 'eq-test-tool-guide',
            'locale' => 'en',
            'translation_group_id' => $groupId,
            'title' => 'EQ Test Tool Guide',
        ]);
        $this->createSeoMeta($zh);
        $this->createSeoMeta($en);

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => $zh->id.','.$en->id,
            '--translation-group-id' => $groupId,
            '--expected-slugs' => 'eq-test-tool-guide,eq-test-tool-guide',
            '--enable-hreflang' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('reciprocal_counterparts_verified', data_get($payload, 'after.0.schema_gates.hreflang_gate_v1.policy'));
        $this->assertSame('reciprocal_counterparts_verified', data_get($payload, 'after.1.schema_gates.hreflang_gate_v1.policy'));
    }

    public function test_execute_requires_all_safety_flags(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $article = $this->createArticle([
            'id' => 3,
            'slug' => 'big-five-tool-guide',
            'locale' => 'zh-CN',
            'translation_group_id' => 'article-3',
        ]);
        $this->createSeoMeta($article);

        $exitCode = Artisan::call('articles:seo-gate-rollout', [
            '--article-ids' => (string) $article->id,
            '--translation-group-id' => 'article-3',
            '--expected-slugs' => 'big-five-tool-guide',
            '--enable-article-schema' => true,
            '--execute' => true,
            '--json' => true,
            '--no-publish' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        $fresh = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->firstOrFail();
        $this->assertNull($fresh->schema_json);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        /** @var Article $article */
        $article = Article::query()->withoutGlobalScopes()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'article-slug',
            'locale' => 'en',
            'translation_group_id' => 'article-group',
            'source_locale' => 'en',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => 'Article Title',
            'excerpt' => 'Article excerpt.',
            'content_md' => '# Article body',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => Carbon::create(2026, 6, 14, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
        ], $overrides));

        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) ($article->translated_from_version_hash ?: $article->source_version_hash),
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => null,
            'seo_description' => null,
            'published_at' => $article->published_at,
        ]);

        $article->forceFill(['published_revision_id' => $revision->id])->save();

        return $article->fresh(['publishedRevision']) ?? $article;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createSeoMeta(Article $article, array $overrides = []): ArticleSeoMeta
    {
        /** @var ArticleSeoMeta */
        return ArticleSeoMeta::query()->withoutGlobalScopes()->create(array_merge([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) ($article->excerpt ?? ''),
            'canonical_url' => 'https://fermatmind.com/'.((string) $article->locale === 'zh-CN' ? 'zh' : 'en').'/articles/'.$article->slug,
            'og_title' => (string) $article->title,
            'og_description' => (string) ($article->excerpt ?? ''),
            'og_image_url' => null,
            'robots' => 'index,follow',
            'schema_json' => null,
            'is_indexable' => true,
        ], $overrides));
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
