<?php

namespace App\Services\Assessment;

use App\Services\Assessment\Drivers\DriverInterface;
use App\Services\Content\ContentPacksIndex;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AssessmentEngine
{
    public function __construct(
        private ContentPacksIndex $packsIndex,
        private ScaleRegistry $scaleRegistry,
        private ScoringModelRouter $modelRouter,
    ) {}

    public function score(array $attempt, array $answers, array $ctx = []): array
    {
        $orgId = (int) ($attempt['org_id'] ?? 0);
        $scaleCode = strtoupper(trim((string) ($attempt['scale_code'] ?? '')));
        if ($scaleCode === '') {
            return $this->error('SCALE_REQUIRED', 'scale_code is required.');
        }

        $packId = trim((string) ($attempt['pack_id'] ?? ''));
        $dirVersion = trim((string) ($attempt['dir_version'] ?? ''));
        if ($packId === '' || $dirVersion === '') {
            return $this->error('PACK_REQUIRED', 'pack_id and dir_version are required.');
        }

        $scaleRow = $this->scaleRegistry->getByCode($scaleCode, $orgId);
        if (! $scaleRow) {
            return $this->error('SCALE_NOT_FOUND', 'scale not found.');
        }

        $driverType = strtolower((string) ($scaleRow['assessment_driver'] ?? ''));
        if ($driverType === '') {
            $driverType = strtolower((string) ($scaleRow['driver_type'] ?? ''));
        }
        if ($driverType === '') {
            $driverType = 'generic_scoring';
        }

        $defaultSpecVersion = trim((string) ($ctx['scoring_spec_version'] ?? ''));
        if ($defaultSpecVersion === '') {
            $defaultSpecVersion = $this->defaultSpecVersionFor($scaleCode, $driverType);
        }
        $modelRouterEnabled = (bool) config('fap.features.model_router_v2', true);
        $modelSelection = $modelRouterEnabled
            ? $this->modelRouter->select(
                $orgId,
                $scaleCode,
                $driverType,
                $defaultSpecVersion,
                $ctx
            )
            : $this->disabledModelSelection($driverType, $defaultSpecVersion, $ctx);
        $selectedDriverType = strtolower(trim((string) ($modelSelection['driver_type'] ?? $driverType)));
        if ($selectedDriverType === '') {
            $selectedDriverType = $driverType;
        }

        if (
            $scaleCode === 'BIG5_OCEAN'
            || $this->isSpecialDriverType($selectedDriverType)
            || $scaleCode === 'CLINICAL_COMBO_68'
            || $scaleCode === 'SDS_20'
            || $scaleCode === 'EQ_60'
        ) {
            $driver = $this->resolveDriver($selectedDriverType);
            if (! $driver) {
                return $this->error('UNSUPPORTED_DRIVER', "unsupported driver_type={$selectedDriverType}");
            }

            $scoringSpecVersion = trim((string) ($modelSelection['scoring_spec_version'] ?? ''));
            if ($scoringSpecVersion === '') {
                $scoringSpecVersion = $this->defaultSpecVersionFor($scaleCode, $selectedDriverType);
            }

            $ctxMerged = array_merge($ctx, [
                'org_id' => $orgId,
                'scale_code' => $scaleCode,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $dirVersion,
                'scoring_spec_version' => $scoringSpecVersion,
                'model_selection' => $modelSelection,
                'base_dir' => '',
                'scoring_spec' => [],
            ]);

            try {
                $result = $driver->score($answers, [], $ctxMerged);
            } catch (\Throwable $e) {
                if ($e instanceof \InvalidArgumentException) {
                    return $this->error('SCORING_INPUT_INVALID', $e->getMessage());
                }

                return $this->error('SCORING_FAILED', 'scoring failed.');
            }

            return [
                'ok' => true,
                'result' => $result,
                'driver_type' => $selectedDriverType,
                'pack' => [
                    'pack_id' => $packId,
                    'dir_version' => $dirVersion,
                    'content_package_version' => $dirVersion,
                ],
                'scoring_spec_version' => $scoringSpecVersion,
                'model_selection' => $modelSelection,
            ];
        }

        $pack = $this->resolvePack($packId, $dirVersion);
        if (! ($pack['ok'] ?? false)) {
            return $this->error('PACK_NOT_FOUND', 'content pack not found.');
        }

        $scoringSpec = $this->readJsonCached(
            $packId,
            $dirVersion,
            (string) ($pack['base_dir'] ?? ''),
            'scoring_spec.json'
        );
        if (! is_array($scoringSpec)) {
            return $this->error('SCORING_SPEC_NOT_FOUND', 'scoring_spec.json not found or invalid.');
        }

        $scoringSpecVersion = (string) ($scoringSpec['version'] ?? ($scoringSpec['scoring_spec_version'] ?? ''));
        $selectedSpecVersion = trim((string) ($modelSelection['scoring_spec_version'] ?? ''));
        if ($selectedSpecVersion !== '') {
            $scoringSpecVersion = $selectedSpecVersion;
        }
        $contentPackageVersion = (string) ($pack['content_package_version'] ?? '');

        $ctxMerged = array_merge($ctx, [
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'model_selection' => $modelSelection,
            'base_dir' => (string) ($pack['base_dir'] ?? ''),
            'scoring_spec' => $scoringSpec,
        ]);

        $questions = $this->readJsonCached(
            $packId,
            $dirVersion,
            (string) ($pack['base_dir'] ?? ''),
            'questions.json'
        );
        if (is_array($questions)) {
            $ctxMerged['questions'] = $questions;
        }

        $driver = $this->resolveDriver($selectedDriverType);
        if (! $driver) {
            return $this->error('UNSUPPORTED_DRIVER', "unsupported driver_type={$selectedDriverType}");
        }

        try {
            $result = $driver->score($answers, $scoringSpec, $ctxMerged);
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                return $this->error('SCORING_INPUT_INVALID', $e->getMessage());
            }

            return $this->error('SCORING_FAILED', 'scoring failed.');
        }

        return [
            'ok' => true,
            'result' => $result,
            'driver_type' => $selectedDriverType,
            'pack' => [
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion,
            ],
            'scoring_spec_version' => $scoringSpecVersion,
            'model_selection' => $modelSelection,
        ];
    }

    private function defaultSpecVersionFor(string $scaleCode, string $driverType): string
    {
        $driverType = strtolower(trim($driverType));
        $scaleCode = strtoupper(trim($scaleCode));

        $isClinical = $scaleCode === 'CLINICAL_COMBO_68' || $driverType === 'clinical_combo_68';
        if ($isClinical) {
            return 'v1.0_2026';
        }

        $isSds20 = $scaleCode === 'SDS_20' || $driverType === 'sds_20';
        if ($isSds20) {
            return 'v2.0_Factor_Logic';
        }

        $isEq60 = $scaleCode === 'EQ_60' || $driverType === 'eq_60' || $driverType === 'eq_test';
        if ($isEq60) {
            return 'eq60_spec_2026_v2';
        }

        return 'big5_spec_2026Q1_v1';
    }

    private function isSpecialDriverType(string $driverType): bool
    {
        return in_array(
            strtolower(trim($driverType)),
            ['big5_ocean', 'clinical_combo_68', 'sds_20', 'eq_60', 'eq_test'],
            true
        );
    }

    /**
     * @return array{
     *   model_key:string,
     *   driver_type:string,
     *   scoring_spec_version:string,
     *   source:string,
     *   experiment_key:?string,
     *   experiment_variant:?string,
     *   rollout_id:?string,
     *   model_id:?string,
     *   experiments_json:array<string,string>
     * }
     */
    private function disabledModelSelection(string $driverType, string $scoringSpecVersion, array $ctx): array
    {
        $experiments = [];
        $rawExperiments = $ctx['experiments_json'] ?? null;
        if (is_array($rawExperiments)) {
            foreach ($rawExperiments as $key => $variant) {
                $experimentKey = trim((string) $key);
                $experimentVariant = trim((string) $variant);
                if ($experimentKey === '' || $experimentVariant === '') {
                    continue;
                }
                $experiments[$experimentKey] = $experimentVariant;
            }
        }

        return [
            'model_key' => 'default',
            'driver_type' => strtolower(trim($driverType)),
            'scoring_spec_version' => trim($scoringSpecVersion),
            'source' => 'disabled',
            'experiment_key' => null,
            'experiment_variant' => null,
            'rollout_id' => null,
            'model_id' => null,
            'experiments_json' => $experiments,
        ];
    }

    private function resolvePack(string $packId, string $dirVersion): array
    {
        $found = $this->packsIndex->find($packId, $dirVersion);
        if (! ($found['ok'] ?? false)) {
            return ['ok' => false];
        }

        $item = $found['item'] ?? [];
        $manifestPath = (string) ($item['manifest_path'] ?? '');
        if ($manifestPath === '') {
            return ['ok' => false];
        }

        $baseDir = dirname($manifestPath);

        return [
            'ok' => true,
            'base_dir' => $baseDir,
            'content_package_version' => (string) ($item['content_package_version'] ?? ''),
        ];
    }

    private function readJsonCached(string $packId, string $dirVersion, string $baseDir, string $filename): ?array
    {
        $key = sprintf('fap:pack_json:%s:%s:%s', $packId, $dirVersion, $filename);

        return Cache::remember($key, 3600, function () use ($baseDir, $filename): ?array {
            return $this->readJson($baseDir, $filename);
        });
    }

    private function readJson(string $baseDir, string $filename): ?array
    {
        if ($baseDir === '') {
            return null;
        }

        $path = rtrim($baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (! File::exists($path) || ! File::isFile($path)) {
            return null;
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolveDriver(string $driverType): ?DriverInterface
    {
        $driverType = strtolower(trim($driverType));
        if ($driverType === '') {
            return null;
        }

        $map = config('fap.assessment_drivers', []);
        if (! is_array($map)) {
            $map = [];
        }

        $class = $map[$driverType] ?? null;
        if (! is_string($class) || $class === '') {
            return null;
        }

        try {
            $driver = app($class);
        } catch (\Throwable $e) {
            return null;
        }

        return $driver instanceof DriverInterface ? $driver : null;
    }

    private function error(string $code, string $message, ?array $result = null): array
    {
        $payload = [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];

        if (is_array($result)) {
            $payload['result'] = $result;
        }

        return $payload;
    }
}
