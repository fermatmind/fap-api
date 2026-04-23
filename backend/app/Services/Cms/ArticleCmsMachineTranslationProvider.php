<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Contracts\Cms\CmsMachineTranslationProvider;
use App\Models\Article;
use RuntimeException;

final class ArticleCmsMachineTranslationProvider implements CmsMachineTranslationProvider
{
    public function __construct(
        private readonly ArticleMachineTranslationProvider $provider,
    ) {}

    public function supports(string $contentType): bool
    {
        return $contentType === 'article';
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    public function unavailableReason(string $contentType): ?string
    {
        if (! $this->supports($contentType)) {
            return sprintf('Provider does not support %s.', $contentType);
        }

        return $this->provider->unavailableReason();
    }

    public function translate(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): array
    {
        if (! $sourceRecord instanceof Article) {
            throw new RuntimeException('Article machine translation requires an Article source model.');
        }

        $translated = $this->provider->translate($sourceRecord, $targetLocale);

        return [
            'title' => (string) $translated['title'],
            'summary' => $translated['excerpt'] ?? null,
            'body_md' => (string) $translated['content_md'],
            'seo_title' => $translated['seo_title'] ?? null,
            'seo_description' => $translated['seo_description'] ?? null,
        ];
    }
}
