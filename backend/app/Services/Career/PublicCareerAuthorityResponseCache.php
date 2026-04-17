<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerLaunchGovernanceClosureService;
use App\Http\Resources\Career\CareerDatasetHubResource;
use App\Http\Resources\Career\CareerDatasetMethodResource;
use App\Services\Career\Dataset\CareerPublicDatasetContractBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class PublicCareerAuthorityResponseCache
{
    public const DATASET_HUB_CACHE_KEY = 'career:public-authority:dataset-hub:v1';

    public const DATASET_METHOD_CACHE_KEY = 'career:public-authority:dataset-method:v1';

    public const LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY = 'career:public-authority:launch-governance-closure:v1';

    public function __construct(
        private readonly CareerPublicDatasetContractBuilder $datasetContractBuilder,
        private readonly CareerLaunchGovernanceClosureService $launchGovernanceClosureService,
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
     * @return array<string, array{cache_key: string, member_count?: int, status: string}>
     */
    public function warm(): array
    {
        $datasetHub = $this->refreshDatasetHubPayload();
        $datasetMethod = $this->refreshDatasetMethodPayload();
        $launchGovernance = $this->refreshLaunchGovernanceClosurePayload();

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
            'launch_governance_closure' => [
                'cache_key' => self::LAUNCH_GOVERNANCE_CLOSURE_CACHE_KEY,
                'status' => 'cached',
                'member_count' => count((array) data_get($launchGovernance, 'members', [])),
            ],
        ];
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
}
