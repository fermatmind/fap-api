<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerRuntimePublishProjectionDTO
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        public readonly string $publicResolutionType,
        public readonly string $runtimePublishState,
        public readonly bool|string $detailRouteEnabled,
        public readonly bool $datasetVisible,
        public readonly bool $searchVisible,
        public readonly bool $sitemapLive,
        public readonly bool $llmsLive,
        public readonly bool $llmsFullLive,
        public readonly ?string $canonicalUrl,
        public readonly bool $canonicalSelf,
        public readonly bool $robotsIndexable,
        public readonly bool $releaseGatePass,
        public readonly array $blockers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'locale' => $this->locale,
            'public_resolution_type' => $this->publicResolutionType,
            'runtime_publish_state' => $this->runtimePublishState,
            'detail_route_enabled' => $this->detailRouteEnabled,
            'dataset_visible' => $this->datasetVisible,
            'search_visible' => $this->searchVisible,
            'sitemap_live' => $this->sitemapLive,
            'llms_live' => $this->llmsLive,
            'llms_full_live' => $this->llmsFullLive,
            'canonical_url' => $this->canonicalUrl,
            'canonical_self' => $this->canonicalSelf,
            'robots_indexable' => $this->robotsIndexable,
            'release_gate_pass' => $this->releaseGatePass,
            'blockers' => $this->blockers,
        ];
    }
}
