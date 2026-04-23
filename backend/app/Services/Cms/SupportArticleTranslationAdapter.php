<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Resources\SupportArticleResource;
use App\Models\SupportArticle;
use Illuminate\Database\Eloquent\Model;

final class SupportArticleTranslationAdapter extends AbstractSiblingTranslationAdapter
{
    public function contentType(): string
    {
        return 'support_article';
    }

    public function modelClass(): string
    {
        return SupportArticle::class;
    }

    public function isPublished(Model $record): bool
    {
        return (string) $record->status === SupportArticle::STATUS_PUBLISHED
            && (string) $record->review_state === SupportArticle::REVIEW_APPROVED;
    }

    public function editUrl(Model $record): string
    {
        return SupportArticleResource::getUrl('edit', ['record' => $record]);
    }

    public function publicUrls(Model $record): array
    {
        $canonical = trim((string) ($record->canonical_path ?? ''));

        return array_values(array_filter([
            $canonical !== '' ? $canonical : '/support/articles/'.trim((string) $record->slug),
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
        return SupportArticle::TRANSLATION_STATUS_SOURCE;
    }

    protected function translationStatusMachineDraft(): string
    {
        return SupportArticle::TRANSLATION_STATUS_MACHINE_DRAFT;
    }

    protected function translationStatusHumanReview(): string
    {
        return SupportArticle::TRANSLATION_STATUS_HUMAN_REVIEW;
    }

    protected function translationStatusApproved(): string
    {
        return SupportArticle::TRANSLATION_STATUS_APPROVED;
    }

    protected function translationStatusPublished(): string
    {
        return SupportArticle::TRANSLATION_STATUS_PUBLISHED;
    }

    protected function translationStatusArchived(): string
    {
        return SupportArticle::TRANSLATION_STATUS_ARCHIVED;
    }

    protected function reviewStateHumanReview(): string
    {
        return SupportArticle::REVIEW_SUPPORT;
    }

    protected function reviewStateApproved(): string
    {
        return SupportArticle::REVIEW_APPROVED;
    }

    protected function publishAttributes(Model $target): array
    {
        return [
            'status' => SupportArticle::STATUS_PUBLISHED,
            'review_state' => SupportArticle::REVIEW_APPROVED,
            'published_at' => $target->published_at ?? now(),
        ];
    }

    protected function archiveAttributes(Model $target): array
    {
        return [
            'status' => SupportArticle::STATUS_ARCHIVED,
        ];
    }

    protected function baseCreateAttributes(Model $source, string $targetLocale): array
    {
        return [
            'slug' => (string) $source->slug,
            'locale' => $targetLocale,
            'status' => SupportArticle::STATUS_DRAFT,
            'review_state' => SupportArticle::REVIEW_DRAFT,
            'support_category' => (string) $source->support_category,
            'support_intent' => (string) $source->support_intent,
            'primary_cta_label' => $source->primary_cta_label,
            'primary_cta_url' => $source->primary_cta_url,
            'related_support_article_ids' => $source->related_support_article_ids,
            'related_content_page_ids' => $source->related_content_page_ids,
            'canonical_path' => $source->canonical_path,
        ];
    }

    protected function additionalPayloadFields(Model $record): array
    {
        return [
            'support_category' => (string) $record->support_category,
            'support_intent' => (string) $record->support_intent,
            'primary_cta_label' => $record->primary_cta_label,
            'primary_cta_url' => $record->primary_cta_url,
            'related_support_article_ids' => is_array($record->related_support_article_ids) ? $record->related_support_article_ids : [],
            'related_content_page_ids' => is_array($record->related_content_page_ids) ? $record->related_content_page_ids : [],
            'canonical_path' => $record->canonical_path,
        ];
    }

    protected function applyAdditionalPayloadFields(Model $record, array $payload): void
    {
        $record->forceFill([
            'support_category' => (string) ($payload['support_category'] ?? $record->support_category),
            'support_intent' => (string) ($payload['support_intent'] ?? $record->support_intent),
            'primary_cta_label' => $payload['primary_cta_label'] ?? null,
            'primary_cta_url' => $payload['primary_cta_url'] ?? null,
            'related_support_article_ids' => array_values((array) ($payload['related_support_article_ids'] ?? [])),
            'related_content_page_ids' => array_values((array) ($payload['related_content_page_ids'] ?? [])),
            'canonical_path' => $payload['canonical_path'] ?? null,
        ]);
    }
}
