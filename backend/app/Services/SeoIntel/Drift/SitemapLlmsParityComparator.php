<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Drift;

final class SitemapLlmsParityComparator
{
    /**
     * @param  list<string>  $inventoryUrls
     * @param  list<string>  $sitemapUrls
     * @param  list<string>  $llmsUrls
     * @param  list<string>  $privateFlowUrls
     * @param  array<string, string>  $sourceAuthoritiesByUrl
     * @return array{
     *     missing_in_sitemap: list<string>,
     *     extra_in_sitemap: list<string>,
     *     missing_in_llms: list<string>,
     *     extra_in_llms: list<string>,
     *     private_flow_exposure_warning: list<string>,
     *     source_authority_mismatch: list<string>
     * }
     */
    public function compare(
        array $inventoryUrls,
        array $sitemapUrls,
        array $llmsUrls,
        array $privateFlowUrls = [],
        array $sourceAuthoritiesByUrl = [],
    ): array {
        $inventory = $this->normalizeSet($inventoryUrls);
        $sitemap = $this->normalizeSet($sitemapUrls);
        $llms = $this->normalizeSet($llmsUrls);
        $private = $this->normalizeSet($privateFlowUrls);
        $publicSurfaces = array_values(array_unique(array_merge($sitemap, $llms)));

        return [
            'missing_in_sitemap' => $this->hashSet(array_values(array_diff($inventory, $sitemap))),
            'extra_in_sitemap' => $this->hashSet(array_values(array_diff($sitemap, $inventory))),
            'missing_in_llms' => $this->hashSet(array_values(array_diff($inventory, $llms))),
            'extra_in_llms' => $this->hashSet(array_values(array_diff($llms, $inventory))),
            'private_flow_exposure_warning' => $this->hashSet(array_values(array_intersect($private, $publicSurfaces))),
            'source_authority_mismatch' => $this->sourceAuthorityMismatches($sourceAuthoritiesByUrl),
        ];
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function normalizeSet(array $urls): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (string $url): string => rtrim(trim($url), '/'), $urls),
            static fn (string $url): bool => $url !== ''
        )));

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function hashSet(array $values): array
    {
        return array_values(array_map(static fn (string $value): string => hash('sha256', $value), $values));
    }

    /**
     * @param  array<string, string>  $sourceAuthoritiesByUrl
     * @return list<string>
     */
    private function sourceAuthorityMismatches(array $sourceAuthoritiesByUrl): array
    {
        $allowed = [
            'backend_sitemap_source',
            'cms_article',
            'cms_topic',
            'cms_personality',
            'cms_career_job',
            'cms_career_recommendation',
            'scale_catalog',
            'backend_public_surface',
        ];

        $mismatches = [];

        foreach ($sourceAuthoritiesByUrl as $url => $sourceAuthority) {
            if (! in_array($sourceAuthority, $allowed, true)) {
                $mismatches[] = hash('sha256', rtrim(trim((string) $url), '/'));
            }
        }

        return $mismatches;
    }
}
