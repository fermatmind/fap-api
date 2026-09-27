<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface article_translation_revision */
final class RestoreLegacyArticleSourceReviewStage extends Command
{
    protected $signature = 'articles:restore-legacy-source-review-stage
        {--source-id= : Exact source row ID}
        {--audit-id= : Exact staging audit ID}
        {--draft-id= : Exact private working revision ID}
        {--source-updated-at= : Exact source row timestamp after staging}
        {--draft-updated-at= : Exact draft revision timestamp after staging}
        {--target-updated-at= : Exact English target timestamp after staging}
        {--dry-run : Validate without writes}
        {--execute : Restore the original working pointer, status and hash}
        {--confirm= : Exact execute confirmation}';

    protected $description = 'Restore an untouched, unreviewed legacy Article source stage without changing public pointers.';

    public function handle(AuditLogger $auditLogger): int
    {
        $execute = (bool) $this->option('execute');
        $errors = [];
        if ($execute === (bool) $this->option('dry-run')) {
            $errors[] = 'exactly_one_mode_required';
        }
        if ($execute && ! SchemaBaseline::hasTable('audit_logs')) {
            $errors[] = 'audit_log_unavailable';
        }
        if ($execute && ! hash_equals($this->confirmation(), trim((string) $this->option('confirm')))) {
            $errors[] = 'confirmation_mismatch';
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
                    /** @var ArticleTranslationRevision $draft */
                    $draft = $locked['draft'];
                    /** @var ArticleTranslationRevision $published */
                    $published = $locked['published'];
                    /** @var Article $target */
                    $target = $locked['target'];
                    /** @var ArticleTranslationRevision $targetPublished */
                    $targetPublished = $locked['target_published'];
                    $sourceBefore = $source->getAttributes();
                    $publishedBefore = $published->getAttributes();
                    $targetBefore = $target->getAttributes();
                    $targetPublishedBefore = $targetPublished->getAttributes();
                    $meta = $locked['meta'];
                    $source->forceFill([
                        'translation_status' => $meta['old_translation_status'],
                        'source_version_hash' => $meta['old_source_hash'],
                        'working_revision_id' => (int) $published->id,
                    ])->saveQuietly();
                    $draft->forceFill(['revision_status' => ArticleTranslationRevision::STATUS_ARCHIVED])->save();

                    $sourceAfter = $source->fresh();
                    $draftAfter = $draft->fresh();
                    $publishedAfter = $published->fresh();
                    $targetAfter = $target->fresh();
                    $targetPublishedAfter = $targetPublished->fresh();
                    if (! $sourceAfter instanceof Article || ! $draftAfter instanceof ArticleTranslationRevision
                        || ! $publishedAfter instanceof ArticleTranslationRevision || ! $targetAfter instanceof Article
                        || ! $targetPublishedAfter instanceof ArticleTranslationRevision) {
                        throw new RuntimeException('readback_missing');
                    }
                    $sourceAfterAttributes = $sourceAfter->getAttributes();
                    foreach (['translation_status', 'source_version_hash', 'working_revision_id', 'updated_at'] as $field) {
                        unset($sourceBefore[$field], $sourceAfterAttributes[$field]);
                    }
                    if ($sourceBefore !== $sourceAfterAttributes
                        || $publishedBefore !== $publishedAfter->getAttributes()
                        || $targetBefore !== $targetAfter->getAttributes()
                        || $targetPublishedBefore !== $targetPublishedAfter->getAttributes()
                        || (string) $sourceAfter->translation_status !== (string) $meta['old_translation_status']
                        || (string) $sourceAfter->source_version_hash !== (string) $meta['old_source_hash']
                        || (int) $sourceAfter->working_revision_id !== (int) $published->id
                        || (int) $sourceAfter->published_revision_id !== (int) $published->id
                        || (string) $draftAfter->revision_status !== ArticleTranslationRevision::STATUS_ARCHIVED) {
                        throw new RuntimeException('readback_mismatch');
                    }
                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/article-translation/restore-legacy-source-review-stage', 'POST'),
                        'article_legacy_source_review_stage_restored', 'article', (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'stage_audit_id' => (int) $this->option('audit-id'),
                            'archived_draft_id' => (int) $draft->id,
                            'published_revision_id' => (int) $published->id,
                            'target_id' => (int) $target->id,
                            'public_revision_changed' => false,
                        ],
                        reason: 'controlled_legacy_article_source_review_stage_restore', result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'article_legacy_source_review_stage_restored')
                        ->where('target_type', 'article')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return ['source_id' => (int) $source->id, 'working_revision_id' => (int) $published->id,
                        'published_revision_id' => (int) $published->id, 'archived_draft_id' => (int) $draft->id,
                        'audit_id' => (int) $audit->id];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $this->line(json_encode([
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'source_id' => (int) $this->option('source-id'),
            'before' => ['published_revision_id' => $before['published_revision_id'] ?? null,
                'working_revision_id' => $before['working_revision_id'] ?? null],
            'after' => $after,
            'errors' => array_values(array_unique($errors)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function snapshot(bool $lock): array
    {
        $sourceId = (int) $this->option('source-id');
        $auditId = (int) $this->option('audit-id');
        $draftId = (int) $this->option('draft-id');
        if ($sourceId <= 0 || $auditId <= 0 || $draftId <= 0
            || trim((string) $this->option('source-updated-at')) === ''
            || trim((string) $this->option('draft-updated-at')) === ''
            || trim((string) $this->option('target-updated-at')) === '') {
            return ['errors' => ['identity_lock_invalid']];
        }
        $audit = AuditLog::query()->withoutGlobalScopes()->whereKey($auditId)->first();
        $meta = (array) ($audit?->meta_json ?? []);
        if (! $audit instanceof AuditLog || (string) $audit->action !== 'article_legacy_source_review_staged'
            || (string) $audit->target_type !== 'article' || (string) $audit->target_id !== (string) $sourceId
            || (int) ($meta['source_id'] ?? 0) !== $sourceId
            || (int) ($meta['new_working_revision_id'] ?? 0) !== $draftId) {
            return ['errors' => ['stage_audit_lock_invalid']];
        }
        $sourceQuery = Article::query()->withoutGlobalScopes()->withTrashed()->whereKey($sourceId);
        $targetQuery = Article::query()->withoutGlobalScopes()->withTrashed()->whereKey((int) $meta['target_id']);
        $revisionQuery = ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->whereIn('id', [$draftId, (int) $meta['published_revision_id'],
                (int) $meta['target_published_revision_id']])->orderBy('id');
        if ($lock) {
            $sourceQuery->lockForUpdate();
            $targetQuery->lockForUpdate();
            $revisionQuery->lockForUpdate();
        }
        $source = $sourceQuery->first();
        $target = $targetQuery->first();
        $revisions = $revisionQuery->get()->keyBy('id');
        $draft = $revisions->get($draftId);
        $published = $revisions->get((int) $meta['published_revision_id']);
        $targetPublished = $revisions->get((int) $meta['target_published_revision_id']);
        $errors = [];
        if (! $source instanceof Article || ! $target instanceof Article
            || ! $draft instanceof ArticleTranslationRevision || ! $published instanceof ArticleTranslationRevision
            || ! $targetPublished instanceof ArticleTranslationRevision) {
            return ['errors' => ['source_target_or_revision_missing']];
        }
        if ((int) $source->org_id !== 0 || $source->deleted_at !== null
            || (string) $source->translation_group_id !== (string) ($meta['group_id'] ?? '')
            || (string) $source->translation_status !== Article::TRANSLATION_STATUS_SOURCE
            || ! hash_equals((string) ($meta['source_after_record_sha256'] ?? ''), $this->recordHash($source))
            || ! hash_equals((string) ($meta['new_source_hash'] ?? ''), (string) $source->source_version_hash)
            || (int) $source->working_revision_id !== $draftId
            || (int) $source->published_revision_id !== (int) $published->id
            || (string) $source->getRawOriginal('updated_at') !== (string) $this->option('source-updated-at')) {
            $errors[] = 'source_changed_after_stage';
        }
        if ((int) $draft->article_id !== $sourceId || (int) $draft->source_article_id !== $sourceId
            || ! hash_equals((string) ($meta['draft_record_sha256'] ?? ''), $this->recordHash($draft))
            || (int) $draft->supersedes_revision_id !== (int) $published->id
            || (string) $draft->revision_status !== ArticleTranslationRevision::STATUS_HUMAN_REVIEW
            || ! hash_equals((string) ($meta['new_source_hash'] ?? ''), (string) $draft->source_version_hash)
            || (string) $draft->title !== (string) $source->title
            || (string) $draft->excerpt !== (string) $source->excerpt
            || (string) $draft->content_md !== (string) $source->content_md
            || (string) $draft->seo_title !== (string) $published->seo_title
            || (string) $draft->seo_description !== (string) $published->seo_description
            || $draft->reviewed_at !== null || $draft->approved_at !== null || $draft->published_at !== null
            || (string) $draft->getRawOriginal('updated_at') !== (string) $this->option('draft-updated-at')) {
            $errors[] = 'draft_changed_or_reviewed';
        }
        if ((int) $published->article_id !== $sourceId
            || (string) $published->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED
            || ! hash_equals((string) ($meta['published_record_sha256'] ?? ''), $this->recordHash($published))
            || (int) $target->published_revision_id !== (int) $targetPublished->id
            || ! hash_equals((string) ($meta['target_record_sha256'] ?? ''), $this->recordHash($target))
            || (string) $target->getRawOriginal('updated_at') !== (string) $this->option('target-updated-at')
            || (int) $target->source_article_id !== $sourceId
            || (int) $target->translated_from_article_id !== $sourceId
            || (int) $targetPublished->article_id !== (int) $target->id
            || ! hash_equals((string) ($meta['target_published_record_sha256'] ?? ''), $this->recordHash($targetPublished))
            || (int) $targetPublished->source_article_id !== $sourceId) {
            $errors[] = 'public_dependency_changed';
        }
        if (ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('supersedes_revision_id', $draftId)->exists()
            || ArticleTranslationRevision::query()->withoutGlobalScopes()
                ->where('article_id', $sourceId)->whereNotIn('id', [$draftId, (int) $published->id])
                ->whereIn('revision_status', [
                    ArticleTranslationRevision::STATUS_SOURCE,
                    ArticleTranslationRevision::STATUS_MACHINE_DRAFT,
                    ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
                    ArticleTranslationRevision::STATUS_APPROVED,
                ])->exists()
            || AuditLog::query()->withoutGlobalScopes()->where('id', '>', $auditId)
                ->where('target_type', 'article')->where('target_id', (string) $sourceId)->exists()) {
            $errors[] = 'intervening_dependency_or_review';
        }

        return ['errors' => $errors, 'source' => $source, 'draft' => $draft,
            'published' => $published, 'target' => $target, 'target_published' => $targetPublished,
            'meta' => $meta, 'published_revision_id' => (int) $published->id,
            'working_revision_id' => (int) $draft->id];
    }

    private function confirmation(): string
    {
        return sprintf('Restore legacy Article source %d from stage audit %d and draft %d.',
            (int) $this->option('source-id'), (int) $this->option('audit-id'), (int) $this->option('draft-id'));
    }

    private function recordHash(Article|ArticleTranslationRevision $record): string
    {
        $attributes = $record->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
