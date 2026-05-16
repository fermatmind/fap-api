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

    /**
     * @return list<array<string, mixed>>
     */
    public function publicDatasetItems(): array
    {
        return $this->visibleItems(static fn (array $item): bool => ($item['dataset_visible'] ?? false) === true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicDetailItems(): array
    {
        return $this->visibleItems(static fn (array $item): bool => ($item['detail_route_enabled'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true);
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

    public function robotsIndexable(string $slug): bool
    {
        return ($this->itemForSlug($slug)['robots_indexable'] ?? false) === true;
    }

    public function releaseGatePass(string $slug): bool
    {
        return ($this->itemForSlug($slug)['release_gate_pass'] ?? false) === true;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function visibleItems(callable $filter): array
    {
        $items = [];

        foreach ($this->itemsBySlug() as $item) {
            if (! $filter($item)) {
                continue;
            }

            $items[] = $item;
        }

        usort($items, static fn (array $left, array $right): int => strcmp(
            strtolower((string) ($left['slug'] ?? '')),
            strtolower((string) ($right['slug'] ?? '')),
        ));

        return $items;
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
        return $this->latestMaterializedJsonPayload(
            storage_path('app/private/career_runtime_publish_projection'),
            CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMaterializedFullReleaseLedger(): ?array
    {
        return $this->latestMaterializedJsonPayload(
            storage_path('app/private/career_release_ledger'),
            CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestMaterializedJsonPayload(string $root, string $filename): ?array
    {
        if (! is_dir($root)) {
            return null;
        }

        $directories = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        if (! is_array($directories) || $directories === []) {
            return null;
        }

        $candidates = [];
        foreach ($directories as $directory) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);
            if (! is_array($payload)) {
                continue;
            }

            clearstatcache(true, $path);
            $candidates[] = [
                'path' => $path,
                'mtime' => filemtime($path) ?: 0,
                'payload' => $payload,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            $mtimeComparison = $right['mtime'] <=> $left['mtime'];
            if ($mtimeComparison !== 0) {
                return $mtimeComparison;
            }

            return strcmp((string) $right['path'], (string) $left['path']);
        });

        return $candidates[0]['payload'];
    }
}
