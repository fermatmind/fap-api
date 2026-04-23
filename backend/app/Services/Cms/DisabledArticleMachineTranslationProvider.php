<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Models\Article;
use RuntimeException;

final class DisabledArticleMachineTranslationProvider implements ArticleMachineTranslationProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function unavailableReason(): ?string
    {
        return 'Machine translation provider is not configured. Configure an ArticleMachineTranslationProvider binding before creating machine drafts.';
    }

    public function translate(Article $source, string $targetLocale): array
    {
        throw new RuntimeException((string) $this->unavailableReason());
    }
}
