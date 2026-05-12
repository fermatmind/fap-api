<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerSeoGeoReadinessRow
{
    /**
     * @param  list<mixed>  $evidence
     * @param  list<CareerSeoGeoReadinessIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly ?string $canonicalPath,
        public readonly bool $canonicalSelf,
        public readonly ?string $robotsPolicy,
        public readonly bool $robotsIndexable,
        public readonly bool $sitemapEligible,
        public readonly bool $llmsEligible,
        public readonly bool $llmsFullEligible,
        public readonly bool $structuredDataReady,
        public readonly bool $datasetEligible,
        public readonly bool $searchEligible,
        public readonly bool $citationMetadataReady,
        public readonly CareerCanonicalEligibilityLayerStatus $seoGeoStatus,
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertNonEmptyString($this->locale, 'locale');
        self::assertList($this->evidence, 'evidence');

        foreach (['canonical_path' => $this->canonicalPath, 'robots_policy' => $this->robotsPolicy] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career SEO/GEO readiness row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerSeoGeoReadinessIssue) {
                throw new InvalidArgumentException('Career SEO/GEO readiness row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array{canonical_slug: string, locale: string, canonical_path: string|null, canonical_self: bool, robots_policy: string|null, robots_indexable: bool, sitemap_eligible: bool, llms_eligible: bool, llms_full_eligible: bool, structured_data_ready: bool, dataset_eligible: bool, search_eligible: bool, citation_metadata_ready: bool, seo_geo_status: array<string, mixed>, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'canonical_path' => $this->canonicalPath,
            'canonical_self' => $this->canonicalSelf,
            'robots_policy' => $this->robotsPolicy,
            'robots_indexable' => $this->robotsIndexable,
            'sitemap_eligible' => $this->sitemapEligible,
            'llms_eligible' => $this->llmsEligible,
            'llms_full_eligible' => $this->llmsFullEligible,
            'structured_data_ready' => $this->structuredDataReady,
            'dataset_eligible' => $this->datasetEligible,
            'search_eligible' => $this->searchEligible,
            'citation_metadata_ready' => $this->citationMetadataReady,
            'seo_geo_status' => $this->seoGeoStatus->toArray(),
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerSeoGeoReadinessIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career SEO/GEO readiness row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career SEO/GEO readiness row [%s] must be a list.', $key));
        }
    }
}
