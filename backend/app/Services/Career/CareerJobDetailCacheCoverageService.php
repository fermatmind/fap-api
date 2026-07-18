<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;

final class CareerJobDetailCacheCoverageService
{
    private const DEFAULT_EXAMPLE_LIMIT = 5;

    private const REPAIRABLE_CLASSIFICATIONS = [
        'missing_pointer',
        'missing_payload',
        'broken_pointer',
        'invalid_payload',
    ];

    public function __construct(
        private readonly CareerRuntimePublishProjectionVisibility $runtimeProjection,
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    /**
     * Perform a read-only inspection of every published Career slug and requested
     * locale. The returned rows are bounded only for in-process repair planning;
     * callers should emit report, whose examples are explicitly bounded.
     *
     * @param  list<string>  $locales
     * @return array{report: array<string, mixed>, rows: list<array{slug: string, locale: string, classification: string, repairable: bool}>}
     */
    public function inspect(array $locales = ['en', 'zh-CN'], int $exampleLimit = self::DEFAULT_EXAMPLE_LIMIT): array
    {
        $normalizedLocales = $this->normalizeLocales($locales);
        $slugs = $this->publishedSlugs();
        $counts = array_fill_keys([
            'ready_active',
            'ready_lkg',
            'legacy_migratable',
            'missing_pointer',
            'missing_payload',
            'broken_pointer',
            'invalid_payload',
            'held_or_unpublished_excluded',
        ], 0);
        $examples = array_fill_keys(array_keys($counts), []);
        $rows = [];
        $exampleLimit = min(25, max(0, $exampleLimit));

        foreach ($slugs as $slug) {
            foreach ($normalizedLocales as $locale) {
                $projectionItem = $this->runtimeProjection->itemForSlug($slug, $locale);
                $classification = $this->responseCache->jobDetailProjectionItemIsPublished($projectionItem)
                    ? $this->responseCache->jobDetailCacheReadiness($slug, $locale)['classification']
                    : 'held_or_unpublished_excluded';
                $counts[$classification]++;
                $repairable = in_array($classification, self::REPAIRABLE_CLASSIFICATIONS, true);
                $rows[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'classification' => $classification,
                    'repairable' => $repairable,
                ];

                if (count($examples[$classification]) < $exampleLimit) {
                    $examples[$classification][] = ['slug' => $slug, 'locale' => $locale];
                }
            }
        }

        $expected = count($rows);
        $excluded = $counts['held_or_unpublished_excluded'];
        $eligible = max(0, $expected - $excluded);
        $covered = $counts['ready_active'] + $counts['ready_lkg'] + $counts['legacy_migratable'];
        $missing = $counts['missing_pointer'] + $counts['missing_payload'];
        $broken = $counts['broken_pointer'] + $counts['invalid_payload'];
        $coverageRatio = $eligible === 0 ? 1.0 : round($covered / $eligible, 6);

        return [
            'report' => [
                'contract_version' => 'career.job_detail_cache_coverage.v1',
                'published_slug_count' => count($slugs),
                'locale_count' => count($normalizedLocales),
                'locales' => $normalizedLocales,
                'expected_target_count' => $expected,
                'ready_active_count' => $counts['ready_active'],
                'ready_lkg_count' => $counts['ready_lkg'],
                'legacy_migratable_count' => $counts['legacy_migratable'],
                'missing_pointer_count' => $counts['missing_pointer'],
                'missing_payload_count' => $counts['missing_payload'],
                'broken_pointer_count' => $counts['broken_pointer'],
                'invalid_payload_count' => $counts['invalid_payload'],
                'missing_count' => $missing,
                'broken_count' => $broken,
                'excluded_count' => $excluded,
                'covered_target_count' => $covered,
                'eligible_target_count' => $eligible,
                'coverage_ratio' => $coverageRatio,
                'examples' => array_filter(
                    $examples,
                    static fn (array $items): bool => $items !== [],
                ),
                'status' => ($missing + $broken) === 0 ? 'ready' : 'incomplete',
            ],
            'rows' => $rows,
        ];
    }

    /** @return list<string> */
    private function publishedSlugs(): array
    {
        $slugs = [];
        foreach ($this->runtimeProjection->publicDetailItems() as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) ($item['slug'] ?? data_get($item, 'identity.canonical_slug', ''))));
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        $slugs = array_keys($slugs);
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function normalizeLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $locale = str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
            $normalized[$locale] = true;
        }

        $result = array_keys($normalized);
        usort($result, static fn (string $left, string $right): int => array_search($left, ['en', 'zh-CN'], true) <=> array_search($right, ['en', 'zh-CN'], true));

        return $result;
    }
}
