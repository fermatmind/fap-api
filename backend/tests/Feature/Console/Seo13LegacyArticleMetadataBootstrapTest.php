<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class Seo13LegacyArticleMetadataBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = [
        [5, 'iq-test-growth-guide', 5, 444, 'ability'],
        [6, 'iq-test-narrative-portrait', 6, 443, 'ability'],
        [7, 'iq-test-tool-guide', 7, 442, 'ability'],
        [9, 'mbti-growth-guide', 9, 441, 'personality'],
        [10, 'mbti-narrative-portrait', 10, 440, 'personality'],
    ];

    private const IQ_TAGS = [
        'iq-test',
        'intelligence-test',
        'online-iq-test',
        'formal-iq-assessment',
        'cognitive-self-assessment',
    ];

    private const MBTI_TAGS = ['mbti', '16-personality-types-zh', '892eefb1a4f4'];

    public function test_dry_run_binds_exact_missing_set_without_writes(): void
    {
        $this->createCohort();
        $before = $this->authorityState();

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['production_write_execution']);
        $this->assertSame(5, $payload['target_count']);
        $this->assertSame(5, $payload['missing_count']);
        $this->assertSame(0, $payload['complete_count']);
        $this->assertTrue($payload['repair_required']);
        $this->assertTrue($payload['apply_supported']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['state_sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['target_set_sha256']);
        $this->assertSame($before, $this->authorityState());
    }

    public function test_execute_applies_exact_metadata_and_preserves_publication_holds(): void
    {
        $this->createCohort();
        $before = $this->authorityState();
        $preflight = $this->preflight();

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['production_write_execution']);
        $this->assertSame(5, $payload['seo_meta_write_count']);
        $this->assertSame(5, $payload['category_write_count']);
        $this->assertSame(21, $payload['tag_mapping_write_count']);
        $this->assertSame(1, $payload['audit_write_count']);
        $this->assertSame(0, $payload['article_body_write_count']);
        $this->assertSame(0, $payload['revision_write_count']);
        $this->assertSame(0, $payload['publication_write_count']);
        $this->assertSame(0, $payload['indexability_write_count']);
        $this->assertSame(0, $payload['schema_write_count']);
        $this->assertSame(0, $payload['hreflang_write_count']);
        $this->assertSame(0, $payload['revalidation_count']);
        $this->assertSame(0, $payload['sitemap_eligibility_write_count']);
        $this->assertSame(0, $payload['llms_eligibility_write_count']);
        $this->assertSame(0, $payload['search_submission_count']);
        $this->assertSame(0, $payload['queue_dispatch_count']);
        $this->assertFalse($payload['repair_required']);
        $this->assertTrue($payload['readback_complete']);

        $after = $this->authorityState();
        foreach (self::TARGETS as [$articleId]) {
            $this->assertSame($before[$articleId]['body_hash'], $after[$articleId]['body_hash']);
            $this->assertSame($before[$articleId]['published_revision_id'], $after[$articleId]['published_revision_id']);
            $this->assertSame($before[$articleId]['working_revision_id'], $after[$articleId]['working_revision_id']);
            $this->assertSame($before[$articleId]['published_status'], $after[$articleId]['published_status']);
            $this->assertSame($before[$articleId]['working_status'], $after[$articleId]['working_status']);
            $this->assertSame($before[$articleId]['is_indexable'], $after[$articleId]['is_indexable']);
            $this->assertSame($before[$articleId]['sitemap_eligible'], $after[$articleId]['sitemap_eligible']);
            $this->assertSame($before[$articleId]['llms_eligible'], $after[$articleId]['llms_eligible']);
        }

        $this->assertSame(5, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_legacy_article_metadata_bootstrap')
            ->count());
    }

    public function test_fifth_target_drift_fails_closed_without_partial_writes(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        Article::query()->withoutGlobalScopes()->whereKey(10)->update(['slug' => 'drifted']);

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_legacy_article_metadata_bootstrap')
            ->count());
        foreach (self::TARGETS as [$articleId]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $this->assertNull($article->category_id);
            $this->assertSame(0, $article->tags()->count());
        }
    }

    public function test_partial_existing_metadata_is_rejected_without_writes(): void
    {
        $this->createCohort();
        $article = Article::query()->withoutGlobalScopes()->findOrFail(5);
        $article->forceFill(['category_id' => ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('slug', 'ability-cognition')
            ->value('id')])->save();

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('legacy_metadata_partial_or_drifted', array_column($payload['errors'], 'code'));
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
    }

    public function test_working_revision_seo_drift_after_preflight_rejects_all_writes(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey(440)
            ->update(['seo_title' => '漂移后的 SEO 标题']);

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('expected_state_sha256_mismatch', array_column($payload['errors'], 'code'));
        $this->assertSame(0, ArticleSeoMeta::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'seo13_legacy_article_metadata_bootstrap')
            ->count());
    }

    public function test_completed_readback_is_idempotent_and_not_applyable(): void
    {
        $this->createCohort();
        $preflight = $this->preflight();
        Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--execute' => true,
            '--expected-state-sha256' => $preflight['state_sha256'],
            '--expected-target-set-sha256' => $preflight['target_set_sha256'],
            '--confirm' => $preflight['expected_confirmation'],
            '--json' => true,
        ]);

        $exitCode = Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['missing_count']);
        $this->assertSame(5, $payload['complete_count']);
        $this->assertFalse($payload['repair_required']);
        $this->assertFalse($payload['apply_supported']);
        $this->assertTrue($payload['readback_complete']);
    }

    /**
     * @return array<string,mixed>
     */
    private function preflight(): array
    {
        Artisan::call('articles:seo13-legacy-metadata-bootstrap', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        return $this->jsonOutput();
    }

    private function createCohort(): void
    {
        ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'name' => '能力与认知',
            'slug' => 'ability-cognition',
            'is_active' => true,
        ]);
        ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'name' => '人格心理学',
            'slug' => 'personality-legacy',
            'is_active' => true,
        ]);

        foreach (array_unique([...self::IQ_TAGS, ...self::MBTI_TAGS]) as $index => $slug) {
            ArticleTag::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'name' => 'Tag '.($index + 1),
                'slug' => $slug,
                'is_active' => true,
            ]);
        }

        foreach (self::TARGETS as [$articleId, $slug, $publishedRevisionId, $workingRevisionId]) {
            $article = new Article;
            $article->forceFill([
                'id' => $articleId,
                'org_id' => 0,
                'category_id' => null,
                'slug' => $slug,
                'locale' => 'zh-CN',
                'translation_group_id' => 'article-'.$articleId,
                'source_locale' => 'zh-CN',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => '旧公开标题 '.$articleId,
                'excerpt' => '旧公开摘要 '.$articleId,
                'content_md' => '# 旧正文 '.$articleId."\n\n".str_repeat('可见内容', 30),
                'content_html' => '<h1>旧正文</h1>',
                'cover_image_url' => 'https://fermatmind.com/images/article-'.$articleId.'.webp',
                'cover_image_alt' => '封面 '.$articleId,
                'status' => 'published',
                'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => now()->subDay(),
            ])->save();

            $publishedRevision = new ArticleTranslationRevision;
            $publishedRevision->forceFill([
                'id' => $publishedRevisionId,
                'org_id' => 0,
                'article_id' => $articleId,
                'source_article_id' => $articleId,
                'translation_group_id' => 'article-'.$articleId,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 1,
                'title' => (string) $article->title,
                'excerpt' => (string) $article->excerpt,
                'content_md' => (string) $article->content_md,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'published_at' => now()->subDay(),
            ])->save();
            $workingRevision = new ArticleTranslationRevision;
            $workingRevision->forceFill([
                'id' => $workingRevisionId,
                'org_id' => 0,
                'article_id' => $articleId,
                'source_article_id' => $articleId,
                'translation_group_id' => 'article-'.$articleId,
                'locale' => 'zh-CN',
                'source_locale' => 'zh-CN',
                'revision_number' => 2,
                'title' => '新版标题 '.$articleId,
                'excerpt' => '新版摘要 '.$articleId,
                'content_md' => '# 新版正文 '.$articleId."\n\n".str_repeat('新版可见内容', 500),
                'seo_title' => '新版 SEO 标题 '.$articleId,
                'seo_description' => '新版 SEO 描述 '.$articleId,
                'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'reviewed_by' => 1,
                'reviewed_at' => now()->subHour(),
                'approved_at' => now()->subHour(),
            ])->save();

            $article->forceFill([
                'published_revision_id' => $publishedRevisionId,
                'working_revision_id' => $workingRevisionId,
            ])->save();
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function authorityState(): array
    {
        $state = [];
        foreach (self::TARGETS as [$articleId, , $publishedRevisionId, $workingRevisionId]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($articleId);
            $state[$articleId] = [
                'body_hash' => hash('sha256', (string) $article->content_md),
                'published_revision_id' => (int) $article->published_revision_id,
                'working_revision_id' => (int) $article->working_revision_id,
                'published_status' => (string) ArticleTranslationRevision::query()
                    ->withoutGlobalScopes()->findOrFail($publishedRevisionId)->revision_status,
                'working_status' => (string) ArticleTranslationRevision::query()
                    ->withoutGlobalScopes()->findOrFail($workingRevisionId)->revision_status,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
            ];
        }

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
