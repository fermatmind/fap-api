<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\CmsMachineTranslationProvider;
use RuntimeException;

final class CmsMachineTranslationProviderRegistry
{
    public function __construct(
        private readonly DisabledCmsMachineTranslationProvider $disabledProvider,
    ) {}

    public function providerFor(string $contentType): CmsMachineTranslationProvider
    {
        $bindings = (array) config('services.cms_translation.providers', []);
        $binding = $bindings[$contentType] ?? null;

        if (! is_string($binding) || trim($binding) === '') {
            return $this->disabledProvider;
        }

        $provider = app($binding);
        if (! $provider instanceof CmsMachineTranslationProvider) {
            throw new RuntimeException(sprintf(
                'Configured cms translation provider [%s] for [%s] does not implement CmsMachineTranslationProvider.',
                $binding,
                $contentType
            ));
        }

        return $provider->supports($contentType)
            ? $provider
            : $this->disabledProvider;
    }
}
