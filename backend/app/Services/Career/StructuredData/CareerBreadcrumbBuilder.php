<?php

declare(strict_types=1);

namespace App\Services\Career\StructuredData;

use App\DTO\Career\CareerFamilyHubBundle;
use App\DTO\Career\CareerJobDetailBundle;

final class CareerBreadcrumbBuilder
{
    /**
     * @return list<array{name:string,path:string}>
     */
    public function buildForJobDetail(CareerJobDetailBundle $bundle): array
    {
        $canonicalPath = $this->normalizeString($bundle->seoContract['canonical_path'] ?? null)
            ?? '/career/jobs/'.$this->normalizeString($bundle->identity['canonical_slug'] ?? null);
        $canonicalTitle = $this->resolveJobTitle($bundle);

        return $this->filterNodes([
            ['name' => 'Career', 'path' => '/career'],
            ['name' => $canonicalTitle, 'path' => $canonicalPath],
        ]);
    }

    /**
     * @return list<array{name:string,path:string}>
     */
    public function buildForFamilyHub(CareerFamilyHubBundle $bundle): array
    {
        $canonicalSlug = $this->normalizeString($bundle->family['canonical_slug'] ?? null);
        $canonicalPath = $canonicalSlug !== null ? '/career/family/'.$canonicalSlug : null;
        $canonicalTitle = $this->resolveFamilyTitle($bundle);

        return $this->filterNodes([
            ['name' => 'Career', 'path' => '/career'],
            ['name' => $canonicalTitle, 'path' => $canonicalPath],
        ]);
    }

    /**
     * @param  list<array{name:string,path:string}>  $nodes
     * @return array<string, mixed>
     */
    public function buildBreadcrumbList(array $nodes): array
    {
        $items = [];

        foreach (array_values($nodes) as $index => $node) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $node['name'],
                'item' => $node['path'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function resolveJobTitle(CareerJobDetailBundle $bundle): string
    {
        return $this->normalizeString($bundle->titles['canonical_en'] ?? null)
            ?? $this->normalizeString($bundle->titles['canonical_zh'] ?? null)
            ?? $this->normalizeString($bundle->identity['canonical_slug'] ?? null)
            ?? 'Career role';
    }

    private function resolveFamilyTitle(CareerFamilyHubBundle $bundle): string
    {
        return $this->normalizeString($bundle->family['title_en'] ?? null)
            ?? $this->normalizeString($bundle->family['title_zh'] ?? null)
            ?? $this->normalizeString($bundle->family['canonical_slug'] ?? null)
            ?? 'Career family';
    }

    /**
     * @param  list<array{name:string,path:?string}>  $nodes
     * @return list<array{name:string,path:string}>
     */
    private function filterNodes(array $nodes): array
    {
        $filtered = [];

        foreach ($nodes as $node) {
            $name = $this->normalizeString($node['name'] ?? null);
            $path = $this->normalizeString($node['path'] ?? null);

            if ($name === null || $path === null) {
                continue;
            }

            $filtered[] = [
                'name' => $name,
                'path' => $path,
            ];
        }

        return $filtered;
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
