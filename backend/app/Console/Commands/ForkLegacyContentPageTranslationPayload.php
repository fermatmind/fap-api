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
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** @review-surface content_page */
final class ForkLegacyContentPageTranslationPayload extends Command
{
    protected $signature = 'translation:fork-content-page-payload
        {--source-id= : Exact public source row ID}
        {--target-id= : Exact public en row ID}
        {--group-id= : Exact translation group ID}
        {--source-hash= : Exact source row version hash}
        {--target-hash= : Exact target row version hash}
        {--source-updated-at= : Exact source row timestamp}
        {--target-updated-at= : Exact target row timestamp}
        {--revision-id= : Exact shared working and published revision ID}
        {--revision-updated-at= : Exact published revision timestamp}
        {--revision-payload-hash= : Exact old published payload SHA256}
        {--row-payload-hash= : Exact proposed row-derived payload SHA256}
        {--dry-run : Validate without writes}
        {--execute : Create one unpublished draft under exact locks}
        {--confirm= : Exact execute confirmation}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Fork a complete legacy ContentPage row payload into a new unpublished draft without changing the published revision or claiming translation freshness.';

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

                    /** @var ContentPage $target */
                    $target = $locked['target'];
                    /** @var CmsTranslationRevision $published */
                    $published = $locked['revision'];
                    $beforeAttributes = $target->getAttributes();
                    $publishedAttributes = $published->getAttributes();
                    $draft = CmsTranslationRevision::query()->create([
                        'org_id' => 0,
                        'content_type' => 'content_page',
                        'content_id' => (int) $target->id,
                        'source_content_id' => (int) $target->source_content_id,
                        'translation_group_id' => (string) $target->translation_group_id,
                        'locale' => 'en',
                        'source_locale' => 'zh-CN',
                        'revision_number' => ((int) $published->revision_number) + 1,
                        'revision_status' => CmsTranslationRevision::STATUS_DRAFT,
                        'source_version_hash' => $target->source_version_hash,
                        'translated_from_version_hash' => $target->translated_from_version_hash,
                        'payload_json' => $locked['row_payload'],
                        'supersedes_revision_id' => (int) $published->id,
                    ]);
                    $target->forceFill([
                        'working_revision_id' => (int) $draft->id,
                        'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT,
                    ])->saveQuietly();

                    $readback = $target->fresh();
                    $draftReadback = $draft->fresh();
                    $oldReadback = $published->fresh();
                    $afterAttributes = $readback?->getAttributes() ?? [];
                    foreach (['working_revision_id', 'translation_status', 'updated_at'] as $key) {
                        unset($beforeAttributes[$key], $afterAttributes[$key]);
                    }
                    if (! $readback instanceof ContentPage
                        || ! $draftReadback instanceof CmsTranslationRevision
                        || ! $oldReadback instanceof CmsTranslationRevision
                        || $afterAttributes !== $beforeAttributes
                        || $oldReadback->getAttributes() !== $publishedAttributes
                        || (int) $readback->published_revision_id !== (int) $published->id
                        || (int) $readback->working_revision_id !== (int) $draftReadback->id
                        || $this->payloadHash($draftReadback->payload_json) !== $locked['row_payload_hash']) {
                        throw new RuntimeException('readback_mismatch');
                    }

                    $lastAuditId = (int) AuditLog::query()->withoutGlobalScopes()->max('id');
                    $auditLogger->log(
                        Request::create('/ops/translation/fork-content-page-payload', 'POST'),
                        'content_page_payload_draft_forked',
                        'content_page',
                        (string) $target->id,
                        [
                            'source_id' => (int) $target->source_content_id,
                            'group_id' => (string) $target->translation_group_id,
                            'published_revision_id' => (int) $published->id,
                            'new_working_revision_id' => (int) $draftReadback->id,
                            'old_payload_hash' => $locked['revision_payload_hash'],
                            'new_payload_hash' => $locked['row_payload_hash'],
                            'row_vs_published_payload_conflict_keys' => $locked['overlap_conflict_keys'],
                            'translation_freshness_attested' => false,
                            'published_content_changed' => false,
                        ],
                        reason: 'controlled_legacy_payload_draft_fork',
                        result: 'success',
                    );
                    $audit = AuditLog::query()->withoutGlobalScopes()->where('id', '>', $lastAuditId)
                        ->where('action', 'content_page_payload_draft_forked')
                        ->where('target_type', 'content_page')->where('target_id', (string) $target->id)
                        ->orderByDesc('id')->first();
                    if (! $audit instanceof AuditLog) {
                        throw new RuntimeException('audit_readback_failed');
                    }

                    return [
                        'target_id' => (int) $readback->id,
                        'published_revision_id' => (int) $readback->published_revision_id,
                        'working_revision_id' => (int) $draftReadback->id,
                        'working_status' => (string) $draftReadback->revision_status,
                        'published_status' => (string) $oldReadback->revision_status,
                        'new_payload_hash' => $locked['row_payload_hash'],
                        'row_vs_published_payload_conflict_keys' => $locked['overlap_conflict_keys'],
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
    private function snapshot(ContentPageTranslationAdapter $adapter, bool $lock): array
    {
        $sourceId = (int) $this->option('source-id');
        $targetId = (int) $this->option('target-id');
        $revisionId = (int) $this->option('revision-id');
        $errors = [];
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId || $revisionId < 1) {
            return ['errors' => ['invalid_exact_ids']];
        }

        $rowsQuery = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->whereIn('id', [$sourceId, $targetId])->orderBy('id');
        if ($lock) {
            $rowsQuery->lockForUpdate();
        }
        $rows = $rowsQuery->get()->keyBy('id');
        $source = $rows->get($sourceId);
        $target = $rows->get($targetId);
        $revisionQuery = CmsTranslationRevision::query()->whereKey($revisionId);
        if ($lock) {
            $revisionQuery->lockForUpdate();
        }
        $revision = $revisionQuery->first();
        if (! $source instanceof ContentPage || ! $target instanceof ContentPage || ! $revision instanceof CmsTranslationRevision) {
            return ['errors' => ['identity_or_revision_missing']];
        }

        if ((string) $source->translation_group_id !== (string) $this->option('group-id')
            || (string) $target->translation_group_id !== (string) $this->option('group-id')
            || (string) $source->slug !== (string) $target->slug
            || (string) $source->locale !== 'zh-CN' || (string) $source->source_locale !== 'zh-CN'
            || $source->source_content_id !== null || (string) $source->status !== ContentPage::STATUS_PUBLISHED
            || ! (bool) $source->is_public || (string) $target->locale !== 'en'
            || (string) $target->source_locale !== 'zh-CN'
            || (int) $target->source_content_id !== $sourceId
            || (string) $target->status !== ContentPage::STATUS_PUBLISHED || ! (bool) $target->is_public
            || (string) $target->translation_status !== ContentPage::TRANSLATION_STATUS_PUBLISHED) {
            $errors[] = 'source_target_identity_invalid';
        }
        $groupQuery = ContentPage::query()->withoutGlobalScopes()
            ->where('translation_group_id', (string) $this->option('group-id'))->orderBy('id');
        if ($lock) {
            $groupQuery->lockForUpdate();
        }
        $groupRows = $groupQuery->get();
        if ($groupRows->count() !== 2 || $groupRows->contains(fn (ContentPage $row): bool => (int) $row->org_id !== 0)) {
            $errors[] = 'group_not_unique_public_pair';
        }
        if ((string) $source->source_version_hash !== (string) $this->option('source-hash')
            || (string) $target->source_version_hash !== (string) $this->option('target-hash')
            || (string) $source->getRawOriginal('updated_at') !== (string) $this->option('source-updated-at')
            || (string) $target->getRawOriginal('updated_at') !== (string) $this->option('target-updated-at')) {
            $errors[] = 'row_lock_mismatch';
        }
        $revisionGroup = (string) $revision->translation_group_id;
        $currentGroup = (string) $target->translation_group_id;
        $legacyGroup = 'content_page-'.$sourceId;
        $revisionGroupMatches = $revisionGroup === $currentGroup
            || ($revisionGroup === $legacyGroup
                && (int) $revision->source_content_id === $sourceId
                && (string) $revision->source_locale === 'zh-CN');
        if ((int) $target->working_revision_id !== $revisionId
            || (int) $target->published_revision_id !== $revisionId
            || (int) $revision->org_id !== 0 || (string) $revision->content_type !== 'content_page'
            || (int) $revision->content_id !== $targetId
            || ! $revisionGroupMatches
            || (string) $revision->locale !== 'en'
            || (string) $revision->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED
            || (string) $revision->getRawOriginal('updated_at') !== (string) $this->option('revision-updated-at')) {
            $errors[] = 'revision_lock_or_identity_mismatch';
        }
        $oldPayload = $revision->payload_json;
        $oldPayloadHash = $this->payloadHash($oldPayload);
        if (($this->option('revision-payload-hash') !== null || (bool) $this->option('execute'))
            && $oldPayloadHash !== (string) $this->option('revision-payload-hash')) {
            $errors[] = 'published_payload_lock_mismatch';
        }
        if (! is_array($oldPayload) || $adapter->requiredPayloadBlockers($oldPayload) === []) {
            $errors[] = 'published_payload_not_legacy_incomplete';
        }

        foreach (['title', 'content_md', 'seo_title', 'seo_description', 'path', 'kind', 'page_type', 'template', 'animation_profile', 'claim_gate_status'] as $field) {
            if (! is_string($target->getRawOriginal($field)) || trim((string) $target->getRawOriginal($field)) === '') {
                $errors[] = 'row_field_missing:'.$field;
            }
        }
        foreach (['is_public', 'is_indexable', 'schema_enabled', 'publish_allowed', 'operator_approval_required', 'faq_schema_eligible', 'legal_review_required', 'science_review_required'] as $field) {
            if ($target->getRawOriginal($field) === null) {
                $errors[] = 'row_field_missing:'.$field;
            }
        }
        foreach (['headings_json', 'faq_items', 'forbidden_claims'] as $field) {
            if ($target->getRawOriginal($field) === null || ! is_array($target->{$field})) {
                $errors[] = 'row_field_missing:'.$field;
            }
        }

        $rowPayload = $adapter->snapshotPayload($target);
        $rowPayload['body_html'] = $target->getRawOriginal('content_html');
        $overlapConflictKeys = [];
        if (is_array($oldPayload)) {
            foreach ($oldPayload as $key => $value) {
                if (array_key_exists($key, $rowPayload) && $value !== $rowPayload[$key]) {
                    $overlapConflictKeys[] = (string) $key;
                }
            }
        }
        sort($overlapConflictKeys);
        $rowPayloadHash = $this->payloadHash($rowPayload);
        if (($this->option('row-payload-hash') !== null || (bool) $this->option('execute'))
            && $rowPayloadHash !== (string) $this->option('row-payload-hash')) {
            $errors[] = 'row_payload_lock_mismatch';
        }
        if ($adapter->requiredPayloadBlockers($rowPayload) !== []) {
            $errors[] = 'row_payload_incomplete';
        }

        return [
            'source' => $source,
            'target' => $target,
            'revision' => $revision,
            'errors' => array_values(array_unique($errors)),
            'row_payload' => $rowPayload,
            'row_payload_hash' => $rowPayloadHash,
            'revision_payload_hash' => $oldPayloadHash,
            'overlap_conflict_keys' => $overlapConflictKeys,
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
            'source_id' => ($snapshot['source'] ?? null)?->id,
            'target_id' => ($snapshot['target'] ?? null)?->id,
            'revision_id' => ($snapshot['revision'] ?? null)?->id,
            'revision_payload_hash' => $snapshot['revision_payload_hash'] ?? null,
            'row_payload_hash' => $snapshot['row_payload_hash'] ?? null,
            'row_vs_published_payload_conflict_keys' => $snapshot['overlap_conflict_keys'] ?? [],
        ];
    }

    private function confirmation(): string
    {
        return sprintf('Fork content page target %d payload in group %s.', (int) $this->option('target-id'), (string) $this->option('group-id'));
    }
}
