<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @review-surface article
 * @review-surface content_page
 */
final class NormalizeTranslationSourceStatus extends Command
{
    protected $signature = 'translation:normalize-source-status
        {--content-type= : article or content_page}
        {--source-id= : Exact public source row ID}
        {--translation-group-id= : Exact translation group ID}
        {--source-locale= : Exact source locale}
        {--expected-status= : Current approved or published translation status}
        {--expected-source-hash= : Current row source version SHA256}
        {--expected-working-revision-id= : Exact current working revision ID}
        {--expected-published-revision-id= : Exact current published revision ID}
        {--expected-updated-at= : Exact row updated_at database timestamp}
        {--restore-audit-id= : Restore the original status from this exact normalization audit log ID}
        {--dry-run : Validate without writes}
        {--execute : Normalize exactly one row in a transaction}
        {--confirm= : Exact execute confirmation}
        {--json : Emit JSON output}';

    protected $description = 'Normalize one unambiguous public source row status without changing content, revisions, or provenance hashes.';

    public function handle(AuditLogger $auditLogger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $errors = [];
        if ($dryRun === $execute) {
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
        } catch (Throwable $exception) {
            $before = ['errors' => ['snapshot_failed'], 'record' => null, 'revisions' => []];
            $errors[] = 'snapshot_failed';
        }
        $beforePublic = $this->publicSnapshot($before);

        $after = null;
        if ($execute && $errors === []) {
            try {
                $after = DB::transaction(function () use ($auditLogger): array {
                    $locked = $this->snapshot(true);
                    if ($locked['errors'] !== []) {
                        throw new \RuntimeException(implode(',', $locked['errors']));
                    }

                    /** @var Model $record */
                    $record = $locked['record'];
                    $beforeAttributes = $record->getAttributes();
                    $record->forceFill(['translation_status' => $this->desiredStatus()])->saveQuietly();
                    $readback = $this->snapshot(true, true);
                    $afterAttributes = $record->fresh()?->getAttributes() ?? [];
                    unset($beforeAttributes['translation_status'], $beforeAttributes['updated_at']);
                    unset($afterAttributes['translation_status'], $afterAttributes['updated_at']);
                    if ($readback['errors'] !== []
                        || $afterAttributes !== $beforeAttributes
                        || $readback['revisions'] !== $locked['revisions']) {
                        throw new \RuntimeException('readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/normalize-source-status', 'POST'),
                        $this->isRestore() ? 'translation_source_status_restored' : 'translation_source_status_normalized',
                        (string) $this->option('content-type'),
                        (string) $record->id,
                        [
                            'translation_group_id' => (string) $this->option('translation-group-id'),
                            'locale' => (string) $this->option('source-locale'),
                            'before_status' => (string) $this->option('expected-status'),
                            'after_status' => $this->desiredStatus(),
                            'restore_audit_id' => $this->isRestore() ? (int) $this->option('restore-audit-id') : null,
                            'source_version_hash' => (string) $this->option('expected-source-hash'),
                            'working_revision_id' => (int) $this->option('expected-working-revision-id'),
                            'published_revision_id' => (int) $this->option('expected-published-revision-id'),
                        ],
                        reason: $this->isRestore() ? 'controlled_translation_source_status_restore' : 'controlled_translation_source_identity_repair',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', $this->isRestore() ? 'translation_source_status_restored' : 'translation_source_status_normalized')
                        ->where('target_type', (string) $this->option('content-type'))
                        ->where('target_id', (string) $record->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new \RuntimeException('audit_readback_failed');
                    }
                    $readback['audit_id'] = (int) $audit->id;

                    return $readback;
                });

                if ((string) $this->option('content-type') === 'content_page') {
                    try {
                        Cache::forget('content_page:v1:0:'.$after['record']['slug'].':'.$after['record']['locale']);
                    } catch (Throwable) {
                        // A cache failure cannot reverse a committed status repair; live readback still applies.
                    }
                }
            } catch (Throwable $exception) {
                $errors[] = 'execute_or_readback_failed';
            }
        }

        $result = [
            'ok' => $errors === [],
            'mode' => $execute ? 'execute' : 'dry_run',
            'action' => $execute && $errors === []
                ? ($this->isRestore() ? 'source_status_restored' : 'source_status_normalized')
                : ($this->isRestore() ? 'would_restore_source_status' : 'would_normalize_source_status'),
            'before' => $beforePublic,
            'after' => $after === null ? null : $this->publicSnapshot($after),
            'errors' => array_values(array_unique($errors)),
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('ok='.($result['ok'] ? '1' : '0'));
            $this->line('action='.$result['action']);
            $this->line('errors='.implode(',', $result['errors']));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{errors:list<string>,record:Model|array<string,mixed>|null,revisions:array<string,mixed>} */
    private function snapshot(bool $lock, bool $after = false): array
    {
        $type = trim((string) $this->option('content-type'));
        $modelClass = match ($type) {
            'article' => Article::class,
            'content_page' => ContentPage::class,
            default => null,
        };
        if ($modelClass === null) {
            return ['errors' => ['unsupported_content_type'], 'record' => null, 'revisions' => []];
        }

        $id = (int) $this->option('source-id');
        $groupId = trim((string) $this->option('translation-group-id'));
        $locale = trim((string) $this->option('source-locale'));
        $expectedHash = trim((string) $this->option('expected-source-hash'));
        $workingId = (int) $this->option('expected-working-revision-id');
        $publishedId = (int) $this->option('expected-published-revision-id');
        if ($id <= 0 || $groupId === '' || $locale === '' || $workingId <= 0 || $publishedId <= 0
            || $workingId !== $publishedId || preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1) {
            return ['errors' => ['identity_or_hash_lock_invalid'], 'record' => null, 'revisions' => []];
        }

        $query = $modelClass::query()->withoutGlobalScopes()->where('org_id', 0)->where('translation_group_id', $groupId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $group = $query->get();
        /** @var Model|null $record */
        $record = $group->firstWhere('id', $id);
        if (! $record instanceof Model) {
            return ['errors' => ['source_row_not_found'], 'record' => null, 'revisions' => []];
        }

        $errors = [];
        $candidates = $group->filter(fn (Model $row): bool => $this->isRoot($row, $type));
        if ($candidates->count() !== 1 || (int) $candidates->first()->id !== $id) {
            $errors[] = 'source_identity_ambiguous';
        }
        if ((string) $record->locale !== $locale || (string) $record->source_locale !== $locale) {
            $errors[] = 'source_locale_drift';
        }
        if ((string) $record->status !== 'published' || ! (bool) $record->is_public) {
            $errors[] = 'source_publication_drift';
        }
        $expectedStatus = trim((string) $this->option('expected-status'));
        $statusAllowed = $this->isRestore()
            ? $expectedStatus === 'source'
            : in_array($expectedStatus, ['approved', 'published'], true);
        if (! $statusAllowed || (string) $record->translation_status !== ($after ? $this->desiredStatus() : $expectedStatus)) {
            $errors[] = 'source_status_drift';
        }
        if ($this->isRestore()) {
            $audit = AuditLog::query()->withoutGlobalScopes()->find((int) $this->option('restore-audit-id'));
            $meta = (array) ($audit?->meta_json ?? []);
            if (! $audit instanceof AuditLog
                || (string) $audit->action !== 'translation_source_status_normalized'
                || (string) $audit->target_type !== $type
                || (string) $audit->target_id !== (string) $id
                || (string) ($meta['translation_group_id'] ?? '') !== $groupId
                || (string) ($meta['locale'] ?? '') !== $locale
                || (string) ($meta['source_version_hash'] ?? '') !== $expectedHash
                || (int) ($meta['working_revision_id'] ?? 0) !== $workingId
                || (int) ($meta['published_revision_id'] ?? 0) !== $publishedId
                || (string) ($meta['after_status'] ?? '') !== 'source'
                || ! in_array((string) ($meta['before_status'] ?? ''), ['approved', 'published'], true)) {
                $errors[] = 'restore_audit_lock_invalid';
            }
        }
        if (! hash_equals($expectedHash, (string) $record->source_version_hash)) {
            $errors[] = 'source_hash_drift';
        }
        if ($type === 'article' && ! hash_equals((string) $record->source_version_hash, $record->computeSourceVersionHash())) {
            $errors[] = 'source_row_hash_drift';
        }
        if (! $after && (string) $record->updated_at?->toDateTimeString() !== trim((string) $this->option('expected-updated-at'))) {
            $errors[] = 'source_updated_at_drift';
        }
        if ((int) $record->working_revision_id !== $workingId || (int) $record->published_revision_id !== $publishedId) {
            $errors[] = 'source_revision_pointer_drift';
        }
        if (filled($record->translated_from_version_hash)) {
            $errors[] = 'source_translation_provenance_present';
        }

        $revisionClass = $type === 'article' ? ArticleTranslationRevision::class : CmsTranslationRevision::class;
        $revisionQuery = $revisionClass::query()->withoutGlobalScopes()->whereKey($workingId);
        if ($lock) {
            $revisionQuery->lockForUpdate();
        }
        $revision = $revisionQuery->first();
        if (! $revision instanceof Model
            || (int) $revision->org_id !== 0
            || (int) ($type === 'article' ? $revision->article_id : $revision->content_id) !== $id
            || (string) $revision->translation_group_id !== $groupId
            || (string) $revision->locale !== $locale
            || (string) $revision->source_locale !== $locale
            || ! in_array((string) $revision->revision_status, ['approved', 'published'], true)
            || ! in_array((int) ($type === 'article' ? $revision->source_article_id : $revision->source_content_id), [0, $id], true)) {
            $errors[] = 'source_revision_identity_drift';
        } elseif (! hash_equals($expectedHash, (string) $revision->source_version_hash)) {
            $errors[] = 'source_revision_hash_drift';
        } elseif (filled($revision->translated_from_version_hash)
            && ! hash_equals($expectedHash, (string) $revision->translated_from_version_hash)) {
            $errors[] = 'source_revision_provenance_drift';
        }

        return [
            'errors' => $errors,
            'record' => $record,
            'revisions' => $revision instanceof Model ? [
                'id' => (int) $revision->id,
                'status' => (string) $revision->revision_status,
                'source_hash' => (string) $revision->source_version_hash,
                'translated_from_hash' => (string) $revision->translated_from_version_hash,
            ] : [],
        ];
    }

    private function isRoot(Model $record, string $type): bool
    {
        return $record->source_content_id === null
            && ($type !== 'article' || ($record->source_article_id === null && $record->translated_from_article_id === null))
            && (string) $record->locale === (string) $record->source_locale;
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function publicSnapshot(array $snapshot): array
    {
        $record = $snapshot['record'] ?? null;
        if (! $record instanceof Model) {
            return ['errors' => $snapshot['errors'] ?? []];
        }

        return [
            'record_id' => (int) $record->id,
            'group_id' => (string) $record->translation_group_id,
            'locale' => (string) $record->locale,
            'translation_status' => (string) $record->translation_status,
            'source_hash' => (string) $record->source_version_hash,
            'working_revision_id' => (int) $record->working_revision_id,
            'published_revision_id' => (int) $record->published_revision_id,
            'revisions' => $snapshot['revisions'],
            'errors' => $snapshot['errors'],
            'audit_id' => $snapshot['audit_id'] ?? null,
        ];
    }

    private function confirmation(): string
    {
        if ($this->isRestore()) {
            return sprintf('Restore %s source %d from audit %d.',
                (string) $this->option('content-type'),
                (int) $this->option('source-id'),
                (int) $this->option('restore-audit-id'));
        }

        return sprintf('Normalize %s source %d in group %s.',
            (string) $this->option('content-type'),
            (int) $this->option('source-id'),
            (string) $this->option('translation-group-id'));
    }

    private function isRestore(): bool
    {
        return (int) $this->option('restore-audit-id') > 0;
    }

    private function desiredStatus(): string
    {
        if (! $this->isRestore()) {
            return 'source';
        }

        $audit = AuditLog::query()->withoutGlobalScopes()->find((int) $this->option('restore-audit-id'));

        return (string) data_get($audit?->meta_json, 'before_status', '');
    }
}
