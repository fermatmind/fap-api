<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use Illuminate\Contracts\Container\Container;

final class CareerRuntimePublishProjectionLookup implements CareerRuntimePublishProjectionVisibility
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $itemsBySlugLocale = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $itemsBySlug = null;

    public function __construct(
        private readonly Container $container,
        private readonly CareerRuntimePublishProjectionService $projectionService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function itemForSlug(string $slug, string $locale = 'en'): ?array
    {
        $slug = $this->normalizeSlug($slug);
        $locale = $this->normalizeLocale($locale);
        if ($slug === null) {
            return null;
        }

        $itemsBySlugLocale = $this->itemsBySlugLocale();

        return $itemsBySlugLocale[$slug.'|'.$locale]
            ?? $itemsBySlugLocale[$slug.'|en']
            ?? $this->itemsBySlug()[$slug]
            ?? null;
    }

    public function datasetVisible(string $slug): bool
    {
        return (bool) ($this->itemForSlug($slug)['dataset_visible'] ?? false);
    }

    public function searchVisible(string $slug): bool
    {
        return (bool) ($this->itemForSlug($slug)['search_visible'] ?? false);
    }

    public function detailRouteEnabled(string $slug): bool
    {
        return ($this->itemForSlug($slug)['detail_route_enabled'] ?? false) === true;
    }

    public function familyHubLive(string $slug): bool
    {
        $item = $this->itemForSlug($slug);

        return is_array($item)
            && ($item['public_resolution_type'] ?? null) === CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB
            && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsBySlugLocale(): array
    {
        if ($this->itemsBySlugLocale !== null) {
            return $this->itemsBySlugLocale;
        }

        $this->hydrate();

        return $this->itemsBySlugLocale ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsBySlug(): array
    {
        if ($this->itemsBySlug !== null) {
            return $this->itemsBySlug;
        }

        $this->hydrate();

        return $this->itemsBySlug ?? [];
    }

    private function hydrate(): void
    {
        $this->itemsBySlugLocale = [];
        $this->itemsBySlug = [];

        $projection = $this->latestMaterializedProjection();
        if ($projection === null) {
            $ledger = $this->latestMaterializedFullReleaseLedger();
            if ($ledger !== null) {
                $projection = $this->projectionService->buildFromLedgerArray($ledger);
            }
        }

        if ($projection === null) {
            try {
                $projection = $this->container
                    ->make(CareerRuntimePublishProjectionExporter::class)
                    ->build();
            } catch (\Throwable) {
                return;
            }
        }

        if ($projection === []) {
            return;
        }

        $items = $projection['items'] ?? [];
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = $this->normalizeSlug((string) ($item['slug'] ?? ''));
            if ($slug === null) {
                continue;
            }

            $locale = $this->normalizeLocale((string) ($item['locale'] ?? 'en'));
            $this->itemsBySlugLocale[$slug.'|'.$locale] = $item;
            $this->itemsBySlug[$slug] ??= $item;
        }
    }

    private function normalizeSlug(string $slug): ?string
    {
        $normalized = strtolower(trim($slug));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        return str_starts_with($normalized, 'zh') ? 'zh' : 'en';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMaterializedProjection(): ?array
    {
        $root = storage_path('app/private/career_runtime_publish_projection');
        if (! is_dir($root)) {
            return null;
        }

        $directories = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        if (! is_array($directories) || $directories === []) {
            return null;
        }

        rsort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $path = $directory.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);

            return is_array($payload) ? $payload : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMaterializedFullReleaseLedger(): ?array
    {
        $root = storage_path('app/private/career_release_ledger');
        if (! is_dir($root)) {
            return null;
        }

        $directories = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        if (! is_array($directories) || $directories === []) {
            return null;
        }

        rsort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $path = $directory.DIRECTORY_SEPARATOR.CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME;
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);

            return is_array($payload) ? $payload : null;
        }

        return null;
    }
}
