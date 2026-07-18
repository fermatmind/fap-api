<?php

declare(strict_types=1);

namespace App\Services\Career;

final class CareerDirectoryAuthorityService
{
    public const AUTHORITY_VERSION = 'career.directory_authority.v1';

    private const EXCLUDED_SLUGS = [
        'software-developers',
        'digital-forensics-analysts',
        'computer-occupations-all-other',
    ];

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(string $locale, int $page = 1, int $perPage = 50, ?string $family = null, ?string $query = null): array
    {
        $publicLocale = $this->normalizePublicLocale($locale);
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $readModel = $this->responseCache->directoryReadModelPayload($publicLocale);
        $items = $this->readyIndexableItems(
            is_array($readModel['items'] ?? null) ? $readModel['items'] : [],
        );
        $publicDetailIndexableCount = count($items);
        $queryNormalized = $this->normalizeFilter($query);
        $familyNormalized = $this->normalizeFilter($family);

        $queryFilteredItems = $queryNormalized === ''
            ? $items
            : array_values(array_filter(
                $items,
                fn (array $item): bool => $this->matchesQuery($item, $queryNormalized),
            ));
        $facets = $this->familyFacets($queryFilteredItems);

        $filteredItems = $familyNormalized === ''
            ? $queryFilteredItems
            : array_values(array_filter(
                $queryFilteredItems,
                fn (array $item): bool => $this->matchesFamily($item, $familyNormalized),
            ));

        $total = count($filteredItems);
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_map(
            fn (array $item): array => $this->publicItem($item),
            array_slice($filteredItems, $offset, $perPage),
        );

        return [
            'authority_version' => self::AUTHORITY_VERSION,
            'bundle_kind' => 'career_directory',
            'bundle_version' => 'career.directory.v1',
            'public_truth' => [
                'public_detail_indexable_count' => $publicDetailIndexableCount,
                'directory_member_count' => $publicDetailIndexableCount,
                'future_scale_ready' => true,
                'excluded_slugs' => self::EXCLUDED_SLUGS,
            ],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
                'has_next_page' => $offset + count($pagedItems) < $total,
                'has_previous_page' => $page > 1,
            ],
            'filters' => [
                'locale' => $publicLocale,
                'family' => $familyNormalized !== '' ? $familyNormalized : null,
                'q' => $queryNormalized !== '' ? $queryNormalized : null,
            ],
            'facets' => [
                'families' => $facets,
            ],
            'items' => $pagedItems,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indexableItems(string $locale): array
    {
        $publicLocale = $this->normalizePublicLocale($locale);
        $readModel = $this->responseCache->directoryReadModelPayload($publicLocale);
        $items = $this->readyIndexableItems(
            is_array($readModel['items'] ?? null) ? $readModel['items'] : [],
        );

        return array_map(fn (array $item): array => $this->publicItem($item), $items);
    }

    /**
     * @return list<string>
     */
    public static function excludedSlugs(): array
    {
        return self::EXCLUDED_SLUGS;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function familyFacets(array $items): array
    {
        $facets = [];

        foreach ($items as $item) {
            $slug = $this->normalizeFilter($item['family']['slug'] ?? null);
            if ($slug === '') {
                continue;
            }

            $facets[$slug] ??= [
                'slug' => $slug,
                'title_en' => $this->normalizeText($item['family']['title_en'] ?? null),
                'title_zh' => $this->normalizeText($item['family']['title_zh'] ?? null),
                'count' => 0,
            ];
            $facets[$slug]['count']++;
        }

        $values = array_values($facets);
        usort($values, static fn (array $left, array $right): int => strcmp((string) $left['slug'], (string) $right['slug']));

        return $values;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesQuery(array $item, string $query): bool
    {
        $haystack = is_string($item['search_text'] ?? null)
            ? $item['search_text']
            : strtolower(implode(' ', [
                $item['slug'] ?? '',
                $item['title'] ?? '',
                $item['title_en'] ?? '',
                $item['title_zh'] ?? '',
            ]));

        return str_contains($haystack, strtolower($query));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesFamily(array $item, string $family): bool
    {
        return $this->normalizeFilter($item['family']['slug'] ?? null) === $family;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function publicItem(array $item): array
    {
        unset($item['search_text']);

        return $item;
    }

    /** @param list<mixed> $items @return list<array<string, mixed>> */
    private function readyIndexableItems(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
                && ($item['indexable'] ?? false) === true
                && ($item['detail_ready'] ?? false) === true,
        ));
    }

    private function normalizePublicLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        return in_array($normalized, ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function normalizeFilter(mixed $value): string
    {
        return strtolower($this->normalizeText($value));
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
