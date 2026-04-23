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
}
