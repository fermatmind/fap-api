<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ContentPageResource\Pages;

use App\Filament\Ops\Resources\ContentPageResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\ContentPage;
use App\Services\Cms\RowBackedRevisionWorkspace;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContentPage extends EditRecord
{
    protected static string $resource = ContentPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(false),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var ContentPage $record */
        $record = $this->getRecord()->fresh();
        $editor = app(RowBackedRevisionWorkspace::class)->editorRecord('content_page', $record);

        return array_merge($data, [
            'title' => (string) $editor->title,
            'path' => (string) $editor->path,
            'kind' => (string) $editor->kind,
            'page_type' => (string) $editor->page_type,
            'kicker' => $editor->kicker,
            'summary' => $editor->summary,
            'template' => (string) $editor->template,
            'animation_profile' => (string) $editor->animation_profile,
            'status' => (string) $editor->status,
            'review_state' => (string) $editor->review_state,
            'owner' => $editor->owner,
            'legal_review_required' => (bool) $editor->legal_review_required,
            'science_review_required' => (bool) $editor->science_review_required,
            'is_public' => (bool) $editor->is_public,
            'is_indexable' => (bool) $editor->is_indexable,
            'published_at' => $editor->published_at,
            'last_reviewed_at' => $editor->last_reviewed_at,
            'source_updated_at' => $editor->source_updated_at,
            'effective_at' => $editor->effective_at,
            'source_doc' => $editor->source_doc,
            'content_md' => (string) ($editor->content_md ?? ''),
            'content_html' => (string) ($editor->content_html ?? ''),
            'seo_title' => $editor->seo_title,
            'meta_description' => $editor->meta_description,
            'seo_description' => $editor->seo_description,
            'canonical_path' => $editor->canonical_path,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ContentPage $record */
        $record = $record;
        $workspace = app(RowBackedRevisionWorkspace::class);
        $status = (string) $data['status'];
        $reviewState = (string) $data['review_state'];
        $revisionStatus = $record->isSourceContent()
            ? ContentPage::TRANSLATION_STATUS_SOURCE
            : ($status === ContentPage::STATUS_PUBLISHED
                ? ContentPage::TRANSLATION_STATUS_PUBLISHED
                : ($reviewState === 'approved'
                    ? ContentPage::TRANSLATION_STATUS_APPROVED
                    : ($reviewState !== 'draft'
                        ? ContentPage::TRANSLATION_STATUS_HUMAN_REVIEW
                        : ContentPage::TRANSLATION_STATUS_DRAFT)));

        $updated = $workspace->saveWorkingDraft(
            'content_page',
            $record,
            [
                'title' => trim((string) $data['title']),
                'summary' => $data['summary'] ?? null,
                'body_md' => (string) ($data['content_md'] ?? ''),
                'body_html' => (string) ($data['content_html'] ?? ''),
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'path' => (string) $data['path'],
                'kind' => (string) $data['kind'],
                'page_type' => (string) $data['page_type'],
                'kicker' => $data['kicker'] ?? null,
                'template' => (string) $data['template'],
                'animation_profile' => (string) $data['animation_profile'],
                'owner' => $data['owner'] ?? null,
                'legal_review_required' => (bool) ($data['legal_review_required'] ?? false),
                'science_review_required' => (bool) ($data['science_review_required'] ?? false),
                'source_doc' => $data['source_doc'] ?? null,
                'headings_json' => [],
                'meta_description' => $data['meta_description'] ?? null,
                'canonical_path' => $data['canonical_path'] ?? null,
                'is_public' => (bool) ($data['is_public'] ?? false),
                'is_indexable' => (bool) ($data['is_indexable'] ?? false),
            ],
            $revisionStatus,
            [
                'status' => $status,
                'review_state' => $reviewState,
                'published_at' => $data['published_at'] ?? null,
                'last_reviewed_at' => $data['last_reviewed_at'] ?? null,
                'source_updated_at' => $data['source_updated_at'] ?? null,
                'effective_at' => $data['effective_at'] ?? null,
            ],
        );

        return $status === ContentPage::STATUS_PUBLISHED && (bool) ($data['is_public'] ?? false)
            ? $workspace->publishWorkingRevision('content_page', $updated)
            : $updated;
    }

    protected function afterSave(): void
    {
        /** @var ContentPage $record */
        $record = $this->getRecord()->fresh();

        if (ContentReleaseAudit::shouldDispatchPublishedFollowUp('content_page', $record, [
            'title',
            'kicker',
            'summary',
            'content_md',
            'content_html',
            'seo_title',
            'seo_description',
            'meta_description',
            'kind',
            'page_type',
            'template',
            'animation_profile',
        ])) {
            ContentReleaseAudit::log('content_page', $record, 'content_page_resource_edit');
        }
    }
}
