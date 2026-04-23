<?php

declare(strict_types=1);

namespace App\Contracts\Cms;

use App\Models\Article;

interface ArticleMachineTranslationProvider
{
    public function isConfigured(): bool;

    public function unavailableReason(): ?string;

    /**
     * @return array{title:string,excerpt:string|null,content_md:string,seo_title:string|null,seo_description:string|null}
     */
    public function translate(Article $source, string $targetLocale): array;
}
