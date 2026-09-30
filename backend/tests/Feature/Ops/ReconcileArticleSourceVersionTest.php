<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Console\Commands\ArticleForkPrivateTranslationLinks;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReconcileArticleSourceVersionTest extends TestCase
{
    use RefreshDatabase;

    public static function currentSourceSnapshots(): array
    {
        return [[8, 'published', true], [46, 'source', false], [48, 'source', false], [58, 'published', true]];
    }

    #[DataProvider('currentSourceSnapshots')]
    public function test_current_source_snapshot_preserves_copy_target_history_and_restore(int $sid, string $oldStatus, bool $historyPackage): void
    {
        [$source, $old, $seo] = $this->legacySource($sid);
        $source->forceFill(['translation_status' => Article::TRANSLATION_STATUS_SOURCE])->saveQuietly();
        $old->forceFill(['revision_status' => $oldStatus])->saveQuietly();
        if (in_array($sid, [46, 48], true)) {
            $old->forceFill(['reviewed_at' => now()->subDays(5), 'approved_at' => now()->subDays(4)])->saveQuietly();
        }
        $target = $this->existingTarget($source);
        if ($historyPackage) {
            $prior = $old->replicate();
            $prior->forceFill(['revision_number' => 2, 'revision_status' => 'archived'])->save();
        }
        $source = $source->fresh();
        $history = ArticleSourceTargetSnapshot::sourceRevisions($source);
        $targetHash = ArticleForkPrivateTranslationLinks::sourceHash($target);
        $oldAttributes = $old->fresh()->getAttributes();
        [$path, $sha, $confirm] = $historyPackage
            ? $this->packageWithHistory($source, $old, $seo)
            : $this->packageWithTarget($source, $old, $seo);
        try {
            $publicBefore = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            $results = [];
            for ($i = 0; $i < 2; $i++) {
                $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--dry-run' => true]), Artisan::output());
                $results[] = json_decode(Artisan::output(), true);
            }
            $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $q) === 1)->values()->all();
            DB::connection()->disableQueryLog();
            $this->assertSame([], $writes);
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($source));
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm]), Artisan::output());
            $source->refresh();
            $new = $source->publishedRevision;
            $this->assertSame($source->computeSourceVersionHash(), $new->source_version_hash);
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($source, [(int) $new->id]));
            $this->assertSame($oldAttributes, $old->fresh()->getAttributes());
            $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
            $this->assertSame($old->reviewed_at?->toISOString(), $new->reviewed_at?->toISOString());
            $this->assertSame($old->approved_at?->toISOString(), $new->approved_at?->toISOString());
            $publicAfter = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                $this->assertSame($publicBefore['article'][$field], $publicAfter['article'][$field]);
            }
            $this->assertSame($publicBefore['seo_surface_v1'], $publicAfter['seo_surface_v1']);
            $this->assertSame($publicBefore['article']['last_reviewed_at'], $publicAfter['article']['last_reviewed_at']);
            $this->assertSame($publicBefore['article']['review_state'], $publicAfter['article']['review_state']);
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $args = ['--source-id' => $sid, '--audit-id' => (int) $audit->id, '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'), '--revision-updated-at' => (string) $new->getRawOriginal('updated_at')];
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $args + ['--dry-run' => true]), Artisan::output());
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $args + ['--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.', $sid, $audit->id, $new->id)]), Artisan::output());
            $this->assertSame($old->id, $source->fresh()->published_revision_id);
            $this->assertSame('source', $source->fresh()->translation_status);
            $this->assertSame($oldAttributes, $old->fresh()->getAttributes());
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
        } finally {
            DB::connection()->disableQueryLog();
            unlink($path);
        }
    }

    public function test_source_status_compatibility_is_bounded_and_wrong_revision_owner_is_rejected(): void
    {
        $this->assertFalse(\App\Console\Commands\ReconcileArticleSourceVersion::allowsOriginalStatus(55, 'source'));
        $this->assertFalse(\App\Console\Commands\ReconcileArticleSourceVersion::allowsOriginalRevisionStatus(8, 'source'));
        [$source, $old, $seo] = $this->legacySource(46);
        $source->forceFill(['translation_status' => 'source'])->saveQuietly();
        $old->forceFill(['revision_status' => 'source', 'source_article_id' => 48])->saveQuietly();
        $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithTarget($source, $old, $seo);
        try {
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm]));
            $this->assertContains('published_revision_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($old->id, $source->fresh()->published_revision_id);
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
        } finally {
            unlink($path);
        }
    }

    public static function legacyUnlinkedPairs(): array
    {
        return [[40, 41], [74, 75]];
    }

    #[DataProvider('legacyUnlinkedPairs')]
    public function test_exact_unlinked_target_is_preserved_during_source_foundation_and_restore(int $sid, int $tid): void
    {
        [$source, $old, $seo] = $this->legacySource($sid);
        $originalStatus = $sid === 40 ? Article::TRANSLATION_STATUS_PUBLISHED : Article::TRANSLATION_STATUS_SOURCE;
        $source->forceFill(['translation_status' => $originalStatus])->saveQuietly();
        $target = Article::forceCreate(['id' => $tid, 'org_id' => 0, 'locale' => 'en',
            'slug' => $sid === 40 ? 'different-english-slug' : $source->slug, 'translation_group_id' => $source->translation_group_id,
            'source_locale' => 'en', 'translation_status' => 'source', 'translated_from_version_hash' => str_repeat('c', 64),
            'title' => 'Existing English', 'content_md' => 'Existing English body', 'status' => 'published', 'is_public' => true]);
        $tr = ArticleTranslationRevision::create(['org_id' => 0, 'article_id' => $tid, 'source_article_id' => $tid,
            'translation_group_id' => $source->translation_group_id, 'locale' => 'en', 'source_locale' => 'en',
            'revision_number' => 1, 'revision_status' => 'published', 'title' => $target->title, 'content_md' => $target->content_md,
            'source_version_hash' => str_repeat('c', 64), 'translated_from_version_hash' => str_repeat('c', 64), 'published_at' => now()]);
        $target->forceFill(['working_revision_id' => $tr->id, 'published_revision_id' => $tr->id])->saveQuietly();
        $targetHash = ArticleForkPrivateTranslationLinks::sourceHash($target->fresh());
        $prior = $old->replicate();
        $prior->forceFill(['revision_number' => 2, 'revision_status' => 'archived'])->save();
        $history = ArticleSourceTargetSnapshot::sourceRevisions($source);
        [$path] = $this->package($source, $old, $seo);
        $p = json_decode(file_get_contents($path), true);
        $p['schema'] = 'fermat_article_source_reconcile_v4';
        $p['existing_english_targets'] = [['article_id' => $tid, 'sha256' => $targetHash]];
        $p['preserved_source_revisions'] = $history;
        $bytes = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);
        try {
            $publicBefore = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            $collision = Article::forceCreate(['org_id' => 0, 'locale' => 'en', 'slug' => 'duplicate-group-candidate',
                'translation_group_id' => $source->translation_group_id, 'source_locale' => 'en', 'translation_status' => 'source',
                'title' => 'Collision', 'content_md' => 'Collision body', 'status' => 'published', 'is_public' => true]);
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--dry-run' => true]));
            $this->assertContains('english_identity_collision', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
            $this->assertSame($old->id, $source->fresh()->published_revision_id);
            $collision->forceDelete();

            DB::connection()->enableQueryLog();
            DB::connection()->flushQueryLog();
            for ($i = 0; $i < 2; $i++) {
                $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--dry-run' => true]), Artisan::output());
            }
            $writes = collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $q) === 1)->all();
            DB::connection()->disableQueryLog();
            $this->assertSame([], array_values($writes));
            $this->assertSame(3, ArticleTranslationRevision::withoutGlobalScopes()->count());
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->count());
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true,
                '--confirm' => sprintf('Reconcile Article source %d in group %s with package %s.', $sid, $source->translation_group_id, $sha)]), Artisan::output());
            $source->refresh();
            $new = $source->publishedRevision;
            $this->assertSame($source->computeSourceVersionHash(), $new->source_version_hash);
            $this->assertNotSame($old->source_version_hash, $new->source_version_hash);
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($source, [(int) $new->id]));
            $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
            $this->assertNull($target->fresh()->source_article_id);
            $this->assertNull($target->fresh()->translated_from_article_id);
            $this->assertNull($new->approved_at);
            $this->assertNull($new->reviewed_at);
            $publicAfter = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                $this->assertSame($publicBefore['article'][$field], $publicAfter['article'][$field]);
            }
            $this->assertSame($publicBefore['seo_surface_v1'], $publicAfter['seo_surface_v1']);
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $args = ['--source-id' => $sid, '--audit-id' => (int) $audit->id, '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'), '--revision-updated-at' => (string) $new->getRawOriginal('updated_at')];
            $meta = $audit->meta_json;
            foreach (['existing_english_targets', 'preserved_source_revisions'] as $field) {
                $meta[$field] = array_map(fn (array $row): array => array_reverse($row, true), $meta[$field]);
            }
            $audit->forceFill(['meta_json' => $meta])->saveQuietly();
            foreach (['existing_english_targets' => 'article_id', 'preserved_source_revisions' => 'revision_id'] as $field => $idKey) {
                foreach (['id_type', 'hash', 'extra_field', 'extra_row', 'list_order'] as $change) {
                    if ($change === 'list_order' && count($meta[$field]) < 2) {
                        continue;
                    }
                    $tampered = $meta;
                    if ($change === 'id_type') {
                        $tampered[$field][0][$idKey] = (string) $tampered[$field][0][$idKey];
                    } elseif ($change === 'hash') {
                        $tampered[$field][0]['sha256'] = str_repeat('0', 64);
                    } elseif ($change === 'extra_field') {
                        $tampered[$field][0]['unexpected'] = true;
                    } elseif ($change === 'list_order') {
                        $tampered[$field] = array_reverse($tampered[$field]);
                    } else {
                        $tampered[$field][] = $tampered[$field][0];
                    }
                    $audit->forceFill(['meta_json' => $tampered])->saveQuietly();
                    $this->assertSame(1, Artisan::call('articles:restore-source-version', $args + ['--dry-run' => true]));
                    $this->assertSame($new->id, $source->fresh()->published_revision_id);
                    $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
                }
            }
            $audit->forceFill(['meta_json' => $meta])->saveQuietly();
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $args + ['--dry-run' => true]), Artisan::output());
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $args + ['--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.', $sid, $audit->id, $new->id)]), Artisan::output());
            $this->assertSame($old->id, $source->fresh()->published_revision_id);
            $this->assertSame($originalStatus, $source->fresh()->translation_status);
            $this->assertSame('archived', $new->fresh()->revision_status);
            $this->assertSame($targetHash, ArticleForkPrivateTranslationLinks::sourceHash($target->fresh()));
        } finally {
            DB::connection()->disableQueryLog();
            unlink($path);
        }
    }

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
            Article::withoutTimestamps(fn () => $target->forceFill($this->targetBefore)->saveQuietly());
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
            Article::withoutTimestamps(fn () => $target->forceFill($this->targetBefore)->saveQuietly());
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

    public function test_row_hash_rebuild_preserves_public_copy_target_provenance_and_restores_exact_old_hash(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $source->forceFill(['source_version_hash' => (string) $old->source_version_hash])->saveQuietly();
        $source = $source->fresh();
        $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithRowHash($source, $old, $seo);
        try {
            $oldHash = (string) $source->source_version_hash;
            $currentHash = $source->computeSourceVersionHash();
            $this->assertNotSame($oldHash, $currentHash);
            $before = $source->getAttributes();
            $oldBefore = $old->fresh()->getAttributes();
            $targetBefore = ArticleSourceTargetSnapshot::capture($source);
            $public = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--dry-run' => true,
            ]));
            $this->assertSame($before, $source->fresh()->getAttributes());
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $source = $source->fresh();
            $new = $source->publishedRevision;
            $this->assertSame($currentHash, $source->source_version_hash);
            $this->assertSame($currentHash, $new->source_version_hash);
            $this->assertSame($oldBefore, $old->fresh()->getAttributes());
            $this->assertSame($targetBefore, ArticleSourceTargetSnapshot::capture($source));
            $after = $this->getJson('/api/v0.5/articles/'.$source->slug.'?locale=zh-CN')->assertOk()->json();
            foreach (['title', 'excerpt', 'content_md'] as $field) {
                $this->assertSame($public['article'][$field], $after['article'][$field]);
            }
            $this->assertSame($public['seo_surface_v1'], $after['seo_surface_v1']);
            $audit = AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->firstOrFail();
            $this->assertSame($oldHash, $audit->meta_json['old_source_hash']);
            $this->assertSame(0, Artisan::call('articles:restore-source-version', [
                '--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id,
                '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'),
                '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'),
                '--execute' => true, '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
                    $source->id, $audit->id, $new->id),
            ]));
            $this->assertSame($oldHash, $source->fresh()->source_version_hash);
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame($targetBefore, ArticleSourceTargetSnapshot::capture($source->fresh()));
        } finally {
            unlink($path);
        }
    }

    public function test_explicit_full_history_lock_preserves_unpointed_approved_draft_and_supports_restore(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $this->existingTarget($source);
        $pending = $old->replicate();
        $pending->forceFill(['revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'title' => 'A different reviewed title', 'content_md' => 'An independently approved source correction',
            'reviewed_at' => now()->subHour(), 'approved_at' => now()->subHour(), 'published_at' => null])->save();
        $pendingBefore = $pending->fresh()->getAttributes();
        $history = ArticleSourceTargetSnapshot::sourceRevisions($source);
        $targets = ArticleSourceTargetSnapshot::capture($source);
        [$path, $sha, $confirm] = $this->packageWithHistory($source, $old, $seo);
        try {
            $count = ArticleTranslationRevision::count();
            for ($i = 0; $i < 2; $i++) {
                $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--dry-run' => true]));
            }
            $this->assertSame($count, ArticleTranslationRevision::count());
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($source));
            $this->assertSame(0, AuditLog::count());
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm]));
            $source = $source->fresh();
            $new = $source->publishedRevision;
            $this->assertSame($history, ArticleSourceTargetSnapshot::sourceRevisions($source, [(int) $new->id]));
            $this->assertSame($pendingBefore, $pending->fresh()->getAttributes());
            $this->assertSame($old->content_md, $new->content_md);
            $this->assertNotSame($pending->content_md, $new->content_md);
            $this->assertNull($new->reviewed_at);
            $this->assertNull($new->approved_at);
            $this->assertSame($targets, ArticleSourceTargetSnapshot::capture($source));
            $audit = AuditLog::where('action', 'article_source_version_reconciled')->firstOrFail();
            $this->assertSame($history, $audit->meta_json['preserved_source_revisions']);
            $restore = ['--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id, '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'), '--revision-updated-at' => (string) $new->getRawOriginal('updated_at')];
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $restore + ['--dry-run' => true]));
            $this->assertSame(0, Artisan::call('articles:restore-source-version', $restore + ['--execute' => true,
                '--confirm' => sprintf('Restore Article source %d from reconciliation audit %d and revision %d.', $source->id, $audit->id, $new->id)]));
            $this->assertSame((int) $old->id, (int) $source->fresh()->working_revision_id);
            $this->assertSame($pendingBefore, $pending->fresh()->getAttributes());
            $this->assertSame($targets, ArticleSourceTargetSnapshot::capture($source->fresh()));
        } finally {
            unlink($path);
        }
    }

    public function test_full_history_drift_and_real_private_working_pointer_remain_conflicts(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $this->existingTarget($source);
        $pending = $old->replicate();
        $pending->forceFill(['revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'content_md' => 'A private correction', 'published_at' => null])->save();
        [$path, $sha, $confirm] = $this->packageWithHistory($source, $old, $seo);
        try {
            $pending->forceFill(['content_md' => 'Changed after snapshot'])->saveQuietly();
            $before = $pending->fresh()->getAttributes();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm]));
            $this->assertContains('source_history_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame($before, $pending->fresh()->getAttributes());
            $this->assertSame(0, AuditLog::count());
            $source->forceFill(['working_revision_id' => $pending->id])->saveQuietly();
            unlink($path);
            [$path, $sha] = $this->packageWithHistory($source->fresh(), $old, $seo);
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--dry-run' => true]));
            $this->assertContains('legacy_source_identity_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame((int) $pending->id, (int) $source->fresh()->working_revision_id);
        } finally {
            unlink($path);
        }
    }

    public function test_preserved_approved_draft_change_or_missing_audit_snapshot_blocks_restore(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $this->existingTarget($source);
        $pending = $old->replicate();
        $pending->forceFill(['revision_number' => 2, 'revision_status' => ArticleTranslationRevision::STATUS_APPROVED,
            'content_md' => 'A private correction', 'published_at' => null])->save();
        [$path, $sha, $confirm] = $this->packageWithHistory($source, $old, $seo);
        try {
            $this->assertSame(0, Artisan::call('articles:reconcile-source-version', ['--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm]));
            $source = $source->fresh();
            $new = $source->publishedRevision;
            $audit = AuditLog::where('action', 'article_source_version_reconciled')->firstOrFail();
            $restore = ['--source-id' => (int) $source->id, '--audit-id' => (int) $audit->id, '--new-revision-id' => (int) $new->id,
                '--source-updated-at' => (string) $source->getRawOriginal('updated_at'), '--revision-updated-at' => (string) $new->getRawOriginal('updated_at'), '--dry-run' => true];
            $pendingBefore = $pending->getAttributes();
            $pending->forceFill(['approved_at' => now()->subDay()])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:restore-source-version', $restore));
            $this->assertContains('preserved_source_revision_changed', json_decode(Artisan::output(), true)['errors']);
            $pending->forceFill($pendingBefore)->saveQuietly();
            $meta = $audit->meta_json;
            unset($meta['preserved_source_revisions']);
            $audit->forceFill(['meta_json' => $meta])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:restore-source-version', $restore));
            $this->assertContains('preserved_source_revision_changed', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame((int) $new->id, (int) $source->fresh()->published_revision_id);
        } finally {
            unlink($path);
        }
    }

    private function packageWithHistory(Article $source, ArticleTranslationRevision $old, ArticleSeoMeta $seo): array
    {
        [$path] = $this->packageWithTarget($source, $old, $seo);
        $package = json_decode(file_get_contents($path), true);
        $package['schema'] = 'fermat_article_source_reconcile_v4';
        $package['preserved_source_revisions'] = ArticleSourceTargetSnapshot::sourceRevisions($source);
        $bytes = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);

        return [$path, $sha, sprintf('Reconcile Article source %d in group %s with package %s.', $source->id, $source->translation_group_id, $sha)];
    }

    public function test_row_hash_rebuild_rejects_metadata_drift_that_leaves_body_unchanged(): void
    {
        [$source, $old, $seo] = $this->legacySource();
        $source->forceFill(['source_version_hash' => (string) $old->source_version_hash])->saveQuietly();
        $source = $source->fresh();
        $this->existingTarget($source);
        [$path, $sha, $confirm] = $this->packageWithRowHash($source, $old, $seo);
        try {
            $source->forceFill(['cover_image_alt' => 'Changed metadata only'])->saveQuietly();
            $this->assertSame(1, Artisan::call('articles:reconcile-source-version', [
                '--file' => $path, '--sha256' => $sha, '--execute' => true, '--confirm' => $confirm,
            ]));
            $this->assertContains('source_lock_mismatch', json_decode(Artisan::output(), true)['errors']);
            $this->assertSame((int) $old->id, (int) $source->fresh()->published_revision_id);
            $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'article_source_version_reconciled')->count());
        } finally {
            unlink($path);
        }
    }

    private function packageWithRowHash(Article $source, ArticleTranslationRevision $old, ArticleSeoMeta $seo): array
    {
        [$path] = $this->packageWithTarget($source, $old, $seo);
        $p = json_decode(file_get_contents($path), true);
        $p['schema'] = 'fermat_article_source_reconcile_v3';
        $p['computed_source_hash'] = $source->computeSourceVersionHash();
        $bytes = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $bytes);
        $sha = hash('sha256', $bytes);

        return [$path, $sha, sprintf('Reconcile Article source %d in group %s with package %s.',
            $source->id, $source->translation_group_id, $sha)];
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
    private function legacySource(int $sourceId = 0): array
    {
        $source = Article::forceCreate([
            ...($sourceId ? ['id' => $sourceId] : []),
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
