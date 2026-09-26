<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LinkContentPageTranslationSource extends Command
{
    protected $signature = 'translation:link-content-page-source
        {--source-id= : Exact public zh-CN source row ID}
        {--target-id= : Exact public en target row ID}
        {--group-id= : Exact shared translation group ID}
        {--slug= : Exact shared slug}
        {--source-hash= : Source row SHA256 lock}
        {--target-hash= : Target row SHA256 lock}
        {--revision-id= : Exact target working/published revision ID}
        {--source-updated-at= : Exact source row timestamp}
        {--target-updated-at= : Exact target row timestamp}
        {--restore-audit-id= : Exact prior linkage audit ID to restore an unlinked row}
        {--dry-run : Validate without writes}
        {--execute : Link or restore exactly one row}
        {--confirm= : Exact execute confirmation}
        {--json : Emit JSON}';

    protected $description = 'Link one unambiguous legacy content-page target to its source row without asserting translation freshness.';

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
            $errors = array_values(array_unique(array_merge($errors, $before['errors'])));
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
                    $beforeAttributes = $target->getAttributes();
                    $target->forceFill(['source_content_id' => $this->isRestore() ? null : (int) $this->option('source-id')])->saveQuietly();
                    $readback = $this->snapshot(true, true);
                    $afterAttributes = $target->fresh()?->getAttributes() ?? [];
                    unset($beforeAttributes['source_content_id'], $beforeAttributes['updated_at']);
                    unset($afterAttributes['source_content_id'], $afterAttributes['updated_at']);
                    if ($readback['errors'] !== [] || $afterAttributes !== $beforeAttributes
                        || $readback['revision'] !== $locked['revision']) {
                        throw new RuntimeException('readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/link-content-page-source', 'POST'),
                        $this->isRestore() ? 'content_page_source_link_restored' : 'content_page_source_linked',
                        'content_page',
                        (string) $target->id,
                        [
                            'source_id' => (int) $this->option('source-id'),
                            'target_id' => (int) $target->id,
                            'group_id' => (string) $this->option('group-id'),
                            'slug' => (string) $this->option('slug'),
                            'source_hash' => (string) $this->option('source-hash'),
                            'target_hash' => (string) $this->option('target-hash'),
                            'revision_id' => (int) $this->option('revision-id'),
                            'before_source_content_id' => $this->isRestore() ? (int) $this->option('source-id') : null,
                            'after_source_content_id' => $this->isRestore() ? null : (int) $this->option('source-id'),
                            'restore_audit_id' => $this->isRestore() ? (int) $this->option('restore-audit-id') : null,
                            'translation_freshness_attested' => false,
                        ],
                        reason: $this->isRestore() ? 'controlled_content_page_source_link_restore' : 'controlled_content_page_source_identity_repair',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', $this->isRestore() ? 'content_page_source_link_restored' : 'content_page_source_linked')
                        ->where('target_type', 'content_page')->where('target_id', (string) $target->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }
                    $readback['audit_id'] = (int) $audit->id;

                    return $readback;
                });
                try {
                    Cache::forget('content_page:v1:0:'.$after['target']['slug'].':en');
                } catch (Throwable) {
                    // A cache failure cannot reverse a committed identity repair.
                }
            } catch (Throwable) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $result = [
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'action' => $execute && $errors === []
                ? ($this->isRestore() ? 'source_link_restored' : 'source_linked')
                : ($this->isRestore() ? 'would_restore_source_link' : 'would_link_source'),
            'before' => $this->publicSnapshot($before),
            'after' => $after === null ? null : $this->publicSnapshot($after),
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

    /** @return array<string,mixed> */
    private function snapshot(bool $lock, bool $after = false): array
    {
        $sourceId = (int) $this->option('source-id');
        $targetId = (int) $this->option('target-id');
        $groupId = trim((string) $this->option('group-id'));
        $slug = trim((string) $this->option('slug'));
        $sourceHash = trim((string) $this->option('source-hash'));
        $targetHash = trim((string) $this->option('target-hash'));
        $revisionId = (int) $this->option('revision-id');
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId || $groupId === '' || $slug === ''
            || $revisionId <= 0 || preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $targetHash) !== 1) {
            return ['errors' => ['identity_or_hash_lock_invalid']];
        }

        $query = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('translation_group_id', $groupId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $group = $query->get();
        /** @var ContentPage|null $source */
        $source = $group->firstWhere('id', $sourceId);
        /** @var ContentPage|null $target */
        $target = $group->firstWhere('id', $targetId);
        if ($group->count() !== 2 || ! $source instanceof ContentPage || ! $target instanceof ContentPage) {
            return ['errors' => ['group_pair_ambiguous']];
        }
        $errors = [];
        if ((string) $source->slug !== $slug || (string) $target->slug !== $slug
            || (string) $source->locale !== 'zh-CN' || (string) $source->source_locale !== 'zh-CN'
            || (string) $target->locale !== 'en' || (string) $target->source_locale !== 'zh-CN'
            || $source->source_content_id !== null || ! in_array((string) $source->translation_status, ['source', 'approved', 'published'], true)
            || (string) $source->status !== 'published' || ! (bool) $source->is_public
            || (string) $target->status !== 'published' || ! (bool) $target->is_public
            || (string) $target->translation_status !== 'published') {
            $errors[] = 'source_or_target_identity_drift';
        }
        $expectedLink = $after ? ($this->isRestore() ? null : $sourceId) : ($this->isRestore() ? $sourceId : null);
        if ($target->source_content_id !== $expectedLink || filled($target->translated_from_version_hash)) {
            $errors[] = 'target_link_or_provenance_drift';
        }
        if (! hash_equals($sourceHash, (string) $source->source_version_hash)
            || ! hash_equals($targetHash, (string) $target->source_version_hash)) {
            $errors[] = 'source_or_target_hash_drift';
        }
        if (! $after && ((string) $source->updated_at?->toDateTimeString() !== trim((string) $this->option('source-updated-at'))
            || (string) $target->updated_at?->toDateTimeString() !== trim((string) $this->option('target-updated-at')))) {
            $errors[] = 'row_timestamp_drift';
        }
        if ((int) $target->working_revision_id !== $revisionId || (int) $target->published_revision_id !== $revisionId) {
            $errors[] = 'target_revision_pointer_drift';
        }
        $revisionQuery = CmsTranslationRevision::query()->withoutGlobalScopes()->whereKey($revisionId);
        if ($lock) {
            $revisionQuery->lockForUpdate();
        }
        $revision = $revisionQuery->first();
        if (! $revision instanceof CmsTranslationRevision || (int) $revision->org_id !== 0
            || (string) $revision->content_type !== 'content_page' || (int) $revision->content_id !== $targetId
            || (string) $revision->translation_group_id !== $groupId || (string) $revision->locale !== 'en'
            || (string) $revision->source_locale !== 'zh-CN' || (string) $revision->revision_status !== 'published'
            || $revision->source_content_id !== null || filled($revision->translated_from_version_hash)) {
            $errors[] = 'published_revision_identity_or_provenance_drift';
        }
        if ($this->isRestore()) {
            $audit = AuditLog::query()->withoutGlobalScopes()->find((int) $this->option('restore-audit-id'));
            $meta = (array) ($audit?->meta_json ?? []);
            if (! $audit instanceof AuditLog || (string) $audit->action !== 'content_page_source_linked'
                || (string) $audit->target_type !== 'content_page' || (string) $audit->target_id !== (string) $targetId
                || (int) ($meta['source_id'] ?? 0) !== $sourceId || (string) ($meta['group_id'] ?? '') !== $groupId
                || (string) ($meta['slug'] ?? '') !== $slug || (string) ($meta['source_hash'] ?? '') !== $sourceHash
                || (string) ($meta['target_hash'] ?? '') !== $targetHash || (int) ($meta['revision_id'] ?? 0) !== $revisionId
                || ! array_key_exists('before_source_content_id', $meta)
                || $meta['before_source_content_id'] !== null
                || (int) ($meta['after_source_content_id'] ?? 0) !== $sourceId) {
                $errors[] = 'restore_audit_lock_invalid';
            }
        }

        return [
            'errors' => $errors,
            'source' => $source,
            'target' => $target,
            'revision' => $revision instanceof CmsTranslationRevision ? [
                'id' => (int) $revision->id,
                'source_content_id' => $revision->source_content_id,
                'translated_from_version_hash' => (string) $revision->translated_from_version_hash,
                'status' => (string) $revision->revision_status,
            ] : [],
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function publicSnapshot(array $snapshot): array
    {
        $source = $snapshot['source'] ?? null;
        $target = $snapshot['target'] ?? null;
        if (! $source instanceof ContentPage || ! $target instanceof ContentPage) {
            return ['errors' => $snapshot['errors'] ?? []];
        }

        return [
            'source_id' => (int) $source->id,
            'target_id' => (int) $target->id,
            'group_id' => (string) $source->translation_group_id,
            'source_content_id' => $target->source_content_id,
            'source_hash' => (string) $source->source_version_hash,
            'target_hash' => (string) $target->source_version_hash,
            'revision' => $snapshot['revision'],
            'audit_id' => $snapshot['audit_id'] ?? null,
            'errors' => $snapshot['errors'],
            'translation_freshness_attested' => false,
        ];
    }

    private function isRestore(): bool
    {
        return (int) $this->option('restore-audit-id') > 0;
    }

    private function confirmation(): string
    {
        return $this->isRestore()
            ? sprintf('Restore content page target %d from audit %d.', (int) $this->option('target-id'), (int) $this->option('restore-audit-id'))
            : sprintf('Link content page target %d to source %d in group %s.',
                (int) $this->option('target-id'), (int) $this->option('source-id'), (string) $this->option('group-id'));
    }
}
