<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Http\Resources\Career\CareerDatasetHubResource;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Http\Resources\Career\CareerJobListItemResource;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerJobListBundleBuilder;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PublicCareerAuthorityResponseCache
{
    public const DATASET_HUB_CACHE_KEY = 'career:public-authority:dataset-hub:v3';

    public const DATASET_METHOD_CACHE_KEY = 'career:public-authority:dataset-method:v3';

    public const LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY = 'career:public-authority:launch-governance-closure:v1';

    public const JOB_INDEX_CACHE_KEY_PREFIX = 'career:public-authority:job-index:v1';

    public const JOB_DETAIL_CACHE_KEY_PREFIX = 'career:public-authority:job-detail:v1';

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $datasetContractBuilder,
        private readonly CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
        private readonly CareerJobListBundleBuilder $careerJobListBundleBuilder,
        private readonly CareerJobDetailBundleBuilder $careerJobDetailBundleBuilder,
    ) {}

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
        $cacheKey = $this->jobIndexCacheKey($publicLocale, $includeNonIndexable);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshJobIndexPayload($publicLocale, $includeNonIndexable);
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

        $cacheKey = $this->jobDetailCacheKey($normalizedSlug, $publicLocale);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->refreshJobDetailPayload($normalizedSlug, $publicLocale);
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
        $jobIndexEn = $this->refreshJobIndexPayload('en');
        $reporter?->__invoke('job_index_en', 'finished');

        $reporter?->__invoke('job_index_zh_cn', 'starting');
        $jobIndexZhCn = $this->refreshJobIndexPayload('zh-CN');
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

        Cache::forever($this->jobIndexCacheKey($publicLocale, $includeNonIndexable), $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refreshJobDetailPayload(string $slug, string $publicLocale): ?array
    {
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

    private function normalizePublicLocale(string $publicLocale): string
    {
        $normalized = strtolower(trim($publicLocale));

        return in_array($normalized, ['en', 'en-us'], true) ? 'en' : 'zh-CN';
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

    private function jobDetailCacheKey(string $slug, string $publicLocale): string
    {
        return sprintf(
            '%s:%s:%s',
            self::JOB_DETAIL_CACHE_KEY_PREFIX,
            strtolower(trim($slug)),
            $this->normalizePublicLocale($publicLocale)
        );
    }
}
