<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

final class CareerSurfaceReadinessAuditor
{
    /**
     * @param  list<mixed>  $planRows
     * @param  list<string>  $locales
     * @param  array<string, mixed>|list<array<string, mixed>>  $apiArtifact
     * @param  array<string, string>|null  $liveHtmlByKey
     */
    public function audit(
        array $planRows,
        array $locales,
        array $apiArtifact,
        bool $includeLiveHtml = false,
        ?string $baseUrl = null,
        ?array $liveHtmlByKey = null,
    ): CareerSurfaceReadinessResult {
        $apiRows = $this->rowsBySlugLocale($this->artifactRows($apiArtifact));
        $rows = [];

        foreach ($this->expectedSlugs($planRows) as $slug) {
            foreach ($this->expectedLocales($locales) as $locale) {
                $key = $this->rowKey($slug, $locale);
                $rows[] = $this->auditExpectedRow(
                    slug: $slug,
                    locale: $locale,
                    apiRow: $apiRows[$key] ?? [],
                    includeLiveHtml: $includeLiveHtml,
                    baseUrl: $baseUrl,
                    liveHtml: $liveHtmlByKey[$key] ?? null,
                );
            }
        }

        return CareerSurfaceReadinessResult::build($rows);
    }

    public function auditPlan(
        CareerPublicResolutionPlan $plan,
        array $locales,
        array $apiArtifact,
        bool $includeLiveHtml = false,
        ?string $baseUrl = null,
        ?array $liveHtmlByKey = null,
    ): CareerSurfaceReadinessResult {
        return $this->audit($plan->rows, $locales, $apiArtifact, $includeLiveHtml, $baseUrl, $liveHtmlByKey);
    }

    /**
     * @param  list<string>  $slugs
     */
    public function auditSlugs(
        array $slugs,
        array $locales,
        array $apiArtifact,
        bool $includeLiveHtml = false,
        ?string $baseUrl = null,
        ?array $liveHtmlByKey = null,
    ): CareerSurfaceReadinessResult {
        return $this->audit($slugs, $locales, $apiArtifact, $includeLiveHtml, $baseUrl, $liveHtmlByKey);
    }

    /**
     * @param  array<string, mixed>  $apiRow
     */
    private function auditExpectedRow(
        string $slug,
        string $locale,
        array $apiRow,
        bool $includeLiveHtml,
        ?string $baseUrl,
        ?string $liveHtml,
    ): CareerSurfaceReadinessRow {
        $expectedPath = $this->expectedCanonicalPath($slug, $locale);
        $apiCanonicalPath = $this->canonicalPath($apiRow);
        $apiIndexable = $this->apiIndexable($apiRow);
        $live = $includeLiveHtml && $baseUrl !== null && $liveHtml !== null
            ? $this->parseLiveHtml($liveHtml)
            : null;
        $liveCanonicalPath = $live['canonical_path'] ?? null;
        $liveRobotsPolicy = $live['robots_policy'] ?? null;
        $ctaPresent = $live['cta_present'] ?? null;
        $issues = $this->issuesFor(
            slug: $slug,
            locale: $locale,
            expectedPath: $expectedPath,
            apiCanonicalPath: $apiCanonicalPath,
            apiIndexable: $apiIndexable,
            includeLiveHtml: $includeLiveHtml,
            baseUrl: $baseUrl,
            liveHtml: $liveHtml,
            liveCanonicalPath: $liveCanonicalPath,
            liveRobotsPolicy: $liveRobotsPolicy,
            ctaPresent: $ctaPresent,
        );
        $status = $this->surfaceLayerStatus(
            issues: $issues,
            evidence: $this->evidenceFor($slug, $locale, $apiCanonicalPath, $apiIndexable, $includeLiveHtml, $liveCanonicalPath, $liveRobotsPolicy, $ctaPresent),
            unverified: $includeLiveHtml && ($baseUrl === null || $liveHtml === null),
        );

        return new CareerSurfaceReadinessRow(
            canonicalSlug: $slug,
            locale: $locale,
            apiCanonicalPath: $apiCanonicalPath,
            apiIndexable: $apiIndexable,
            liveHtmlRequested: $includeLiveHtml,
            liveHtmlVerified: $live !== null,
            liveCanonicalPath: $liveCanonicalPath,
            liveRobotsPolicy: $liveRobotsPolicy,
            ctaPresent: $ctaPresent,
            surfaceStatus: $status,
            evidence: $status->evidence,
            issues: $issues,
        );
    }

    /**
     * @return list<CareerSurfaceReadinessIssue>
     */
    private function issuesFor(
        string $slug,
        string $locale,
        string $expectedPath,
        ?string $apiCanonicalPath,
        bool $apiIndexable,
        bool $includeLiveHtml,
        ?string $baseUrl,
        ?string $liveHtml,
        ?string $liveCanonicalPath,
        ?string $liveRobotsPolicy,
        ?bool $ctaPresent,
    ): array {
        $issues = [];
        if ($apiCanonicalPath !== $expectedPath) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::API_CANONICAL_NOT_SELF, 'API canonical path is missing or does not match expected self path.', ['api_canonical_path' => $apiCanonicalPath, 'expected_path' => $expectedPath]);
        }
        if (! $apiIndexable) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::API_NOINDEX_PRESENT, 'API surface reports noindex or non-indexable state.', ['api_indexable' => false]);
        }

        if (! $includeLiveHtml) {
            return $issues;
        }

        if ($baseUrl === null) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::VALIDATOR_CONTEXT_MISSING, 'Live HTML validation was requested without a base URL.', ['base_url' => null]);

            return $issues;
        }
        if ($liveHtml === null) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::SURFACE_VERIFIER_MISSING, 'Live HTML validation was requested but no verifier HTML was supplied.', ['base_url' => $baseUrl]);

            return $issues;
        }

        if ($liveCanonicalPath !== $expectedPath) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::LIVE_CANONICAL_NOT_SELF, 'Live canonical path is missing or does not match expected self path.', ['live_canonical_path' => $liveCanonicalPath, 'expected_path' => $expectedPath]);
        }
        if ($liveRobotsPolicy !== null && str_contains(strtolower($liveRobotsPolicy), 'noindex')) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::LIVE_NOINDEX_PRESENT, 'Live HTML contains a noindex robots directive.', ['live_robots_policy' => $liveRobotsPolicy]);
        }
        if ($ctaPresent !== true) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::CTA_MISSING_OR_UNATTRIBUTED, 'Live HTML is missing an attributable career CTA marker.', ['cta_present' => $ctaPresent]);
        }
        if ($apiCanonicalPath !== null && $liveCanonicalPath !== null && $apiCanonicalPath !== $liveCanonicalPath) {
            $issues[] = $this->issue($slug, $locale, CareerSurfaceReadinessIssue::REAL_SURFACE_MISMATCH, 'API and live HTML canonical surfaces disagree.', ['api_canonical_path' => $apiCanonicalPath, 'live_canonical_path' => $liveCanonicalPath]);
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function issue(string $slug, string $locale, string $reason, string $message, array $evidence): CareerSurfaceReadinessIssue
    {
        return new CareerSurfaceReadinessIssue(
            reason: $reason,
            message: $message,
            severity: CareerCanonicalEligibilitySeverity::HIGH,
            canonicalSlug: $slug,
            locale: $locale,
            evidence: [$evidence],
        );
    }

    /**
     * @param  list<CareerSurfaceReadinessIssue>  $issues
     * @param  list<mixed>  $evidence
     */
    private function surfaceLayerStatus(array $issues, array $evidence, bool $unverified): CareerCanonicalEligibilityLayerStatus
    {
        return new CareerCanonicalEligibilityLayerStatus(
            layer: CareerCanonicalEligibilityLayer::SURFACE,
            status: $issues === [] ? CareerCanonicalEligibilityStatus::PASS : ($unverified ? CareerCanonicalEligibilityStatus::UNVERIFIED : CareerCanonicalEligibilityStatus::BLOCKED),
            reasons: array_values(array_unique(array_map(
                static fn (CareerSurfaceReadinessIssue $issue): string => $issue->reason,
                $issues
            ))),
            evidence: $evidence,
            source: 'surface_artifacts',
        );
    }

    /**
     * @return list<mixed>
     */
    private function evidenceFor(string $slug, string $locale, ?string $apiCanonicalPath, bool $apiIndexable, bool $includeLiveHtml, ?string $liveCanonicalPath, ?string $liveRobotsPolicy, ?bool $ctaPresent): array
    {
        return [
            ['slug' => $slug, 'locale' => $locale],
            ['api_canonical_path' => $apiCanonicalPath, 'api_indexable' => $apiIndexable],
            ['live_html_requested' => $includeLiveHtml],
            ['live_canonical_path' => $liveCanonicalPath],
            ['live_robots_policy' => $liveRobotsPolicy],
            ['cta_present' => $ctaPresent],
        ];
    }

    /**
     * @return array{canonical_path: string|null, robots_policy: string|null, cta_present: bool}
     */
    private function parseLiveHtml(string $html): array
    {
        $canonical = null;
        if (preg_match("~<link[^>]+rel=[\"']canonical[\"'][^>]+href=[\"']([^\"']+)[\"']~i", $html, $matches) === 1
            || preg_match("~<link[^>]+href=[\"']([^\"']+)[\"'][^>]+rel=[\"']canonical[\"']~i", $html, $matches) === 1) {
            $canonical = $this->pathFromUrl($matches[1]);
        }

        $robots = null;
        if (preg_match("~<meta[^>]+name=[\"']robots[\"'][^>]+content=[\"']([^\"']+)[\"']~i", $html, $matches) === 1
            || preg_match("~<meta[^>]+content=[\"']([^\"']+)[\"'][^>]+name=[\"']robots[\"']~i", $html, $matches) === 1) {
            $robots = trim($matches[1]);
        }

        return [
            'canonical_path' => $canonical,
            'robots_policy' => $robots,
            'cta_present' => str_contains($html, 'data-career-cta') || str_contains($html, 'career-cta'),
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $artifact
     * @return list<array<string, mixed>>
     */
    private function artifactRows(array $artifact): array
    {
        $candidates = [$artifact, $artifact['items'] ?? null, $artifact['rows'] ?? null, $artifact['api']['items'] ?? null, $artifact['api']['rows'] ?? null];
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
            if ($slug !== null && $locale !== null) {
                $indexed[$this->rowKey($slug, strtolower($locale))] = $row;
            }
        }

        return $indexed;
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

    private function apiIndexable(array $row): bool
    {
        $indexable = $row['api_indexable'] ?? $row['indexable'] ?? $row['robots_indexable'] ?? null;
        if (is_bool($indexable)) {
            return $indexable;
        }

        $robots = $this->normalizeString($row['api_robots_policy'] ?? $row['robots_policy'] ?? $row['robots'] ?? null);

        return $robots !== null && ! str_contains(strtolower($robots), 'noindex');
    }

    private function canonicalPath(array $row): ?string
    {
        $path = $this->normalizeString($row['api_canonical_path'] ?? $row['canonical_path'] ?? null);
        if ($path !== null) {
            return $path;
        }

        $url = $this->normalizeString($row['api_canonical_url'] ?? $row['canonical_url'] ?? null);

        return $url === null ? null : $this->pathFromUrl($url);
    }

    private function pathFromUrl(string $url): ?string
    {
        $parsedPath = parse_url($url, PHP_URL_PATH);

        return is_string($parsedPath) && trim($parsedPath) !== '' ? $parsedPath : null;
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

    private function slugForArrayRow(array $row): ?string
    {
        return $this->normalizeSlug($row['canonical_slug'] ?? $row['source_slug'] ?? $row['slug'] ?? null);
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
