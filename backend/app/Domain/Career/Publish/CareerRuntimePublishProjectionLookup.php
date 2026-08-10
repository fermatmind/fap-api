<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use Throwable;

final class CareerRuntimePublishProjectionLookup implements CareerRuntimePublishProjectionCoverageSnapshot, CareerRuntimePublishProjectionVisibility
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

        return $itemsBySlugLocale[$slug.'|'.$locale] ?? null;
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

    /**
     * @param  list<string>  $locales
     * @return array<string, array<string, mixed>>
     */
    public function jobDetailCoverageItems(array $locales): array
    {
        $normalizedLocales = array_values(array_unique(array_map(
            fn (string $locale): string => $this->normalizeLocale($locale),
            $locales,
        )));
        $this->hydrate();
        $items = [];

        foreach ($this->itemsBySlugLocale ?? [] as $item) {
            $slug = $this->normalizeSlug((string) ($item['slug'] ?? ''));
            $locale = $this->normalizeLocale((string) ($item['locale'] ?? 'en'));
            if ($slug === null || ! in_array($locale, $normalizedLocales, true)) {
                continue;
            }

            $publicLocale = $locale === 'zh' ? 'zh-CN' : 'en';
            $items[$slug.'|'.$publicLocale] = $item;
        }

        ksort($items, SORT_STRING);

        return $items;
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
        $this->hydrate();

        return $this->itemsBySlugLocale ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsBySlug(): array
    {
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

        if ($projection === null && app()->runningUnitTests()) {
            $projection = $this->projectionFromTestingDatabaseFixtures();
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

    /**
     * @return array<string, mixed>|null
     */
    private function projectionFromTestingDatabaseFixtures(): ?array
    {
        try {
            $items = array_merge(
                $this->testingCompiledOccupationItems(),
                $this->testingDirectoryDraftItems(),
                $this->testingFamilyHubItems(),
            );
        } catch (Throwable) {
            return null;
        }

        if ($items === []) {
            return null;
        }

        return [
            'projection_kind' => CareerRuntimePublishProjectionService::PROJECTION_KIND,
            'projection_version' => CareerRuntimePublishProjectionService::PROJECTION_VERSION,
            'source_authority' => 'testing_database_fixture_fallback',
            'items' => $items,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function testingCompiledOccupationItems(): array
    {
        $snapshots = RecommendationSnapshot::query()
            ->with(['occupation', 'indexState', 'contextSnapshot', 'profileProjection'])
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('occupation_id')
            ->map(static fn ($group): ?RecommendationSnapshot => $group->first())
            ->filter(static fn (mixed $snapshot): bool => $snapshot instanceof RecommendationSnapshot)
            ->values();

        $items = [];
        foreach ($snapshots as $snapshot) {
            $occupation = $snapshot->occupation;
            if (! $occupation instanceof Occupation) {
                continue;
            }

            $slug = $this->normalizeSlug((string) $occupation->canonical_slug);
            if ($slug === null) {
                continue;
            }

            $indexEligible = (bool) ($snapshot->indexState?->index_eligible ?? false);
            foreach (CareerRuntimePublishProjectionService::LOCALES as $locale) {
                $items[] = $this->testingProjectionItem(
                    slug: $slug,
                    locale: $locale,
                    publicResolutionType: CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
                    detailRouteEnabled: true,
                    datasetVisible: $indexEligible,
                    searchVisible: true,
                    robotsIndexable: $indexEligible,
                    releaseGatePass: $indexEligible,
                );
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function testingDirectoryDraftItems(): array
    {
        $occupations = Occupation::query()
            ->where('crosswalk_mode', 'directory_draft')
            ->orderBy('canonical_slug')
            ->get();

        $items = [];
        foreach ($occupations as $occupation) {
            $slug = $this->normalizeSlug((string) $occupation->canonical_slug);
            if ($slug === null) {
                continue;
            }

            foreach (CareerRuntimePublishProjectionService::LOCALES as $locale) {
                $items[] = $this->testingProjectionItem(
                    slug: $slug,
                    locale: $locale,
                    publicResolutionType: CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    detailRouteEnabled: false,
                    datasetVisible: true,
                    searchVisible: true,
                    robotsIndexable: false,
                    releaseGatePass: false,
                    blockers: ['testing_directory_draft_detail_unavailable'],
                );
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function testingFamilyHubItems(): array
    {
        $families = OccupationFamily::query()
            ->orderBy('canonical_slug')
            ->get();

        $items = [];
        foreach ($families as $family) {
            $slug = $this->normalizeSlug((string) $family->canonical_slug);
            if ($slug === null) {
                continue;
            }

            foreach (CareerRuntimePublishProjectionService::LOCALES as $locale) {
                $items[] = $this->testingProjectionItem(
                    slug: $slug,
                    locale: $locale,
                    publicResolutionType: CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB,
                    detailRouteEnabled: false,
                    datasetVisible: false,
                    searchVisible: false,
                    robotsIndexable: true,
                    releaseGatePass: true,
                );
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string, mixed>
     */
    private function testingProjectionItem(
        string $slug,
        string $locale,
        string $publicResolutionType,
        bool $detailRouteEnabled,
        bool $datasetVisible,
        bool $searchVisible,
        bool $robotsIndexable,
        bool $releaseGatePass,
        array $blockers = [],
    ): array {
        $published = $publicResolutionType === CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB
            || $detailRouteEnabled
            || $searchVisible
            || $datasetVisible;

        return [
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => $publicResolutionType,
            'runtime_publish_state' => $published
                ? CareerRuntimePublishProjectionService::STATE_PUBLISHED
                : CareerRuntimePublishProjectionService::STATE_BLOCKED,
            'detail_route_enabled' => $detailRouteEnabled,
            'dataset_visible' => $datasetVisible,
            'search_visible' => $searchVisible,
            'sitemap_live' => $robotsIndexable,
            'llms_live' => $robotsIndexable,
            'llms_full_live' => $robotsIndexable,
            'canonical_url' => $detailRouteEnabled
                ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug
                : null,
            'canonical_self' => $detailRouteEnabled,
            'robots_indexable' => $robotsIndexable,
            'release_gate_pass' => $releaseGatePass,
            'blockers' => $blockers,
            'projection_source' => 'testing_database_fixture_fallback',
        ];
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
            clearstatcache(true, $directory);
            clearstatcache(true, $path);
            $directoryReadable = is_readable($directory);
            $fileReadable = $directoryReadable && is_file($path) && is_readable($path);
            $payload = $fileReadable
                ? json_decode((string) file_get_contents($path), true)
                : null;
            $candidates[] = [
                'path' => $path,
                'mtime' => $fileReadable
                    ? (@filemtime($path) ?: 0)
                    : (@filemtime($directory) ?: 0),
                'payload' => is_array($payload) ? $payload : null,
                'valid' => is_array($payload),
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

        // A finalized authority directory that is newer but unreadable or
        // invalid must never make runtime silently select an older artifact.
        // The empty-array sentinel keeps hydrate() fail-closed and prevents a
        // fallback to a potentially stale release ledger.
        return $candidates[0]['valid'] ? $candidates[0]['payload'] : [];
    }
}
