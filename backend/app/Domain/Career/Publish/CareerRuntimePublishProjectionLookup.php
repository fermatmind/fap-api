<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;

final class CareerRuntimePublishProjectionLookup implements CareerRuntimePublishProjectionCoverageSnapshot, CareerRuntimePublishProjectionVisibility
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $itemsBySlugLocale = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $itemsBySlug = null;

    public function __construct(
        private readonly CareerGenerationAuthorityLoader $generationAuthority,
    ) {}

    /** @return array<string, mixed>|null */
    public function itemForSlug(string $slug, string $locale = 'en'): ?array
    {
        $slug = $this->normalizeSlug($slug);
        $locale = $this->normalizeLocale($locale);
        if ($slug === null) {
            return null;
        }

        return $this->itemsBySlugLocale()[$slug.'|'.$locale] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function publicDatasetItems(): array
    {
        return $this->visibleItems(static fn (array $item): bool => ($item['dataset_visible'] ?? false) === true);
    }

    /** @return list<array<string, mixed>> */
    public function publicDetailItems(): array
    {
        return $this->visibleItems(static fn (array $item): bool => ($item['detail_route_enabled'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true);
    }

    /** @param list<string> $locales @return array<string, array<string, mixed>> */
    public function jobDetailCoverageItems(array $locales): array
    {
        $normalizedLocales = array_values(array_unique(array_map(
            fn (string $locale): string => $this->normalizeLocale($locale),
            $locales,
        )));
        $this->hydrate();
        $items = [];

        foreach ($this->itemsBySlugLocale ?? [] as $item) {
            $slug = $this->normalizeSlug((string) ($item['slug'] ?? ''));
            $locale = $this->normalizeLocale((string) ($item['locale'] ?? 'en'));
            if ($slug === null || ! in_array($locale, $normalizedLocales, true)) {
                continue;
            }
            $items[$slug.'|'.($locale === 'zh' ? 'zh-CN' : 'en')] = $item;
        }
        ksort($items, SORT_STRING);

        return $items;
    }

    public function datasetVisible(string $slug): bool
    {
        return (bool) ($this->itemForSlug($slug)['dataset_visible'] ?? false);
    }

    public function searchVisible(string $slug): bool
    {
        return (bool) ($this->itemForSlug($slug)['search_visible'] ?? false);
    }

    public function detailRouteEnabled(string $slug): bool
    {
        return ($this->itemForSlug($slug)['detail_route_enabled'] ?? false) === true;
    }

    public function robotsIndexable(string $slug): bool
    {
        return ($this->itemForSlug($slug)['robots_indexable'] ?? false) === true;
    }

    public function releaseGatePass(string $slug): bool
    {
        return ($this->itemForSlug($slug)['release_gate_pass'] ?? false) === true;
    }

    public function familyHubLive(string $slug): bool
    {
        $item = $this->itemForSlug($slug);

        return is_array($item)
            && ($item['public_resolution_type'] ?? null) === CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB
            && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED;
    }

    /** @return array<string, array<string, mixed>> */
    private function itemsBySlugLocale(): array
    {
        $this->hydrate();

        return $this->itemsBySlugLocale ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    private function itemsBySlug(): array
    {
        $this->hydrate();

        return $this->itemsBySlug ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function visibleItems(callable $filter): array
    {
        $items = array_values(array_filter($this->itemsBySlug(), $filter));
        usort($items, static fn (array $left, array $right): int => strcmp(
            strtolower((string) ($left['slug'] ?? '')),
            strtolower((string) ($right['slug'] ?? '')),
        ));

        return $items;
    }

    private function hydrate(): void
    {
        if ($this->itemsBySlugLocale !== null && $this->itemsBySlug !== null) {
            return;
        }

        $this->itemsBySlugLocale = [];
        $this->itemsBySlug = [];
        $projection = $this->generationAuthority->activeProjection();
        if (! is_array($projection)) {
            return;
        }

        $items = $projection['items'] ?? null;
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if (! is_array($item)
                || ($item['runtime_publish_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
                continue;
            }
            $slug = $this->normalizeSlug((string) ($item['slug'] ?? ''));
            if ($slug === null) {
                continue;
            }
            $locale = $this->normalizeLocale((string) ($item['locale'] ?? 'en'));
            $this->itemsBySlugLocale[$slug.'|'.$locale] = $item;
            $this->itemsBySlug[$slug] ??= $item;
        }
    }

    private function normalizeSlug(string $slug): ?string
    {
        $normalized = strtolower(trim($slug));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }
}
