<?php

namespace App\Services\Assessment;

use App\Services\Assessment\Drivers\DriverInterface;
use App\Services\Assessment\Drivers\GenericLikertDriver;
use App\Services\Assessment\Drivers\IqTestDriver;
use App\Services\Assessment\Drivers\MbtiDriver;
use App\Services\Assessment\Drivers\SimpleScoreDriver;
use App\Services\Content\ContentPacksIndex;
use App\Services\Scale\ScaleRegistry;
use Illuminate\Support\Facades\File;

class AssessmentEngine
{
    public function __construct(
        private ContentPacksIndex $packsIndex,
        private ScaleRegistry $scaleRegistry,
        private MbtiDriver $mbtiDriver,
        private SimpleScoreDriver $simpleScoreDriver,
        private GenericLikertDriver $genericLikertDriver,
        private IqTestDriver $iqTestDriver,
    ) {
    }

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
        if (!$scaleRow) {
            return $this->error('SCALE_NOT_FOUND', 'scale not found.');
        }

        $driverType = strtolower((string) ($scaleRow['driver_type'] ?? ''));
        if ($driverType === '') {
            return $this->error('DRIVER_NOT_CONFIGURED', 'driver_type missing for scale.');
        }

        $pack = $this->resolvePack($packId, $dirVersion);
        if (!($pack['ok'] ?? false)) {
            return $this->error('PACK_NOT_FOUND', 'content pack not found.');
        }

        $scoringSpec = $this->readJson($pack['base_dir'] ?? '', 'scoring_spec.json');
        if (!is_array($scoringSpec)) {
            return $this->error('SCORING_SPEC_NOT_FOUND', 'scoring_spec.json not found or invalid.');
        }

        $scoringSpecVersion = (string) ($scoringSpec['version'] ?? ($scoringSpec['scoring_spec_version'] ?? ''));
        $contentPackageVersion = (string) ($pack['content_package_version'] ?? '');

        $ctxMerged = array_merge($ctx, [
            'org_id' => $orgId,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'scoring_spec_version' => $scoringSpecVersion,
        ]);

        if ($driverType === 'mbti') {
            $questions = $this->readJson($pack['base_dir'] ?? '', 'questions.json');
            if (!is_array($questions)) {
                return $this->error('QUESTIONS_NOT_FOUND', 'questions.json not found or invalid.');
            }
            $ctxMerged['questions'] = $questions;
        }

        $driver = $this->resolveDriver($driverType);
        if (!$driver) {
            return $this->error('DRIVER_NOT_SUPPORTED', "unsupported driver_type={$driverType}");
        }

        try {
            $result = $driver->score($answers, $scoringSpec, $ctxMerged);
        } catch (\Throwable $e) {
            return $this->error('SCORING_FAILED', $e->getMessage());
        }

        return [
            'ok' => true,
            'result' => $result,
            'driver_type' => $driverType,
            'pack' => [
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion,
            ],
            'scoring_spec_version' => $scoringSpecVersion,
        ];
    }

    private function resolvePack(string $packId, string $dirVersion): array
    {
        $found = $this->packsIndex->find($packId, $dirVersion);
        if (!($found['ok'] ?? false)) {
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

    private function readJson(string $baseDir, string $filename): ?array
    {
        if ($baseDir === '') {
            return null;
        }

        $path = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        if (!File::exists($path) || !File::isFile($path)) {
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
        return match ($driverType) {
            'mbti' => $this->mbtiDriver,
            'simple_score' => $this->simpleScoreDriver,
            'generic_likert', 'likert' => $this->genericLikertDriver,
            'iq_test' => $this->iqTestDriver,
            default => null,
        };
    }

    private function error(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }
}
