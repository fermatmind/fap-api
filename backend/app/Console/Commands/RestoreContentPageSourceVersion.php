<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** @review-surface content_page */
final class RestoreContentPageSourceVersion extends Command
{
    protected $signature = 'translation:restore-content-page-source-version
        {--source-id= : Exact source row ID}
        {--target-id= : Exact unchanged en target row ID}
        {--group-id= : Exact translation group ID}
        {--slug= : Exact shared slug}
        {--source-hash= : Current source row hash lock}
        {--target-hash= : Current target row hash lock}
        {--source-updated-at= : Current source row timestamp lock}
        {--target-updated-at= : Current target row timestamp lock}
        {--old-revision-id= : Original published source revision ID}
        {--new-revision-id= : Reconciled source revision ID}
        {--new-revision-updated-at= : Reconciled revision timestamp lock}
        {--audit-id= : Exact successful reconciliation audit ID}
        {--dry-run : Validate without writes}
        {--execute : Restore the old source pointers and archive the reconciled revision}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Restore one unchanged ContentPage source reconciliation under exact row, revision and audit locks.';

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
        if (! SchemaBaseline::hasTable('audit_logs')) {
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
                    /** @var ContentPage $source */
                    $source = $locked['source'];
                    /** @var ContentPage $target */
                    $target = $locked['target'];
                    /** @var CmsTranslationRevision $old */
                    $old = $locked['old'];
                    /** @var CmsTranslationRevision $new */
                    $new = $locked['new'];
                    $sourceBefore = $source->getAttributes();
                    $targetBefore = $target->getAttributes();
                    $oldBefore = $old->getAttributes();
                    $newBefore = $new->getAttributes();
                    $meta = (array) $locked['audit']->meta_json;

                    $source->forceFill([
                        'translation_status' => (string) $meta['before_status'],
                        'source_version_hash' => (string) $meta['before_hash'],
                        'working_revision_id' => (int) $old->id,
                        'published_revision_id' => (int) $old->id,
                    ])->saveQuietly();
                    $new->forceFill([
                        'revision_status' => CmsTranslationRevision::STATUS_ARCHIVED,
                        'archived_at' => now(),
                    ])->saveQuietly();

                    $sourceAfter = $source->fresh();
                    $targetAfter = $target->fresh();
                    $oldAfter = $old->fresh();
                    $newAfter = $new->fresh();
                    if (! $sourceAfter instanceof ContentPage || ! $targetAfter instanceof ContentPage
                        || ! $oldAfter instanceof CmsTranslationRevision || ! $newAfter instanceof CmsTranslationRevision) {
                        throw new RuntimeException('readback_missing');
                    }
                    $sourceReadback = $sourceAfter->getAttributes();
                    $newReadback = $newAfter->getAttributes();
                    foreach (['translation_status', 'source_version_hash', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                        unset($sourceBefore[$field], $sourceReadback[$field]);
                    }
                    foreach (['revision_status', 'archived_at', 'updated_at'] as $field) {
                        unset($newBefore[$field], $newReadback[$field]);
                    }
                    if ($sourceBefore !== $sourceReadback || $newBefore !== $newReadback
                        || $targetBefore !== $targetAfter->getAttributes() || $oldBefore !== $oldAfter->getAttributes()
                        || (int) $sourceAfter->working_revision_id !== (int) $old->id
                        || (int) $sourceAfter->published_revision_id !== (int) $old->id
                        || (string) $sourceAfter->translation_status !== (string) $meta['before_status']
                        || ! hash_equals((string) $meta['before_hash'], (string) $sourceAfter->source_version_hash)
                        || (string) $newAfter->revision_status !== CmsTranslationRevision::STATUS_ARCHIVED) {
                        throw new RuntimeException('restore_readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/restore-content-page-source-version', 'POST'),
                        'content_page_source_version_reconciliation_restored',
                        'content_page',
                        (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'target_id' => (int) $target->id,
                            'group_id' => (string) $source->translation_group_id,
                            'reconcile_audit_id' => (int) $locked['audit']->id,
                            'restored_revision_id' => (int) $old->id,
                            'archived_revision_id' => (int) $new->id,
                            'target_provenance_changed' => false,
                        ],
                        reason: 'controlled_content_page_source_version_restore',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'content_page_source_version_reconciliation_restored')
                        ->where('target_type', 'content_page')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('restore_audit_readback_failed');
                    }

                    return [
                        'source_id' => (int) $sourceAfter->id,
                        'restored_revision_id' => (int) $oldAfter->id,
                        'archived_revision_id' => (int) $newAfter->id,
                        'audit_id' => (int) $audit->id,
                    ];
                });
                try {
                    Cache::forget('content_page:v1:0:'.$this->option('slug').':zh-CN');
                } catch (Throwable $exception) {
                    Log::warning('translation_content_page_source_version_restore_cache_invalidation_failed', [
                        'source_id' => (int) ($after['source_id'] ?? 0),
                        'exception_type' => $exception::class,
                    ]);
                }
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $result = [
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'before' => [
                'source_id' => ($before['source'] ?? null)?->id,
                'target_id' => ($before['target'] ?? null)?->id,
                'old_revision_id' => ($before['old'] ?? null)?->id,
                'new_revision_id' => ($before['new'] ?? null)?->id,
                'audit_id' => ($before['audit'] ?? null)?->id,
            ],
            'after' => $after,
            'errors' => array_values(array_unique($errors)),
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('ok='.($result['ok'] ? '1' : '0'));
            $this->line('errors='.implode(',', $result['errors']));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function snapshot(bool $lock): array
    {
        $sourceId = (int) $this->option('source-id');
        $targetId = (int) $this->option('target-id');
        $oldId = (int) $this->option('old-revision-id');
        $newId = (int) $this->option('new-revision-id');
        $auditId = (int) $this->option('audit-id');
        $groupId = trim((string) $this->option('group-id'));
        $slug = trim((string) $this->option('slug'));
        if ($sourceId < 1 || $targetId < 1 || $oldId < 1 || $newId < 1 || $auditId < 1
            || $sourceId === $targetId || $oldId === $newId || $groupId === '' || $slug === '') {
            return ['errors' => ['invalid_exact_identity']];
        }
        $groupQuery = ContentPage::query()->withoutGlobalScopes()
            ->where('translation_group_id', $groupId)->orderBy('id');
        $revisionQuery = CmsTranslationRevision::query()->withoutGlobalScopes()
            ->whereIn('id', [$oldId, $newId])->orderBy('id');
        $auditQuery = AuditLog::query()->withoutGlobalScopes()->whereKey($auditId);
        if ($lock) {
            $groupQuery->lockForUpdate();
            $revisionQuery->lockForUpdate();
            $auditQuery->lockForUpdate();
        }
        $group = $groupQuery->get();
        $revisions = $revisionQuery->get()->keyBy('id');
        $source = $group->firstWhere('id', $sourceId);
        $target = $group->firstWhere('id', $targetId);
        $old = $revisions->get($oldId);
        $new = $revisions->get($newId);
        $audit = $auditQuery->first();
        if ($group->count() !== 2 || ! $source instanceof ContentPage || ! $target instanceof ContentPage
            || ! $old instanceof CmsTranslationRevision || ! $new instanceof CmsTranslationRevision
            || ! $audit instanceof AuditLog) {
            return ['errors' => ['identity_revision_or_audit_missing']];
        }

        $errors = [];
        $meta = (array) $audit->meta_json;
        if ((int) $source->org_id !== 0 || (int) $target->org_id !== 0
            || (string) $source->slug !== $slug || (string) $target->slug !== $slug
            || (string) $source->locale !== 'zh-CN' || (string) $source->source_locale !== 'zh-CN'
            || $source->source_content_id !== null || (string) $source->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE
            || (string) $target->locale !== 'en' || (string) $target->source_locale !== 'zh-CN'
            || (int) $target->source_content_id !== $sourceId
            || (int) $source->working_revision_id !== $newId || (int) $source->published_revision_id !== $newId
            || (string) $source->status !== ContentPage::STATUS_PUBLISHED || ! $source->passesPublicReadinessGate()
            || (string) $target->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $target->is_public) {
            $errors[] = 'source_target_state_invalid';
        }
        if ((string) $source->source_version_hash !== (string) $this->option('source-hash')
            || (string) $target->source_version_hash !== (string) $this->option('target-hash')
            || (string) $source->getRawOriginal('updated_at') !== (string) $this->option('source-updated-at')
            || (string) $target->getRawOriginal('updated_at') !== (string) $this->option('target-updated-at')
            || (string) $new->getRawOriginal('updated_at') !== (string) $this->option('new-revision-updated-at')) {
            $errors[] = 'row_or_revision_lock_mismatch';
        }
        foreach ([$old, $new] as $revision) {
            if ((int) $revision->org_id !== 0 || (string) $revision->content_type !== 'content_page'
                || (int) $revision->content_id !== $sourceId
                || (string) $revision->translation_group_id !== $groupId
                || (string) $revision->locale !== 'zh-CN' || (string) $revision->source_locale !== 'zh-CN'
                || $revision->source_content_id !== null) {
                $errors[] = 'source_revision_identity_invalid';
            }
        }
        $legacyApprovedRevision = (string) $old->revision_status === CmsTranslationRevision::STATUS_APPROVED
            && $old->getRawOriginal('published_at') !== null
            && (string) ($meta['old_revision_status'] ?? '') === CmsTranslationRevision::STATUS_APPROVED
            && (string) ($meta['old_revision_published_at'] ?? '') === (string) $old->getRawOriginal('published_at');
        if (! $legacyApprovedRevision && (string) $old->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED
            || (string) $new->revision_status !== CmsTranslationRevision::STATUS_SOURCE
            || (int) $new->supersedes_revision_id !== $oldId
            || (string) $audit->action !== 'content_page_source_version_reconciled'
            || (int) $audit->org_id !== 0 || (string) $audit->target_type !== 'content_page'
            || (string) $audit->target_id !== (string) $sourceId
            || (int) ($meta['source_id'] ?? 0) !== $sourceId
            || (int) ($meta['target_id'] ?? 0) !== $targetId
            || (string) ($meta['group_id'] ?? '') !== $groupId
            || (string) ($meta['slug'] ?? '') !== $slug
            || (int) ($meta['old_revision_id'] ?? 0) !== $oldId
            || (int) ($meta['new_revision_id'] ?? 0) !== $newId
            || (string) ($meta['after_hash'] ?? '') !== (string) $source->source_version_hash
            || (string) ($meta['target_hash'] ?? '') !== (string) $target->source_version_hash
            || (string) ($meta['target_updated_at'] ?? '') !== (string) $target->getRawOriginal('updated_at')
            || (string) ($meta['old_revision_hash'] ?? '') !== (string) $old->source_version_hash
            || (string) ($meta['old_revision_updated_at'] ?? '') !== (string) $old->getRawOriginal('updated_at')
            || (string) ($meta['old_revision_payload_hash'] ?? '') !== $this->payloadHash($old->payload_json)
            || (string) ($meta['new_revision_payload_hash'] ?? '') !== $this->payloadHash($new->payload_json)
            || (string) $new->source_version_hash !== (string) $source->source_version_hash
            || ! hash_equals((string) $source->source_version_hash, $source->freshSourceVersionHash())
            || (string) $target->translated_from_version_hash === (string) $source->source_version_hash) {
            $errors[] = 'reconciliation_audit_or_provenance_mismatch';
        }
        if (AuditLog::query()->withoutGlobalScopes()->where('id', '>', $auditId)
            ->where('target_type', 'content_page')->whereIn('target_id', [(string) $sourceId, (string) $targetId])->exists()
            || CmsTranslationRevision::query()->withoutGlobalScopes()->where('content_type', 'content_page')
                ->where('content_id', $sourceId)->where('id', '>', $newId)->exists()) {
            $errors[] = 'intervening_source_or_target_change';
        }

        return compact('errors', 'source', 'target', 'old', 'new', 'audit');
    }

    private function payloadHash(mixed $payload): string
    {
        return CanonicalTranslationPayloadHash::hash($payload);
    }

    private function confirmation(): string
    {
        return sprintf('Restore content page source %d revision %d.',
            (int) $this->option('source-id'), (int) $this->option('new-revision-id'));
    }
}
