<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerSeoGeoReadinessAuditor
{
    /**
     * @param  list<mixed>  $planRows
     * @param  list<string>  $locales
     * @param  array<string, mixed>|list<array<string, mixed>>  $seoGeo
     */
    public function audit(array $planRows, array $locales, array $seoGeo): CareerSeoGeoReadinessResult
    {
        $expectedSlugs = $this->expectedSlugs($planRows);
        $expectedLocales = $this->expectedLocales($locales);
        $rowsByKey = $this->rowsBySlugLocale($this->artifactRows($seoGeo));

        $rows = [];
        foreach ($expectedSlugs as $slug) {
            foreach ($expectedLocales as $locale) {
                $artifactRow = $rowsByKey[$this->rowKey($slug, $locale)] ?? [];
                $rows[] = $this->auditExpectedRow($slug, $locale, $artifactRow);
            }
        }

        return CareerSeoGeoReadinessResult::build($rows);
    }

    public function auditPlan(CareerPublicResolutionPlan $plan, array $locales, array $seoGeo): CareerSeoGeoReadinessResult
    {
        return $this->audit($plan->rows, $locales, $seoGeo);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  array<string, mixed>|list<array<string, mixed>>  $seoGeo
     */
    public function auditSlugs(array $slugs, array $locales, array $seoGeo): CareerSeoGeoReadinessResult
    {
        return $this->audit($slugs, $locales, $seoGeo);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function auditExpectedRow(string $slug, string $locale, array $row): CareerSeoGeoReadinessRow
    {
        $canonicalPath = $this->canonicalPath($row);
        $canonicalPathSelf = $canonicalPath !== null && $canonicalPath === $this->expectedCanonicalPath($slug, $locale);
        $canonicalSelf = ($this->boolValue($row, ['canonical_self']) ?? $canonicalPathSelf) && $canonicalPathSelf;
        $robotsPolicy = $this->normalizeString($row['robots_policy'] ?? $row['robots'] ?? null);
        $robotsIndexable = $this->boolValue($row, ['robots_indexable', 'index_eligible'])
            ?? ($robotsPolicy !== null && ! str_contains(strtolower($robotsPolicy), 'noindex'));
        $sitemapEligible = $this->boolValue($row, ['sitemap_eligible', 'sitemap_live', 'sitemap']);
        $llmsEligible = $this->boolValue($row, ['llms_eligible', 'llms_live', 'llms']);
        $llmsFullEligible = $this->boolValue($row, ['llms_full_eligible', 'llms_full_live', 'llms_full']);
        $structuredDataReady = $this->boolValue($row, ['structured_data_ready', 'structured_metadata_ready'])
            ?? $this->nonEmptyArrayValue($row, ['structured_data', 'structured_data_json', 'jsonld']);
        $datasetEligible = $this->boolValue($row, ['dataset_eligible', 'dataset_visible', 'dataset']);
        $searchEligible = $this->boolValue($row, ['search_eligible', 'search_visible', 'search']);
        $citationMetadataReady = $this->boolValue($row, ['citation_metadata_ready', 'ai_citation_metadata_ready'])
            ?? $this->nonEmptyArrayValue($row, ['citation_metadata', 'ai_citation_metadata']);

        $issues = $this->issuesFor(
            slug: $slug,
            locale: $locale,
            canonicalPath: $canonicalPath,
            canonicalSelf: $canonicalSelf,
            robotsPolicy: $robotsPolicy,
            robotsIndexable: $robotsIndexable,
            sitemapEligible: $sitemapEligible,
            llmsEligible: $llmsEligible,
            llmsFullEligible: $llmsFullEligible,
            structuredDataReady: $structuredDataReady,
            datasetEligible: $datasetEligible,
            searchEligible: $searchEligible,
            citationMetadataReady: $citationMetadataReady,
        );
        $evidence = $this->evidenceFor(
            slug: $slug,
            locale: $locale,
            canonicalPath: $canonicalPath,
            canonicalSelf: $canonicalSelf,
            robotsPolicy: $robotsPolicy,
            robotsIndexable: $robotsIndexable,
            sitemapEligible: $sitemapEligible,
            llmsEligible: $llmsEligible,
            llmsFullEligible: $llmsFullEligible,
            structuredDataReady: $structuredDataReady,
            datasetEligible: $datasetEligible,
            searchEligible: $searchEligible,
            citationMetadataReady: $citationMetadataReady,
        );

        return new CareerSeoGeoReadinessRow(
            canonicalSlug: $slug,
            locale: $locale,
            canonicalPath: $canonicalPath,
            canonicalSelf: $canonicalSelf,
            robotsPolicy: $robotsPolicy,
            robotsIndexable: $robotsIndexable,
            sitemapEligible: $sitemapEligible ?? false,
            llmsEligible: $llmsEligible ?? false,
            llmsFullEligible: $llmsFullEligible ?? false,
            structuredDataReady: $structuredDataReady ?? false,
            datasetEligible: $datasetEligible ?? false,
            searchEligible: $searchEligible ?? false,
            citationMetadataReady: $citationMetadataReady ?? false,
            seoGeoStatus: $this->seoGeoLayerStatus($issues, $evidence),
            evidence: $evidence,
            issues: $issues,
        );
    }

    /**
     * @return list<CareerSeoGeoReadinessIssue>
     */
    private function issuesFor(
        string $slug,
        string $locale,
        ?string $canonicalPath,
        bool $canonicalSelf,
        ?string $robotsPolicy,
        bool $robotsIndexable,
        ?bool $sitemapEligible,
        ?bool $llmsEligible,
        ?bool $llmsFullEligible,
        ?bool $structuredDataReady,
        ?bool $datasetEligible,
        ?bool $searchEligible,
        ?bool $citationMetadataReady,
    ): array {
        $issues = [];
        if (! $canonicalSelf) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::CANONICAL_NOT_SELF, 'Canonical path is missing or does not match the expected self path.', ['canonical_path' => $canonicalPath]);
        }
        if (! $robotsIndexable) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::ROBOTS_NOINDEX, 'Robots policy or indexability indicates noindex.', ['robots_policy' => $robotsPolicy, 'robots_indexable' => $robotsIndexable]);
        }
        if ($sitemapEligible !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::SITEMAP_MISSING, 'Sitemap eligibility is missing or false.', ['sitemap_eligible' => $sitemapEligible]);
        }
        if ($llmsEligible !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::LLMS_MISSING, 'LLMS eligibility is missing or false.', ['llms_eligible' => $llmsEligible]);
        }
        if ($llmsFullEligible !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::LLMS_FULL_MISSING, 'LLMS-full eligibility is missing or false.', ['llms_full_eligible' => $llmsFullEligible]);
        }
        if ($structuredDataReady !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::STRUCTURED_DATA_MISSING, 'Structured metadata readiness is missing or false.', ['structured_data_ready' => $structuredDataReady]);
        }
        if ($datasetEligible !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::DATASET_MISSING, 'Dataset eligibility is missing or false.', ['dataset_eligible' => $datasetEligible]);
        }
        if ($searchEligible !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::SEARCH_MISSING, 'Search eligibility is missing or false.', ['search_eligible' => $searchEligible]);
        }
        if ($citationMetadataReady !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSeoGeoReadinessIssue::CITATION_METADATA_MISSING, 'AI citation metadata readiness is missing or false.', ['citation_metadata_ready' => $citationMetadataReady]);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function issue(string $slug, string $locale, string $reason, string $message, array $evidence): CareerSeoGeoReadinessIssue
    {
        return new CareerSeoGeoReadinessIssue(
            reason: $reason,
            message: $message,
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            canonicalSlug: $slug,
            locale: $locale,
            evidence: [$evidence],
        );
    }

    /**
     * @param  list<CareerSeoGeoReadinessIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    private function seoGeoLayerStatus(array $issues, array $evidence): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::SEO_GEO,
            status: $issues === [] ? CareerCanonicalEligibilityStatus::PASS : CareerCanonicalEligibilityStatus::BLOCKED,
            reasons: array_values(array_unique(array_map(
                static fn (CareerSeoGeoReadinessIssue $issue): string => $issue->reason,
                $issues
            ))),
            evidence: $evidence,
            source: 'seo_geo_artifacts',
        );
    }

    /**
     * @return list<mixed>
     */
    private function evidenceFor(
        string $slug,
        string $locale,
        ?string $canonicalPath,
        bool $canonicalSelf,
        ?string $robotsPolicy,
        bool $robotsIndexable,
        ?bool $sitemapEligible,
        ?bool $llmsEligible,
        ?bool $llmsFullEligible,
        ?bool $structuredDataReady,
        ?bool $datasetEligible,
        ?bool $searchEligible,
        ?bool $citationMetadataReady,
    ): array {
        return [
            ['slug' => $slug, 'locale' => $locale],
            ['canonical_path' => $canonicalPath, 'canonical_self' => $canonicalSelf],
            ['robots_policy' => $robotsPolicy, 'robots_indexable' => $robotsIndexable],
            ['sitemap_eligible' => $sitemapEligible],
            ['llms_eligible' => $llmsEligible],
            ['llms_full_eligible' => $llmsFullEligible],
            ['structured_data_ready' => $structuredDataReady],
            ['dataset_eligible' => $datasetEligible],
            ['search_eligible' => $searchEligible],
            ['citation_metadata_ready' => $citationMetadataReady],
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedSlugs(array $planRows): array
    {
        $slugs = [];
        foreach ($planRows as $row) {
            $slug = $this->slugForPlanRow($row);
            if ($slug !== null && ! in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function expectedLocales(array $locales): array
    {
        $normalized = [];
        foreach ($locales as $locale) {
            $value = $this->normalizeString($locale);
            if ($value !== null) {
                $value = strtolower($value);
                if (! in_array($value, $normalized, true)) {
                    $normalized[] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $artifact
     * @return list<array<string, mixed>>
     */
    private function artifactRows(array $artifact): array
    {
        $candidates = [
            $artifact,
            $artifact['items'] ?? null,
            $artifact['rows'] ?? null,
            $artifact['seo_geo']['items'] ?? null,
            $artifact['seo_geo']['rows'] ?? null,
            $artifact['projection']['items'] ?? null,
            $artifact['truth']['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, static fn (mixed $row): bool => is_array($row)));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function rowsBySlugLocale(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $slug = $this->slugForArrayRow($row);
            $locale = $this->normalizeString($row['locale'] ?? null);
            if ($slug === null || $locale === null) {
                continue;
            }

            $indexed[$this->rowKey($slug, strtolower($locale))] = $row;
        }

        return $indexed;
    }

    private function slugForPlanRow(mixed $row): ?string
    {
        if ($row instanceof CareerPublicResolutionPlanRow) {
            return $this->normalizeSlug($row->canonicalSlug);
        }

        if (is_string($row)) {
            return $this->normalizeSlug($row);
        }

        if (is_array($row)) {
            return $this->slugForArrayRow($row);
        }

        if (is_object($row) && property_exists($row, 'canonicalSlug')) {
            return $this->normalizeSlug($row->canonicalSlug);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function slugForArrayRow(array $row): ?string
    {
        return $this->normalizeSlug($row['canonical_slug'] ?? $row['source_slug'] ?? $row['slug'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function boolValue(array $row, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value)) {
                return $value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'present', 'ready', 'live', 'eligible', 'index,follow'], true)) {
                    return true;
                }
                if (in_array($normalized, ['0', 'false', 'no', 'missing', 'not_ready', 'noindex,follow'], true)) {
                    return false;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function nonEmptyArrayValue(array $row, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return is_array($row[$key]) && $row[$key] !== [];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function canonicalPath(array $row): ?string
    {
        $path = $this->normalizeString($row['canonical_path'] ?? null);
        if ($path !== null) {
            return $path;
        }

        $url = $this->normalizeString($row['canonical_url'] ?? null);
        if ($url === null) {
            return null;
        }

        $parsedPath = parse_url($url, PHP_URL_PATH);

        return is_string($parsedPath) && trim($parsedPath) !== '' ? $parsedPath : null;
    }

    private function expectedCanonicalPath(string $slug, string $locale): string
    {
        return '/'.$locale.'/career/jobs/'.$slug;
    }

    private function rowKey(string $slug, string $locale): string
    {
        return $slug.'|'.$locale;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return $normalized === null ? null : strtolower($normalized);
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
