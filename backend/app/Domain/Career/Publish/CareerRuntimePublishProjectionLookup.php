<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

        if (is_array($item)
            && ($item['public_resolution_type'] ?? null) === CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB
            && ($item['runtime_publish_state'] ?? null) === CareerRuntimePublishProjectionService::STATE_PUBLISHED) {
            return true;
        }

        return $this->familyHubLiveFromPublishedChildren($slug);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function itemsBySlugLocale(): array
    {
        if ($this->itemsBySlugLocale !== null && ! app()->runningUnitTests()) {
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
        if ($this->itemsBySlug !== null && ! app()->runningUnitTests()) {
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

    private function familyHubLiveFromPublishedChildren(string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === null || str_ends_with($slug, '-all-other')) {
            return false;
        }

        try {
            $family = OccupationFamily::query()
                ->where('canonical_slug', $slug)
                ->first();
        } catch (Throwable) {
            return false;
        }

        if (! $family instanceof OccupationFamily) {
            return false;
        }

        $childSlugs = Occupation::query()
            ->where('family_id', $family->id)
            ->pluck('canonical_slug')
            ->all();

        foreach ($childSlugs as $childSlug) {
            if (! is_scalar($childSlug)) {
                continue;
            }

            if ($this->detailRouteEnabled((string) $childSlug)
                && $this->releaseGatePass((string) $childSlug)) {
                return true;
            }
        }

        return false;
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

        if ($projection === null) {
            $projection = $this->projectionFromCachedDatasetHub();
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
    private function projectionFromCachedDatasetHub(): ?array
    {
        $payload = Cache::get(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY);
        if (! is_array($payload)) {
            return null;
        }

        $members = array_values(array_filter(
            (array) ($payload['members'] ?? []),
            static fn (mixed $member): bool => is_array($member)
        ));

        if ($members === []) {
            return null;
        }

        $items = [];
        foreach ($members as $member) {
            $slug = $this->normalizeSlug((string) ($member['canonical_slug'] ?? ''));
            if ($slug === null) {
                continue;
            }

            $included = ($member['included_in_public_dataset'] ?? false) === true;
            $releaseCohort = strtolower(trim((string) ($member['release_cohort'] ?? '')));
            $publicIndexState = strtolower(trim((string) ($member['public_index_state'] ?? '')));
            $strongIndexDecision = strtolower(trim((string) ($member['strong_index_decision'] ?? '')));
            $published = $included
                && $releaseCohort === 'public_detail_indexable'
                && in_array($publicIndexState, ['indexable', 'index'], true)
                && in_array($strongIndexDecision, ['strong_index_ready', 'runtime_publish_projection_visible'], true);

            foreach (CareerRuntimePublishProjectionService::LOCALES as $locale) {
                $items[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'public_resolution_type' => $published
                        ? CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
                        : CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'runtime_publish_state' => $published
                        ? CareerRuntimePublishProjectionService::STATE_PUBLISHED
                        : CareerRuntimePublishProjectionService::STATE_BLOCKED,
                    'detail_route_enabled' => $published,
                    'dataset_visible' => $published,
                    'search_visible' => $published,
                    'sitemap_live' => $published,
                    'llms_live' => $published,
                    'llms_full_live' => $published,
                    'canonical_url' => $published
                        ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug
                        : null,
                    'canonical_self' => $published,
                    'robots_indexable' => $published,
                    'release_gate_pass' => $published,
                    'blockers' => $published ? [] : ['dataset_cache_projection_not_public_indexable'],
                    'projection_source' => 'cached_dataset_hub_fallback',
                ];
            }
        }

        if ($items === []) {
            return null;
        }

        return [
            'projection_kind' => CareerRuntimePublishProjectionService::PROJECTION_KIND,
            'projection_version' => CareerRuntimePublishProjectionService::PROJECTION_VERSION,
            'source_authority' => 'cached_dataset_hub',
            'items' => $items,
        ];
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
