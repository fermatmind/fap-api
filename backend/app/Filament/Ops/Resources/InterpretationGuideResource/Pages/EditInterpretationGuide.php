<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\InterpretationGuideResource\Pages;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\InterpretationGuide;
use App\Services\Cms\RowBackedRevisionWorkspace;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditInterpretationGuide extends EditRecord
{
    protected static string $resource = InterpretationGuideResource::class;

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
        /** @var InterpretationGuide $record */
        $record = $this->getRecord()->fresh();
        $editor = app(RowBackedRevisionWorkspace::class)->editorRecord('interpretation_guide', $record);

        return array_merge($data, [
            'title' => (string) $editor->title,
            'summary' => $editor->summary,
            'body_md' => (string) ($editor->body_md ?? ''),
            'body_html' => (string) ($editor->body_html ?? ''),
            'test_family' => (string) $editor->test_family,
            'result_context' => (string) $editor->result_context,
            'audience' => $editor->audience,
            'status' => (string) $editor->status,
            'review_state' => (string) $editor->review_state,
            'related_guide_ids' => is_array($editor->related_guide_ids) ? $editor->related_guide_ids : [],
            'related_methodology_page_ids' => is_array($editor->related_methodology_page_ids) ? $editor->related_methodology_page_ids : [],
            'published_at' => $editor->published_at,
            'last_reviewed_at' => $editor->last_reviewed_at,
            'seo_title' => $editor->seo_title,
            'seo_description' => $editor->seo_description,
            'canonical_path' => $editor->canonical_path,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var InterpretationGuide $record */
        $record = $record;
        $workspace = app(RowBackedRevisionWorkspace::class);
        $revisionStatus = $record->isSourceContent()
            ? InterpretationGuide::TRANSLATION_STATUS_SOURCE
            : ((string) $data['status'] === InterpretationGuide::STATUS_PUBLISHED
                ? InterpretationGuide::TRANSLATION_STATUS_PUBLISHED
                : ((string) $data['review_state'] === InterpretationGuide::REVIEW_APPROVED
                    ? InterpretationGuide::TRANSLATION_STATUS_APPROVED
                    : (in_array((string) $data['review_state'], [InterpretationGuide::REVIEW_CONTENT, InterpretationGuide::REVIEW_SCIENCE_OR_PRODUCT], true)
                        ? InterpretationGuide::TRANSLATION_STATUS_HUMAN_REVIEW
                        : InterpretationGuide::TRANSLATION_STATUS_DRAFT)));

        $updated = $workspace->saveWorkingDraft(
            'interpretation_guide',
            $record,
            [
                'title' => trim((string) $data['title']),
                'summary' => $data['summary'] ?? null,
                'body_md' => (string) ($data['body_md'] ?? ''),
                'body_html' => (string) ($data['body_html'] ?? ''),
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'test_family' => (string) $data['test_family'],
                'result_context' => (string) $data['result_context'],
                'audience' => $data['audience'] ?? null,
                'related_guide_ids' => array_values((array) ($data['related_guide_ids'] ?? [])),
                'related_methodology_page_ids' => array_values((array) ($data['related_methodology_page_ids'] ?? [])),
                'canonical_path' => $data['canonical_path'] ?? null,
            ],
            $revisionStatus,
            [
                'status' => (string) $data['status'],
                'review_state' => (string) $data['review_state'],
                'last_reviewed_at' => $data['last_reviewed_at'] ?? null,
                'published_at' => $data['published_at'] ?? null,
            ],
        );

        return (string) $data['status'] === InterpretationGuide::STATUS_PUBLISHED
            ? $workspace->publishWorkingRevision('interpretation_guide', $updated)
            : $updated;
    }

    protected function afterSave(): void
    {
        /** @var InterpretationGuide $record */
        $record = $this->getRecord()->fresh();

        if (ContentReleaseAudit::shouldDispatchPublishedFollowUp('interpretation_guide', $record, [
            'title',
            'summary',
            'body_md',
            'body_html',
            'seo_title',
            'seo_description',
            'test_family',
            'result_context',
            'audience',
        ])) {
            ContentReleaseAudit::log('interpretation_guide', $record, 'interpretation_guide_resource_edit');
        }
    }
}
