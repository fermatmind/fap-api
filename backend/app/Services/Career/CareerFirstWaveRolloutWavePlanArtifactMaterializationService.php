<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutWavePlanArtifactProjectionService;
use Illuminate\Support\Facades\File;

class CareerFirstWaveRolloutWavePlanArtifactMaterializationService
{
    public const OUTPUT_ROOT = 'app/private/career_rollout_wave_plan_artifacts';

    public const ARTIFACT_FILENAME = 'career-rollout-wave-plan.json';

    public function __construct(
        private readonly CareerFirstWaveRolloutWavePlanArtifactProjectionService $projectionService,
    ) {}

    /**
     * @return array{
     *   status:string,
     *   output_dir:string,
     *   artifacts:array{
     *     career-rollout-wave-plan.json:string
     *   }
     * }
     */
    public function materialize(?string $timestamp = null): array
    {
        $normalizedTimestamp = $this->normalizeTimestamp($timestamp);
        $rootDir = storage_path(self::OUTPUT_ROOT);
        $finalDir = $rootDir.DIRECTORY_SEPARATOR.$normalizedTimestamp;
        $tmpDir = $finalDir.'.tmp';

        if (is_dir($finalDir)) {
            throw new \RuntimeException('rollout wave-plan artifact output dir already exists: '.$finalDir);
        }
        if (is_dir($tmpDir)) {
            throw new \RuntimeException('rollout wave-plan artifact temp dir already exists: '.$tmpDir);
        }

        $payload = $this->extractArtifactPayload($this->projectedArtifact());
        $this->validateArtifactPayload($payload);

        File::ensureDirectoryExists($tmpDir);

        try {
            $this->writeJsonFile($tmpDir.DIRECTORY_SEPARATOR.self::ARTIFACT_FILENAME, $payload);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize rollout wave-plan artifact directory: '.$finalDir);
            }
        } catch (\Throwable $e) {
            if (is_dir($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }

            throw $e;
        }

        return [
            'status' => 'materialized',
            'output_dir' => $finalDir,
            'artifacts' => [
                self::ARTIFACT_FILENAME => $finalDir.DIRECTORY_SEPARATOR.self::ARTIFACT_FILENAME,
            ],
        ];
    }

    protected function projectedArtifact(): object
    {
        return $this->projectionService->build();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractArtifactPayload(object $artifact): array
    {
        if (! method_exists($artifact, 'toArray')) {
            throw new \RuntimeException('invalid projected rollout wave-plan artifact');
        }

        /** @var mixed $payload */
        $payload = $artifact->toArray();
        if (! is_array($payload)) {
            throw new \RuntimeException('projected rollout wave-plan artifact payload is not an array');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateArtifactPayload(array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode rollout wave-plan artifact json');
        }

        $decoded = json_decode($encoded, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('rollout wave-plan artifact json round-trip decode failed');
        }

        if (($decoded['artifact_kind'] ?? null) !== 'career_rollout_wave_plan') {
            throw new \RuntimeException('invalid artifact_kind for rollout wave-plan artifact');
        }

        $slugs = collect((array) ($decoded['members'] ?? []))
            ->map(static fn (array $row): string => trim((string) ($row['canonical_slug'] ?? '')))
            ->all();

        if (in_array('', $slugs, true)) {
            throw new \RuntimeException('rollout wave-plan artifact contains empty canonical_slug');
        }

        if (count(array_unique($slugs)) !== count($slugs)) {
            throw new \RuntimeException('rollout wave-plan artifact contains duplicate canonical_slug values');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJsonFile(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode json: '.$path);
        }

        File::put($path, $encoded.PHP_EOL);
    }

    private function normalizeTimestamp(?string $timestamp): string
    {
        $value = trim((string) $timestamp);
        if ($value === '') {
            $value = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \RuntimeException('invalid timestamp segment for rollout wave-plan artifact export');
        }

        return $value;
    }
}
