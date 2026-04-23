<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\InterpretationGuideResource;
use App\Models\InterpretationGuide;
use Illuminate\Database\Eloquent\Model;

final class InterpretationGuideTranslationAdapter extends AbstractSiblingTranslationAdapter
{
    public function contentType(): string
    {
        return 'interpretation_guide';
    }

    public function modelClass(): string
    {
        return InterpretationGuide::class;
    }

    public function isPublished(Model $record): bool
    {
        return (string) $record->status === InterpretationGuide::STATUS_PUBLISHED
            && (string) $record->review_state === InterpretationGuide::REVIEW_APPROVED;
    }

    public function editUrl(Model $record): string
    {
        return InterpretationGuideResource::getUrl('edit', ['record' => $record]);
    }

    public function publicUrls(Model $record): array
    {
        $canonical = trim((string) ($record->canonical_path ?? ''));

        return array_values(array_filter([
            $canonical !== '' ? $canonical : '/support/guides/'.trim((string) $record->slug),
        ]));
    }

    protected function titleField(): string
    {
        return 'title';
    }

    protected function summaryField(): string
    {
        return 'summary';
    }

    protected function bodyField(): string
    {
        return 'body_md';
    }

    protected function seoTitleField(): string
    {
        return 'seo_title';
    }

    protected function seoDescriptionField(): string
    {
        return 'seo_description';
    }

    protected function slugField(): string
    {
        return 'slug';
    }

    protected function localeField(): string
    {
        return 'locale';
    }

    protected function translationStatusSource(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_SOURCE;
    }

    protected function translationStatusMachineDraft(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_MACHINE_DRAFT;
    }

    protected function translationStatusHumanReview(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_HUMAN_REVIEW;
    }

    protected function translationStatusApproved(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_APPROVED;
    }

    protected function translationStatusPublished(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_PUBLISHED;
    }

    protected function translationStatusArchived(): string
    {
        return InterpretationGuide::TRANSLATION_STATUS_ARCHIVED;
    }

    protected function reviewStateHumanReview(): string
    {
        return InterpretationGuide::REVIEW_CONTENT;
    }

    protected function reviewStateApproved(): string
    {
        return InterpretationGuide::REVIEW_APPROVED;
    }

    protected function publishAttributes(Model $target): array
    {
        return [
            'status' => InterpretationGuide::STATUS_PUBLISHED,
            'review_state' => InterpretationGuide::REVIEW_APPROVED,
            'published_at' => $target->published_at ?? now(),
        ];
    }

    protected function archiveAttributes(Model $target): array
    {
        return [
            'status' => InterpretationGuide::STATUS_ARCHIVED,
        ];
    }

    protected function baseCreateAttributes(Model $source, string $targetLocale): array
    {
        return [
            'slug' => (string) $source->slug,
            'locale' => $targetLocale,
            'status' => InterpretationGuide::STATUS_DRAFT,
            'review_state' => InterpretationGuide::REVIEW_DRAFT,
            'test_family' => (string) $source->test_family,
            'result_context' => (string) $source->result_context,
            'audience' => $source->audience,
            'related_guide_ids' => $source->related_guide_ids,
            'related_methodology_page_ids' => $source->related_methodology_page_ids,
            'canonical_path' => $source->canonical_path,
        ];
    }

    protected function additionalPayloadFields(Model $record): array
    {
        return [
            'test_family' => (string) $record->test_family,
            'result_context' => (string) $record->result_context,
            'audience' => $record->audience,
            'related_guide_ids' => is_array($record->related_guide_ids) ? $record->related_guide_ids : [],
            'related_methodology_page_ids' => is_array($record->related_methodology_page_ids) ? $record->related_methodology_page_ids : [],
            'canonical_path' => $record->canonical_path,
        ];
    }

    protected function applyAdditionalPayloadFields(Model $record, array $payload): void
    {
        $record->forceFill([
            'test_family' => (string) ($payload['test_family'] ?? ''),
            'result_context' => (string) ($payload['result_context'] ?? ''),
            'audience' => $payload['audience'] ?? null,
            'related_guide_ids' => array_values((array) ($payload['related_guide_ids'] ?? [])),
            'related_methodology_page_ids' => array_values((array) ($payload['related_methodology_page_ids'] ?? [])),
            'canonical_path' => $payload['canonical_path'] ?? null,
        ]);
    }
}
