<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

use Illuminate\Database\Eloquent\Model;

interface SiblingTranslationAdapter
{
    public function contentType(): string;

    /**
     * @return class-string<Model>
     */
    public function modelClass(): string;

    public function sourceLocale(Model $record): string;

    public function translationGroupId(Model $record): string;

    public function isSource(Model $record): bool;

    public function isPublished(Model $record): bool;

    public function supportsPublishedResync(): bool;

    public function supportsCreateDraft(): bool;

    public function editUrl(Model $record): string;

    /**
     * @return list<string>
     */
    public function publicUrls(Model $record): array;

    /**
     * @return array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}
     */
    public function normalizedSourcePayload(Model $record): array;

    /**
     * @param  array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}  $payload
     */
    public function createTarget(Model $source, string $targetLocale, array $payload): Model;

    /**
     * @param  array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}  $payload
     */
    public function applyMachinePayload(Model $target, array $payload): void;

    public function markHumanReview(Model $target): void;

    public function markApproved(Model $target): void;

    public function markPublished(Model $target): void;

    public function markArchived(Model $target): void;

    /**
     * @return list<string>
     */
    public function requiredFieldBlockers(Model $target): array;
}
