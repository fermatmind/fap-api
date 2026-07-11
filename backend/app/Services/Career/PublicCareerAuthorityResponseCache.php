<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Http\Resources\Career\CareerDatasetHubResource;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Http\Resources\Career\CareerJobListItemResource;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PublicCareerAuthorityResponseCache
{
    public const DATASET_HUB_CACHE_KEY = 'career:public-authority:dataset-hub:v3';

    public const DATASET_METHOD_CACHE_KEY = 'career:public-authority:dataset-method:v3';

    public const LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY = 'career:public-authority:launch-governance-closure:v1';

    public const JOB_INDEX_CACHE_KEY_PREFIX = 'career:public-authority:job-index:v1';

    public const JOB_DETAIL_CACHE_KEY_PREFIX = 'career:public-authority:job-detail:v1';

    public const DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX = 'career:public-authority:directory-read-model:v1';

    public const DIRECTORY_VERSIONED_CACHE_KEY_PREFIX = 'career:public-authority:directory-read-model:v2';

    public const DIRECTORY_CACHE_MAX_AGE_SECONDS = 1800;

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $datasetContractBuilder,
        private readonly CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
        private readonly CareerJobListBundleBuilder $careerJobListBundleBuilder,
        private readonly CareerJobDetailBundleBuilder $careerJobDetailBundleBuilder,
        private readonly CareerRuntimePublishProjectionVisibility $runtimePublishProjection,
        private readonly CareerDirectoryReadModelBuilder $careerDirectoryReadModelBuilder,
    ) {}

    /** @return array<string, mixed> */
    public function directoryReadModelPayload(string $publicLocale = 'zh-CN'): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        foreach (['active' => $this->directoryActiveVersionKey($normalizedLocale), 'stale' => $this->directoryLkgVersionKey($normalizedLocale)] as $state => $pointerKey) {
            $version = Cache::get($pointerKey);
            $payload = is_string($version) && $version !== ''
                ? Cache::get($this->directoryVersionPayloadKey($normalizedLocale, $version))
                : null;
            if (is_array($payload)) {
                $this->logDirectoryCacheState($normalizedLocale, $state === 'active' ? 'hit' : 'stale', $version);

                return $payload;
            }
        }

        // One-release compatibility bridge for the v1 read model. This never rebuilds
        // authority on the HTTP request path and is promoted by the next warm command.
        $legacy = Cache::get($this->directoryReadModelCacheKey($normalizedLocale));
        if (is_array($legacy)) {
            $this->logDirectoryCacheState($normalizedLocale, 'stale', 'legacy-v1');

            return $legacy;
        }

        $this->logDirectoryCacheState($normalizedLocale, 'miss', null);

        throw new \RuntimeException(sprintf('Career directory authority cache is unavailable for locale %s.', $normalizedLocale));
    }

    /**
     * @return array<string, mixed>
     */
    public function datasetHubPayload(): array
    {
        $cached = Cache::get(self::DATASET_HUB_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshDatasetHubPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function datasetMethodPayload(): array
    {
        $cached = Cache::get(self::DATASET_METHOD_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshDatasetMethodPayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function launchGovernanceClosurePayload(): array
    {
        $cached = Cache::get(self::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshLaunchGovernanceClosurePayload();
    }

    /**
     * @return array<string, mixed>
     */
    public function jobIndexPayload(string $publicLocale = 'zh-CN', bool $includeNonIndexable = false): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $cacheKey = $this->jobIndexCacheKey($normalizedLocale, $includeNonIndexable);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $this->filterJobIndexPayloadForPublicLocale($cached, $normalizedLocale);
        }

        return $this->refreshJobIndexPayload($normalizedLocale, $includeNonIndexable);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jobDetailPayload(string $slug, string $publicLocale = 'zh-CN'): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return null;
        }

        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $cacheKey = $this->jobDetailCacheKey($normalizedSlug, $normalizedLocale);
        if (! $this->detailReadIsPublishedForLocale($normalizedSlug, $normalizedLocale)) {
            Cache::forget($cacheKey);

            return null;
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshJobDetailPayload($normalizedSlug, $normalizedLocale);
    }

    public function forgetJobDetailPayload(string $slug, string $publicLocale = 'zh-CN'): bool
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return false;
        }

        return Cache::forget($this->jobDetailCacheKey($normalizedSlug, $publicLocale));
    }

    /**
     * @return array{cache_key: string, locale: string, slug: string, status: string, member_count: int}
     */
    public function warmJobDetailPayload(string $slug, string $publicLocale = 'zh-CN', bool $forgetFirst = false): array
    {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        if ($normalizedSlug === '') {
            return [
                'cache_key' => '',
                'locale' => $normalizedLocale,
                'slug' => '',
                'status' => 'invalid_slug',
                'member_count' => 0,
            ];
        }

        $cacheKey = $this->jobDetailCacheKey($normalizedSlug, $normalizedLocale);
        if ($forgetFirst) {
            Cache::forget($cacheKey);
        }

        $payload = $this->refreshJobDetailPayload($normalizedSlug, $normalizedLocale);

        return [
            'cache_key' => $cacheKey,
            'locale' => $normalizedLocale,
            'slug' => $normalizedSlug,
            'status' => $payload === null ? 'missing' : 'cached',
            'member_count' => $payload === null ? 0 : count((array) data_get($payload, 'sections', data_get($payload, 'modules', []))),
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $publicLocales
     * @return array<string, array{cache_key: string, locale: string, slug: string, status: string, member_count: int}>
     */
    public function warmJobDetailPayloads(array $slugs, array $publicLocales = ['zh-CN'], bool $forgetFirst = false, ?callable $reporter = null): array
    {
        $normalizedSlugs = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs,
        ), static fn (string $slug): bool => $slug !== '')));
        $normalizedLocales = array_values(array_unique(array_map(
            fn (string $locale): string => $this->normalizePublicLocale($locale),
            $publicLocales === [] ? ['zh-CN'] : $publicLocales,
        )));

        $summary = [];
        foreach ($normalizedLocales as $locale) {
            foreach ($normalizedSlugs as $slug) {
                $phase = sprintf('job_detail_%s_%s', $this->cachePhaseLocale($locale), $slug);
                $reporter?->__invoke($phase, 'starting');
                $summary[$phase] = $this->warmJobDetailPayload($slug, $locale, $forgetFirst);
                $reporter?->__invoke($phase, 'finished');
            }
        }

        return $summary;
    }

    /**
     * @return array<string, array{cache_key: string, member_count?: int, status: string}>
     */
    public function warm(?callable $reporter = null): array
    {
        $reporter?->__invoke('dataset_payloads', 'starting');
        [$datasetHub, $datasetMethod] = $this->refreshDatasetPayloads();
        $reporter?->__invoke('dataset_payloads', 'finished');

        $reporter?->__invoke('job_index_en', 'starting');
        $jobIndexEn = $this->warmJobIndexPayload('en');
        $reporter?->__invoke('job_index_en', 'finished');

        $reporter?->__invoke('job_index_zh_cn', 'starting');
        $jobIndexZhCn = $this->warmJobIndexPayload('zh-CN');
        $reporter?->__invoke('job_index_zh_cn', 'finished');

        $reporter?->__invoke('launch_governance_closure', 'starting');
        $launchGovernance = $this->refreshLaunchGovernanceClosurePayload();
        $reporter?->__invoke('launch_governance_closure', 'finished');

        return [
            'dataset_hub' => [
                'cache_key' => self::DATASET_HUB_CACHE_KEY,
                'status' => 'cached',
                'member_count' => (int) data_get($datasetHub, 'collection_summary.member_count', 0),
            ],
            'dataset_method' => [
                'cache_key' => self::DATASET_METHOD_CACHE_KEY,
                'status' => 'cached',
                'member_count' => (int) data_get($datasetMethod, 'scope_summary.member_count', 0),
            ],
            'job_index_en' => [
                'cache_key' => $this->jobIndexCacheKey('en', false),
                'status' => 'cached',
                'member_count' => count((array) data_get($jobIndexEn, 'items', [])),
            ],
            'job_index_zh_cn' => [
                'cache_key' => $this->jobIndexCacheKey('zh-CN', false),
                'status' => 'cached',
                'member_count' => count((array) data_get($jobIndexZhCn, 'items', [])),
            ],
            'launch_governance_closure' => [
                'cache_key' => self::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY,
                'status' => 'cached',
                'member_count' => count((array) data_get($launchGovernance, 'members', [])),
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function refreshDatasetPayloads(): array
    {
        $contracts = $this->datasetContractBuilder->buildPublicContracts();
        $datasetHub = (new CareerDatasetHubResource($contracts['hub']))
            ->toArray(Request::create('/api/v0.5/career/datasets/occupations', 'GET'));
        $datasetMethod = (new CareerDatasetMethodResource($contracts['method']))
            ->toArray(Request::create('/api/v0.5/career/datasets/occupations/method', 'GET'));

        Cache::forever(self::DATASET_HUB_CACHE_KEY, $datasetHub);
        Cache::forever(self::DATASET_METHOD_CACHE_KEY, $datasetMethod);

        return [$datasetHub, $datasetMethod];
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshDatasetHubPayload(): array
    {
        $payload = (new CareerDatasetHubResource($this->datasetContractBuilder->buildHubContract()))
            ->toArray(Request::create('/api/v0.5/career/datasets/occupations', 'GET'));

        Cache::forever(self::DATASET_HUB_CACHE_KEY, $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshDatasetMethodPayload(): array
    {
        $payload = (new CareerDatasetMethodResource($this->datasetContractBuilder->buildMethodContract()))
            ->toArray(Request::create('/api/v0.5/career/datasets/occupations/method', 'GET'));

        Cache::forever(self::DATASET_METHOD_CACHE_KEY, $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshLaunchGovernanceClosurePayload(): array
    {
        $payload = $this->launchGovernanceClosureService->build()->toArray();

        Cache::forever(self::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY, $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshJobIndexPayload(string $publicLocale, bool $includeNonIndexable = false): array
    {
        $items = CareerJobListItemResource::collection(
            $this->careerJobListBundleBuilder->build($includeNonIndexable)
        )->resolve();

        $payload = [
            'bundle_kind' => 'career_job_index',
            'bundle_version' => 'career.protocol.job_index.v1',
            'items' => $items,
        ];

        $payload = $this->filterJobIndexPayloadForPublicLocale($payload, $publicLocale);

        Cache::forever($this->jobIndexCacheKey($publicLocale, $includeNonIndexable), $payload);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function warmJobIndexPayload(string $publicLocale): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $observedVersion = Cache::get($this->directoryActiveVersionKey($normalizedLocale));

        return $this->singleFlightDirectoryRebuild(
            $normalizedLocale,
            is_string($observedVersion) ? $observedVersion : null,
            function () use ($normalizedLocale): array {
                $jobIndex = $this->refreshJobIndexPayload($normalizedLocale);
                $items = is_array($jobIndex['items'] ?? null) ? $jobIndex['items'] : [];
                $this->publishDirectoryReadModel(
                    $normalizedLocale,
                    $this->careerDirectoryReadModelBuilder->build($items, $normalizedLocale),
                );

                return $jobIndex;
            },
        );
    }

    /**
     * @param  callable(): array<string, mixed>  $rebuild
     * @return array<string, mixed>
     */
    public function singleFlightDirectoryRebuild(string $publicLocale, ?string $observedVersion, callable $rebuild): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $lock = Cache::lock($this->directoryRebuildLockKey($normalizedLocale), 60);

        return $lock->block(65, function () use ($normalizedLocale, $observedVersion, $rebuild): array {
            $currentVersion = Cache::get($this->directoryActiveVersionKey($normalizedLocale));
            if ($currentVersion !== null && $currentVersion !== $observedVersion) {
                $this->logDirectoryCacheState($normalizedLocale, 'hit', (string) $currentVersion, ['rebuild' => 'coalesced']);

                $cached = Cache::get($this->jobIndexCacheKey($normalizedLocale, false));
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $this->logDirectoryCacheState($normalizedLocale, 'rebuild', is_string($currentVersion) ? $currentVersion : null, ['rebuild' => 'starting']);

            $started = hrtime(true);
            try {
                return $rebuild();
            } catch (\Throwable $throwable) {
                $this->logDirectoryCacheState($normalizedLocale, 'stale', is_string($currentVersion) ? $currentVersion : null, [
                    'rebuild' => 'failed',
                    'error_class' => $throwable::class,
                ]);

                throw $throwable;
            } finally {
                Cache::forever(
                    $this->directoryLastRebuildDurationKey($normalizedLocale),
                    round((hrtime(true) - $started) / 1_000_000, 3),
                );
            }
        });
    }

    /** @param array<string, mixed> $payload */
    public function publishDirectoryReadModel(string $publicLocale, array $payload): string
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $version = (string) Str::ulid();
        $activeKey = $this->directoryActiveVersionKey($normalizedLocale);
        $previousVersion = Cache::get($activeKey);

        Cache::forever($this->directoryVersionPayloadKey($normalizedLocale, $version), $payload);
        if (is_string($previousVersion) && $previousVersion !== '') {
            Cache::forever($this->directoryLkgVersionKey($normalizedLocale), $previousVersion);
        }
        Cache::forever($activeKey, $version);
        Cache::forever($this->directoryActivatedAtKey($normalizedLocale), now()->timestamp);
        Cache::forget($this->directoryReadModelCacheKey($normalizedLocale));
        $this->logDirectoryCacheState($normalizedLocale, 'rebuild', $version, ['rebuild' => 'finished']);

        return $version;
    }

    /** @return array{locale: string, status: string, active_version: ?string, lkg_version: ?string, age_seconds: ?int, last_rebuild_ms: ?float} */
    public function directoryCacheStatus(string $publicLocale): array
    {
        $locale = $this->normalizePublicLocale($publicLocale);
        $active = Cache::get($this->directoryActiveVersionKey($locale));
        $lkg = Cache::get($this->directoryLkgVersionKey($locale));
        $activePayload = is_string($active) ? Cache::get($this->directoryVersionPayloadKey($locale, $active)) : null;
        $activatedAt = Cache::get($this->directoryActivatedAtKey($locale));
        $lastRebuildMs = Cache::get($this->directoryLastRebuildDurationKey($locale));

        return [
            'locale' => $locale,
            'status' => is_array($activePayload) ? 'ready' : 'unavailable',
            'active_version' => is_string($active) ? $active : null,
            'lkg_version' => is_string($lkg) ? $lkg : null,
            'age_seconds' => is_numeric($activatedAt) ? max(0, now()->timestamp - (int) $activatedAt) : null,
            'last_rebuild_ms' => is_numeric($lastRebuildMs) ? (float) $lastRebuildMs : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refreshJobDetailPayload(string $slug, string $publicLocale): ?array
    {
        if (! $this->detailReadIsPublishedForLocale($slug, $publicLocale)) {
            return null;
        }

        $bundle = $this->careerJobDetailBundleBuilder->buildBySlug($slug, $publicLocale);
        if ($bundle === null) {
            return null;
        }

        $payload = (new CareerJobDetailResource($bundle))->toArray(
            Request::create('/api/v0.5/career/jobs/'.$slug, 'GET', ['locale' => $publicLocale])
        );

        Cache::forever($this->jobDetailCacheKey($slug, $publicLocale), $payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterJobIndexPayloadForPublicLocale(array $payload, string $publicLocale): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $payload['items'] = array_values(array_filter($items, function (mixed $item) use ($publicLocale): bool {
            if (! is_array($item)) {
                return false;
            }

            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            $projectionItem = $this->runtimePublishProjection->itemForSlug($slug, $publicLocale);

            return $slug !== '' && $this->detailReadIsPublishedForLocale($slug, $publicLocale)
                && ($projectionItem['dataset_visible'] ?? false) === true;
        }));

        return $payload;
    }

    private function detailReadIsPublishedForLocale(string $slug, string $publicLocale): bool
    {
        $item = $this->runtimePublishProjection->itemForSlug($slug, $publicLocale);
        $state = is_array($item)
            ? (string) (
                $item['runtime_publish_state']
                ?? $item['runtime_state']
                ?? $item['projection_state']
                ?? $item['state']
                ?? ''
            )
            : '';

        return is_array($item)
            && $state === 'published'
            && ($item['detail_route_enabled'] ?? false) === true
            && ($item['robots_indexable'] ?? false) === true
            && ($item['release_gate_pass'] ?? false) === true;
    }

    private function normalizePublicLocale(string $publicLocale): string
    {
        $normalized = strtolower(trim($publicLocale));

        return in_array($normalized, ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    private function cachePhaseLocale(string $publicLocale): string
    {
        return strtolower(str_replace('-', '_', $this->normalizePublicLocale($publicLocale)));
    }

    private function jobIndexCacheKey(string $publicLocale, bool $includeNonIndexable): string
    {
        return sprintf(
            '%s:%s:%s',
            self::JOB_INDEX_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
            $includeNonIndexable ? 'with-non-indexable' : 'public'
        );
    }

    public function jobDetailCacheKey(string $slug, string $publicLocale): string
    {
        return sprintf(
            '%s:%s:%s',
            self::JOB_DETAIL_CACHE_KEY_PREFIX,
            strtolower(trim($slug)),
            $this->normalizePublicLocale($publicLocale)
        );
    }

    private function directoryReadModelCacheKey(string $publicLocale): string
    {
        return sprintf(
            '%s:%s',
            self::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
        );
    }

    private function directoryActiveVersionKey(string $publicLocale): string
    {
        return sprintf('%s:%s:active', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale));
    }

    private function directoryLkgVersionKey(string $publicLocale): string
    {
        return sprintf('%s:%s:lkg', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale));
    }

    private function directoryVersionPayloadKey(string $publicLocale, string $version): string
    {
        return sprintf('%s:%s:versions:%s', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale), $version);
    }

    private function directoryRebuildLockKey(string $publicLocale): string
    {
        return sprintf('%s:%s:rebuild-lock', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale));
    }

    private function directoryActivatedAtKey(string $publicLocale): string
    {
        return sprintf('%s:%s:activated-at', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale));
    }

    private function directoryLastRebuildDurationKey(string $publicLocale): string
    {
        return sprintf('%s:%s:last-rebuild-ms', self::DIRECTORY_VERSIONED_CACHE_KEY_PREFIX, $this->normalizePublicLocale($publicLocale));
    }

    /** @param array<string, mixed> $extra */
    private function logDirectoryCacheState(string $locale, string $state, ?string $version, array $extra = []): void
    {
        Log::info('career_public_authority_cache', array_merge([
            'surface' => 'directory',
            'locale' => $locale,
            'cache_state' => $state,
            'version' => $version,
        ], $extra));
    }
}
