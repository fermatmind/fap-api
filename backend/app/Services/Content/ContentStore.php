<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Content\V2\ContentStoreV2;

/**
 * Thin facade for gradual cutover.
 *
 * Public API is preserved; implementation is delegated by feature flag.
 */
final class ContentStore
{
    private ContentStoreV2 $v2;

    /**
     * Legacy path is intentionally kept as a separate instance to preserve a rollback point.
     */
    private ContentStoreV2 $legacy;

    public function __construct(array $chain, array $ctx = [], string $legacyDir = '')
    {
        $this->v2 = new ContentStoreV2($chain, $ctx, $legacyDir);
        $this->legacy = new ContentStoreV2($chain, $ctx, $legacyDir);
    }

    public function loadCards(string $section): array
    {
        return $this->delegate()->loadCards($section);
    }

    public function loadCardsDoc(string $section): array
    {
        return $this->delegate()->loadCardsDoc($section);
    }

    public function loadSectionPolicies(): array
    {
        return $this->delegate()->loadSectionPolicies();
    }

    public function loadFallbackCards(string $section): array
    {
        return $this->delegate()->loadFallbackCards($section);
    }

    public function loadHighlightPools(): array
    {
        return $this->delegate()->loadHighlightPools();
    }

    public function loadHighlightRules(): array
    {
        return $this->delegate()->loadHighlightRules();
    }

    public function loadHighlightPolicy(): array
    {
        return $this->delegate()->loadHighlightPolicy();
    }

    public function loadSelectRules(): array
    {
        return $this->delegate()->loadSelectRules();
    }

    public function loadHighlights(): array
    {
        return $this->delegate()->loadHighlights();
    }

    public function loadReads(): array
    {
        return $this->delegate()->loadReads();
    }

    public function loadReportOverrides(): array
    {
        return $this->delegate()->loadReportOverrides();
    }

    public function loadOverrides(): ?array
    {
        return $this->delegate()->loadOverrides();
    }

    public function overridesOrderBuckets(): array
    {
        return $this->delegate()->overridesOrderBuckets();
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->delegate()->{$name}(...$arguments);
    }

    private function delegate(): ContentStoreV2
    {
        if (config('features.content_store_v2', false) === true) {
            return $this->v2;
        }

        return $this->legacy;
    }
}
