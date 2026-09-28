<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Support\ArticleSourceTargetSnapshot;
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
            $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $new->revision_status);
            $this->assertSame($old->published_at->toISOString(), $new->published_at->toISOString());
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

    public function test_null_old_hash_is_locked_preserved_and_restorable(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $old->forceFill(['source_version_hash' => null])->saveQuietly();
        [$path, $sha, $confirm] = $this->package($source, $old->fresh(), $seo);
        try {
            $count = ArticleTranslationRevision::withoutGlobalScopes()->count();
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertSame($count, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertNull($old->fresh()->getRawOriginal('source_version_hash'));
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $after = $source->fresh();
            $new = $after->publishedRevision;
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $this->assertArrayHasKey('old_revision_hash', $audit->meta_json);
            $this->assertNull($audit->meta_json['old_revision_hash']);
            $this->assertNull($old->fresh()->getRawOriginal('source_version_hash'));
            $restore = [
                '--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id,
                '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $after->getRawOriginal('updated_at'),
                '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'),
            ];
            $auditMeta = $audit->meta_json;
            $missingHashMeta = $auditMeta;
            unset($missingHashMeta['old_revision_hash']);
            $audit->forceFill(['meta_json' => $missingHashMeta])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:restore-source-version', [
                ...$restore, '--dry-run' => true,
            ]));
            $audit->forceFill(['meta_json' => $auditMeta])->saveQuietly();
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [
                ...$restore, '--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
                    $source->id, $audit->id, $new->id),
            ]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertNull($old->fresh()->getRawOriginal('source_version_hash'));
        } finally {
            unlink($path);
        }
    }

    public function test_null_hash_package_rejects_hash_drift_before_execute(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $old->forceFill(['source_version_hash' => null])->saveQuietly();
        [$path, $sha, $confirm] = $this->package($source, $old->fresh(), $seo);
        try {
            $old->forceFill(['source_version_hash' => str_repeat('b', 64)])->saveQuietly();
            $count = ArticleTranslationRevision::withoutGlobalScopes()->count();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true,
                '--confirm' => $confirm, '--json' => true,
            ]));
            $this->assertContains('published_revision_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($count, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
        } finally {
            unlink($path);
        }
    }

    public function test_reconciled_source_remains_in_public_list_and_preserves_public_copy_and_seo(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha, $confirm] = $this->package($source, $old, $seo);
        try {
            $url = '/api/v0.5/articles/'.$source->slug.'?locale=zh-CN';
            $before = $this->getJson($url)->assertOk()->json();
            $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')->assertOk()
                ->assertJsonPath('pagination.total', 1)
                ->assertJsonPath('items.0.id', (int) $source->id);

            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true,
                '--confirm' => $confirm, '--json' => true,
            ]));

            \Illuminate\Support\Facades\Cache::flush();
            $after = $this->getJson($url)->assertOk()->json();
            $this->getJson('/api/v0.5/articles?locale=zh-CN&page=1')->assertOk()
                ->assertJsonPath('pagination.total', 1)
                ->assertJsonPath('items.0.id', (int) $source->id);
            foreach (['title', 'excerpt', 'content_md', 'content_html', 'status', 'is_indexable', 'published_at'] as $field) {
                $this->assertSame($before['article'][$field] ?? null, $after['article'][$field] ?? null);
            }
            foreach (['title', 'description', 'canonical_url', 'robots_policy', 'alternates', 'sitemap_state'] as $field) {
                $this->assertSame($before['seo_surface_v1'][$field] ?? null, $after['seo_surface_v1'][$field] ?? null);
            }
            $this->assertSame(ArticleTranslationRevision::STATUS_PUBLISHED, $source->fresh()->publishedRevision->revision_status);
            $this->assertNull($source->fresh()->publishedRevision->approved_at);
        } finally {
            unlink($path);
        }
    }

    public function test_restore_accepts_legacy_source_lifecycle_but_rejects_unpublished_review_state(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        [$path, $sha, $confirm] = $this->package($source, $old, $seo);
        try {
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $source = $source->fresh();
            $new = $source->publishedRevision;
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            foreach ([ArticleTranslationRevision::STATUS_APPROVED, ArticleTranslationRevision::STATUS_SOURCE] as $status) {
                $new->forceFill(['revision_status' => $status])->saveQuietly();
                $restore = [
                    '--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id,
                    '--new-revision-id' => (int) $new->id,
                    '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                    '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'),
                    '--dry-run' => true,
                ];
                $this->assertSame($status === ArticleTranslationRevision::STATUS_SOURCE ? 0 : 1,
                    Artisan::call('articles:restore-source-version', $restore));
                $this->assertSame((int) $new->id, (int) $source->fresh()->published_revision_id);
            }
            unset($restore['--dry-run']);
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [
                ...$restore, '--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
                    $source->id, $audit->id, $new->id),
            ]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
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

    public function test_existing_target_is_locked_preserved_and_does_not_prevent_safe_restore(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $target = $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithTarget($source, $old, $seo);
        try {
            $before = ArticleSourceTargetSnapshot::capture($source);
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame($before, ArticleSourceTargetSnapshot::capture($source));
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertSame($before, ArticleSourceTargetSnapshot::capture($source->fresh()));
            $this->assertSame(str_repeat('c', 64), $target->fresh()->translated_from_version_hash);
            $source = $source->fresh();
            $new = $source->publishedRevision;
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $this->assertSame($before, $audit->meta_json['existing_english_targets']);
            $restore = ['--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id,
                '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                '--revision-updated-at' => (string) $new->getRawOriginal('updated_at')];
            $target->forceFill(['excerpt' => 'Changed after source reconciliation'])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:restore-source-version', [...$restore, '--dry-run' => true]));
            // Restore the exact locked target bytes, including its original timestamp.
            $target->forceFill($this->targetBefore)->saveQuietly();
            $this->assertSame($before, ArticleSourceTargetSnapshot::capture($source));
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [...$restore,
                '--execute' => true, '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
                    $source->id, $audit->id, $new->id)]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame($before, ArticleSourceTargetSnapshot::capture($source->fresh()));
        } finally {
            unlink($path);
        }
    }

    public function test_existing_target_package_rejects_content_and_identity_drift_without_writes(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $target = $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithTarget($source, $old, $seo);
        try {
            $target->forceFill(['content_md' => 'New English copy'])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertContains('english_identity_collision', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->count());
            $target->forceFill($this->targetBefore)->saveQuietly();
            $target->publishedRevision->forceFill(['translated_from_version_hash' => str_repeat('d', 64)])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $target->forceFill(['source_article_id' => null])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
        } finally {
            unlink($path);
        }
    }

    public function test_unpublished_approved_source_revision_is_preserved_and_blocks_reconciliation(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithTarget($source, $old, $seo);
        try {
            $pending = $old->replicate();
            $pending->forceFill(['revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
                'content_md' => 'An independently approved source correction', 'published_at' => null])->save();
            $before = $pending->fresh()->getAttributes();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertContains('active_source_revision_conflict', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, $pending->fresh()->getAttributes());
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->count());
        } finally {
            unlink($path);
        }
    }

    private array $targetBefore = [];

    private function existingTarget(Article $source): Article
    {
        $target = Article::query()->create([
            'org_id' => 0, 'slug' => (string) $source->slug, 'locale' => 'en',
            'translation_group_id' => (string) $source->translation_group_id, 'source_locale' => 'zh-CN',
            'source_article_id' => (int) $source->id, 'translated_from_article_id' => (int) $source->id,
            'translated_from_version_hash' => str_repeat('c', 64),
            'translation_status' => Article::TRANSLATION_STATUS_PUBLISHED,
            'title' => 'Existing English copy', 'content_md' => 'Existing target remains stale.',
            'status' => 'published', 'is_public' => true,
        ])->fresh();
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => 0, 'article_id' => (int) $target->id, 'source_article_id' => (int) $source->id,
            'translation_group_id' => (string) $source->translation_group_id,
            'locale' => 'en', 'source_locale' => 'zh-CN', 'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => str_repeat('c', 64), 'translated_from_version_hash' => str_repeat('c', 64),
            'title' => $target->title, 'content_md' => $target->content_md,
            'seo_title' => 'Existing English SEO', 'seo_description' => 'Existing description',
        ]);
        $target->forceFill(['working_revision_id' => (int) $revision->id,
            'published_revision_id' => (int) $revision->id])->saveQuietly();
        $target = $target->fresh();
        $this->targetBefore = $target->getAttributes();

        return $target;
    }

    private function packageWithTarget(Article $source, ArticleTranslationRevision $old, ArticleSeoMeta $seo): array
    {
        [$path] = $this->package($source, $old, $seo);
        $p = json_decode(file_get_contents($path), true);
        $p['schema'] = 'fermat_article_source_reconcile_v2';
        $p['existing_english_targets'] = ArticleSourceTargetSnapshot::capture($source);
        $bytes = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);

        return [$path, $sha, sprintf('Reconcile Article source %d in group %s with package %s.',
            $source->id, $source->translation_group_id, $sha)];
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
            'seo_title' => '旧 SEO 标题', 'seo_description' => '旧 SEO 描述', 'published_at' => now()->subDay(),
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
            'revision_hash' => $revision->getRawOriginal('source_version_hash'),
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
