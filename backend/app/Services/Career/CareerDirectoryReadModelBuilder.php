<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\IndexStateValue;
use App\Models\OccupationFamily;

final class CareerDirectoryReadModelBuilder
{
    public const READ_MODEL_VERSION = 'career.directory_read_model.v1';

    /** @param list<mixed> $rows @return array<string, mixed> */
    public function build(array $rows, string $publicLocale): array
    {
        $locale = $this->normalizePublicLocale($publicLocale);
        $localePrefix = $locale === 'zh-CN' ? 'zh' : 'en';
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

            $familyUuid = is_scalar($row['identity']['family_uuid'] ?? null) ? (string) $row['identity']['family_uuid'] : '';
            $family = $familyUuid !== '' ? ($familyMap[$familyUuid] ?? null) : null;
            $titleEn = $this->normalizeText($row['titles']['canonical_en'] ?? null);
            $titleZh = $this->normalizeText($row['titles']['canonical_zh'] ?? null);

            $items[] = [
                'slug' => $slug,
                'title_en' => $titleEn,
                'title_zh' => $titleZh,
                'title' => $locale === 'zh-CN' && $titleZh !== '' ? $titleZh : $titleEn,
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
                'search_text' => strtolower(implode(' ', array_filter([$slug, $titleEn, $titleZh]))),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp(strtolower((string) ($left['title_en'] ?? '')), strtolower((string) ($right['title_en'] ?? '')))
                ?: strcmp((string) ($left['slug'] ?? ''), (string) ($right['slug'] ?? ''));
        });

        return [
            'read_model_version' => self::READ_MODEL_VERSION,
            'locale' => $locale,
            'public_count' => count($items),
            'facets' => $this->familyFacets($items),
            'items' => $items,
        ];
    }

    /** @param list<mixed> $rows @return array<string, array{slug: string, title_en: string, title_zh: string}> */
    private function familyMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_scalar($row['identity']['family_uuid'] ?? null)) {
                $ids[(string) $row['identity']['family_uuid']] = true;
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
            ])->all();
    }

    /** @param array<string, mixed> $row */
    private function isDirectoryEligible(array $row): bool
    {
        $slug = $this->normalizeSlug($row['identity']['canonical_slug'] ?? null);
        if ($slug === '' || in_array($slug, CareerDirectoryAuthorityService::excludedSlugs(), true)) {
            return false;
        }

        $seo = is_array($row['seo_contract'] ?? null) ? $row['seo_contract'] : [];
        $indexState = strtolower($this->normalizeText($seo['index_state'] ?? null));
        $robotsPolicy = strtolower($this->normalizeText($seo['robots_policy'] ?? null));

        return ($seo['index_eligible'] ?? false) === true
            && in_array($indexState, [IndexStateValue::INDEXABLE, 'indexed'], true)
            && ! str_contains($robotsPolicy, 'noindex');
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function familyFacets(array $items): array
    {
        $facets = [];
        foreach ($items as $item) {
            $slug = strtolower($this->normalizeText($item['family']['slug'] ?? null));
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
        ksort($facets);

        return array_values($facets);
    }

    private function normalizePublicLocale(string $locale): string
    {
        return in_array(strtolower(trim($locale)), ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function normalizeSlug(mixed $value): string
    {
        $normalized = strtolower($this->normalizeText($value));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalized) === 1 ? $normalized : '';
    }

    private function normalizeText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
