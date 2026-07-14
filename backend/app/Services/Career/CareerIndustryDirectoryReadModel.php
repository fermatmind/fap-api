<?php

declare(strict_types=1);

namespace App\Services\Career;

final class CareerIndustryDirectoryReadModel
{
    public const AUTHORITY_VERSION = 'career.industry_directory.v1';

    private const DISCOVERY_JOB_LIMIT = 3;

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /** @return array<string, mixed> */
    public function payload(string $locale): array
    {
        $publicLocale = $this->normalizePublicLocale($locale);
        $readModel = $this->responseCache->directoryReadModelPayload($publicLocale);
        $items = is_array($readModel['items'] ?? null) ? $readModel['items'] : [];
        $industries = $this->industries($items, $publicLocale);

        return [
            'authority_version' => self::AUTHORITY_VERSION,
            'bundle_kind' => 'career_industry_directory',
            'bundle_version' => 'career.industry_directory.v1',
            'locale' => $publicLocale,
            'public_detail_indexable_count' => (int) ($readModel['public_count'] ?? count($items)),
            'industry_count' => count($industries),
            'industries' => $industries,
        ];
    }

    /**
     * @param  list<mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function industries(array $items, string $locale): array
    {
        $industries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $family = is_array($item['family'] ?? null) ? $item['family'] : [];
            $familySlug = $this->normalizeSlug($family['slug'] ?? null);
            $jobSlug = $this->normalizeSlug($item['slug'] ?? null);
            if ($familySlug === '' || $jobSlug === '') {
                continue;
            }

            $industries[$familySlug] ??= [
                'slug' => $familySlug,
                'title' => $this->localizedTitle($family, $locale, $familySlug),
                'title_en' => $this->normalizeText($family['title_en'] ?? null),
                'title_zh' => $this->normalizeText($family['title_zh'] ?? null),
                'count' => 0,
                'public_detail_count' => 0,
                'indexable_count' => 0,
                'canonical_path' => $this->localePrefix($locale).'/career/industries/'.$familySlug,
                'discovery_jobs' => [],
            ];

            $industries[$familySlug]['count']++;
            $industries[$familySlug]['public_detail_count']++;
            $industries[$familySlug]['indexable_count']++;
            $industries[$familySlug]['discovery_jobs'][] = [
                'slug' => $jobSlug,
                'title' => $this->localizedTitle($item, $locale, $jobSlug),
                'title_en' => $this->normalizeText($item['title_en'] ?? null),
                'title_zh' => $this->normalizeText($item['title_zh'] ?? null),
                'canonical_path' => $this->localePrefix($locale).'/career/jobs/'.$jobSlug,
            ];
        }

        $values = array_values($industries);
        foreach ($values as &$industry) {
            usort(
                $industry['discovery_jobs'],
                static fn (array $left, array $right): int => strcasecmp(
                    (string) ($left['title_en'] ?: $left['title']),
                    (string) ($right['title_en'] ?: $right['title']),
                ),
            );
            $industry['discovery_jobs'] = array_slice($industry['discovery_jobs'], 0, self::DISCOVERY_JOB_LIMIT);
        }
        unset($industry);

        usort($values, static function (array $left, array $right): int {
            $countOrder = (int) $right['count'] <=> (int) $left['count'];

            return $countOrder !== 0
                ? $countOrder
                : strcasecmp((string) $left['title'], (string) $right['title']);
        });

        return $values;
    }

    /** @param array<string, mixed> $source */
    private function localizedTitle(array $source, string $locale, string $fallback): string
    {
        $preferred = $locale === 'en' ? $source['title_en'] ?? null : $source['title_zh'] ?? null;
        $secondary = $locale === 'en' ? $source['title_zh'] ?? null : $source['title_en'] ?? null;

        return $this->normalizeText($preferred)
            ?: ($this->normalizeText($source['title'] ?? null)
                ?: ($this->normalizeText($secondary) ?: $fallback));
    }

    private function normalizePublicLocale(string $locale): string
    {
        return in_array(strtolower(trim($locale)), ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function localePrefix(string $locale): string
    {
        return $locale === 'en' ? '/en' : '/zh';
    }

    private function normalizeSlug(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    private function normalizeText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
