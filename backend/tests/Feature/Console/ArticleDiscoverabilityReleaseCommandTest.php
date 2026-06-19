<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ArticleDiscoverabilityReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_registered_for_production_artisan_path(): void
    {
        $this->assertArrayHasKey('articles:discoverability-release', Artisan::all());
    }

    public function test_dry_run_plans_sitemap_and_llms_release_without_writes(): void
    {
        $article = $this->createPublishedIndexableArticle();

        $exitCode = Artisan::call('articles:discoverability-release', [
            '--article-id' => (string) $article->id,
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['would_write']);
        $this->assertSame('would_release_article_discoverability', $payload['action']);
        $this->assertFalse(data_get($payload, 'plan.before.sitemap_eligible'));
        $this->assertFalse(data_get($payload, 'plan.before.llms_eligible'));
        $this->assertTrue(data_get($payload, 'plan.after.sitemap_eligible'));
        $this->assertTrue(data_get($payload, 'plan.after.llms_eligible'));
        $this->assertFalse($payload['external_search_submission_attempted']);
        $this->assertFalse($payload['schema_hreflang_write_attempted']);
        $this->assertStringContainsString('article id 53 slug gaokao-score-major-shortlist-riasec-checklist', $payload['expected_confirmation']);

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail(53);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_discoverability_release')->count());
    }

    public function test_execute_releases_only_sitemap_and_llms_eligibility(): void
    {
        $article = $this->createPublishedIndexableArticle();
        $contentHash = hash('sha256', (string) $article->content_md);
        $publishedRevisionId = (int) $article->published_revision_id;
        $schemaHash = hash('sha256', (string) json_encode($article->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        $exitCode = Artisan::call('articles:discoverability-release', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--confirm' => 'I explicitly approve articles:discoverability-release execute for article id 53 slug gaokao-score-major-shortlist-riasec-checklist after dry-run passes.',
            '--execute' => true,
            '--json' => true,
            '--no-content-change' => true,
            '--no-publish' => true,
            '--no-search' => true,
            '--no-schema-hreflang' => true,
            '--no-revalidation' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertSame('released_article_discoverability', $payload['action']);
        $this->assertTrue(data_get($payload, 'plan.after.sitemap_eligible'));
        $this->assertTrue(data_get($payload, 'plan.after.llms_eligible'));

        $fresh = Article::query()->withoutGlobalScopes()->with('seoMeta')->findOrFail(53);
        $this->assertSame('published', (string) $fresh->status);
        $this->assertTrue((bool) $fresh->is_public);
        $this->assertTrue((bool) $fresh->is_indexable);
        $this->assertTrue((bool) $fresh->sitemap_eligible);
        $this->assertTrue((bool) $fresh->llms_eligible);
        $this->assertSame($contentHash, hash('sha256', (string) $fresh->content_md));
        $this->assertSame($publishedRevisionId, (int) $fresh->published_revision_id);
        $this->assertSame($schemaHash, hash('sha256', (string) json_encode($fresh->seoMeta?->schema_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)));

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'articles_discoverability_release')->first();
        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame('article', (string) $audit->target_type);
        $this->assertSame('53', (string) $audit->target_id);
        $this->assertSame('articles:discoverability-release', data_get($audit->meta_json, 'command'));
        $this->assertTrue((bool) data_get($audit->meta_json, 'no_search'));
        $this->assertSame(['articles.sitemap_eligible', 'articles.llms_eligible'], data_get($audit->meta_json, 'updates_scope'));
    }

    public function test_execute_requires_confirmation_and_safety_flags(): void
    {
        $this->createPublishedIndexableArticle();

        $exitCode = Artisan::call('articles:discoverability-release', [
            '--article-id' => '53',
            '--expected-slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            '--execute' => true,
            '--json' => true,
            '--no-content-change' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'required_safety_flag_missing');
        $this->assertErrorCode($payload, 'confirmation_mismatch');

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail(53);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
    }

    public function test_slug_lock_and_indexability_preflight_block_without_write(): void
    {
        $article = $this->createPublishedIndexableArticle([
            'is_indexable' => false,
        ]);
        ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', (int) $article->id)->update([
            'is_indexable' => false,
            'robots' => 'noindex,nofollow',
        ]);

        $exitCode = Artisan::call('articles:discoverability-release', [
            '--article-id' => '53',
            '--expected-slug' => 'wrong-slug',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertErrorCode($payload, 'expected_slug_mismatch');
        $this->assertErrorCode($payload, 'article_not_indexable');
        $this->assertErrorCode($payload, 'seo_meta_not_indexable');
        $this->assertErrorCode($payload, 'seo_meta_robots_not_index_follow');

        $fresh = Article::query()->withoutGlobalScopes()->findOrFail(53);
        $this->assertFalse((bool) $fresh->sitemap_eligible);
        $this->assertFalse((bool) $fresh->llms_eligible);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createPublishedIndexableArticle(array $overrides = []): Article
    {
        /** @var Article $article */
        $article = Model::unguarded(fn (): Article => Article::query()->withoutGlobalScopes()->create(array_merge([
            'id' => 53,
            'org_id' => 0,
            'category_id' => null,
            'author_name' => 'Fermat Institute',
            'reading_minutes' => 12,
            'slug' => 'gaokao-score-major-shortlist-riasec-checklist',
            'locale' => 'zh-CN',
            'translation_group_id' => 'tg_article_gaokao_score_major_shortlist_riasec_2026v1',
            'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
            'title' => '高考出分后专业太多怎么筛？位次+霍兰德排除清单',
            'excerpt' => '高考出分后，用位次、选科要求、霍兰德职业兴趣测试和排除清单缩小专业范围。',
            'content_md' => '# 高考出分后专业太多怎么筛？'."\n\n正文。",
            'content_html' => '<h1>高考出分后专业太多怎么筛？</h1>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => Carbon::create(2026, 6, 19, 5, 30, 0, 'UTC'),
        ], $overrides)));

        /** @var ArticleTranslationRevision $revision */
        $revision = Model::unguarded(fn (): ArticleTranslationRevision => ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) $article->id,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => (string) $article->source_version_hash,
            'translated_from_version_hash' => (string) $article->source_version_hash,
            'title' => (string) $article->title,
            'excerpt' => (string) $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'published_at' => $article->published_at,
        ]));

        $article->forceFill(['published_revision_id' => (int) $revision->id])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => 'zh-CN',
            'seo_title' => (string) $article->title,
            'seo_description' => (string) $article->excerpt,
            'canonical_url' => 'https://fermatmind.com/zh/articles/gaokao-score-major-shortlist-riasec-checklist',
            'og_title' => (string) $article->title,
            'og_description' => (string) $article->excerpt,
            'og_image_url' => 'https://api.fermatmind.com/storage/media-library/variants/article/og.jpg',
            'robots' => 'index,follow',
            'schema_json' => [
                'schema_gates_v1' => [
                    'article' => true,
                    'breadcrumb' => true,
                    'faq' => false,
                ],
                'hreflang_gate_v1' => [
                    'enabled' => false,
                    'policy' => 'no_direct_english_counterpart_approved',
                ],
            ],
            'is_indexable' => true,
        ]);

        return $article->fresh(['publishedRevision', 'seoMeta']) ?? $article;
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
