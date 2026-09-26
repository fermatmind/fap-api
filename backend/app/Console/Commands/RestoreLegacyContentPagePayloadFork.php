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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface content_page */
final class RestoreLegacyContentPagePayloadFork extends Command
{
    protected $signature = 'translation:restore-content-page-payload-fork
        {--target-id= : Exact public en row ID}
        {--group-id= : Exact translation group ID}
        {--source-id= : Exact source row ID}
        {--source-hash= : Exact source row version hash}
        {--target-hash= : Exact target row version hash}
        {--source-updated-at= : Exact source row timestamp}
        {--target-updated-at= : Exact target row timestamp}
        {--published-revision-id= : Original published revision ID}
        {--published-revision-updated-at= : Original published revision timestamp}
        {--published-payload-hash= : Original published payload SHA256}
        {--draft-revision-id= : Exact forked draft revision ID}
        {--draft-revision-updated-at= : Exact forked draft timestamp}
        {--draft-payload-hash= : Exact forked draft payload SHA256}
        {--fork-audit-id= : Exact successful fork audit ID}
        {--dry-run : Validate without writes}
        {--execute : Restore old working pointer and archive only untouched forked draft}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Restore a legacy ContentPage payload fork while retaining its audit trail and old published revision.';

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
                    /** @var ContentPage $target */
                    $target = $locked['target'];
                    /** @var CmsTranslationRevision $draft */
                    $draft = $locked['draft'];
                    /** @var CmsTranslationRevision $published */
                    $published = $locked['published'];
                    $targetBefore = $target->getAttributes();
                    $draftBefore = $draft->getAttributes();
                    $publishedBefore = $published->getAttributes();

                    $target->forceFill([
                        'working_revision_id' => (int) $published->id,
                        'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
                    ])->saveQuietly();
                    $draft->forceFill([
                        'revision_status' => CmsTranslationRevision::STATUS_ARCHIVED,
                        'archived_at' => now(),
                    ])->saveQuietly();

                    $targetAfter = $target->fresh();
                    $draftAfter = $draft->fresh();
                    $publishedAfter = $published->fresh();
                    $targetReadback = $targetAfter?->getAttributes() ?? [];
                    $draftReadback = $draftAfter?->getAttributes() ?? [];
                    foreach (['working_revision_id', 'translation_status', 'updated_at'] as $key) {
                        unset($targetBefore[$key], $targetReadback[$key]);
                    }
                    foreach (['revision_status', 'archived_at', 'updated_at'] as $key) {
                        unset($draftBefore[$key], $draftReadback[$key]);
                    }
                    if (! $targetAfter instanceof ContentPage
                        || ! $draftAfter instanceof CmsTranslationRevision
                        || ! $publishedAfter instanceof CmsTranslationRevision
                        || $targetBefore !== $targetReadback
                        || $draftBefore !== $draftReadback
                        || $publishedBefore !== $publishedAfter->getAttributes()
                        || (int) $targetAfter->working_revision_id !== (int) $publishedAfter->id
                        || (int) $targetAfter->published_revision_id !== (int) $publishedAfter->id
                        || $draftAfter->revision_status !== CmsTranslationRevision::STATUS_ARCHIVED) {
                        throw new RuntimeException('restore_readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/restore-content-page-payload-fork', 'POST'),
                        'content_page_payload_draft_fork_restored',
                        'content_page',
                        (string) $target->id,
                        [
                            'group_id' => (string) $target->translation_group_id,
                            'fork_audit_id' => (int) $this->option('fork-audit-id'),
                            'published_revision_id' => (int) $publishedAfter->id,
                            'archived_draft_revision_id' => (int) $draftAfter->id,
                            'published_content_changed' => false,
                        ],
                        reason: 'controlled_legacy_payload_draft_fork_restore',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'content_page_payload_draft_fork_restored')
                        ->where('target_type', 'content_page')->where('target_id', (string) $target->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('restore_audit_readback_failed');
                    }

                    return [
                        'target_id' => (int) $targetAfter->id,
                        'working_revision_id' => (int) $publishedAfter->id,
                        'published_revision_id' => (int) $publishedAfter->id,
                        'archived_draft_revision_id' => (int) $draftAfter->id,
                        'audit_id' => (int) $audit->id,
                    ];
                });
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $result = [
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'before' => $this->publicSnapshot($before),
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
        $publishedId = (int) $this->option('published-revision-id');
        $draftId = (int) $this->option('draft-revision-id');
        $auditId = (int) $this->option('fork-audit-id');
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId
            || $publishedId < 1 || $draftId < 1 || $publishedId === $draftId || $auditId < 1) {
            return ['errors' => ['invalid_exact_ids']];
        }

        $rowsQuery = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->whereIn('id', [$sourceId, $targetId])->orderBy('id');
        $revisionsQuery = CmsTranslationRevision::query()->whereIn('id', [$publishedId, $draftId])->orderBy('id');
        $auditQuery = AuditLog::query()->withoutGlobalScopes()->whereKey($auditId);
        if ($lock) {
            $rowsQuery->lockForUpdate();
            $revisionsQuery->lockForUpdate();
            $auditQuery->lockForUpdate();
        }
        $rows = $rowsQuery->get()->keyBy('id');
        $revisions = $revisionsQuery->get()->keyBy('id');
        $source = $rows->get($sourceId);
        $target = $rows->get($targetId);
        $published = $revisions->get($publishedId);
        $draft = $revisions->get($draftId);
        $audit = $auditQuery->first();
        if (! $source instanceof ContentPage || ! $target instanceof ContentPage
            || ! $published instanceof CmsTranslationRevision || ! $draft instanceof CmsTranslationRevision
            || ! $audit instanceof AuditLog) {
            return ['errors' => ['identity_revision_or_audit_missing']];
        }

        $errors = [];
        $groupQuery = ContentPage::query()->withoutGlobalScopes()
            ->where('translation_group_id', (string) $this->option('group-id'))->orderBy('id');
        if ($lock) {
            $groupQuery->lockForUpdate();
        }
        $groupRows = $groupQuery->get();
        if ($groupRows->count() !== 2 || $groupRows->contains(fn (ContentPage $row): bool => (int) $row->org_id !== 0)) {
            $errors[] = 'group_not_unique_public_pair';
        }
        if ((string) $source->translation_group_id !== (string) $this->option('group-id')
            || (string) $target->translation_group_id !== (string) $this->option('group-id')
            || (string) $source->slug !== (string) $target->slug
            || (string) $source->locale !== 'zh-CN' || (string) $target->locale !== 'en'
            || (int) $target->source_content_id !== $sourceId
            || (string) $target->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $target->is_public
            || (string) $target->translation_status !== ContentPage::TRANSLATION_STATUS_DRAFT) {
            $errors[] = 'source_target_identity_invalid';
        }
        if ((string) $source->source_version_hash !== (string) $this->option('source-hash')
            || (string) $target->source_version_hash !== (string) $this->option('target-hash')
            || (string) $source->getRawOriginal('updated_at') !== (string) $this->option('source-updated-at')
            || (string) $target->getRawOriginal('updated_at') !== (string) $this->option('target-updated-at')) {
            $errors[] = 'row_lock_mismatch';
        }
        if ((int) $target->working_revision_id !== $draftId
            || (int) $target->published_revision_id !== $publishedId
            || (string) $published->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED
            || (string) $draft->revision_status !== CmsTranslationRevision::STATUS_DRAFT
            || (int) $published->content_id !== $targetId || (int) $draft->content_id !== $targetId
            || (string) $published->content_type !== 'content_page'
            || (string) $draft->content_type !== 'content_page'
            || (int) $draft->supersedes_revision_id !== $publishedId
            || $draft->reviewed_at !== null || $draft->approved_at !== null || $draft->published_at !== null
            || (string) $published->getRawOriginal('updated_at') !== (string) $this->option('published-revision-updated-at')
            || (string) $draft->getRawOriginal('updated_at') !== (string) $this->option('draft-revision-updated-at')) {
            $errors[] = 'revision_lock_or_state_mismatch';
        }
        if ($this->payloadHash($published->payload_json) !== (string) $this->option('published-payload-hash')
            || $this->payloadHash($draft->payload_json) !== (string) $this->option('draft-payload-hash')) {
            $errors[] = 'payload_hash_mismatch';
        }
        $meta = (array) $audit->meta_json;
        if ((int) $audit->org_id !== 0 || (string) $audit->action !== 'content_page_payload_draft_forked'
            || (string) $audit->target_type !== 'content_page' || (string) $audit->target_id !== (string) $targetId
            || (int) ($meta['source_id'] ?? 0) !== $sourceId
            || (string) ($meta['group_id'] ?? '') !== (string) $this->option('group-id')
            || (int) ($meta['published_revision_id'] ?? 0) !== $publishedId
            || (int) ($meta['new_working_revision_id'] ?? 0) !== $draftId
            || (string) ($meta['old_payload_hash'] ?? '') !== (string) $this->option('published-payload-hash')
            || (string) ($meta['new_payload_hash'] ?? '') !== (string) $this->option('draft-payload-hash')) {
            $errors[] = 'fork_audit_mismatch';
        }
        if (AuditLog::query()->withoutGlobalScopes()
            ->where('id', '>', $auditId)
            ->where('target_type', 'content_page')
            ->where('target_id', (string) $targetId)
            ->exists()) {
            $errors[] = 'intervening_target_audit';
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'source' => $source,
            'target' => $target,
            'published' => $published,
            'draft' => $draft,
            'audit' => $audit,
        ];
    }

    private function payloadHash(mixed $payload): string
    {
        return CanonicalTranslationPayloadHash::hash($payload);
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'target_id' => ($snapshot['target'] ?? null)?->id,
            'published_revision_id' => ($snapshot['published'] ?? null)?->id,
            'draft_revision_id' => ($snapshot['draft'] ?? null)?->id,
            'fork_audit_id' => ($snapshot['audit'] ?? null)?->id,
        ];
    }

    private function confirmation(): string
    {
        return sprintf('Restore content page target %d payload fork %d.', (int) $this->option('target-id'), (int) $this->option('draft-revision-id'));
    }
}
