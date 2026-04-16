<?php

declare(strict_types=1);

namespace App\Domain\Career\Production;

use App\Domain\Career\Publish\CareerFirstWaveIndexPolicyEngine;
use App\Domain\Career\Publish\CareerFirstWaveLaunchManifestService;
use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanService;
use App\DTO\Career\CareerAssetBatchManifest;
use Throwable;

final class CareerAssetBatchRegressionRunner
{
    public function __construct(
        private readonly CareerFirstWaveLaunchManifestService $launchManifestService,
        private readonly CareerFirstWaveRolloutWavePlanService $rolloutWavePlanService,
        private readonly CareerFirstWaveIndexPolicyEngine $indexPolicyEngine,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $truthBySlug
     * @return array<string, mixed>
     */
    public function run(CareerAssetBatchManifest $manifest, array $truthBySlug): array
    {
        $checks = [];

        try {
            $launchManifest = $this->launchManifestService->build()->toArray();
            $launchTotal = (int) data_get($launchManifest, 'counts.total', 0);
            $checks[] = [
                'check' => 'first_wave_launch_manifest_baseline',
                'passed' => $launchTotal === 10,
                'details' => [
                    'first_wave_total' => $launchTotal,
                ],
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'check' => 'first_wave_launch_manifest_baseline',
                'passed' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }

        try {
            $rolloutPlan = $this->rolloutWavePlanService->build()->toArray();
            $checks[] = [
                'check' => 'rollout_wave_plan_compatibility',
                'passed' => trim((string) ($rolloutPlan['scope'] ?? '')) !== '',
                'details' => [
                    'scope' => (string) ($rolloutPlan['scope'] ?? ''),
                ],
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'check' => 'rollout_wave_plan_compatibility',
                'passed' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }

        try {
            $policy = $this->indexPolicyEngine->build(array_values($truthBySlug), $manifest->scope)->toArray();
            $checks[] = [
                'check' => 'index_policy_engine_compatibility',
                'passed' => is_array($policy['members'] ?? null),
                'details' => [
                    'members' => count((array) ($policy['members'] ?? [])),
                ],
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'check' => 'index_policy_engine_compatibility',
                'passed' => false,
                'details' => ['error' => $e->getMessage()],
            ];
        }

        $passed = collect($checks)->every(static fn (array $check): bool => (bool) ($check['passed'] ?? false));

        return [
            'stage' => 'regression',
            'passed' => $passed,
            'checks' => $checks,
        ];
    }
}
