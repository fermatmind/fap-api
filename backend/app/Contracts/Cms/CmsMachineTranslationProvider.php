<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

interface CmsMachineTranslationProvider
{
    public function supports(string $contentType): bool;

    public function isConfigured(): bool;

    public function unavailableReason(string $contentType): ?string;

    /**
     * @param  array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}  $normalizedSource
     * @return array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}
     */
    public function translate(
        string $contentType,
        object $sourceRecord,
        array $normalizedSource,
        string $targetLocale
    ): array;
}
