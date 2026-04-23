<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\ContentPageResource;
use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Model;

final class ContentPageTranslationAdapter extends AbstractSiblingTranslationAdapter
{
    public function contentType(): string
    {
        return 'content_page';
    }

    public function modelClass(): string
    {
        return ContentPage::class;
    }

    public function isPublished(Model $record): bool
    {
        return (string) $record->status === ContentPage::STATUS_PUBLISHED
            && (bool) $record->is_public;
    }

    public function editUrl(Model $record): string
    {
        return ContentPageResource::getUrl('edit', ['record' => $record]);
    }

    public function publicUrls(Model $record): array
    {
        return array_values(array_filter([
            trim((string) ($record->canonical_path ?: $record->path ?: '/'.trim((string) $record->slug))),
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
        return 'content_md';
    }

    protected function bodyHtmlField(): ?string
    {
        return 'content_html';
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
        return ContentPage::TRANSLATION_STATUS_SOURCE;
    }

    protected function translationStatusMachineDraft(): string
    {
        return ContentPage::TRANSLATION_STATUS_MACHINE_DRAFT;
    }

    protected function translationStatusHumanReview(): string
    {
        return ContentPage::TRANSLATION_STATUS_HUMAN_REVIEW;
    }

    protected function translationStatusApproved(): string
    {
        return ContentPage::TRANSLATION_STATUS_APPROVED;
    }

    protected function translationStatusPublished(): string
    {
        return ContentPage::TRANSLATION_STATUS_PUBLISHED;
    }

    protected function translationStatusArchived(): string
    {
        return ContentPage::TRANSLATION_STATUS_ARCHIVED;
    }

    protected function reviewStateHumanReview(): string
    {
        return 'owner_review';
    }

    protected function reviewStateApproved(): string
    {
        return 'approved';
    }

    protected function publishAttributes(Model $target): array
    {
        return [
            'status' => ContentPage::STATUS_PUBLISHED,
            'review_state' => 'approved',
            'is_public' => true,
            'published_at' => $target->published_at ?? now(),
        ];
    }

    protected function archiveAttributes(Model $target): array
    {
        return [
            'status' => ContentPage::STATUS_ARCHIVED,
            'is_public' => false,
        ];
    }

    protected function baseCreateAttributes(Model $source, string $targetLocale): array
    {
        return [
            'slug' => (string) $source->slug,
            'path' => (string) $source->path,
            'locale' => $targetLocale,
            'kind' => (string) $source->kind,
            'page_type' => (string) $source->page_type,
            'template' => (string) $source->template,
            'animation_profile' => (string) $source->animation_profile,
            'status' => ContentPage::STATUS_DRAFT,
            'review_state' => 'draft',
            'owner' => $source->owner,
            'legal_review_required' => (bool) $source->legal_review_required,
            'science_review_required' => (bool) $source->science_review_required,
            'is_public' => false,
            'is_indexable' => (bool) $source->is_indexable,
            'canonical_path' => $source->canonical_path,
            'meta_description' => $source->meta_description,
        ];
    }

    protected function additionalPayloadFields(Model $record): array
    {
        return [
            'path' => (string) $record->path,
            'kind' => (string) $record->kind,
            'page_type' => (string) $record->page_type,
            'kicker' => $record->kicker,
            'template' => (string) $record->template,
            'animation_profile' => (string) $record->animation_profile,
            'owner' => $record->owner,
            'legal_review_required' => (bool) $record->legal_review_required,
            'science_review_required' => (bool) $record->science_review_required,
            'source_doc' => $record->source_doc,
            'headings_json' => is_array($record->headings_json) ? $record->headings_json : [],
            'meta_description' => $record->meta_description,
            'canonical_path' => $record->canonical_path,
            'is_public' => (bool) $record->is_public,
            'is_indexable' => (bool) $record->is_indexable,
        ];
    }

    protected function applyAdditionalPayloadFields(Model $record, array $payload): void
    {
        $record->forceFill([
            'path' => (string) ($payload['path'] ?? ''),
            'kind' => (string) ($payload['kind'] ?? ''),
            'page_type' => (string) ($payload['page_type'] ?? ''),
            'kicker' => $payload['kicker'] ?? null,
            'template' => (string) ($payload['template'] ?? ''),
            'animation_profile' => (string) ($payload['animation_profile'] ?? ''),
            'owner' => $payload['owner'] ?? null,
            'legal_review_required' => (bool) ($payload['legal_review_required'] ?? false),
            'science_review_required' => (bool) ($payload['science_review_required'] ?? false),
            'source_doc' => $payload['source_doc'] ?? null,
            'headings_json' => array_values((array) ($payload['headings_json'] ?? [])),
            'meta_description' => $payload['meta_description'] ?? null,
            'canonical_path' => $payload['canonical_path'] ?? null,
            'is_public' => (bool) ($payload['is_public'] ?? false),
            'is_indexable' => (bool) ($payload['is_indexable'] ?? false),
        ]);
    }
}
