<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Compilation\CareerContentV3Projector;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Publish\CareerJobDetailExposureReadiness;
use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionCoverageSnapshot;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Http\Resources\Career\CareerDatasetHubResource;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Http\Resources\Career\CareerJobListItemResource;
use App\Jobs\Career\WarmCareerJobDetailProjection;
use App\Services\Career\AiImpactAssets\CareerAiImpactPreviewDetailShellBuilder;
use App\Services\Career\Bundles\CareerCnProxyPublicOwnerSurfaceBuilder;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerJobDetailDegradedShellBuilder;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use App\Services\ReviewGovernance\PublicReviewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @review-surface career_trust_manifest
 */
final class PublicCareerAuthorityResponseCache implements CareerJobDetailExposureReadiness
{
    public const DATASET_HUB_CACHE_KEY = 'career:public-authority:dataset-hub:v3';

    public const DATASET_METHOD_CACHE_KEY = 'career:public-authority:dataset-method:v3';

    public const LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY = 'career:public-authority:launch-governance-closure:v1';

    public const JOB_INDEX_CACHE_KEY_PREFIX = 'career:public-authority:job-index:v2';

    public const JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX = 'career:public-authority:job-index:v3';

    public const JOB_DETAIL_CACHE_KEY_PREFIX = 'career:public-authority:job-detail:v1';

    public const JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX = 'career:public-authority:job-detail:v3';

    public const JOB_DETAIL_NEGATIVE_CACHE_TTL_SECONDS = 300;

    public const JOB_DETAIL_WARM_DISPATCH_TTL_SECONDS = 300;

    public const JOB_DETAIL_HTTP_BUILD_BUDGET_MS = 2000;

    public const JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS = 5000;

    public const DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX = 'career:public-authority:directory-read-model:v1';

    public const DIRECTORY_VERSIONED_CACHE_KEY_PREFIX = 'career:public-authority:directory-read-model:v2';

    public const DIRECTORY_CACHE_MAX_AGE_SECONDS = 1800;

    private const JOB_DETAIL_EXPOSURE_LOCK_WAIT_SECONDS = 35;

    private const JOB_DETAIL_EXPOSURE_LOCK_WORK_LEASE_SECONDS = 120;

    private const DIRECTORY_REBUILD_LOCK_WAIT_SECONDS = 65;

    private const DIRECTORY_REBUILD_LOCK_WORK_LEASE_SECONDS = 120;

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $datasetContractBuilder,
        private readonly CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
        private readonly CareerJobListBundleBuilder $careerJobListBundleBuilder,
        private readonly CareerJobDetailBundleBuilder $careerJobDetailBundleBuilder,
        private readonly CareerJobDetailDegradedShellBuilder $careerJobDetailDegradedShellBuilder,
        private readonly CareerCnProxyPublicOwnerSurfaceBuilder $cnProxySurfaceBuilder,
        private readonly CareerAiImpactPreviewDetailShellBuilder $aiImpactPreviewDetailShellBuilder,
        private readonly CareerRuntimePublishProjectionVisibility $runtimePublishProjection,
        private readonly CareerDirectoryReadModelBuilder $careerDirectoryReadModelBuilder,
        private readonly PublicReviewContract $publicReviewContract,
        private readonly CareerContentV3Projector $contentV3Projector,
    ) {}

    /** @return array<string, mixed> */
    public function directoryReadModelPayload(string $publicLocale = 'zh-CN', bool $recordCacheState = true): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        foreach (['active' => $this->directoryActiveVersionKey($normalizedLocale), 'stale' => $this->directoryLkgVersionKey($normalizedLocale)] as $state => $pointerKey) {
            $version = Cache::get($pointerKey);
            $payload = is_string($version) && $version !== ''
                ? Cache::get($this->directoryVersionPayloadKey($normalizedLocale, $version))
                : null;
            if (is_array($payload)) {
                if ($recordCacheState) {
                    $this->logDirectoryCacheState($normalizedLocale, $state === 'active' ? 'hit' : 'stale', $version);
                }

                return $payload;
            }
        }

        // One-release compatibility bridge for the v1 read model. This never rebuilds
        // authority on the HTTP request path and is promoted by the next warm command.
        $legacy = Cache::get($this->directoryReadModelCacheKey($normalizedLocale));
        if (is_array($legacy)) {
            if ($recordCacheState) {
                $this->logDirectoryCacheState($normalizedLocale, 'stale', 'legacy-v1');
            }

            return $legacy;
        }

        if ($recordCacheState) {
            $this->logDirectoryCacheState($normalizedLocale, 'miss', null);
        }

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
    public function jobIndexPayload(
        string $publicLocale = 'zh-CN',
        bool $includeNonIndexable = false,
        bool $recordCacheState = true,
    ): array {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        foreach ([
            'active' => $this->jobIndexActiveVersionKey($normalizedLocale, $includeNonIndexable),
            'lkg' => $this->jobIndexLkgVersionKey($normalizedLocale, $includeNonIndexable),
        ] as $state => $pointerKey) {
            $version = Cache::get($pointerKey);
            $payload = is_string($version) && $version !== ''
                ? Cache::get($this->jobIndexVersionPayloadKey($normalizedLocale, $includeNonIndexable, $version))
                : null;
            if (is_array($payload)) {
                if ($recordCacheState) {
                    $this->logJobIndexCacheState($normalizedLocale, $state, $version);
                }

                return $payload;
            }
        }

        if ($recordCacheState) {
            $this->logJobIndexCacheState($normalizedLocale, 'miss', null);
        }

        throw new \RuntimeException(sprintf(
            'Career job index authority cache is not warm for locale %s.',
            $normalizedLocale,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jobDetailPayload(string $slug, string $publicLocale = 'zh-CN'): ?array
    {
        $read = $this->jobDetailRead($slug, $publicLocale);

        // This legacy accessor is also used by import/readiness gates. A recovery
        // shell is HTTP-readable, but it is not a verified detail projection.
        return $read['state'] === 'degraded' ? null : $read['payload'];
    }

    /**
     * @return array{payload: array<string, mixed>|null, state: 'degraded'|'fresh'|'not_found'|'stale'}
     */
    public function jobDetailRead(string $slug, string $publicLocale = 'zh-CN'): array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return ['payload' => null, 'state' => 'not_found'];
        }

        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $projectionItem = $this->effectiveJobDetailProjectionItem($normalizedSlug, $normalizedLocale);
        if (! $this->jobDetailProjectionItemIsPublished($projectionItem)) {
            if (is_array($projectionItem)) {
                Cache::put(
                    $this->jobDetailNegativeKey($normalizedSlug, $normalizedLocale),
                    true,
                    now()->addSeconds(self::JOB_DETAIL_NEGATIVE_CACHE_TTL_SECONDS),
                );
            }

            // Publication authority remains fail-closed (404), but a read path
            // must not destroy immutable/pointer state prepared by an in-flight
            // promotion or retained while a materialized projection catches up.
            return ['payload' => null, 'state' => 'not_found'];
        }

        Cache::forget($this->jobDetailNegativeKey($normalizedSlug, $normalizedLocale));
        $readiness = $this->jobDetailCacheReadiness($normalizedSlug, $normalizedLocale);
        if ($readiness['classification'] === 'ready_active') {
            $this->logJobDetailCacheState($normalizedSlug, $normalizedLocale, 'fresh', $readiness['version']);

            return [
                'payload' => $this->normalizeJobDetailReviewContract($this->hydrateDerivedContentV3(
                    (array) $readiness['payload'],
                    $normalizedSlug,
                    $normalizedLocale,
                )),
                'state' => 'fresh',
            ];
        }
        if ($readiness['classification'] === 'ready_lkg') {
            $this->logJobDetailCacheState($normalizedSlug, $normalizedLocale, 'stale', $readiness['version']);

            return [
                'payload' => $this->normalizeJobDetailReviewContract($this->hydrateDerivedContentV3(
                    (array) $readiness['payload'],
                    $normalizedSlug,
                    $normalizedLocale,
                )),
                'state' => 'stale',
            ];
        }
        if ($readiness['classification'] === 'legacy_migratable') {
            $payload = $this->normalizeJobDetailReviewContract($this->hydrateDerivedContentV3(
                (array) $readiness['payload'],
                $normalizedSlug,
                $normalizedLocale,
            ));
            $this->publishJobDetailReadModel($normalizedSlug, $normalizedLocale, $payload);
            $this->logJobDetailCacheState($normalizedSlug, $normalizedLocale, 'stale', 'legacy-v1');

            return ['payload' => $payload, 'state' => 'stale'];
        }

        $this->dispatchJobDetailWarm($normalizedSlug, $normalizedLocale);
        $this->logJobDetailCacheState($normalizedSlug, $normalizedLocale, 'degraded', null);

        return [
            'payload' => $this->careerJobDetailDegradedShellBuilder->build(
                $normalizedSlug,
                $normalizedLocale,
                $projectionItem,
            ),
            'state' => 'degraded',
        ];
    }

    /**
     * Resolve only an already-readable public payload for protected verification.
     * This path never promotes legacy state, clears negative cache state, dispatches
     * a warm, or returns a degraded shell.
     *
     * @return array{payload: array<string, mixed>|null, state: 'fresh'|'not_found'|'stale'}
     */
    public function jobDetailVerifyOnlyRead(string $slug, string $publicLocale = 'zh-CN'): array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return ['payload' => null, 'state' => 'not_found'];
        }

        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $projectionItem = $this->effectiveJobDetailProjectionItem($normalizedSlug, $normalizedLocale);
        if (! $this->jobDetailProjectionItemIsPublished($projectionItem)) {
            return ['payload' => null, 'state' => 'not_found'];
        }

        $readiness = $this->jobDetailCacheReadiness($normalizedSlug, $normalizedLocale);
        $classification = (string) ($readiness['classification'] ?? '');
        $payload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : null;
        if ($payload === null || ! in_array($classification, ['ready_active', 'ready_lkg', 'legacy_migratable'], true)) {
            return ['payload' => null, 'state' => 'not_found'];
        }

        return [
            'payload' => $this->normalizeJobDetailReviewContract($this->hydrateDerivedContentV3(
                $payload,
                $normalizedSlug,
                $normalizedLocale,
            )),
            'state' => $classification === 'ready_active' ? 'fresh' : 'stale',
        ];
    }

    /**
     * Inspect the exact active -> LKG -> legacy cache chain without promoting,
     * warming, forgetting, or reading content authority.
     *
     * @return array{
     *   classification: 'broken_pointer'|'invalid_payload'|'legacy_migratable'|'missing_payload'|'missing_pointer'|'ready_active'|'ready_lkg',
     *   payload: array<string, mixed>|null,
     *   version: string|null
     * }
     */
    public function jobDetailCacheReadiness(string $slug, string $publicLocale = 'zh-CN'): array
    {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $issues = [];

        foreach ([
            'ready_active' => $this->jobDetailActiveVersionKey($normalizedSlug, $normalizedLocale),
            'ready_lkg' => $this->jobDetailLkgVersionKey($normalizedSlug, $normalizedLocale),
        ] as $classification => $pointerKey) {
            $version = Cache::get($pointerKey);
            if ($version === null) {
                continue;
            }
            if (! is_string($version) || trim($version) === '') {
                $issues[] = 'broken_pointer';

                continue;
            }

            $payload = Cache::get($this->jobDetailVersionPayloadKey($normalizedSlug, $normalizedLocale, $version));
            if ($payload === null) {
                $issues[] = 'missing_payload';

                continue;
            }
            if (! is_array($payload)) {
                $issues[] = 'invalid_payload';

                continue;
            }

            return [
                'classification' => $classification,
                'payload' => $payload,
                'version' => $version,
            ];
        }

        $legacy = Cache::get($this->jobDetailCacheKey($normalizedSlug, $normalizedLocale));
        if (is_array($legacy)) {
            return [
                'classification' => 'legacy_migratable',
                'payload' => $legacy,
                'version' => 'legacy-v1',
            ];
        }
        if ($legacy !== null) {
            $issues[] = 'invalid_payload';
        }

        foreach (['invalid_payload', 'missing_payload', 'broken_pointer'] as $issue) {
            if (in_array($issue, $issues, true)) {
                return ['classification' => $issue, 'payload' => null, 'version' => null];
            }
        }

        return ['classification' => 'missing_pointer', 'payload' => null, 'version' => null];
    }

    public function jobDetailCacheIsReady(string $slug, string $publicLocale = 'zh-CN'): bool
    {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $readiness = $this->jobDetailCacheReadiness($normalizedSlug, $normalizedLocale);
        if (! in_array($readiness['classification'], ['ready_active', 'ready_lkg', 'legacy_migratable'], true)) {
            return false;
        }

        $materializedItem = $this->runtimePublishProjection->itemForSlug($normalizedSlug, $normalizedLocale);
        if ($this->jobDetailProjectionItemIsPublished($materializedItem)) {
            return true;
        }

        // A stale candidate materialization may use only the active payload
        // paired with its exact published exposure snapshot. LKG and legacy
        // remain recovery paths for already-published materialized authority;
        // they can never become first-exposure authority by themselves.
        return $readiness['classification'] === 'ready_active'
            && $this->jobDetailProjectionItemIsPublished(
                $this->effectiveJobDetailProjectionItem($normalizedSlug, $normalizedLocale),
            );
    }

    /**
     * Resolve one request-bounded publication snapshot without rehydrating the
     * materialized projection once per slug and locale.
     *
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string,array<string,bool>>
     */
    public function jobDetailPublishedSnapshot(array $slugs, array $locales): array
    {
        $published = [];
        foreach ($this->jobDetailPublicationSnapshot($slugs, $locales) as $slug => $localesBySlug) {
            foreach ($localesBySlug as $locale => $evidence) {
                $published[$slug][$locale] = $evidence['published'];
            }
        }

        return $published;
    }

    /**
     * Return the exact payload/version whose publication authority was checked,
     * so downstream quality and review reads cannot drift onto another active
     * pointer after accepting a boolean from an earlier version.
     *
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array<string,array<string,array{published:bool,classification:string,version:string|null,payload:array<string,mixed>|null}>>
     */
    public function jobDetailPublicationSnapshot(array $slugs, array $locales): array
    {
        $normalizedSlugs = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs,
        ))));
        $normalizedLocales = array_values(array_unique(array_map(
            fn (string $locale): string => $this->normalizePublicLocale($locale),
            $locales,
        )));
        $snapshot = $this->runtimePublishProjection instanceof CareerRuntimePublishProjectionCoverageSnapshot
            ? $this->runtimePublishProjection->jobDetailCoverageItems($normalizedLocales)
            : null;

        $evidence = [];
        foreach ($normalizedSlugs as $slug) {
            foreach ($normalizedLocales as $locale) {
                $item = is_array($snapshot)
                    ? ($snapshot[$slug.'|'.$locale] ?? null)
                    : $this->runtimePublishProjection->itemForSlug($slug, $locale);
                $materializedItem = is_array($item) ? $item : null;
                $readiness = $this->jobDetailCacheReadiness($slug, $locale);
                $classification = (string) ($readiness['classification'] ?? '');
                $version = is_string($readiness['version'] ?? null)
                    ? $readiness['version']
                    : null;
                $payload = is_array($readiness['payload'] ?? null)
                    ? $readiness['payload']
                    : null;
                $published = $payload !== null && in_array(
                    $classification,
                    ['ready_active', 'ready_lkg', 'legacy_migratable'],
                    true,
                ) && (
                    $this->jobDetailProjectionItemIsPublished($materializedItem)
                    || (
                        $classification === 'ready_active'
                        && $version !== null
                        && $this->jobDetailProjectionItemIsPublished(
                            $this->jobDetailExposureProjectionForVersion($slug, $locale, $version),
                        )
                    )
                );
                $evidence[$slug][$locale] = [
                    'published' => $published,
                    'classification' => $classification,
                    'version' => $version,
                    'payload' => $payload,
                ];
            }
        }

        return $evidence;
    }

    private function dispatchJobDetailWarm(string $slug, string $publicLocale): void
    {
        $dispatchKey = $this->jobDetailWarmDispatchKey($slug, $publicLocale);

        try {
            if (config('queue.default') === 'sync') {
                Log::debug('career_job_detail_warm_dispatch_skipped_sync', [
                    'slug' => $slug,
                    'locale' => $publicLocale,
                ]);

                return;
            }

            if (! Cache::add($dispatchKey, true, now()->addSeconds(self::JOB_DETAIL_WARM_DISPATCH_TTL_SECONDS))) {
                return;
            }

            try {
                WarmCareerJobDetailProjection::dispatch($slug, $publicLocale);
            } catch (\Throwable $throwable) {
                Cache::forget($dispatchKey);
                Log::warning('career_job_detail_warm_dispatch_failed', [
                    'slug' => $slug,
                    'locale' => $publicLocale,
                    'error_class' => $throwable::class,
                ]);
            }
        } catch (\Throwable $throwable) {
            Log::warning('career_job_detail_warm_dispatch_guard_failed', [
                'slug' => $slug,
                'locale' => $publicLocale,
                'error_class' => $throwable::class,
            ]);
        }
    }

    private function jobDetailWarmDispatchKey(string $slug, string $publicLocale): string
    {
        return sprintf(
            '%s:%s:%s:warm-dispatch',
            self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX,
            strtolower(trim($slug)),
            $this->normalizePublicLocale($publicLocale),
        );
    }

    public function forgetJobDetailPayload(string $slug, string $publicLocale = 'zh-CN'): bool
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($normalizedSlug === '') {
            return false;
        }

        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $forgotten = false;
        foreach ([
            $this->jobDetailCacheKey($normalizedSlug, $normalizedLocale),
            $this->jobDetailActiveVersionKey($normalizedSlug, $normalizedLocale),
            $this->jobDetailLkgVersionKey($normalizedSlug, $normalizedLocale),
            $this->jobDetailNegativeKey($normalizedSlug, $normalizedLocale),
        ] as $key) {
            $forgotten = Cache::forget($key) || $forgotten;
        }

        return $forgotten;
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

        $cacheKey = $this->jobDetailActiveVersionKey($normalizedSlug, $normalizedLocale);
        $materializedProjectionItem = $this->runtimePublishProjection->itemForSlug(
            $normalizedSlug,
            $normalizedLocale,
        );
        $effectiveProjectionItem = $this->effectiveJobDetailProjectionItem(
            $normalizedSlug,
            $normalizedLocale,
        );
        $snapshotBackedProjectionItem = ! $this->jobDetailProjectionItemIsPublished($materializedProjectionItem)
            && $this->jobDetailProjectionItemIsPublished($effectiveProjectionItem)
                ? $effectiveProjectionItem
                : null;
        if ($forgetFirst) {
            $this->forgetJobDetailPayload($normalizedSlug, $normalizedLocale);
        }

        $started = hrtime(true);
        try {
            $payload = $this->buildJobDetailReadModel(
                $normalizedSlug,
                $normalizedLocale,
                $snapshotBackedProjectionItem,
            );
        } catch (Throwable $cause) {
            throw CareerJobDetailWarmFailure::buildException(
                $cause,
                round((hrtime(true) - $started) / 1_000_000, 3),
            );
        }
        $buildMs = round((hrtime(true) - $started) / 1_000_000, 3);
        if ($payload !== null && $buildMs > self::JOB_DETAIL_HTTP_BUILD_BUDGET_MS) {
            throw CareerJobDetailWarmFailure::buildBudgetExceeded($buildMs);
        }
        $publishStarted = hrtime(true);
        try {
            $version = $payload === null ? null : $this->publishJobDetailReadModel(
                $normalizedSlug,
                $normalizedLocale,
                $payload,
                $snapshotBackedProjectionItem,
            );
        } catch (Throwable $cause) {
            throw CareerJobDetailWarmFailure::publishException(
                $cause,
                $buildMs,
                round((hrtime(true) - $publishStarted) / 1_000_000, 3),
            );
        }

        return [
            'cache_key' => $cacheKey,
            'locale' => $normalizedLocale,
            'slug' => $normalizedSlug,
            'status' => $payload === null ? 'missing' : 'cached',
            'member_count' => $payload === null ? 0 : count((array) data_get($payload, 'sections', data_get($payload, 'modules', []))),
            'version' => $version,
            'build_ms' => $buildMs,
        ];
    }

    /**
     * Candidate-exact cache bootstrap entry point. Unlike the HTTP/runtime
     * warmer, this method accepts an already batched conversion closure and
     * reports only bounded classifications suitable for production receipts.
     *
     * @param  array<string, mixed>  $conversionClosure
     * @return array{
     *   status: 'cached'|'failed',
     *   failure_stage: 'build_detail_payload'|'publish_cache_payload'|null,
     *   error_category: 'build_budget_exceeded'|'cache_publish_failed'|'database_permanent_read'|'database_transient_read'|'payload_not_cached'|'unexpected'|null,
     *   build_ms: float
     * }
     */
    public function warmJobDetailPayloadForOfflineBootstrap(
        string $slug,
        string $publicLocale,
        array $conversionClosure,
    ): array {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        if (
            $normalizedSlug === ''
            || strtolower(trim((string) ($conversionClosure['subject_slug'] ?? ''))) !== $normalizedSlug
        ) {
            return $this->offlineBootstrapFailure(
                'build_detail_payload',
                'payload_not_cached',
                0.0,
            );
        }

        $materializedProjectionItem = $this->runtimePublishProjection->itemForSlug(
            $normalizedSlug,
            $normalizedLocale,
        );
        $effectiveProjectionItem = $this->effectiveJobDetailProjectionItem(
            $normalizedSlug,
            $normalizedLocale,
        );
        $snapshotBackedProjectionItem = ! $this->jobDetailProjectionItemIsPublished($materializedProjectionItem)
            && $this->jobDetailProjectionItemIsPublished($effectiveProjectionItem)
                ? $effectiveProjectionItem
                : null;

        $started = hrtime(true);
        try {
            $payload = $this->buildJobDetailReadModel(
                $normalizedSlug,
                $normalizedLocale,
                $snapshotBackedProjectionItem,
                $conversionClosure,
            );
        } catch (\Throwable $throwable) {
            return $this->offlineBootstrapFailure(
                'build_detail_payload',
                $this->offlineBootstrapBuildErrorCategory($throwable),
                $this->elapsedMilliseconds($started),
            );
        }

        $buildMs = $this->elapsedMilliseconds($started);
        if ($buildMs > self::JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS) {
            return $this->offlineBootstrapFailure(
                'build_detail_payload',
                'build_budget_exceeded',
                $buildMs,
            );
        }
        if ($payload === null) {
            return $this->offlineBootstrapFailure(
                'build_detail_payload',
                'payload_not_cached',
                $buildMs,
            );
        }

        try {
            $this->publishJobDetailReadModel(
                $normalizedSlug,
                $normalizedLocale,
                $payload,
                $snapshotBackedProjectionItem,
            );
        } catch (\Throwable) {
            return $this->offlineBootstrapFailure(
                'publish_cache_payload',
                'cache_publish_failed',
                $buildMs,
            );
        }

        return [
            'status' => 'cached',
            'failure_stage' => null,
            'error_category' => null,
            'build_ms' => $buildMs,
        ];
    }

    /**
     * Build and stage one target-locale detail projection from an explicit
     * post-promotion projection item while the exposure transaction is still
     * uncommitted. Only the immutable version payload is written; no public
     * active/LKG pointer can be removed by a concurrent candidate request.
     *
     * @param  array<string, mixed>  $projectionItem
     * @return array<string, mixed>
     */
    public function prepareJobDetailPayloadForExposure(
        string $slug,
        string $publicLocale,
        array $projectionItem,
    ): array {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        if (
            $normalizedSlug === ''
            || strtolower(trim((string) ($projectionItem['slug'] ?? ''))) !== $normalizedSlug
            || ! $this->jobDetailProjectionItemIsPublished($projectionItem)
        ) {
            return [
                'slug' => $normalizedSlug,
                'locale' => $normalizedLocale,
                'status' => 'projection_not_exposable',
                'classification' => 'missing_pointer',
            ];
        }

        $payload = $this->buildJobDetailReadModel(
            $normalizedSlug,
            $normalizedLocale,
            $projectionItem,
        );
        if ($payload === null) {
            return [
                'slug' => $normalizedSlug,
                'locale' => $normalizedLocale,
                'status' => 'projection_build_failed',
                'classification' => 'missing_pointer',
            ];
        }

        $version = (string) Str::ulid();
        $payloadKey = $this->jobDetailVersionPayloadKey(
            $normalizedSlug,
            $normalizedLocale,
            $version,
        );
        Cache::forever(
            $payloadKey,
            $this->withoutDerivedContentV3($payload, $normalizedSlug, $normalizedLocale),
        );
        $exposureProjectionKey = $this->jobDetailExposureProjectionVersionKey(
            $normalizedSlug,
            $normalizedLocale,
            $version,
        );
        Cache::forever($exposureProjectionKey, $projectionItem);
        $stagedPayload = Cache::get($payloadKey);
        $stagedExposureProjection = Cache::get($exposureProjectionKey);
        $ready = is_array($stagedPayload)
            && $this->jobDetailExposureProjectionSnapshotIsValid(
                $stagedExposureProjection,
                $normalizedSlug,
                $normalizedLocale,
            );

        return [
            'slug' => $normalizedSlug,
            'locale' => $normalizedLocale,
            'status' => $ready ? 'ready' : 'verification_failed',
            'classification' => $ready ? 'ready_staged' : 'missing_payload',
            'version' => $version,
        ];
    }

    /**
     * Stage a replacement payload against the currently published authority.
     * This writes only an unreachable immutable candidate; active/LKG pointers
     * remain unchanged until activatePreparedJobDetailPayloadsForExposure().
     *
     * @return array<string, mixed>
     */
    public function preparePublishedJobDetailReplacement(string $slug, string $publicLocale): array
    {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $projectionItem = $this->effectiveJobDetailProjectionItem($normalizedSlug, $normalizedLocale);
        if (! $this->jobDetailProjectionItemIsPublished($projectionItem)) {
            return [
                'slug' => $normalizedSlug,
                'locale' => $normalizedLocale,
                'status' => 'projection_not_exposable',
                'classification' => 'missing_pointer',
            ];
        }

        return $this->prepareJobDetailPayloadForExposure(
            $normalizedSlug,
            $normalizedLocale,
            $projectionItem,
        );
    }

    /**
     * Read back one unreachable immutable replacement candidate before the
     * batch pointer transaction. This method never promotes or mutates cache
     * state and exists only to bind the prepared bytes to the expected package.
     *
     * @param  array<string, mixed>  $preparedEntry
     * @return array<string, mixed>|null
     */
    public function preparedJobDetailReplacementPayload(array $preparedEntry): ?array
    {
        $slug = strtolower(trim((string) ($preparedEntry['slug'] ?? '')));
        $rawLocale = trim((string) ($preparedEntry['locale'] ?? ''));
        $version = trim((string) ($preparedEntry['version'] ?? ''));
        if ($slug === '' || $rawLocale === '' || $version === '') {
            return null;
        }

        $payload = Cache::get($this->jobDetailVersionPayloadKey(
            $slug,
            $this->normalizePublicLocale($rawLocale),
            $version,
        ));

        return is_array($payload)
            ? $this->hydrateDerivedContentV3($payload, $slug, $this->normalizePublicLocale($rawLocale))
            : null;
    }

    /**
     * Atomically activate an already verified batch of immutable detail payloads
     * after database exposure commits. Pointer snapshots are restored if any
     * target cannot be verified or switched.
     *
     * @param  list<array<string, mixed>>  $preparedEntries
     * @return array{status: string, entries: list<array<string, mixed>>, failures: list<array<string, mixed>>, rollback_snapshots?: array<string, mixed>}
     */
    public function activatePreparedJobDetailPayloadsForExposure(
        array $preparedEntries,
        bool $retainRollbackSnapshots = false,
    ): array {
        $entries = [];
        $failures = [];
        if ($preparedEntries === []) {
            return [
                'status' => 'blocked',
                'entries' => [],
                'failures' => [['reason' => 'prepared_detail_entries_missing']],
            ];
        }

        foreach ($preparedEntries as $entry) {
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $rawLocale = trim((string) ($entry['locale'] ?? ''));
            $locale = $this->normalizePublicLocale($rawLocale);
            $version = trim((string) ($entry['version'] ?? ''));
            if (
                $slug === ''
                || $rawLocale === ''
                || $version === ''
                || ($entry['status'] ?? null) !== 'ready'
                || ($entry['classification'] ?? null) !== 'ready_staged'
            ) {
                $failures[] = [
                    'reason' => 'prepared_detail_entry_invalid',
                    'slug' => $slug,
                    'locale' => $locale,
                ];

                continue;
            }

            $key = $slug.'|'.$locale;
            if (isset($entries[$key])) {
                $failures[] = [
                    'reason' => 'prepared_detail_entry_duplicate',
                    'slug' => $slug,
                    'locale' => $locale,
                ];

                continue;
            }

            $entries[$key] = [
                'slug' => $slug,
                'locale' => $locale,
                'version' => $version,
            ];
        }

        if ($failures !== [] || count($entries) !== count($preparedEntries)) {
            return ['status' => 'blocked', 'entries' => [], 'failures' => $failures];
        }

        ksort($entries, SORT_STRING);

        try {
            $activated = $this->withJobDetailExposureLocks(
                array_keys($entries),
                fn (): array => $this->activateStagedJobDetailReadModels(array_values($entries)),
            );
        } catch (\Throwable $throwable) {
            return [
                'status' => 'blocked',
                'entries' => [],
                'failures' => [[
                    'reason' => 'prepared_detail_activation_failed',
                    'context' => ['error_class' => $throwable::class],
                ]],
            ];
        }

        $result = [
            'status' => 'pass',
            'entries' => $activated['entries'],
            'failures' => [],
        ];
        if ($retainRollbackSnapshots) {
            $result['rollback_snapshots'] = $activated['snapshots'];
        }

        return $result;
    }

    /**
     * Restore the complete active/LKG/negative/legacy pointer state retained by
     * a successful batch activation. This is intentionally a separate primitive
     * so callers can compensate a later full-readback failure.
     *
     * @param  list<array<string, mixed>>  $preparedEntries
     * @param  array<string, mixed>  $snapshots
     */
    public function restorePreparedJobDetailExposurePointers(array $preparedEntries, array $snapshots): void
    {
        $targets = [];
        foreach ($preparedEntries as $entry) {
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $rawLocale = trim((string) ($entry['locale'] ?? ''));
            $locale = $this->normalizePublicLocale($rawLocale);
            $version = trim((string) ($entry['version'] ?? ''));
            $key = $slug.'|'.$locale;
            if ($slug === '' || $rawLocale === '' || $version === '' || ! isset($snapshots[$key])) {
                throw new \InvalidArgumentException('Prepared detail rollback snapshot is incomplete.');
            }
            $targets[$key] = ['slug' => $slug, 'locale' => $locale, 'version' => $version];
        }
        ksort($targets, SORT_STRING);
        if (count($targets) !== count($preparedEntries)) {
            throw new \InvalidArgumentException('Prepared detail rollback targets are invalid.');
        }

        $this->withJobDetailExposureLocks(array_keys($targets), function () use ($targets, $snapshots): array {
            foreach ($targets as $key => $target) {
                $snapshot = $snapshots[$key];
                foreach (['active', 'lkg', 'negative', 'legacy'] as $name) {
                    if (! is_array($snapshot[$name] ?? null)) {
                        throw new \InvalidArgumentException('Prepared detail rollback snapshot is invalid.');
                    }
                }
                if (Cache::get($this->jobDetailActiveVersionKey(
                    $target['slug'],
                    $target['locale'],
                )) !== $target['version']) {
                    throw new \RuntimeException('Prepared detail active pointer drifted before rollback.');
                }
            }
            foreach ($targets as $key => $target) {
                $snapshot = $snapshots[$key];
                $slug = $target['slug'];
                $locale = $target['locale'];
                $this->restoreCacheValue($this->jobDetailActiveVersionKey($slug, $locale), $snapshot['active']);
                $this->restoreCacheValue($this->jobDetailLkgVersionKey($slug, $locale), $snapshot['lkg']);
                $this->restoreCacheValue($this->jobDetailNegativeKey($slug, $locale), $snapshot['negative']);
                $this->restoreCacheValue($this->jobDetailCacheKey($slug, $locale), $snapshot['legacy']);
            }

            return [];
        });
    }

    /**
     * Remove only the immutable exposure-projection snapshots prepared for a
     * failed post-commit activation. The detail payload may remain cached, but
     * without this matching snapshot a stale materialized candidate projection
     * keeps the public route fail-closed.
     *
     * @param  list<array<string, mixed>>  $preparedEntries
     */
    public function forgetPreparedJobDetailExposureProjectionSnapshots(array $preparedEntries): void
    {
        foreach ($preparedEntries as $entry) {
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $rawLocale = trim((string) ($entry['locale'] ?? ''));
            $version = trim((string) ($entry['version'] ?? ''));
            if ($slug === '' || $rawLocale === '' || $version === '') {
                continue;
            }

            Cache::forget($this->jobDetailExposureProjectionVersionKey(
                $slug,
                $this->normalizePublicLocale($rawLocale),
                $version,
            ));
        }
    }

    /** @param list<array<string, mixed>> $preparedEntries */
    public function forgetPreparedJobDetailCandidates(array $preparedEntries): void
    {
        $this->forgetPreparedJobDetailExposureProjectionSnapshots($preparedEntries);
        foreach ($preparedEntries as $entry) {
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            $rawLocale = trim((string) ($entry['locale'] ?? ''));
            $version = trim((string) ($entry['version'] ?? ''));
            if ($slug === '' || $rawLocale === '' || $version === '') {
                continue;
            }
            Cache::forget($this->jobDetailVersionPayloadKey(
                $slug,
                $this->normalizePublicLocale($rawLocale),
                $version,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $exposureProjectionItem
     */
    public function publishJobDetailReadModel(
        string $slug,
        string $publicLocale,
        array $payload,
        ?array $exposureProjectionItem = null,
    ): string {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $payload = $this->normalizeJobDetailReviewContract($payload);
        if (
            $exposureProjectionItem !== null
            && ! $this->jobDetailExposureProjectionSnapshotIsValid(
                $exposureProjectionItem,
                $normalizedSlug,
                $normalizedLocale,
            )
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Career detail exposure projection is invalid for %s (%s).',
                $normalizedSlug,
                $normalizedLocale,
            ));
        }

        $version = (string) Str::ulid();
        $activeKey = $this->jobDetailActiveVersionKey($normalizedSlug, $normalizedLocale);
        $previousVersion = Cache::get($activeKey);

        Cache::forever(
            $this->jobDetailVersionPayloadKey($normalizedSlug, $normalizedLocale, $version),
            $this->withoutDerivedContentV3($payload, $normalizedSlug, $normalizedLocale),
        );
        if ($exposureProjectionItem !== null) {
            $exposureProjectionKey = $this->jobDetailExposureProjectionVersionKey(
                $normalizedSlug,
                $normalizedLocale,
                $version,
            );
            Cache::forever($exposureProjectionKey, $exposureProjectionItem);
            if (! $this->jobDetailExposureProjectionSnapshotIsValid(
                Cache::get($exposureProjectionKey),
                $normalizedSlug,
                $normalizedLocale,
            )) {
                throw new \RuntimeException(sprintf(
                    'Career detail exposure projection write verification failed for %s (%s).',
                    $normalizedSlug,
                    $normalizedLocale,
                ));
            }
        }
        if (is_string($previousVersion) && $previousVersion !== '') {
            Cache::forever($this->jobDetailLkgVersionKey($normalizedSlug, $normalizedLocale), $previousVersion);
        }
        Cache::forever($activeKey, $version);
        Cache::forget($this->jobDetailNegativeKey($normalizedSlug, $normalizedLocale));
        Cache::forget($this->jobDetailCacheKey($normalizedSlug, $normalizedLocale));

        return $version;
    }

    /**
     * @param  list<array{slug: string, locale: string, version: string}>  $entries
     * @return array{entries: list<array<string, mixed>>, snapshots: array<string, mixed>}
     */
    private function activateStagedJobDetailReadModels(array $entries): array
    {
        $snapshots = [];
        foreach ($entries as $entry) {
            $slug = $entry['slug'];
            $locale = $entry['locale'];
            $version = $entry['version'];
            $payload = Cache::get($this->jobDetailVersionPayloadKey($slug, $locale, $version));
            if (! is_array($payload)) {
                throw new \RuntimeException(sprintf(
                    'Career detail staged payload verification failed for %s (%s).',
                    $slug,
                    $locale,
                ));
            }

            $exposureProjection = Cache::get($this->jobDetailExposureProjectionVersionKey(
                $slug,
                $locale,
                $version,
            ));
            if (! $this->jobDetailExposureProjectionSnapshotIsValid($exposureProjection, $slug, $locale)) {
                throw new \RuntimeException(sprintf(
                    'Career detail exposure projection verification failed for %s (%s).',
                    $slug,
                    $locale,
                ));
            }

            $snapshots[$slug.'|'.$locale] = [
                'active' => $this->cacheValueSnapshot($this->jobDetailActiveVersionKey($slug, $locale)),
                'lkg' => $this->cacheValueSnapshot($this->jobDetailLkgVersionKey($slug, $locale)),
                'negative' => $this->cacheValueSnapshot($this->jobDetailNegativeKey($slug, $locale)),
                'legacy' => $this->cacheValueSnapshot($this->jobDetailCacheKey($slug, $locale)),
            ];
        }

        $attempted = [];
        try {
            foreach ($entries as $entry) {
                $slug = $entry['slug'];
                $locale = $entry['locale'];
                $version = $entry['version'];
                $key = $slug.'|'.$locale;
                $attempted[] = $key;
                $previousVersion = $snapshots[$key]['active']['value'];
                if (is_string($previousVersion) && $previousVersion !== '') {
                    Cache::forever($this->jobDetailLkgVersionKey($slug, $locale), $previousVersion);
                }
                Cache::forever($this->jobDetailActiveVersionKey($slug, $locale), $version);
                if (Cache::get($this->jobDetailActiveVersionKey($slug, $locale)) !== $version) {
                    throw new \RuntimeException(sprintf(
                        'Career detail active pointer verification failed for %s (%s).',
                        $slug,
                        $locale,
                    ));
                }
                Cache::forget($this->jobDetailNegativeKey($slug, $locale));
                Cache::forget($this->jobDetailCacheKey($slug, $locale));
            }
        } catch (\Throwable $throwable) {
            foreach (array_reverse($attempted) as $key) {
                [$slug, $locale] = explode('|', $key, 2);
                $snapshot = $snapshots[$key];
                $this->restoreCacheValue($this->jobDetailActiveVersionKey($slug, $locale), $snapshot['active']);
                $this->restoreCacheValue($this->jobDetailLkgVersionKey($slug, $locale), $snapshot['lkg']);
                $this->restoreCacheValue($this->jobDetailNegativeKey($slug, $locale), $snapshot['negative']);
                $this->restoreCacheValue($this->jobDetailCacheKey($slug, $locale), $snapshot['legacy']);
            }

            throw $throwable;
        }

        return [
            'entries' => array_map(static fn (array $entry): array => [
                'slug' => $entry['slug'],
                'locale' => $entry['locale'],
                'status' => 'ready',
                'classification' => 'ready_active',
                'version' => $entry['version'],
            ], $entries),
            'snapshots' => $snapshots,
        ];
    }

    /**
     * @param  list<string>  $targets
     * @param  callable(): list<array<string, mixed>>  $callback
     * @return list<array<string, mixed>>
     */
    private function withJobDetailExposureLocks(array $targets, callable $callback, int $offset = 0): array
    {
        if (! isset($targets[$offset])) {
            return $callback();
        }

        [$slug, $locale] = explode('|', $targets[$offset], 2);
        $lock = Cache::lock(
            $this->jobDetailExposureActivationLockKey($slug, $locale),
            $this->nestedLockLeaseSeconds(
                count($targets),
                $offset,
                self::JOB_DETAIL_EXPOSURE_LOCK_WAIT_SECONDS,
                self::JOB_DETAIL_EXPOSURE_LOCK_WORK_LEASE_SECONDS,
            ),
        );

        return $lock->block(self::JOB_DETAIL_EXPOSURE_LOCK_WAIT_SECONDS, fn (): array => $this->withJobDetailExposureLocks(
            $targets,
            $callback,
            $offset + 1,
        ));
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
        $reporter?->__invoke('job_index_zh_cn', 'starting');
        $this->warmDirectoryReadModels(
            ['en', 'zh-CN'],
            null,
            null,
            activateJobIndexPayloads: true,
        );
        $jobIndexEn = $this->jobIndexPayload('en');
        $jobIndexZhCn = $this->jobIndexPayload('zh-CN');
        $reporter?->__invoke('job_index_en', 'finished');
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
                'cache_key' => $this->jobIndexActiveVersionKey('en', false),
                'status' => 'cached',
                'member_count' => count((array) data_get($jobIndexEn, 'items', [])),
            ],
            'job_index_zh_cn' => [
                'cache_key' => $this->jobIndexActiveVersionKey('zh-CN', false),
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
     * Rebuild only the locale-keyed Career directory read models. The
     * source job list is computed in memory and is not written to the
     * broader job-index, dataset, or launch-governance cache families.
     *
     * @param  list<string>  $publicLocales
     * @param  list<array<string, mixed>>|null  $exposureProjectionItems
     * @return array<string, array{locale: string, status: string, version: ?string, member_count: int, job_index_activated: bool}>
     */
    public function warmDirectoryReadModels(
        array $publicLocales = ['en', 'zh-CN'],
        ?callable $reporter = null,
        ?array $exposureProjectionItems = null,
        bool $activateJobIndexPayloads = false,
    ): array {
        $locales = array_values(array_unique(array_map(
            fn (string $locale): string => $this->normalizePublicLocale($locale),
            $publicLocales === [] ? ['en', 'zh-CN'] : $publicLocales,
        )));
        sort($locales, SORT_STRING);
        $startedAt = [];
        foreach ($locales as $locale) {
            $phase = 'career_directory_'.$this->cachePhaseLocale($locale);
            $reporter?->__invoke($phase, 'starting');
            $startedAt[$locale] = hrtime(true);
        }

        try {
            $activation = $this->withDirectoryRebuildLocks(
                $locales,
                function () use ($locales, $exposureProjectionItems, $activateJobIndexPayloads): array {
                    // Build from authority and inspect detail readiness only after
                    // every target-locale rebuild lock is held. A delayed older
                    // warmer therefore cannot activate a payload captured before
                    // a newer detail warm or promotion completed.
                    $sourceItems = CareerJobListItemResource::collection(
                        $this->careerJobListBundleBuilder->build(false)
                    )->resolve();
                    $payloads = [];
                    $jobIndexes = [];
                    foreach ($locales as $locale) {
                        $authorityJobIndex = $this->filterJobIndexPayloadForPublicLocale([
                            'bundle_kind' => 'career_job_index',
                            'bundle_version' => 'career.protocol.job_index.v1',
                            'items' => $sourceItems,
                        ], $locale, false);
                        $preservedExposureItems = $this->missingActiveDirectoryExposureProjectionItems(
                            is_array($authorityJobIndex['items'] ?? null) ? $authorityJobIndex['items'] : [],
                            $locale,
                        );
                        $directoryExposureItems = array_merge(
                            $preservedExposureItems,
                            $exposureProjectionItems ?? [],
                        );
                        if ($directoryExposureItems !== []) {
                            $authorityJobIndex['items'] = $this->mergeExposureDirectoryItems(
                                is_array($authorityJobIndex['items'] ?? null) ? $authorityJobIndex['items'] : [],
                                $directoryExposureItems,
                                $locale,
                            );
                        }
                        $items = is_array($authorityJobIndex['items'] ?? null) ? $authorityJobIndex['items'] : [];
                        $jobIndexes[$locale] = $this->filterJobIndexPayloadForPublicLocale(
                            $authorityJobIndex,
                            $locale,
                            false,
                        );
                        $payloads[$locale] = $this->careerDirectoryReadModelBuilder->build(
                            $items,
                            $locale,
                            fn (string $slug, string $itemLocale): bool => $this->jobDetailCacheIsReady($slug, $itemLocale),
                        );
                    }

                    return [
                        'payloads' => $payloads,
                        'versions' => $this->activateDirectoryAndOptionalJobIndexReadModels(
                            $payloads,
                            $jobIndexes,
                            $activateJobIndexPayloads,
                        ),
                        'job_indexes_activated' => $activateJobIndexPayloads,
                    ];
                },
            );
        } finally {
            foreach ($startedAt as $locale => $started) {
                try {
                    Cache::forever(
                        $this->directoryLastRebuildDurationKey($locale),
                        round((hrtime(true) - $started) / 1_000_000, 3),
                    );
                } catch (\Throwable) {
                    // A telemetry write must not mask the activation failure.
                }
            }
        }
        $payloads = $activation['payloads'];
        $versions = $activation['versions'];
        $summary = [];
        foreach ($payloads as $locale => $payload) {
            $phase = 'career_directory_'.$this->cachePhaseLocale($locale);
            $summary[$phase] = [
                'locale' => $locale,
                'status' => 'cached',
                'version' => $versions[$locale] ?? null,
                'member_count' => count((array) ($payload['items'] ?? [])),
                'job_index_activated' => (bool) ($activation['job_indexes_activated'] ?? false),
            ];
            $reporter?->__invoke($phase, 'finished');
        }

        return $summary;
    }

    /**
     * @param  array<string, array<string, mixed>>  $directoryPayloads
     * @param  array<string, array<string, mixed>>  $jobIndexPayloads
     * @return array<string, string>
     */
    private function activateDirectoryAndOptionalJobIndexReadModels(
        array $directoryPayloads,
        array $jobIndexPayloads,
        bool $activateJobIndexPayloads,
    ): array {
        if (! $activateJobIndexPayloads) {
            return $this->stageAndActivateDirectoryReadModels($directoryPayloads);
        }

        $jobIndexSnapshots = [];
        foreach (array_keys($jobIndexPayloads) as $locale) {
            $jobIndexSnapshots[$locale] = $this->jobIndexPointerSnapshot($locale, false);
        }

        try {
            $this->stageAndActivateJobIndexReadModels($jobIndexPayloads, false);
            $versions = $this->stageAndActivateDirectoryReadModels($directoryPayloads);
            foreach (array_keys($jobIndexPayloads) as $locale) {
                $this->forgetLegacyJobIndexSafely($locale, false);
            }

            return $versions;
        } catch (\Throwable $throwable) {
            foreach ($jobIndexSnapshots as $locale => $snapshot) {
                $this->restoreJobIndexPointerSnapshot($locale, false, $snapshot);
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloadsByLocale
     * @return array<string, string>
     */
    public function publishJobIndexReadModelsAtomically(array $payloadsByLocale): array
    {
        $normalized = [];
        foreach ($payloadsByLocale as $locale => $payload) {
            $normalizedLocale = $this->normalizePublicLocale((string) $locale);
            $normalized[$normalizedLocale] = $this->filterJobIndexPayloadForPublicLocale(
                $payload,
                $normalizedLocale,
            );
        }
        ksort($normalized, SORT_STRING);

        return $this->stageAndActivateJobIndexReadModels($normalized, false);
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloadsByLocale
     * @return array<string, string>
     */
    private function stageAndActivateJobIndexReadModels(array $payloadsByLocale, bool $includeNonIndexable): array
    {
        $versions = [];
        $snapshots = [];
        foreach ($payloadsByLocale as $locale => $payload) {
            $version = (string) Str::ulid();
            $payloadKey = $this->jobIndexVersionPayloadKey($locale, $includeNonIndexable, $version);
            Cache::forever($payloadKey, $payload);
            if (Cache::get($payloadKey) !== $payload) {
                throw new \RuntimeException(sprintf(
                    'Career job index staged payload verification failed for locale %s.',
                    $locale,
                ));
            }
            $versions[$locale] = $version;
            $snapshots[$locale] = $this->jobIndexPointerSnapshot($locale, $includeNonIndexable);
        }

        $attempted = [];
        try {
            foreach ($versions as $locale => $version) {
                $attempted[] = $locale;
                $previousVersion = $snapshots[$locale]['active']['value'];
                Cache::forever(
                    $this->jobIndexLkgVersionKey($locale, $includeNonIndexable),
                    is_string($previousVersion) && $previousVersion !== '' ? $previousVersion : $version,
                );
                Cache::forever($this->jobIndexActiveVersionKey($locale, $includeNonIndexable), $version);
                if (Cache::get($this->jobIndexActiveVersionKey($locale, $includeNonIndexable)) !== $version) {
                    throw new \RuntimeException(sprintf(
                        'Career job index active pointer verification failed for locale %s.',
                        $locale,
                    ));
                }
                Cache::forever($this->jobIndexActivatedAtKey($locale, $includeNonIndexable), now()->timestamp);
            }
        } catch (\Throwable $throwable) {
            foreach (array_reverse($attempted) as $locale) {
                $this->restoreJobIndexPointerSnapshot(
                    $locale,
                    $includeNonIndexable,
                    $snapshots[$locale],
                );
            }

            throw $throwable;
        }

        foreach ($versions as $locale => $version) {
            $this->logJobIndexCacheState($locale, 'rebuild', $version);
        }

        return $versions;
    }

    /**
     * @param  list<mixed>  $currentItems
     * @param  list<array<string, mixed>>  $projectionItems
     * @return list<array<string, mixed>>
     */
    private function mergeExposureDirectoryItems(
        array $currentItems,
        array $projectionItems,
        string $publicLocale,
    ): array {
        $projectionLocale = $this->normalizePublicLocale($publicLocale) === 'zh-CN' ? 'zh' : 'en';
        $localeItems = array_values(array_filter(
            $projectionItems,
            fn (array $item): bool => $this->projectionItemLocale($item) === $projectionLocale
                && ($item['dataset_visible'] ?? false) === true
                && $this->jobDetailProjectionItemIsPublished($item),
        ));
        $exposureItems = CareerJobListItemResource::collection(
            $this->careerJobListBundleBuilder->buildFromRuntimeProjectionItems($localeItems),
        )->resolve();

        $itemsBySlug = [];
        foreach (array_merge($currentItems, $exposureItems) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            if ($slug !== '') {
                $itemsBySlug[$slug] = $item;
            }
        }

        ksort($itemsBySlug, SORT_STRING);

        return array_values($itemsBySlug);
    }

    /**
     * Preserve only active-directory members missing from the newly rebuilt
     * source list when they still have an active payload and the exact matching
     * published exposure snapshot. The current directory is enumeration only;
     * the versioned backend cache remains the authority proof.
     *
     * @param  list<mixed>  $currentItems
     * @return list<array<string, mixed>>
     */
    private function missingActiveDirectoryExposureProjectionItems(
        array $currentItems,
        string $publicLocale,
    ): array {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $currentSlugs = [];
        foreach ($currentItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            if ($slug !== '') {
                $currentSlugs[$slug] = true;
            }
        }

        $directoryVersion = Cache::get($this->directoryActiveVersionKey($normalizedLocale));
        $directoryPayload = is_string($directoryVersion) && $directoryVersion !== ''
            ? Cache::get($this->directoryVersionPayloadKey($normalizedLocale, $directoryVersion))
            : null;
        $activeItems = is_array($directoryPayload)
            && is_array($directoryPayload['items'] ?? null)
                ? $directoryPayload['items']
                : [];
        $projectionItems = [];
        foreach ($activeItems as $activeItem) {
            if (! is_array($activeItem)) {
                continue;
            }

            $slug = strtolower(trim((string) ($activeItem['slug'] ?? '')));
            if ($slug === '' || isset($currentSlugs[$slug])) {
                continue;
            }

            $detailVersion = Cache::get($this->jobDetailActiveVersionKey($slug, $normalizedLocale));
            if (! is_string($detailVersion) || $detailVersion === '') {
                continue;
            }

            $detailPayload = Cache::get($this->jobDetailVersionPayloadKey(
                $slug,
                $normalizedLocale,
                $detailVersion,
            ));
            $exposureProjection = Cache::get($this->jobDetailExposureProjectionVersionKey(
                $slug,
                $normalizedLocale,
                $detailVersion,
            ));
            if (
                ! is_array($detailPayload)
                || ! $this->jobDetailExposureProjectionSnapshotIsValid(
                    $exposureProjection,
                    $slug,
                    $normalizedLocale,
                )
                || ($exposureProjection['dataset_visible'] ?? false) !== true
            ) {
                continue;
            }

            $projectionItems[$slug] = $exposureProjection;
        }

        ksort($projectionItems, SORT_STRING);

        return array_values($projectionItems);
    }

    /**
     * Stage every locale payload while all target-locale rebuild locks are held
     * before switching any active pointer. If a pointer switch fails, restore
     * all pointer metadata touched by the batch. Staged payloads are immutable
     * and harmless when left unreachable.
     *
     * @param  array<string, array<string, mixed>>  $payloadsByLocale
     * @return array<string, string>
     */
    public function publishDirectoryReadModelsAtomically(array $payloadsByLocale): array
    {
        $payloads = [];
        foreach ($payloadsByLocale as $locale => $payload) {
            $payloads[$this->normalizePublicLocale((string) $locale)] = $payload;
        }
        ksort($payloads, SORT_STRING);

        return $this->withDirectoryRebuildLocks(
            array_keys($payloads),
            fn (): array => $this->stageAndActivateDirectoryReadModels($payloads),
        );
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

    /** @return array<string, mixed> */
    private function buildJobIndexAuthorityPayload(bool $includeNonIndexable): array
    {
        return [
            'bundle_kind' => 'career_job_index',
            'bundle_version' => 'career.protocol.job_index.v1',
            'items' => CareerJobListItemResource::collection(
                $this->careerJobListBundleBuilder->build($includeNonIndexable),
            )->resolve(),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $rebuild
     * @return array<string, mixed>
     */
    public function singleFlightDirectoryRebuild(string $publicLocale, ?string $observedVersion, callable $rebuild): array
    {
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $lock = Cache::lock(
            $this->directoryRebuildLockKey($normalizedLocale),
            self::DIRECTORY_REBUILD_LOCK_WORK_LEASE_SECONDS,
        );

        return $lock->block(self::DIRECTORY_REBUILD_LOCK_WAIT_SECONDS, function () use ($normalizedLocale, $observedVersion, $rebuild): array {
            $currentVersion = Cache::get($this->directoryActiveVersionKey($normalizedLocale));
            if ($currentVersion !== null && $currentVersion !== $observedVersion) {
                $this->logDirectoryCacheState($normalizedLocale, 'hit', (string) $currentVersion, ['rebuild' => 'coalesced']);

                try {
                    return $this->jobIndexPayload($normalizedLocale);
                } catch (\RuntimeException) {
                    // The directory may have advanced without a matching job-index
                    // activation. The lock owner must complete the supplied rebuild.
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

    /**
     * @param  array<string, array<string, mixed>>  $payloadsByLocale
     * @return array<string, string>
     */
    private function stageAndActivateDirectoryReadModels(array $payloadsByLocale): array
    {
        $versions = [];
        $snapshots = [];

        foreach ($payloadsByLocale as $locale => $payload) {
            $version = (string) Str::ulid();
            $payloadKey = $this->directoryVersionPayloadKey($locale, $version);
            Cache::forever($payloadKey, $payload);
            if (! is_array(Cache::get($payloadKey))) {
                throw new \RuntimeException(sprintf(
                    'Career directory staged payload verification failed for locale %s.',
                    $locale,
                ));
            }

            $versions[$locale] = $version;
            $snapshots[$locale] = $this->directoryPointerSnapshot($locale);
        }

        $attempted = [];
        try {
            foreach ($versions as $locale => $version) {
                $attempted[] = $locale;
                $this->activateStagedDirectoryReadModel(
                    $locale,
                    $version,
                    $snapshots[$locale]['active']['value'],
                );
            }
        } catch (\Throwable $throwable) {
            foreach (array_reverse($attempted) as $locale) {
                $this->restoreDirectoryPointerSnapshot($locale, $snapshots[$locale]);
            }

            throw $throwable;
        }

        foreach ($versions as $locale => $version) {
            $this->forgetLegacyDirectoryReadModelSafely($locale);
            $this->logDirectoryCacheState($locale, 'rebuild', $version, [
                'rebuild' => 'batch_finished',
                'activation' => 'atomic_multi_locale',
            ]);
        }

        return $versions;
    }

    /**
     * The v1 directory key is only a compatibility fallback after verified v2
     * pointers have been activated. Cleanup must not turn a successful atomic
     * pointer switch into a promotion failure whose remediation could diverge
     * database authority from those active pointers.
     */
    private function forgetLegacyDirectoryReadModelSafely(string $locale): void
    {
        try {
            Cache::forget($this->directoryReadModelCacheKey($locale));
        } catch (\Throwable $throwable) {
            Log::warning('career_directory_legacy_cache_cleanup_failed', [
                'locale' => $locale,
                'error_class' => $throwable::class,
                'activation' => 'atomic_multi_locale',
            ]);
        }
    }

    private function activateStagedDirectoryReadModel(string $locale, string $version, mixed $previousVersion): void
    {
        if (is_string($previousVersion) && $previousVersion !== '') {
            Cache::forever($this->directoryLkgVersionKey($locale), $previousVersion);
        }
        Cache::forever($this->directoryActiveVersionKey($locale), $version);
        if (Cache::get($this->directoryActiveVersionKey($locale)) !== $version) {
            throw new \RuntimeException(sprintf(
                'Career directory active pointer verification failed for locale %s.',
                $locale,
            ));
        }
        Cache::forever($this->directoryActivatedAtKey($locale), now()->timestamp);
    }

    /**
     * @return array{
     *     active: array{exists: bool, value: mixed},
     *     lkg: array{exists: bool, value: mixed},
     *     activated_at: array{exists: bool, value: mixed}
     * }
     */
    private function directoryPointerSnapshot(string $locale): array
    {
        return [
            'active' => $this->cacheValueSnapshot($this->directoryActiveVersionKey($locale)),
            'lkg' => $this->cacheValueSnapshot($this->directoryLkgVersionKey($locale)),
            'activated_at' => $this->cacheValueSnapshot($this->directoryActivatedAtKey($locale)),
        ];
    }

    /**
     * @param  array{
     *     active: array{exists: bool, value: mixed},
     *     lkg: array{exists: bool, value: mixed},
     *     activated_at: array{exists: bool, value: mixed}
     * }  $snapshot
     */
    private function restoreDirectoryPointerSnapshot(string $locale, array $snapshot): void
    {
        $this->restoreCacheValue($this->directoryActiveVersionKey($locale), $snapshot['active']);
        $this->restoreCacheValue($this->directoryLkgVersionKey($locale), $snapshot['lkg']);
        $this->restoreCacheValue($this->directoryActivatedAtKey($locale), $snapshot['activated_at']);
    }

    /** @return array{exists: bool, value: mixed} */
    private function cacheValueSnapshot(string $key): array
    {
        return [
            'exists' => Cache::has($key),
            'value' => Cache::get($key),
        ];
    }

    /** @param array{exists: bool, value: mixed} $snapshot */
    private function restoreCacheValue(string $key, array $snapshot): void
    {
        if ($snapshot['exists']) {
            Cache::forever($key, $snapshot['value']);

            return;
        }

        Cache::forget($key);
    }

    /**
     * @param  list<string>  $locales
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withDirectoryRebuildLocks(array $locales, callable $callback, int $offset = 0): array
    {
        if (! isset($locales[$offset])) {
            return $callback();
        }

        $lock = Cache::lock(
            $this->directoryRebuildLockKey($locales[$offset]),
            $this->nestedLockLeaseSeconds(
                count($locales),
                $offset,
                self::DIRECTORY_REBUILD_LOCK_WAIT_SECONDS,
                self::DIRECTORY_REBUILD_LOCK_WORK_LEASE_SECONDS,
            ),
        );

        return $lock->block(self::DIRECTORY_REBUILD_LOCK_WAIT_SECONDS, fn (): array => $this->withDirectoryRebuildLocks(
            $locales,
            $callback,
            $offset + 1,
        ));
    }

    private function nestedLockLeaseSeconds(int $targetCount, int $offset, int $waitSeconds, int $workLeaseSeconds): int
    {
        $nestedWaits = max(0, $targetCount - $offset - 1);

        return $workLeaseSeconds + ($nestedWaits * $waitSeconds);
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
    /** @param array<string, mixed>|null $exposureProjectionItem */
    private function buildJobDetailReadModel(
        string $slug,
        string $publicLocale,
        ?array $exposureProjectionItem = null,
        ?array $conversionClosureOverride = null,
    ): ?array {
        if (
            $exposureProjectionItem === null
            && ! $this->detailReadIsPublishedForLocale($slug, $publicLocale)
        ) {
            return null;
        }

        $bundle = $this->careerJobDetailBundleBuilder->buildBySlug(
            $slug,
            $publicLocale,
            $exposureProjectionItem,
            $conversionClosureOverride,
        );
        if ($bundle !== null) {
            return (new CareerJobDetailResource($bundle))->toArray(
                Request::create('/api/v0.5/career/jobs/'.$slug, 'GET', ['locale' => $publicLocale])
            );
        }

        return $this->cnProxySurfaceBuilder->buildBySlug($slug, $publicLocale)
            ?? $this->aiImpactPreviewDetailShellBuilder->build($slug, $publicLocale);
    }

    /**
     * @return array{
     *   status: 'failed',
     *   failure_stage: 'build_detail_payload'|'publish_cache_payload',
     *   error_category: string,
     *   build_ms: float
     * }
     */
    private function offlineBootstrapFailure(
        string $failureStage,
        string $errorCategory,
        float $buildMs,
    ): array {
        return [
            'status' => 'failed',
            'failure_stage' => $failureStage,
            'error_category' => $errorCategory,
            'build_ms' => $buildMs,
        ];
    }

    private function elapsedMilliseconds(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }

    private function offlineBootstrapBuildErrorCategory(\Throwable $throwable): string
    {
        for ($candidate = $throwable; $candidate instanceof \Throwable; $candidate = $candidate->getPrevious()) {
            $sqlState = (string) $candidate->getCode();
            $driverCode = null;
            if ($candidate instanceof \Illuminate\Database\QueryException) {
                $sqlState = (string) ($candidate->errorInfo[0] ?? $sqlState);
                $driverCode = $candidate->errorInfo[1] ?? null;
            } elseif ($candidate instanceof \PDOException) {
                $driverCode = $candidate->errorInfo[1] ?? null;
            }

            if (
                str_starts_with($sqlState, '08')
                || $sqlState === '40001'
                || in_array((int) $driverCode, [1205, 1213, 2006, 2013], true)
            ) {
                return 'database_transient_read';
            }
            if ($candidate instanceof \Illuminate\Database\QueryException || $candidate instanceof \PDOException) {
                return 'database_permanent_read';
            }
        }

        return 'unexpected';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterJobIndexPayloadForPublicLocale(
        array $payload,
        string $publicLocale,
        bool $requireDetailReady = true,
    ): array {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $items = array_map(
            fn (mixed $item): mixed => is_array($item)
                ? $this->normalizeJobIndexItemReviewContract($item)
                : $item,
            $items,
        );
        $payload['items'] = array_values(array_filter($items, function (mixed $item) use ($publicLocale, $requireDetailReady): bool {
            if (! is_array($item)) {
                return false;
            }

            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            $projectionItem = $this->effectiveJobDetailProjectionItem($slug, $publicLocale);

            return $slug !== '' && $this->detailReadIsPublishedForLocale($slug, $publicLocale)
                && ($projectionItem['dataset_visible'] ?? false) === true
                && (! $requireDetailReady || $this->jobDetailCacheIsReady($slug, $publicLocale));
        }));

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizeJobDetailReviewContract(array $payload): array
    {
        if (is_array($payload['trust_manifest'] ?? null)) {
            $payload['trust_manifest'] = $this->normalizeReviewContainer($payload['trust_manifest']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutDerivedContentV3(array $payload, string $slug, string $locale): array
    {
        $contentV3 = data_get($payload, 'display_surface_v1.content_v3');
        $hydrated = is_array($contentV3)
            ? $this->hydrateDerivedContentV3($payload, $slug, $locale)
            : $payload;
        if (is_array($contentV3) && data_get($hydrated, 'display_surface_v1.content_v3') === $contentV3) {
            unset($payload['display_surface_v1']['content_v3']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function hydrateDerivedContentV3(array $payload, string $slug, string $locale): array
    {
        $surface = $payload['display_surface_v1'] ?? null;
        $page = is_array($surface) ? data_get($surface, 'page.content') : null;
        if (! is_array($surface) || ! is_array($page)) {
            return $payload;
        }

        try {
            $presentation = $surface['presentation_v2'] ?? null;
            $sources = $surface['sources'] ?? [];
            $contentV3 = $this->contentV3Projector->project(
                strtolower(trim($slug)),
                $this->normalizePublicLocale($locale),
                $page,
                is_array($presentation) ? $presentation : null,
                is_array($sources) ? $sources : [],
            );
            CareerContentV3Contract::assert($contentV3);
            $payload['display_surface_v1']['content_v3'] = $contentV3;
        } catch (Throwable) {
            unset($payload['display_surface_v1']['content_v3']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function normalizeJobIndexItemReviewContract(array $item): array
    {
        if (is_array($item['trust_summary'] ?? null)) {
            $item['trust_summary'] = $this->normalizeReviewContainer($item['trust_summary']);
        }

        return $item;
    }

    /** @param array<string, mixed> $review @return array<string, mixed> */
    private function normalizeReviewContainer(array $review): array
    {
        $hasCanonicalReviewState = array_key_exists('review_state', $review);

        return array_merge(
            $review,
            $this->publicReviewContract->project(
                $hasCanonicalReviewState
                    ? $review['review_state']
                    : ($review['reviewer_status'] ?? null),
                $hasCanonicalReviewState
                    ? ($review['last_reviewed_at'] ?? null)
                    : ($review['reviewed_at'] ?? null),
            ),
        );
    }

    private function detailReadIsPublishedForLocale(string $slug, string $publicLocale): bool
    {
        $item = $this->effectiveJobDetailProjectionItem($slug, $publicLocale);

        return $this->jobDetailProjectionItemIsPublished($item);
    }

    /** @return array<string, mixed>|null */
    private function effectiveJobDetailProjectionItem(string $slug, string $publicLocale): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $materializedItem = $this->runtimePublishProjection->itemForSlug($normalizedSlug, $normalizedLocale);

        return $this->effectiveJobDetailProjectionItemFromMaterialized(
            $normalizedSlug,
            $normalizedLocale,
            $materializedItem,
        );
    }

    /** @param array<string, mixed>|null $materializedItem */
    private function effectiveJobDetailProjectionItemFromMaterialized(
        string $slug,
        string $publicLocale,
        ?array $materializedItem,
    ): ?array {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        if ($this->jobDetailProjectionItemIsPublished($materializedItem)) {
            return $materializedItem;
        }

        $activeVersion = Cache::get($this->jobDetailActiveVersionKey($normalizedSlug, $normalizedLocale));
        if (! is_string($activeVersion) || trim($activeVersion) === '') {
            return $materializedItem;
        }

        $activePayload = Cache::get($this->jobDetailVersionPayloadKey(
            $normalizedSlug,
            $normalizedLocale,
            $activeVersion,
        ));
        $exposureProjection = Cache::get($this->jobDetailExposureProjectionVersionKey(
            $normalizedSlug,
            $normalizedLocale,
            $activeVersion,
        ));
        if (
            ! is_array($activePayload)
            || ! $this->jobDetailExposureProjectionSnapshotIsValid(
                $exposureProjection,
                $normalizedSlug,
                $normalizedLocale,
            )
        ) {
            return $materializedItem;
        }

        return $exposureProjection;
    }

    /** @return array<string,mixed>|null */
    private function jobDetailExposureProjectionForVersion(
        string $slug,
        string $publicLocale,
        string $version,
    ): ?array {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);
        $exposureProjection = Cache::get($this->jobDetailExposureProjectionVersionKey(
            $normalizedSlug,
            $normalizedLocale,
            $version,
        ));

        return $this->jobDetailExposureProjectionSnapshotIsValid(
            $exposureProjection,
            $normalizedSlug,
            $normalizedLocale,
        ) ? $exposureProjection : null;
    }

    private function jobDetailExposureProjectionSnapshotIsValid(
        mixed $exposureProjection,
        string $slug,
        string $publicLocale,
    ): bool {
        $normalizedSlug = strtolower(trim($slug));
        $normalizedLocale = $this->normalizePublicLocale($publicLocale);

        return is_array($exposureProjection)
            && strtolower(trim((string) ($exposureProjection['slug'] ?? ''))) === $normalizedSlug
            && $this->normalizePublicLocale((string) ($exposureProjection['locale'] ?? '')) === $normalizedLocale
            && $this->jobDetailProjectionItemIsPublished($exposureProjection);
    }

    /** @param array<string, mixed>|null $item */
    public function jobDetailProjectionItemIsPublished(?array $item): bool
    {
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

    /** @param array<string, mixed> $item */
    private function projectionItemLocale(array $item): string
    {
        return str_starts_with(strtolower(trim((string) ($item['locale'] ?? 'en'))), 'zh')
            ? 'zh'
            : 'en';
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

    private function jobIndexActiveVersionKey(string $publicLocale, bool $includeNonIndexable): string
    {
        return sprintf(
            '%s:%s:%s:active',
            self::JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
            $includeNonIndexable ? 'with-non-indexable' : 'public',
        );
    }

    private function jobIndexLkgVersionKey(string $publicLocale, bool $includeNonIndexable): string
    {
        return sprintf(
            '%s:%s:%s:lkg',
            self::JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
            $includeNonIndexable ? 'with-non-indexable' : 'public',
        );
    }

    private function jobIndexVersionPayloadKey(string $publicLocale, bool $includeNonIndexable, string $version): string
    {
        return sprintf(
            '%s:%s:%s:versions:%s',
            self::JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
            $includeNonIndexable ? 'with-non-indexable' : 'public',
            $version,
        );
    }

    private function jobIndexActivatedAtKey(string $publicLocale, bool $includeNonIndexable): string
    {
        return sprintf(
            '%s:%s:%s:activated-at',
            self::JOB_INDEX_VERSIONED_CACHE_KEY_PREFIX,
            $this->normalizePublicLocale($publicLocale),
            $includeNonIndexable ? 'with-non-indexable' : 'public',
        );
    }

    /**
     * @return array{
     *     active: array{exists: bool, value: mixed},
     *     lkg: array{exists: bool, value: mixed},
     *     activated_at: array{exists: bool, value: mixed}
     * }
     */
    private function jobIndexPointerSnapshot(string $locale, bool $includeNonIndexable): array
    {
        return [
            'active' => $this->cacheValueSnapshot($this->jobIndexActiveVersionKey($locale, $includeNonIndexable)),
            'lkg' => $this->cacheValueSnapshot($this->jobIndexLkgVersionKey($locale, $includeNonIndexable)),
            'activated_at' => $this->cacheValueSnapshot($this->jobIndexActivatedAtKey($locale, $includeNonIndexable)),
        ];
    }

    /** @param array{active: array{exists: bool, value: mixed}, lkg: array{exists: bool, value: mixed}, activated_at: array{exists: bool, value: mixed}} $snapshot */
    private function restoreJobIndexPointerSnapshot(string $locale, bool $includeNonIndexable, array $snapshot): void
    {
        $this->restoreCacheValue($this->jobIndexActiveVersionKey($locale, $includeNonIndexable), $snapshot['active']);
        $this->restoreCacheValue($this->jobIndexLkgVersionKey($locale, $includeNonIndexable), $snapshot['lkg']);
        $this->restoreCacheValue($this->jobIndexActivatedAtKey($locale, $includeNonIndexable), $snapshot['activated_at']);
    }

    private function forgetLegacyJobIndexSafely(string $locale, bool $includeNonIndexable): void
    {
        try {
            Cache::forget($this->jobIndexCacheKey($locale, $includeNonIndexable));
        } catch (\Throwable $throwable) {
            Log::warning('career_job_index_legacy_cache_cleanup_failed', [
                'locale' => $locale,
                'error_class' => $throwable::class,
                'activation' => 'atomic_multi_locale',
            ]);
        }
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

    public function jobDetailActiveVersionKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:active', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale));
    }

    private function jobDetailLkgVersionKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:lkg', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale));
    }

    private function jobDetailVersionPayloadKey(string $slug, string $publicLocale, string $version): string
    {
        return sprintf('%s:%s:%s:versions:%s', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale), $version);
    }

    private function jobDetailExposureProjectionVersionKey(string $slug, string $publicLocale, string $version): string
    {
        return sprintf('%s:%s:%s:exposure-projections:%s', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale), $version);
    }

    public function jobDetailNegativeKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:negative', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale));
    }

    private function jobDetailExposureActivationLockKey(string $slug, string $publicLocale): string
    {
        return sprintf('%s:%s:%s:exposure-lock', self::JOB_DETAIL_VERSIONED_CACHE_KEY_PREFIX, strtolower(trim($slug)), $this->normalizePublicLocale($publicLocale));
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

    private function logJobIndexCacheState(string $locale, string $state, ?string $version): void
    {
        Log::info('career_public_authority_cache', [
            'surface' => 'job_index',
            'locale' => $locale,
            'cache_state' => $state,
            'version' => $version,
        ]);
    }

    private function logJobDetailCacheState(string $slug, string $locale, string $state, ?string $version): void
    {
        Log::info('career_public_authority_cache', [
            'surface' => 'job_detail',
            'slug' => $slug,
            'locale' => $locale,
            'cache_state' => $state,
            'version' => $version,
        ]);
    }
}
