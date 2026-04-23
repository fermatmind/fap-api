<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\SiblingTranslationAdapter;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractSiblingTranslationAdapter implements SiblingTranslationAdapter
{
    protected const PUBLIC_EDITORIAL_ORG_ID = 0;

    abstract protected function titleField(): string;

    abstract protected function summaryField(): string;

    abstract protected function bodyField(): string;

    abstract protected function seoTitleField(): string;

    abstract protected function seoDescriptionField(): string;

    abstract protected function slugField(): string;

    abstract protected function localeField(): string;

    abstract protected function translationStatusSource(): string;

    abstract protected function translationStatusMachineDraft(): string;

    abstract protected function translationStatusHumanReview(): string;

    abstract protected function translationStatusApproved(): string;

    abstract protected function translationStatusPublished(): string;

    abstract protected function translationStatusArchived(): string;

    abstract protected function reviewStateHumanReview(): string;

    abstract protected function reviewStateApproved(): string;

    abstract protected function publishAttributes(Model $target): array;

    abstract protected function archiveAttributes(Model $target): array;

    abstract protected function baseCreateAttributes(Model $source, string $targetLocale): array;

    public function sourceLocale(Model $record): string
    {
        return (string) ($record->source_locale ?: $record->locale);
    }

    public function translationGroupId(Model $record): string
    {
        return (string) $record->translation_group_id;
    }

    public function isSource(Model $record): bool
    {
        return (string) $record->translation_status === $this->translationStatusSource()
            && $record->source_content_id === null
            && (string) $record->locale === (string) $record->source_locale;
    }

    public function supportsPublishedResync(): bool
    {
        return false;
    }

    public function supportsCreateDraft(): bool
    {
        return true;
    }

    public function normalizedSourcePayload(Model $record): array
    {
        $titleField = $this->titleField();
        $summaryField = $this->summaryField();
        $bodyField = $this->bodyField();
        $seoTitleField = $this->seoTitleField();
        $seoDescriptionField = $this->seoDescriptionField();

        return [
            'title' => (string) $record->{$titleField},
            'summary' => $record->{$summaryField},
            'body_md' => (string) ($record->{$bodyField} ?? ''),
            'seo_title' => $record->{$seoTitleField},
            'seo_description' => $record->{$seoDescriptionField},
        ];
    }

    public function createTarget(Model $source, string $targetLocale, array $payload): Model
    {
        $modelClass = $this->modelClass();
        /** @var Model $target */
        $target = new $modelClass;
        $target->fill($this->baseCreateAttributes($source, $targetLocale) + [
            'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
            'translation_group_id' => (string) $source->translation_group_id,
            'source_locale' => (string) $source->locale,
            'translation_status' => $this->translationStatusMachineDraft(),
            'source_content_id' => (int) $source->id,
            'source_version_hash' => (string) $source->source_version_hash,
            'translated_from_version_hash' => (string) $source->source_version_hash,
        ]);

        $this->applyMachinePayload($target, $payload);
        $target->save();

        return $target->refresh();
    }

    public function applyMachinePayload(Model $target, array $payload): void
    {
        $titleField = $this->titleField();
        $summaryField = $this->summaryField();
        $bodyField = $this->bodyField();
        $seoTitleField = $this->seoTitleField();
        $seoDescriptionField = $this->seoDescriptionField();

        $target->forceFill([
            $titleField => (string) $payload['title'],
            $summaryField => $payload['summary'] ?? null,
            $bodyField => (string) $payload['body_md'],
            $seoTitleField => $payload['seo_title'] ?? null,
            $seoDescriptionField => $payload['seo_description'] ?? null,
            'translation_status' => $this->translationStatusMachineDraft(),
        ]);
    }

    public function markHumanReview(Model $target): void
    {
        $target->forceFill([
            'translation_status' => $this->translationStatusHumanReview(),
            'review_state' => $this->reviewStateHumanReview(),
        ]);
    }

    public function markApproved(Model $target): void
    {
        $target->forceFill([
            'translation_status' => $this->translationStatusApproved(),
            'review_state' => $this->reviewStateApproved(),
        ]);
    }

    public function markPublished(Model $target): void
    {
        $target->forceFill([
            'translation_status' => $this->translationStatusPublished(),
        ] + $this->publishAttributes($target));
    }

    public function markArchived(Model $target): void
    {
        $target->forceFill([
            'translation_status' => $this->translationStatusArchived(),
        ] + $this->archiveAttributes($target));
    }

    public function requiredFieldBlockers(Model $target): array
    {
        $payload = $this->normalizedSourcePayload($target);
        $blockers = [];

        if (trim((string) $payload['title']) === '') {
            $blockers[] = 'title missing';
        }
        if (trim((string) ($payload['body_md'] ?? '')) === '') {
            $blockers[] = 'body missing';
        }
        if (trim((string) ($payload['seo_title'] ?? '')) === '') {
            $blockers[] = 'seo title missing';
        }
        if (trim((string) ($payload['seo_description'] ?? '')) === '') {
            $blockers[] = 'seo description missing';
        }

        return $blockers;
    }
}
