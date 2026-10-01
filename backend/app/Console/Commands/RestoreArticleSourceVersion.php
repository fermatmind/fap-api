<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\ArticleSourceTargetSnapshot;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class RestoreArticleSourceVersion extends Command
{
    protected $signature = 'articles:restore-source-version
        {--source-id= : Exact source row ID}
        {--audit-id= : Exact reconciliation audit ID}
        {--new-revision-id= : Exact current source revision ID}
        {--source-updated-at= : Current source row timestamp}
        {--revision-updated-at= : Current source revision timestamp}
        {--dry-run : Read and validate only}
        {--execute : Restore the old source pointers and archive the new revision}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Undo one unchanged Article source reconciliation before any English target depends on it.';

    public function handle(AuditLogger $auditLogger): int
    {
        $execute = (bool) $this->option('execute');
        $errors = [];
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($execute && ! hash_equals($this->confirmation(), trim((string) $this->option('confirm')))) {
            $errors[] = 'confirmation_mismatch';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        try {
            $before = $this->snapshot(false);
            $errors = array_values(array_unique([...$errors, ...$before['errors']]));
        } catch (Throwable) {
            $before = ['errors' => ['snapshot_failed']];
            $errors[] = 'snapshot_failed';
        }
        $after = null;
        if ($execute && $errors === []) {
            try {
                $after = DB::transaction(function () use ($auditLogger): array {
                    $locked = $this->snapshot(true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException(implode(',', $locked['errors']));
                    }
                    /** @var Article $source */
                    $source = $locked['source'];
                    /** @var ArticleTranslationRevision $old */
                    $old = $locked['old'];
                    /** @var ArticleTranslationRevision $new */
                    $new = $locked['new'];
                    $oldBefore = $old->getAttributes();
                    $sourceBefore = $source->getAttributes();
                    $new->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED])->saveQuietly();
                    $source->forceFill([
                        'translation_status' => $locked['old_translation_status'],
                        'source_version_hash' => (string) ($locked['old_source_hash']),
                        'working_revision_id' => (int) $old->id,
                        'published_revision_id' => (int) $old->id,
                    ])->saveQuietly();
                    $sourceAfter = $source->fresh();
                    $oldAfter = $old->fresh();
                    $newAfter = $new->fresh();
                    if (! $sourceAfter instanceof Article || ! $oldAfter instanceof ArticleTranslationRevision
                        || ! $newAfter instanceof ArticleTranslationRevision) {
                        throw new RuntimeException('readback_missing');
                    }
                    $sourceAttributes = $sourceAfter->getAttributes();
                    foreach (['translation_status', 'source_version_hash', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                        unset($sourceBefore[$field], $sourceAttributes[$field]);
                    }
                    if ($sourceBefore !== $sourceAttributes || $oldBefore !== $oldAfter->getAttributes()
                        || ! hash_equals($locked['old_source_hash'], (string) $sourceAfter->source_version_hash)
                        || (string) $sourceAfter->translation_status !== $locked['old_translation_status']
                        || (int) $sourceAfter->working_revision_id !== (int) $old->id
                        || (int) $sourceAfter->published_revision_id !== (int) $old->id
                        || (string) $newAfter->revision_status !== ArticleTranslationRevision::STATUS_ARCHIVED
                        || (string) $newAfter->content_md !== (string) $sourceAfter->content_md) {
                        throw new RuntimeException('readback_mismatch');
                    }
                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/article-translation/restore-source-version', 'POST'),
                        'article_source_version_reconciliation_restored', 'article', (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'old_revision_id' => (int) $old->id,
                            'archived_revision_id' => (int) $new->id,
                            'reconciliation_audit_id' => (int) $this->option('audit-id'),
                            'public_copy_changed' => false,
                        ],
                        reason: 'controlled_article_source_version_reconciliation_restore', result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'article_source_version_reconciliation_restored')
                        ->where('target_type', 'article')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['source_id' => (int) $source->id, 'restored_revision_id' => (int) $old->id,
                        'archived_revision_id' => (int) $new->id, 'audit_id' => (int) $audit->id];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }
        $result = ['ok' => $errors === [], 'mode' => $execute ? 'execute' : 'dry_run',
            'source_id' => (int) $this->option('source-id'),
            'audit_id' => (int) $this->option('audit-id'), 'after' => $after,
            'errors' => array_values(array_unique($errors))];
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function snapshot(bool $lock): array
    {
        $sourceId = (int) $this->option('source-id');
        $auditId = (int) $this->option('audit-id');
        $newId = (int) $this->option('new-revision-id');
        if ($sourceId <= 0 || $auditId <= 0 || $newId <= 0) {
            return ['errors' => ['invalid_ids']];
        }
        $sourceQuery = Article::query()->withoutGlobalScopes()->whereKey($sourceId);
        $newQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($newId);
        if ($lock) {
            $sourceQuery->lockForUpdate();
            $newQuery->lockForUpdate();
        }
        $source = $sourceQuery->first();
        $new = $newQuery->first();
        $audit = AuditLog::query()->withoutGlobalScopes()->whereKey($auditId)->first();
        if (! $source instanceof Article || ! $new instanceof ArticleTranslationRevision || ! $audit instanceof AuditLog) {
            return ['errors' => ['source_revision_or_audit_missing']];
        }
        $meta = $audit->meta_json ?? [];
        $oldId = (int) ($meta['old_revision_id'] ?? 0);
        $oldQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()->whereKey($oldId);
        if ($lock) {
            $oldQuery->lockForUpdate();
        }
        $old = $oldQuery->first();
        $old_source_hash = $meta['old_source_hash'] ?? ($meta['source_version_hash'] ?? '');
        $old_translation_status = $meta['old_translation_status'] ?? Article::TRANSLATION_STATUS_APPROVED;
        $errors = [];
        if (! is_string($old_translation_status)
            || ! ReconcileArticleSourceVersion::allowsOriginalStatus($sourceId, $old_translation_status)
            || ! is_string($old_source_hash) || ! preg_match('/^[0-9a-f]{64}$/', $old_source_hash)
            || ! $old instanceof ArticleTranslationRevision
            || (int) $source->org_id !== 0 || ! $source->isSourceArticle()
            || (string) $source->status !== 'published' || ! (bool) $source->is_public
            || (int) $source->working_revision_id !== $newId
            || (int) $source->published_revision_id !== $newId
            || (string) $source->getRawOriginal('updated_at') !== (string) $this->option('source-updated-at')
            || (string) $new->getRawOriginal('updated_at') !== (string) $this->option('revision-updated-at')
            || ! in_array((string) $new->revision_status, [
                ArticleTranslationRevision::STATUS_SOURCE,
                ArticleTranslationRevision::STATUS_PUBLISHED,
            ], true)
            || (int) $new->article_id !== $sourceId || (int) $new->source_article_id !== $sourceId
            || (int) $new->supersedes_revision_id !== $oldId
            || (string) $new->translation_group_id !== (string) $source->translation_group_id
            || ! ReconcileArticleSourceVersion::supportsSourceLocale($source)
            || (string) $new->locale !== (string) $source->locale
            || (string) $new->source_locale !== (string) $source->source_locale
            || (string) $new->content_md !== (string) $source->content_md
            || (string) $new->title !== (string) $source->title
            || (string) $new->excerpt !== (string) $source->excerpt
            || ! hash_equals((string) $new->source_version_hash, (string) $source->source_version_hash)
            || ! hash_equals((string) $source->source_version_hash, $source->computeSourceVersionHash())
            || (int) $old->article_id !== $sourceId
            || ! ReconcileArticleSourceVersion::allowsOriginalRevisionStatus($sourceId, (string) $old->revision_status)
            || ! array_key_exists('old_revision_hash', $meta)
            || $meta['old_revision_hash'] !== $old->getRawOriginal('source_version_hash')
            || (string) ($meta['old_revision_body_sha256'] ?? '') !== hash('sha256', (string) $old->content_md)
            || (string) ($meta['old_revision_updated_at'] ?? '') !== (string) $old->getRawOriginal('updated_at')
            || (string) $audit->action !== 'article_source_version_reconciled'
            || (int) $audit->org_id !== 0 || (string) $audit->target_type !== 'article'
            || (string) $audit->target_id !== (string) $sourceId
            || (int) ($meta['source_id'] ?? 0) !== $sourceId
            || (int) ($meta['new_revision_id'] ?? 0) !== $newId
            || (string) ($meta['source_version_hash'] ?? '') !== (string) $source->source_version_hash) {
            $errors[] = 'reconciliation_lock_mismatch';
        }
        try {
            $targetChanged = ! $this->sameFingerprints(ReconcileArticleSourceVersion::targetSnapshot($source, $lock), $meta['existing_english_targets'] ?? []);
        } catch (RuntimeException) {
            $targetChanged = true;
        }
        if ($targetChanged
            || ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', $sourceId)
                ->where('id', '>', $newId)->exists()
            || AuditLog::query()->withoutGlobalScopes()->where('id', '>', $auditId)
                ->where('target_type', 'article')->where('target_id', (string) $sourceId)->exists()) {
            $errors[] = 'intervening_dependency_or_change';
        }
        if (($meta['source_package_schema'] ?? null) === 'fermat_article_source_reconcile_v4') {
            try {
                $history = ArticleSourceTargetSnapshot::sourceRevisions($source, [$newId], $lock);
                if (! $this->sameFingerprints($history, $meta['preserved_source_revisions'] ?? null)) {
                    $errors[] = 'preserved_source_revision_changed';
                }
            } catch (RuntimeException) {
                $errors[] = 'preserved_source_revision_changed';
            }
        }

        return compact('errors', 'source', 'old', 'new', 'old_source_hash', 'old_translation_status');
    }

    /** JSON object key order is not identity; list order and all value types are. */
    private function sameFingerprints(array $actual, mixed $expected): bool
    {
        if (! is_array($expected) || ! array_is_list($expected) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($actual as $index => $row) {
            if (! is_array($expected[$index])) {
                return false;
            }
            $saved = $expected[$index];
            ksort($row);
            ksort($saved);
            if ($row !== $saved) {
                return false;
            }
        }

        return true;
    }

    private function confirmation(): string
    {
        return sprintf('Restore Article source %d from reconciliation audit %d and revision %d.',
            (int) $this->option('source-id'), (int) $this->option('audit-id'),
            (int) $this->option('new-revision-id'));
    }
}
