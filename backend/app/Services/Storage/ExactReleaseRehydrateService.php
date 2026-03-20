<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseExactManifest;
use App\Models\StorageBlobLocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class ExactReleaseRehydrateService
{
    private const LOCATION_KIND_REMOTE_COPY = 'remote_copy';

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(?int $exactManifestId, ?string $releaseId, string $disk, ?string $targetRoot = null): array
    {
        $manifest = $this->resolveExactManifest($exactManifestId, $releaseId);
        $files = $manifest->files()
            ->orderBy('logical_path')
            ->get();

        if ($files->isEmpty()) {
            throw new \RuntimeException('exact manifest has no file rows to rehydrate.');
        }

        $targetRoot = $this->normalizeAbsolutePath($targetRoot ?: storage_path('app/private/rehydrate_runs'));
        $totalBytes = 0;
        $missingLocations = 0;
        $plannedFiles = [];

        foreach ($files as $file) {
            $logicalPath = $this->normalizeRelativePath((string) $file->logical_path);
            $sizeBytes = max(0, (int) $file->size_bytes);
            $totalBytes += $sizeBytes;

            $location = $this->resolveVerifiedLocation((string) $file->blob_hash, $disk);
            if ($location === null) {
                $missingLocations++;
            }

            $plannedFiles[] = [
                'logical_path' => $logicalPath,
                'blob_hash' => strtolower(trim((string) $file->blob_hash)),
                'size_bytes' => $sizeBytes,
                'checksum' => $file->checksum,
                'content_type' => $file->content_type,
                'encoding' => $file->encoding,
                'remote_location' => $location === null ? null : [
                    'id' => (int) $location->id,
                    'disk' => (string) $location->disk,
                    'storage_path' => (string) $location->storage_path,
                    'verified_at' => optional($location->verified_at)->toAtomString(),
                    'meta_json' => $location->meta_json,
                ],
            ];
        }

        return [
            'schema' => (string) config('storage_rollout.rehydrate_plan_schema_version', 'storage_rehydrate_exact_release_plan.v1'),
            'generated_at' => now()->toAtomString(),
            'disk' => trim($disk),
            'target_root' => $targetRoot,
            'run_key' => hash('sha256', trim((string) $manifest->exact_identity_hash).'|'.trim($disk)),
            'exact_manifest' => [
                'id' => (int) $manifest->getKey(),
                'content_pack_release_id' => $manifest->content_pack_release_id,
                'exact_identity_hash' => (string) $manifest->exact_identity_hash,
                'pack_id' => $manifest->pack_id,
                'pack_version' => $manifest->pack_version,
                'source_kind' => (string) $manifest->source_kind,
                'source_disk' => (string) $manifest->source_disk,
                'source_storage_path' => (string) $manifest->source_storage_path,
                'manifest_hash' => (string) $manifest->manifest_hash,
            ],
            'summary' => [
                'file_count' => $files->count(),
                'total_bytes' => $totalBytes,
                'missing_locations' => $missingLocations,
            ],
            'files' => $plannedFiles,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $disk = trim((string) ($plan['disk'] ?? ''));
        $targetRoot = $this->normalizeAbsolutePath((string) ($plan['target_root'] ?? ''));
        $exactManifest = is_array($plan['exact_manifest'] ?? null) ? $plan['exact_manifest'] : [];
        $files = collect(is_array($plan['files'] ?? null) ? $plan['files'] : [])
            ->filter(static fn ($file): bool => is_array($file))
            ->values();

        if ($disk === '' || $targetRoot === '') {
            throw new \RuntimeException('invalid rehydrate plan: disk and target_root are required.');
        }

        if ((int) (($plan['summary']['missing_locations'] ?? 0)) > 0) {
            throw new \RuntimeException('cannot execute rehydrate plan with missing verified remote locations.');
        }

        if ($files->isEmpty()) {
            throw new \RuntimeException('cannot execute rehydrate plan without file rows.');
        }

        $runBase = $targetRoot.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
        $stagingDir = $runBase.DIRECTORY_SEPARATOR.'staging';
        $finalDir = $runBase.DIRECTORY_SEPARATOR.'final';
        File::ensureDirectoryExists($stagingDir);

        $rehydratedFiles = 0;
        $verifiedFiles = 0;
        $bytes = 0;

        try {
            foreach ($files as $file) {
                /** @var array<string,mixed> $file */
                $location = is_array($file['remote_location'] ?? null) ? $file['remote_location'] : null;
                if ($location === null) {
                    throw new \RuntimeException('missing verified remote location for '.$this->filePathLabel($file));
                }

                $remotePath = trim((string) ($location['storage_path'] ?? ''));
                if ($remotePath === '') {
                    throw new \RuntimeException('invalid remote location for '.$this->filePathLabel($file));
                }

                $remoteDisk = Storage::disk($disk);
                if (! $remoteDisk->exists($remotePath)) {
                    throw new \RuntimeException('remote object missing for '.$this->filePathLabel($file).': '.$remotePath);
                }

                $payload = $remoteDisk->get($remotePath);
                if (! is_string($payload)) {
                    throw new \RuntimeException('failed to read remote object for '.$this->filePathLabel($file));
                }

                $this->verifyPayload($payload, $file);

                $destination = $stagingDir.DIRECTORY_SEPARATOR.$this->normalizeRelativePath((string) $file['logical_path']);
                File::ensureDirectoryExists(dirname($destination));
                File::put($destination, $payload);

                $rehydratedFiles++;
                $verifiedFiles++;
                $bytes += strlen($payload);
            }

            $this->verifyTree($stagingDir, $files, trim((string) ($exactManifest['manifest_hash'] ?? '')));
            if (! File::moveDirectory($stagingDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize rehydrated run directory.');
            }

            $manifest = [
                'schema' => (string) config('storage_rollout.rehydrate_plan_schema_version', 'storage_rehydrate_exact_release_plan.v1'),
                'built_at' => now()->toAtomString(),
                'disk' => $disk,
                'exact_manifest_id' => (int) ($exactManifest['id'] ?? 0),
                'exact_identity_hash' => (string) ($exactManifest['exact_identity_hash'] ?? ''),
                'file_count' => $rehydratedFiles,
                'verified_files' => $verifiedFiles,
                'bytes' => $bytes,
            ];
            $encoded = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode rehydrate sentinel.');
            }
            File::put($finalDir.DIRECTORY_SEPARATOR.'.rehydrate.json', $encoded.PHP_EOL);

            return [
                'exact_manifest_id' => (int) ($exactManifest['id'] ?? 0),
                'disk' => $disk,
                'rehydrated_files' => $rehydratedFiles,
                'verified_files' => $verifiedFiles,
                'bytes' => $bytes,
                'run_dir' => $finalDir,
            ];
        } catch (\Throwable $e) {
            if (is_dir($runBase)) {
                File::deleteDirectory($runBase);
            }

            throw $e;
        }
    }

    private function resolveExactManifest(?int $exactManifestId, ?string $releaseId): ContentReleaseExactManifest
    {
        $releaseId = trim((string) $releaseId);

        if ($exactManifestId !== null) {
            $manifest = ContentReleaseExactManifest::query()
                ->whereKey($exactManifestId)
                ->first();

            if ($manifest === null) {
                throw new \RuntimeException('exact manifest not found: '.$exactManifestId);
            }

            if ($releaseId !== '' && trim((string) $manifest->content_pack_release_id) !== $releaseId) {
                throw new \RuntimeException('exact manifest does not belong to release '.$releaseId.'.');
            }

            return $manifest;
        }

        if ($releaseId === '') {
            throw new \RuntimeException('either exact manifest id or release id is required.');
        }

        $manifests = ContentReleaseExactManifest::query()
            ->where('content_pack_release_id', $releaseId)
            ->orderBy('id')
            ->get();

        if ($manifests->count() === 0) {
            throw new \RuntimeException('no exact manifest found for release '.$releaseId.'.');
        }

        if ($manifests->count() > 1) {
            throw new \RuntimeException('multiple exact manifests found for release '.$releaseId.'; pass --exact-manifest-id explicitly.');
        }

        /** @var ContentReleaseExactManifest $manifest */
        $manifest = $manifests->first();

        return $manifest;
    }

    private function resolveVerifiedLocation(string $blobHash, string $disk): ?StorageBlobLocation
    {
        return StorageBlobLocation::query()
            ->where('blob_hash', strtolower(trim($blobHash)))
            ->where('disk', trim($disk))
            ->where('location_kind', self::LOCATION_KIND_REMOTE_COPY)
            ->whereNotNull('verified_at')
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function verifyPayload(string $payload, array $file): void
    {
        $expectedHash = strtolower(trim((string) ($file['blob_hash'] ?? '')));
        if ($expectedHash === '' || hash('sha256', $payload) !== $expectedHash) {
            throw new \RuntimeException('blob hash mismatch for '.$this->filePathLabel($file));
        }

        $expectedSize = max(0, (int) ($file['size_bytes'] ?? 0));
        if (strlen($payload) !== $expectedSize) {
            throw new \RuntimeException('size mismatch for '.$this->filePathLabel($file));
        }

        $checksum = trim((string) ($file['checksum'] ?? ''));
        if ($checksum !== '' && str_starts_with($checksum, 'sha256:')) {
            $expectedChecksum = strtolower(substr($checksum, strlen('sha256:')));
            if ($expectedChecksum !== '' && hash('sha256', $payload) !== $expectedChecksum) {
                throw new \RuntimeException('checksum mismatch for '.$this->filePathLabel($file));
            }
        }
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $files
     */
    private function verifyTree(string $stagingDir, Collection $files, string $manifestHash): void
    {
        $actualPaths = collect(File::allFiles($stagingDir))
            ->map(fn (\SplFileInfo $file): string => str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($stagingDir)), '/\\')))
            ->sort()
            ->values()
            ->all();

        $expectedPaths = $files
            ->map(fn (array $file): string => $this->normalizeRelativePath((string) $file['logical_path']))
            ->sort()
            ->values()
            ->all();

        if ($actualPaths !== $expectedPaths) {
            throw new \RuntimeException('rehydrated logical_path set does not match exact authority.');
        }

        foreach ($files as $file) {
            $path = $stagingDir.DIRECTORY_SEPARATOR.$this->normalizeRelativePath((string) $file['logical_path']);
            if (! is_file($path)) {
                throw new \RuntimeException('rehydrated file missing after write: '.$this->filePathLabel($file));
            }

            $payload = (string) File::get($path);
            $this->verifyPayload($payload, $file);
        }

        if ($manifestHash !== '') {
            $manifestPath = $stagingDir.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'manifest.json';
            if (! is_file($manifestPath)) {
                throw new \RuntimeException('rehydrated manifest.json is missing.');
            }

            if (hash_file('sha256', $manifestPath) !== strtolower($manifestHash)) {
                throw new \RuntimeException('rehydrated manifest hash does not match exact authority.');
            }
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            throw new \RuntimeException('logical_path cannot be empty.');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $normalized) === 1) {
            throw new \RuntimeException('logical_path must be relative to the rehydrate run directory.');
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            throw new \RuntimeException('logical_path cannot be empty.');
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \RuntimeException('logical_path contains forbidden traversal segments: '.$path);
            }
        }

        return implode('/', $segments);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        return str_replace('\\', '/', rtrim(trim($path), '/\\'));
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function filePathLabel(array $file): string
    {
        return (string) ($file['logical_path'] ?? '[unknown logical_path]');
    }
}
