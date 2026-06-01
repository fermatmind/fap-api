<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Models\OccupationFamily;

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
        $localePrefix = $publicLocale === 'zh-CN' ? 'zh' : 'en';
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        $items = $this->directoryItems($publicLocale, $localePrefix);
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
        $pagedItems = array_slice($filteredItems, $offset, $perPage);

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
    private function directoryItems(string $publicLocale, string $localePrefix): array
    {
        $payload = $this->responseCache->jobIndexPayload($publicLocale);
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $familyMap = $this->familyMap($rows);
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! $this->isDirectoryEligible($row)) {
                continue;
            }

            $slug = $this->normalizeSlug($row['identity']['canonical_slug'] ?? null);
            if ($slug === '') {
                continue;
            }

            $familyUuid = is_scalar($row['identity']['family_uuid'] ?? null) ? (string) $row['identity']['family_uuid'] : null;
            $family = $familyUuid !== null ? ($familyMap[$familyUuid] ?? null) : null;
            $titleEn = $this->normalizeText($row['titles']['canonical_en'] ?? null);
            $titleZh = $this->normalizeText($row['titles']['canonical_zh'] ?? null);

            $items[] = [
                'slug' => $slug,
                'title_en' => $titleEn,
                'title_zh' => $titleZh,
                'title' => $publicLocale === 'zh-CN' && $titleZh !== '' ? $titleZh : $titleEn,
                'family' => [
                    'slug' => $family['slug'] ?? null,
                    'title_en' => $family['title_en'] ?? null,
                    'title_zh' => $family['title_zh'] ?? null,
                ],
                'canonical_path' => '/'.$localePrefix.'/career/jobs/'.$slug,
                'indexability_state' => $this->normalizeText($row['seo_contract']['index_state'] ?? null),
                'robots_policy' => $this->normalizeText($row['seo_contract']['robots_policy'] ?? null),
                'indexable' => true,
                'detail_ready' => true,
                'updated_at' => $this->normalizeText($row['provenance_meta']['compiled_at'] ?? null) ?: null,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp(strtolower((string) ($left['title_en'] ?? '')), strtolower((string) ($right['title_en'] ?? '')))
                ?: strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
        });

        return $items;
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<string, array{slug: string, title_en: string, title_zh: string}>
     */
    private function familyMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $familyUuid = is_scalar($row['identity']['family_uuid'] ?? null) ? (string) $row['identity']['family_uuid'] : '';
            if ($familyUuid !== '') {
                $ids[$familyUuid] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return OccupationFamily::query()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'canonical_slug', 'title_en', 'title_zh'])
            ->mapWithKeys(static fn (OccupationFamily $family): array => [
                (string) $family->id => [
                    'slug' => (string) $family->canonical_slug,
                    'title_en' => (string) $family->title_en,
                    'title_zh' => (string) $family->title_zh,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isDirectoryEligible(array $row): bool
    {
        $slug = $this->normalizeSlug($row['identity']['canonical_slug'] ?? null);
        if ($slug === '' || in_array($slug, self::EXCLUDED_SLUGS, true)) {
            return false;
        }

        $seo = is_array($row['seo_contract'] ?? null) ? $row['seo_contract'] : [];
        $indexEligible = ($seo['index_eligible'] ?? false) === true;
        $indexState = strtolower($this->normalizeText($seo['index_state'] ?? null));
        $robotsPolicy = strtolower($this->normalizeText($seo['robots_policy'] ?? null));

        return $indexEligible
            && in_array($indexState, [IndexStateValue::INDEXABLE, 'indexed'], true)
            && ! str_contains($robotsPolicy, 'noindex');
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
        $haystack = strtolower(implode(' ', [
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

    private function normalizePublicLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        return in_array($normalized, ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function normalizeSlug(mixed $value): string
    {
        $normalized = strtolower($this->normalizeText($value));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalized) === 1 ? $normalized : '';
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
