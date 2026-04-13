<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

use App\DTO\Career\CareerFamilyHubBundle;

final class CareerFamilyHubStructuredDataBuilder
{
    public function __construct(
        private readonly CareerBreadcrumbBuilder $breadcrumbBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CareerFamilyHubBundle $bundle): array
    {
        $canonicalSlug = $this->normalizeString($bundle->family['canonical_slug'] ?? null);
        $canonicalPath = $canonicalSlug !== null ? '/career/family/'.$canonicalSlug : null;
        $canonicalTitle = $this->resolveTitle($bundle);
        $breadcrumbNodes = $this->breadcrumbBuilder->buildForFamilyHub($bundle);
        $itemListElements = $this->buildItemListElements($bundle);

        return [
            'route_kind' => 'career_family_hub',
            'canonical_path' => $canonicalPath,
            'canonical_title' => $canonicalTitle,
            'breadcrumb_nodes' => $breadcrumbNodes,
            'fragments' => [
                'collection_page' => $this->buildCollectionPageFragment($canonicalPath, $canonicalTitle, count($itemListElements)),
                'item_list' => $this->buildItemListFragment($itemListElements),
                'breadcrumb_list' => $this->breadcrumbBuilder->buildBreadcrumbList($breadcrumbNodes),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCollectionPageFragment(?string $canonicalPath, string $canonicalTitle, int $numberOfItems): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $canonicalTitle,
            'url' => $canonicalPath,
            'mainEntityOfPage' => $canonicalPath,
            'numberOfItems' => $numberOfItems,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildItemListElements(CareerFamilyHubBundle $bundle): array
    {
        $items = [];

        foreach (array_values($bundle->visibleChildren) as $child) {
            $name = $this->normalizeString($child['canonical_title_en'] ?? null)
                ?? $this->normalizeString($child['canonical_title_zh'] ?? null)
                ?? $this->normalizeString($child['canonical_slug'] ?? null);
            $path = $this->normalizeString(data_get($child, 'seo_contract.canonical_path'));

            if ($name === null || $path === null) {
                continue;
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'name' => $name,
                'url' => $path,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function buildItemListFragment(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $items,
            'numberOfItems' => count($items),
        ];
    }

    private function resolveTitle(CareerFamilyHubBundle $bundle): string
    {
        return $this->normalizeString($bundle->family['title_en'] ?? null)
            ?? $this->normalizeString($bundle->family['title_zh'] ?? null)
            ?? $this->normalizeString($bundle->family['canonical_slug'] ?? null)
            ?? 'Career family';
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
