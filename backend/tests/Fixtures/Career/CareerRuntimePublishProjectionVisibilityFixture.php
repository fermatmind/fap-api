<?php

declare(strict_types=1);

namespace Tests\Fixtures\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;

final class CareerRuntimePublishProjectionVisibilityFixture implements CareerRuntimePublishProjectionVisibility
{
    /**
     * @param  array<string, bool>  $datasetVisible
     * @param  array<string, bool>  $searchVisible
     * @param  array<string, bool>  $detailRouteEnabled
     * @param  array<string, bool>  $robotsIndexable
     * @param  array<string, bool>  $releaseGatePass
     * @param  array<string, bool>  $familyHubLive
     * @param  array<string, array<string, mixed>>  $items
     */
    public function __construct(
        private readonly bool $defaultDatasetVisible = true,
        private readonly bool $defaultSearchVisible = true,
        private readonly bool $defaultDetailRouteEnabled = true,
        private readonly bool $defaultRobotsIndexable = true,
        private readonly bool $defaultReleaseGatePass = true,
        private readonly bool $defaultFamilyHubLive = true,
        private readonly array $datasetVisible = [],
        private readonly array $searchVisible = [],
        private readonly array $detailRouteEnabled = [],
        private readonly array $robotsIndexable = [],
        private readonly array $releaseGatePass = [],
        private readonly array $familyHubLive = [],
        private readonly array $items = [],
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function itemForSlug(string $slug, string $locale = 'en'): ?array
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizeLocale($locale);

        return $this->items[$normalizedSlug.'|'.$normalizedLocale]
            ?? $this->items[$normalizedSlug.'|en']
            ?? $this->items[$normalizedSlug]
            ?? null;
    }

    public function datasetVisible(string $slug): bool
    {
        return $this->datasetVisible[$this->normalizeSlug($slug)] ?? $this->defaultDatasetVisible;
    }

    public function searchVisible(string $slug): bool
    {
        return $this->searchVisible[$this->normalizeSlug($slug)] ?? $this->defaultSearchVisible;
    }

    public function detailRouteEnabled(string $slug): bool
    {
        return $this->detailRouteEnabled[$this->normalizeSlug($slug)] ?? $this->defaultDetailRouteEnabled;
    }

    public function robotsIndexable(string $slug): bool
    {
        return $this->robotsIndexable[$this->normalizeSlug($slug)] ?? $this->defaultRobotsIndexable;
    }

    public function releaseGatePass(string $slug): bool
    {
        return $this->releaseGatePass[$this->normalizeSlug($slug)] ?? $this->defaultReleaseGatePass;
    }

    public function familyHubLive(string $slug): bool
    {
        return $this->familyHubLive[$this->normalizeSlug($slug)] ?? $this->defaultFamilyHubLive;
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }
}
