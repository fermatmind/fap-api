<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\SiblingTranslationAdapter;
use App\Models\CmsTranslationRevision;
use Illuminate\Database\Eloquent\Model;

final class RowBackedRevisionWorkspace
{
    /**
     * @var array<string, SiblingTranslationAdapter>
     */
    private array $adapters;

    public function __construct(
        SupportArticleTranslationAdapter $supportArticles,
        InterpretationGuideTranslationAdapter $interpretationGuides,
        ContentPageTranslationAdapter $contentPages,
    ) {
        $this->adapters = [
            $supportArticles->contentType() => $supportArticles,
            $interpretationGuides->contentType() => $interpretationGuides,
            $contentPages->contentType() => $contentPages,
        ];
    }

    public function adapter(string $contentType): SiblingTranslationAdapter
    {
        $adapter = $this->adapters[$contentType] ?? null;
        if (! $adapter instanceof SiblingTranslationAdapter) {
            throw new CmsTranslationWorkflowException(sprintf('Unsupported content type [%s].', $contentType));
        }

        return $adapter;
    }

    public function ensureInitialRevision(string $contentType, Model $record, ?int $adminUserId = null): CmsTranslationRevision
    {
        if ($record->workingRevision instanceof CmsTranslationRevision) {
            return $record->workingRevision;
        }

        $adapter = $this->adapter($contentType);

        $revision = CmsTranslationRevision::query()->create([
            'org_id' => (int) $record->org_id,
            'content_type' => $contentType,
            'content_id' => (int) $record->getKey(),
            'source_content_id' => $record->source_content_id ? (int) $record->source_content_id : null,
            'translation_group_id' => (string) $record->translation_group_id,
            'locale' => (string) $record->locale,
            'source_locale' => (string) ($record->source_locale ?: $record->locale),
            'revision_number' => 1,
            'revision_status' => $this->revisionStatusFromRecord($record),
            'source_version_hash' => $record->source_version_hash ? (string) $record->source_version_hash : null,
            'translated_from_version_hash' => $record->translated_from_version_hash ? (string) $record->translated_from_version_hash : null,
            'payload_json' => $adapter->snapshotPayload($record),
            'supersedes_revision_id' => null,
            'created_by_admin_id' => $adminUserId,
            'approved_at' => $adapter->isPublished($record) || (string) $record->translation_status === CmsTranslationRevision::STATUS_APPROVED ? now() : null,
            'published_at' => $adapter->isPublished($record) ? ($record->published_at ?? now()) : null,
        ]);

        $record->forceFill([
            'working_revision_id' => (int) $revision->id,
            'published_revision_id' => $adapter->isPublished($record) ? (int) $revision->id : $record->published_revision_id,
        ])->saveQuietly();

        return $revision;
    }

    public function workingRevision(string $contentType, Model $record): CmsTranslationRevision
    {
        return $record->workingRevision instanceof CmsTranslationRevision
            ? $record->workingRevision
            : $this->ensureInitialRevision($contentType, $record);
    }

    public function editorRecord(string $contentType, Model $record): Model
    {
        $working = $this->workingRevision($contentType, $record);
        $adapter = $this->adapter($contentType);

        $editor = $record->replicate();
        $editor->exists = true;
        $editor->setAttribute($record->getKeyName(), $record->getKey());
        $editor->setAttribute('org_id', $record->org_id);
        $editor->setAttribute('working_revision_id', $working->id);
        $editor->setAttribute('published_revision_id', $record->published_revision_id);
        $editor->setAttribute('translation_status', $working->revision_status);
        $editor->setAttribute('updated_at', $working->updated_at);

        $adapter->applyRevisionPayload($editor, $working->payload_json ?? []);

        return $editor;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $recordAttributes
     */
    public function saveWorkingDraft(
        string $contentType,
        Model $record,
        array $payload,
        string $revisionStatus,
        array $recordAttributes = [],
        ?int $adminUserId = null,
        ?int $sourceContentId = null
    ): Model {
        $adapter = $this->adapter($contentType);
        $currentWorking = $this->workingRevision($contentType, $record);
        $publishedRevisionId = $record->published_revision_id ? (int) $record->published_revision_id : null;
        $shouldFork = $publishedRevisionId !== null && (int) $currentWorking->id === $publishedRevisionId;

        if ($shouldFork) {
            $currentWorking->forceFill([
                'revision_status' => $currentWorking->revision_status === CmsTranslationRevision::STATUS_PUBLISHED
                    ? CmsTranslationRevision::STATUS_STALE
                    : $currentWorking->revision_status,
            ])->save();

            $working = CmsTranslationRevision::query()->create([
                'org_id' => (int) $record->org_id,
                'content_type' => $contentType,
                'content_id' => (int) $record->getKey(),
                'source_content_id' => $sourceContentId ?? ($record->source_content_id ? (int) $record->source_content_id : null),
                'translation_group_id' => (string) $record->translation_group_id,
                'locale' => (string) $record->locale,
                'source_locale' => (string) ($record->source_locale ?: $record->locale),
                'revision_number' => ((int) $currentWorking->revision_number) + 1,
                'revision_status' => $revisionStatus,
                'source_version_hash' => $record->source_version_hash ? (string) $record->source_version_hash : null,
                'translated_from_version_hash' => (string) ($recordAttributes['translated_from_version_hash'] ?? $record->translated_from_version_hash),
                'payload_json' => $payload,
                'supersedes_revision_id' => (int) $currentWorking->id,
                'created_by_admin_id' => $adminUserId,
                'reviewed_at' => $revisionStatus === CmsTranslationRevision::STATUS_HUMAN_REVIEW ? now() : null,
                'approved_at' => $revisionStatus === CmsTranslationRevision::STATUS_APPROVED ? now() : null,
            ]);
        } else {
            $working = $currentWorking;
            $working->forceFill([
                'org_id' => (int) $record->org_id,
                'source_content_id' => $sourceContentId ?? ($record->source_content_id ? (int) $record->source_content_id : null),
                'translation_group_id' => (string) $record->translation_group_id,
                'locale' => (string) $record->locale,
                'source_locale' => (string) ($record->source_locale ?: $record->locale),
                'revision_status' => $revisionStatus,
                'source_version_hash' => $record->source_version_hash ? (string) $record->source_version_hash : null,
                'translated_from_version_hash' => (string) ($recordAttributes['translated_from_version_hash'] ?? $record->translated_from_version_hash),
                'payload_json' => $payload,
                'created_by_admin_id' => $adminUserId ?? $working->created_by_admin_id,
                'reviewed_at' => $revisionStatus === CmsTranslationRevision::STATUS_HUMAN_REVIEW ? now() : null,
                'approved_at' => $revisionStatus === CmsTranslationRevision::STATUS_APPROVED ? now() : null,
            ])->save();
        }

        $record->forceFill([
            'working_revision_id' => (int) $working->id,
            'translation_status' => $revisionStatus,
        ] + $recordAttributes);

        if ($adapter->isSource($record) || ! filled($record->published_revision_id)) {
            $adapter->applyRevisionPayload($record, $payload);
        }

        $record->save();

        return $record->refresh();
    }

    public function updateWorkingRevisionStatus(string $contentType, Model $record, string $revisionStatus): Model
    {
        $working = $this->workingRevision($contentType, $record);
        $working->forceFill([
            'revision_status' => $revisionStatus,
            'reviewed_at' => $revisionStatus === CmsTranslationRevision::STATUS_HUMAN_REVIEW ? now() : $working->reviewed_at,
            'approved_at' => $revisionStatus === CmsTranslationRevision::STATUS_APPROVED ? now() : $working->approved_at,
            'archived_at' => $revisionStatus === CmsTranslationRevision::STATUS_ARCHIVED ? now() : $working->archived_at,
        ])->save();

        $record->forceFill([
            'translation_status' => $revisionStatus,
        ])->save();

        return $record->refresh();
    }

    public function publishWorkingRevision(string $contentType, Model $record): Model
    {
        $adapter = $this->adapter($contentType);
        $working = $this->workingRevision($contentType, $record);

        $adapter->applyRevisionPayload($record, $working->payload_json ?? []);
        $adapter->markPublished($record);
        $record->forceFill([
            'translation_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'working_revision_id' => (int) $working->id,
            'published_revision_id' => (int) $working->id,
        ])->save();

        $working->forceFill([
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED,
            'published_at' => $record->published_at ?? now(),
            'approved_at' => $working->approved_at ?? now(),
        ])->save();

        return $record->refresh();
    }

    private function revisionStatusFromRecord(Model $record): string
    {
        $translationStatus = (string) ($record->translation_status ?? '');
        if ($translationStatus !== '') {
            return $translationStatus;
        }

        if ((string) ($record->status ?? '') === 'published') {
            return CmsTranslationRevision::STATUS_PUBLISHED;
        }

        return CmsTranslationRevision::STATUS_DRAFT;
    }
}
