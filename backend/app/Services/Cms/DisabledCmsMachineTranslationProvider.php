<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\CmsMachineTranslationProvider;
use RuntimeException;

final class DisabledCmsMachineTranslationProvider implements CmsMachineTranslationProvider
{
    public function supports(string $contentType): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function unavailableReason(string $contentType): ?string
    {
        return sprintf(
            'Machine translation provider is not configured for %s. Bind a CmsMachineTranslationProvider for this content type before creating machine drafts.',
            $contentType
        );
    }

    public function translate(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): array
    {
        throw new RuntimeException((string) $this->unavailableReason($contentType));
    }
}
