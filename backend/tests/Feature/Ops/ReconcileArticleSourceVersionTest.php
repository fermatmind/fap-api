<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReconcileArticleSourceVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_execute_only_reconciles_source_metadata(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha, $confirm] = $this->package($source, $old, $seo);
        try {
            $sourceBefore = $source->getAttributes();
            $oldBefore = $old->getAttributes();
            $seoBefore = $seo->getAttributes();
            $counts = [Article::withoutGlobalScopes()->count(), ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()];
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true, '--json' => true,
            ]));
            $this->assertSame($counts, [Article::withoutGlobalScopes()->count(), ArticleTranslationRevision::withoutGlobalScopes()->count(), AuditLog::withoutGlobalScopes()->count()]);
            $this->assertSame($sourceBefore, $source->fresh()->getAttributes());

            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true,
                '--confirm' => $confirm, '--json' => true,
            ]));
            $sourceAfter = $source->fresh();
            $new = $sourceAfter->publishedRevision;
            $this->assertTrue($sourceAfter->isSourceArticle());
            $this->assertSame((int) $new->id, (int) $sourceAfter->working_revision_id);
            $this->assertSame((int) $old->id, (int) $new->supersedes_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_SOURCE, $new->revision_status);
            $this->assertSame((string) $sourceAfter->source_version_hash, (string) $new->source_version_hash);
            $this->assertSame((string) $sourceAfter->content_md, (string) $new->content_md);
            $this->assertSame((string) $old->seo_description, (string) $new->seo_description);
            $this->assertNotSame((string) $seo->seo_description, (string) $new->seo_description);
            $this->assertSame($oldBefore, $old->fresh()->getAttributes());
            $this->assertSame($seoBefore, $seo->fresh()->getAttributes());
            foreach (['translation_status', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                unset($sourceBefore[$field]);
            }
            $after = $sourceAfter->getAttributes();
            foreach (['translation_status', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                unset($after[$field]);
            }
            $this->assertSame($sourceBefore, $after);
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->count());
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));

            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $restore = [
                '--source-id' => (int) $source->id,
                '--audit-id' => (int) $audit->id,
                '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $sourceAfter->getRawOriginal('updated_at'),
                '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'),
            ];
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [
                ...$restore, '--dry-run' => true,
            ]));
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [
                ...$restore, '--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
                    $source->id, $audit->id, $new->id),
            ]));
            $this->assertSame(Article::TRANSLATION_STATUS_APPROVED, $source->fresh()->translation_status);
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame(ArticleTranslationRevision::STATUS_ARCHIVED, $new->fresh()->revision_status);
            $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciliation_restored')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_source_or_revision_drift_rejects_without_writes(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha, $confirm] = $this->package($source, $old, $seo);
        try {
            $old->forceFill(['content_md' => '# Changed after package export'])->save();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertContains('published_revision_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame(Article::TRANSLATION_STATUS_APPROVED, $source->fresh()->translation_status);
            $this->assertSame(1, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->count());
        } finally {
            unlink($path);
        }
    }

    public function test_english_identity_collision_blocks_reconciliation(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha] = $this->package($source, $old, $seo);
        try {
            Article::query()->create([
                'org_id' => 0, 'slug' => (string) $source->slug, 'locale' => 'en',
                'translation_group_id' => 'independent-english-group', 'source_locale' => 'en',
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => 'Independent English source', 'content_md' => 'Independent content',
                'status' => 'draft', 'is_public' => false,
            ]);
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertContains('english_identity_collision', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame(Article::TRANSLATION_STATUS_APPROVED, $source->fresh()->translation_status);
        } finally {
            unlink($path);
        }
    }

    public function test_restore_refuses_an_english_target_created_after_reconciliation(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha, $confirm] = $this->package($source, $old, $seo);
        try {
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $source = $source->fresh();
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $new = $source->publishedRevision;
            Article::query()->create([
                'org_id' => 0, 'slug' => (string) $source->slug, 'locale' => 'en',
                'translation_group_id' => (string) $source->translation_group_id,
                'source_locale' => 'zh-CN', 'translation_status' => Article::TRANSLATION_STATUS_MACHINE_DRAFT,
                'source_article_id' => (int) $source->id, 'translated_from_article_id' => (int) $source->id,
                'title' => 'English draft', 'content_md' => 'Private English draft',
                'status' => 'draft', 'is_public' => false,
            ]);
            $this->assertSame(1, Artisan::call('articles:restore-source-version', [
                '--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id,
                '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'),
                '--dry-run' => true,
            ]));
            $this->assertContains('intervening_dependency_or_change', json_decode(Artisan::output(), true)['errors']);
            $this->assertTrue($source->fresh()->isSourceArticle());
        } finally {
            unlink($path);
        }
    }

    /** @return array{Article,ArticleTranslationRevision,ArticleSeoMeta} */
    private function legacySource(): array
    {
        $source = Article::query()->create([
            'org_id' => 0, 'slug' => 'legacy-source-reconcile-test', 'locale' => 'zh-CN',
            'translation_group_id' => 'legacy-source-reconcile-test', 'source_locale' => 'zh-CN',
            'translation_status' => Article::TRANSLATION_STATUS_APPROVED,
            'title' => '中文源文', 'excerpt' => '中文摘要', 'content_md' => "## 中文源文\n\n正文。",
            'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'published_at' => now(),
        ]);
        $seo = ArticleSeoMeta::query()->create([
            'org_id' => 0, 'article_id' => (int) $source->id, 'locale' => 'zh-CN',
            'seo_title' => '当前 SEO 标题', 'seo_description' => '当前 SEO 描述', 'is_indexable' => true,
        ]);
        $old = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => (int) $source->id, 'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'zh-CN', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => str_repeat('a', 64), 'translated_from_version_hash' => str_repeat('a', 64),
            'title' => (string) $source->title, 'excerpt' => (string) $source->excerpt,
            'content_md' => (string) $source->content_md,
            'seo_title' => '旧 SEO 标题', 'seo_description' => '旧 SEO 描述', 'published_at' => now(),
        ]);
        $source->forceFill(['working_revision_id' => (int) $old->id,
            'published_revision_id' => (int) $old->id])->saveQuietly();

        return [$source->fresh(), $old->fresh(), $seo->fresh()];
    }

    /** @return array{string,string,string} */
    private function package(Article $source, ArticleTranslationRevision $revision, ArticleSeoMeta $seo): array
    {
        $package = [
            'schema' => 'fermat_article_source_reconcile_v1',
            'source_id' => (int) $source->id,
            'group_id' => (string) $source->translation_group_id,
            'slug' => (string) $source->slug,
            'source_hash' => (string) $source->source_version_hash,
            'source_body_sha256' => hash('sha256', (string) $source->content_md),
            'source_updated_at' => (string) $source->getRawOriginal('updated_at'),
            'revision_id' => (int) $revision->id,
            'revision_hash' => (string) $revision->source_version_hash,
            'revision_body_sha256' => hash('sha256', (string) $revision->content_md),
            'revision_updated_at' => (string) $revision->getRawOriginal('updated_at'),
            'seo_meta_id' => (int) $seo->id,
            'seo_meta_content_sha256' => hash('sha256', json_encode([
                'seo_title' => $seo->seo_title,
                'seo_description' => $seo->seo_description,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'seo_meta_updated_at' => (string) $seo->getRawOriginal('updated_at'),
        ];
        $path = tempnam(sys_get_temp_dir(), 'article-source-reconcile-');
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);

        return [$path, $sha, sprintf('Reconcile Article source %d in group %s with package %s.',
            $source->id, $source->translation_group_id, $sha)];
    }
}
