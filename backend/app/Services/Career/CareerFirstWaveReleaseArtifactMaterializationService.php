<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveReleaseArtifactProjectionService;
use Illuminate\Support\Facades\File;

class CareerFirstWaveReleaseArtifactMaterializationService
{
    public const OUTPUT_ROOT = 'app/private/career_release_artifacts';

    public const LAUNCH_MANIFEST_FILENAME = 'career-launch-manifest.json';

    public const SMOKE_MATRIX_FILENAME = 'career-smoke-matrix.json';

    public function __construct(
        private readonly CareerFirstWaveReleaseArtifactProjectionService $projectionService,
    ) {}

    /**
     * @return array{
     *   status:string,
     *   output_dir:string,
     *   artifacts:array{
     *     career-launch-manifest.json:string,
     *     career-smoke-matrix.json:string
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
            throw new \RuntimeException('release artifact output dir already exists: '.$finalDir);
        }
        if (is_dir($tmpDir)) {
            throw new \RuntimeException('release artifact temp dir already exists: '.$tmpDir);
        }

        $projected = $this->projectedArtifacts();
        $launchManifestPayload = $this->extractArtifactPayload($projected, self::LAUNCH_MANIFEST_FILENAME);
        $smokeMatrixPayload = $this->extractArtifactPayload($projected, self::SMOKE_MATRIX_FILENAME);

        $this->validateArtifactPayloads($launchManifestPayload, $smokeMatrixPayload);

        File::ensureDirectoryExists($tmpDir);

        try {
            $this->writeJsonFile($tmpDir.DIRECTORY_SEPARATOR.self::LAUNCH_MANIFEST_FILENAME, $launchManifestPayload);
            $this->writeJsonFile($tmpDir.DIRECTORY_SEPARATOR.self::SMOKE_MATRIX_FILENAME, $smokeMatrixPayload);

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize release artifact directory: '.$finalDir);
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
                self::LAUNCH_MANIFEST_FILENAME => $finalDir.DIRECTORY_SEPARATOR.self::LAUNCH_MANIFEST_FILENAME,
                self::SMOKE_MATRIX_FILENAME => $finalDir.DIRECTORY_SEPARATOR.self::SMOKE_MATRIX_FILENAME,
            ],
        ];
    }

    /**
     * @return array<string, object>
     */
    protected function projectedArtifacts(): array
    {
        return $this->projectionService->build();
    }

    /**
     * @param  array<string, object>  $projected
     * @return array<string, mixed>
     */
    private function extractArtifactPayload(array $projected, string $filename): array
    {
        $artifact = $projected[$filename] ?? null;
        if (! is_object($artifact) || ! method_exists($artifact, 'toArray')) {
            throw new \RuntimeException('invalid projected artifact for '.$filename);
        }

        /** @var mixed $payload */
        $payload = $artifact->toArray();
        if (! is_array($payload)) {
            throw new \RuntimeException('projected artifact payload is not an array for '.$filename);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $launchManifestPayload
     * @param  array<string, mixed>  $smokeMatrixPayload
     */
    private function validateArtifactPayloads(array $launchManifestPayload, array $smokeMatrixPayload): void
    {
        $launchEncoded = json_encode($launchManifestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $smokeEncoded = json_encode($smokeMatrixPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (! is_string($launchEncoded)) {
            throw new \RuntimeException('failed to encode launch manifest artifact json');
        }
        if (! is_string($smokeEncoded)) {
            throw new \RuntimeException('failed to encode smoke matrix artifact json');
        }

        $launchDecoded = json_decode($launchEncoded, true);
        $smokeDecoded = json_decode($smokeEncoded, true);
        if (! is_array($launchDecoded)) {
            throw new \RuntimeException('launch manifest artifact json round-trip decode failed');
        }
        if (! is_array($smokeDecoded)) {
            throw new \RuntimeException('smoke matrix artifact json round-trip decode failed');
        }

        $launchMemberSlugs = collect((array) ($launchDecoded['members'] ?? []))
            ->map(static fn (array $row): string => trim((string) ($row['canonical_slug'] ?? '')))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->values()
            ->all();
        $smokeMemberSlugs = collect((array) ($smokeDecoded['members'] ?? []))
            ->map(static fn (array $row): string => trim((string) ($row['canonical_slug'] ?? '')))
            ->filter(static fn (string $slug): bool => $slug !== '')
            ->values()
            ->all();

        $launchUniqueSlugs = array_values(array_unique($launchMemberSlugs));
        $smokeUniqueSlugs = array_values(array_unique($smokeMemberSlugs));
        if (count($launchUniqueSlugs) !== count($launchMemberSlugs)) {
            throw new \RuntimeException('launch manifest contains duplicate member slugs');
        }
        if (count($smokeUniqueSlugs) !== count($smokeMemberSlugs)) {
            throw new \RuntimeException('smoke matrix contains duplicate member slugs');
        }

        sort($launchUniqueSlugs);
        sort($smokeUniqueSlugs);
        if ($launchUniqueSlugs !== $smokeUniqueSlugs) {
            throw new \RuntimeException('launch/smoke member slug sets are inconsistent');
        }

        foreach ((array) ($launchDecoded['members'] ?? []) as $member) {
            if (! is_array($member)) {
                continue;
            }

            foreach (['evidence_refs', 'blockers', 'demand_signal', 'novelty_score', 'canonical_conflict'] as $forbidden) {
                if (array_key_exists($forbidden, $member)) {
                    throw new \RuntimeException('launch manifest contains forbidden field: '.$forbidden);
                }
            }
        }

        foreach ((array) ($smokeDecoded['members'] ?? []) as $member) {
            if (! is_array($member)) {
                continue;
            }

            $smoke = $member['smoke_matrix'] ?? null;
            if (! is_array($smoke)) {
                continue;
            }

            foreach (['frontend_smoke_passed', 'jsonld_emitted', 'attribution_fired', 'render_success'] as $forbidden) {
                if (array_key_exists($forbidden, $smoke)) {
                    throw new \RuntimeException('smoke matrix contains forbidden field: '.$forbidden);
                }
            }
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
            throw new \RuntimeException('invalid timestamp segment for release artifact export');
        }

        return $value;
    }
}
