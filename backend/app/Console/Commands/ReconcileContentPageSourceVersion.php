<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ContentPageTranslationAdapter;
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
final class ReconcileContentPageSourceVersion extends Command
{
    protected $signature = 'translation:reconcile-content-page-source-version
        {--source-id= : Exact public zh-CN source row ID}
        {--target-id= : Exact linked en row ID}
        {--group-id= : Exact translation group ID}
        {--slug= : Exact shared slug}
        {--source-hash= : Existing source row hash lock}
        {--target-hash= : Existing target row hash lock}
        {--fresh-source-hash= : Recomputed source payload hash lock}
        {--revision-id= : Exact shared source working/published revision ID}
        {--revision-hash= : Existing source revision hash lock}
        {--revision-payload-hash= : Existing source revision payload hash lock}
        {--row-payload-hash= : Exact current row-derived payload hash lock}
        {--source-updated-at= : Exact source row timestamp}
        {--target-updated-at= : Exact target row timestamp}
        {--revision-updated-at= : Exact source revision timestamp}
        {--dry-run : Validate without writes}
        {--execute : Create one published source snapshot from the already-public row}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Reconcile one reviewed public ContentPage source row into a new immutable source version; leave all target content and provenance unchanged.';

    public function handle(ContentPageTranslationAdapter $adapter, AuditLogger $auditLogger): int
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
            $before = $this->snapshot($adapter, false);
            $errors = array_values(array_unique([...$errors, ...$before['errors']]));
        } catch (Throwable) {
            $before = ['errors' => ['snapshot_failed']];
            $errors[] = 'snapshot_failed';
        }

        $after = null;
        if ($execute && $errors === []) {
            try {
                $after = DB::transaction(function () use ($adapter, $auditLogger): array {
                    $locked = $this->snapshot($adapter, true);
                    if ($locked['errors'] !== []) {
                        throw new RuntimeException(implode(',', $locked['errors']));
                    }
                    /** @var ContentPage $source */
                    $source = $locked['source'];
                    /** @var ContentPage $target */
                    $target = $locked['target'];
                    /** @var CmsTranslationRevision $oldRevision */
                    $oldRevision = $locked['revision'];
                    $beforeAttributes = $source->getAttributes();
                    $targetAttributes = $target->getAttributes();
                    $oldRevisionAttributes = $oldRevision->getAttributes();
                    $nextNumber = ((int) CmsTranslationRevision::query()->withoutGlobalScopes()
                        ->where('org_id', 0)->where('content_type', 'content_page')
                        ->where('content_id', $source->id)->lockForUpdate()->max('revision_number')) + 1;

                    $revision = CmsTranslationRevision::query()->create([
                        'org_id' => 0,
                        'content_type' => 'content_page',
                        'content_id' => (int) $source->id,
                        'source_content_id' => null,
                        'translation_group_id' => (string) $source->translation_group_id,
                        'locale' => 'zh-CN',
                        'source_locale' => 'zh-CN',
                        'revision_number' => $nextNumber,
                        'revision_status' => CmsTranslationRevision::STATUS_SOURCE,
                        'source_version_hash' => $locked['fresh_hash'],
                        'translated_from_version_hash' => null,
                        'payload_json' => $locked['row_payload'],
                        'supersedes_revision_id' => (int) $oldRevision->id,
                        'published_at' => now(),
                    ]);
                    $source->forceFill([
                        'source_version_hash' => $locked['fresh_hash'],
                        'translation_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
                        'working_revision_id' => (int) $revision->id,
                        'published_revision_id' => (int) $revision->id,
                    ])->saveQuietly();

                    $sourceAfter = $source->fresh();
                    $targetAfter = $target->fresh();
                    $oldAfter = $oldRevision->fresh();
                    $newAfter = $revision->fresh();
                    if (! $sourceAfter instanceof ContentPage || ! $targetAfter instanceof ContentPage
                        || ! $oldAfter instanceof CmsTranslationRevision || ! $newAfter instanceof CmsTranslationRevision) {
                        throw new RuntimeException('readback_missing');
                    }
                    $afterAttributes = $sourceAfter->getAttributes();
                    foreach (['source_version_hash', 'translation_status', 'working_revision_id', 'published_revision_id', 'updated_at'] as $field) {
                        unset($beforeAttributes[$field], $afterAttributes[$field]);
                    }
                    if ($beforeAttributes !== $afterAttributes || $targetAfter->getAttributes() !== $targetAttributes
                        || $oldAfter->getAttributes() !== $oldRevisionAttributes
                        || (int) $sourceAfter->working_revision_id !== (int) $newAfter->id
                        || (int) $sourceAfter->published_revision_id !== (int) $newAfter->id
                        || (string) $sourceAfter->translation_status !== ContentPage::TRANSLATION_STATUS_SOURCE
                        || ! hash_equals($locked['fresh_hash'], (string) $sourceAfter->source_version_hash)
                        || ! hash_equals($locked['fresh_hash'], $sourceAfter->freshSourceVersionHash())
                        || ! hash_equals($locked['row_payload_hash'], $this->payloadHash($newAfter->payload_json))
                        || (int) $newAfter->supersedes_revision_id !== (int) $oldRevision->id
                        || (string) $newAfter->revision_status !== CmsTranslationRevision::STATUS_SOURCE
                        || ! $sourceAfter->passesPublicReadinessGate()) {
                        throw new RuntimeException('readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/reconcile-content-page-source-version', 'POST'),
                        'content_page_source_version_reconciled',
                        'content_page',
                        (string) $source->id,
                        [
                            'source_id' => (int) $source->id,
                            'target_id' => (int) $target->id,
                            'group_id' => (string) $source->translation_group_id,
                            'slug' => (string) $source->slug,
                            'before_status' => (string) $locked['before_status'],
                            'after_status' => ContentPage::TRANSLATION_STATUS_SOURCE,
                            'before_hash' => (string) $locked['source_hash'],
                            'after_hash' => (string) $locked['fresh_hash'],
                            'target_hash' => (string) $target->source_version_hash,
                            'target_updated_at' => (string) $target->getRawOriginal('updated_at'),
                            'old_revision_id' => (int) $oldRevision->id,
                            'new_revision_id' => (int) $newAfter->id,
                            'old_revision_hash' => (string) $oldRevision->source_version_hash,
                            'old_revision_updated_at' => (string) $oldRevision->getRawOriginal('updated_at'),
                            'old_revision_payload_hash' => (string) $locked['revision_payload_hash'],
                            'new_revision_payload_hash' => (string) $locked['row_payload_hash'],
                            'target_provenance_changed' => false,
                            'public_row_copy_changed' => false,
                        ],
                        reason: 'controlled_content_page_source_version_reconstruction',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'content_page_source_version_reconciled')
                        ->where('target_type', 'content_page')->where('target_id', (string) $source->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return [
                        'source_id' => (int) $sourceAfter->id,
                        'target_id' => (int) $targetAfter->id,
                        'old_revision_id' => (int) $oldRevision->id,
                        'new_revision_id' => (int) $newAfter->id,
                        'before_hash' => (string) $locked['source_hash'],
                        'after_hash' => (string) $sourceAfter->source_version_hash,
                        'audit_id' => (int) $audit->id,
                    ];
                });
                try {
                    Cache::forget('content_page:v1:0:'.$this->option('slug').':zh-CN');
                } catch (Throwable $exception) {
                    Log::warning('translation_content_page_source_version_cache_invalidation_failed', [
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
    private function snapshot(ContentPageTranslationAdapter $adapter, bool $lock): array
    {
        $sourceId = (int) $this->option('source-id');
        $targetId = (int) $this->option('target-id');
        $revisionId = (int) $this->option('revision-id');
        $groupId = trim((string) $this->option('group-id'));
        $slug = trim((string) $this->option('slug'));
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId || $revisionId < 1
            || $groupId === '' || $slug === '') {
            return ['errors' => ['invalid_exact_identity']];
        }
        $groupQuery = ContentPage::query()->withoutGlobalScopes()
            ->where('translation_group_id', $groupId)->orderBy('id');
        if ($lock) {
            $groupQuery->lockForUpdate();
        }
        $group = $groupQuery->get();
        $source = $group->firstWhere('id', $sourceId);
        $target = $group->firstWhere('id', $targetId);
        if ($group->count() !== 2 || ! $source instanceof ContentPage || ! $target instanceof ContentPage
            || $group->contains(fn (ContentPage $row): bool => (int) $row->org_id !== 0)) {
            return ['errors' => ['group_pair_ambiguous']];
        }
        $errors = [];
        if ((string) $source->slug !== $slug || (string) $target->slug !== $slug
            || (string) $source->locale !== 'zh-CN' || (string) $source->source_locale !== 'zh-CN'
            || $source->source_content_id !== null || (string) $target->locale !== 'en'
            || (string) $target->source_locale !== 'zh-CN' || (int) $target->source_content_id !== $sourceId
            || ! in_array((string) $source->translation_status, ['approved', 'published'], true)
            || (string) $source->status !== ContentPage::STATUS_PUBLISHED
            || ! (bool) $source->is_public || (string) $source->review_state !== 'approved'
            || ! $source->passesPublicReadinessGate()
            || (string) $target->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $target->is_public) {
            $errors[] = 'source_or_target_identity_or_publication_invalid';
        }
        if ((int) $source->working_revision_id !== $revisionId
            || (int) $source->published_revision_id !== $revisionId) {
            $errors[] = 'source_revision_pointer_drift';
        }
        $revisionQuery = CmsTranslationRevision::query()->withoutGlobalScopes()->whereKey($revisionId);
        if ($lock) {
            $revisionQuery->lockForUpdate();
        }
        $revision = $revisionQuery->first();
        if (! $revision instanceof CmsTranslationRevision || (int) $revision->org_id !== 0
            || (string) $revision->content_type !== 'content_page' || (int) $revision->content_id !== $sourceId
            || (string) $revision->translation_group_id !== $groupId
            || (string) $revision->locale !== 'zh-CN' || (string) $revision->source_locale !== 'zh-CN'
            || $revision->source_content_id !== null
            || (string) $revision->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = 'old_source_revision_identity_invalid';
        }
        $rowPayload = $adapter->snapshotPayload($source);
        $rowPayload['body_html'] = $source->getRawOriginal('content_html');
        $revisionPayloadHash = $this->payloadHash($revision?->payload_json);
        $rowPayloadHash = $this->payloadHash($rowPayload);
        $freshHash = $source->freshSourceVersionHash();
        if ($freshHash === (string) $source->source_version_hash) {
            $errors[] = 'source_hash_already_current';
        }
        foreach (['title', 'content_md', 'seo_title', 'seo_description', 'path', 'kind', 'page_type', 'template', 'animation_profile', 'claim_gate_status'] as $field) {
            if (! is_string($source->getRawOriginal($field)) || trim((string) $source->getRawOriginal($field)) === '') {
                $errors[] = 'raw_row_field_missing:'.$field;
            }
        }
        foreach (['is_public', 'is_indexable', 'schema_enabled', 'publish_allowed', 'operator_approval_required', 'faq_schema_eligible', 'legal_review_required', 'science_review_required', 'headings_json', 'faq_items', 'forbidden_claims'] as $field) {
            if ($source->getRawOriginal($field) === null) {
                $errors[] = 'raw_row_field_missing:'.$field;
            }
        }
        if ($adapter->requiredPayloadBlockers($rowPayload) !== []) {
            $errors[] = 'row_payload_incomplete';
        }

        $locks = [
            'source-hash' => (string) $source->source_version_hash,
            'target-hash' => (string) $target->source_version_hash,
            'fresh-source-hash' => $freshHash,
            'revision-hash' => (string) ($revision?->source_version_hash ?? ''),
            'revision-payload-hash' => $revisionPayloadHash,
            'row-payload-hash' => $rowPayloadHash,
            'source-updated-at' => (string) $source->getRawOriginal('updated_at'),
            'target-updated-at' => (string) $target->getRawOriginal('updated_at'),
            'revision-updated-at' => (string) ($revision?->getRawOriginal('updated_at') ?? ''),
        ];
        foreach ($locks as $name => $value) {
            $supplied = $this->option($name);
            if (($supplied !== null || (bool) $this->option('execute')) && (string) $supplied !== $value) {
                $errors[] = 'lock_mismatch:'.$name;
            }
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'source' => $source,
            'target' => $target,
            'revision' => $revision,
            'before_status' => (string) $source->translation_status,
            'source_hash' => (string) $source->source_version_hash,
            'fresh_hash' => $freshHash,
            'revision_payload_hash' => $revisionPayloadHash,
            'row_payload_hash' => $rowPayloadHash,
            'row_payload' => $rowPayload,
            'locks' => $locks,
        ];
    }

    private function payloadHash(mixed $payload): string
    {
        return CanonicalTranslationPayloadHash::hash($payload);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function publicSnapshot(array $snapshot): array
    {
        return [
            'source_id' => ($snapshot['source'] ?? null)?->id,
            'target_id' => ($snapshot['target'] ?? null)?->id,
            'revision_id' => ($snapshot['revision'] ?? null)?->id,
            'before_status' => $snapshot['before_status'] ?? null,
            'old_source_hash' => $snapshot['source_hash'] ?? null,
            'fresh_source_hash' => $snapshot['fresh_hash'] ?? null,
            'locks' => $snapshot['locks'] ?? [],
        ];
    }

    private function confirmation(): string
    {
        return sprintf('Reconcile content page source %d for target %d in group %s.',
            (int) $this->option('source-id'), (int) $this->option('target-id'), (string) $this->option('group-id'));
    }
}
