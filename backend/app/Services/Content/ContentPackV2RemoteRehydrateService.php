<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentReleaseExactManifest;
use App\Services\Storage\ExactReleaseRehydrateService;
use App\Services\Storage\ReleaseStorageLocator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ContentPackV2RemoteRehydrateService
{
    private const ALLOWED_SOURCE_KINDS = [
        'v2.primary',
        'v2.mirror',
    ];

    public function __construct(
        private readonly ContentPackV2Materializer $materializer,
        private readonly ExactReleaseRehydrateService $rehydrateService,
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {}

    public function materializeFromRemote(object $release, string $disk): string
    {
        $disk = trim($disk);
        if ($disk === '') {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_DISK_REQUIRED');
        }

        $exactManifest = $this->resolveExactManifest($release);
        $plan = $this->rehydrateService->buildPlan((int) $exactManifest->getKey(), null, $disk);
        if ((int) data_get($plan, 'summary.missing_locations', 0) > 0) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_REMOTE_COVERAGE_INCOMPLETE');
        }

        $targetCompiledDir = $this->materializer->targetCompiledDir($release);
        $targetRoot = dirname($targetCompiledDir);
        $sentinelPath = $targetRoot.'/.materialization.json';

        if ($this->isFresh($release, $targetCompiledDir, $sentinelPath)) {
            return $targetCompiledDir;
        }

        /** @var Collection<int,array<string,mixed>> $files */
        $files = collect(is_array($plan['files'] ?? null) ? $plan['files'] : [])
            ->filter(static fn ($file): bool => is_array($file))
            ->values();
        if ($files->isEmpty()) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_PLAN_EMPTY');
        }

        $stagingRoot = $targetRoot.'-remote-staging-'.substr(bin2hex(random_bytes(4)), 0, 8);
        File::deleteDirectory($stagingRoot);
        File::ensureDirectoryExists($stagingRoot);

        try {
            foreach ($files as $file) {
                $location = is_array($file['remote_location'] ?? null) ? $file['remote_location'] : null;
                if ($location === null) {
                    throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_MISSING_REMOTE_LOCATION');
                }

                $remotePath = trim((string) ($location['storage_path'] ?? ''));
                if ($remotePath === '') {
                    throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_INVALID_REMOTE_PATH');
                }

                $remoteDisk = Storage::disk($disk);
                if (! $remoteDisk->exists($remotePath)) {
                    throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_REMOTE_OBJECT_MISSING');
                }

                $payload = $remoteDisk->get($remotePath);
                if (! is_string($payload)) {
                    throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_REMOTE_READ_FAILED');
                }

                $this->verifyPayload($payload, $file);

                $destination = $stagingRoot.DIRECTORY_SEPARATOR.$this->normalizeRelativePath((string) ($file['logical_path'] ?? ''));
                File::ensureDirectoryExists(dirname($destination));
                File::put($destination, $payload);
            }

            $this->verifyTree(
                $stagingRoot,
                $files,
                strtolower(trim((string) data_get($plan, 'exact_manifest.manifest_hash', '')))
            );

            $this->writeSentinel($stagingRoot.'/.materialization.json', $release, $exactManifest);

            if (File::isDirectory($targetRoot)) {
                File::deleteDirectory($targetRoot);
            }

            File::ensureDirectoryExists(dirname($targetRoot));
            if (! File::moveDirectory($stagingRoot, $targetRoot)) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_FINALIZE_FAILED');
            }

            if (! is_file($targetCompiledDir.'/manifest.json')) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_MANIFEST_MISSING');
            }

            return $targetCompiledDir;
        } catch (\Throwable $e) {
            if (File::isDirectory($stagingRoot)) {
                File::deleteDirectory($stagingRoot);
            }

            throw $e;
        }
    }

    /**
     * @return array{
     *   available:bool,
     *   exact_manifest_id:int,
     *   exact_identity_hash:string,
     *   source_kind:string,
     *   manifest_hash:string,
     *   reason:?string
     * }
     */
    public function probeRemoteFallback(object $release, string $disk): array
    {
        $disk = trim($disk);
        if ($disk === '') {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_DISK_REQUIRED');
        }

        $exactManifest = $this->resolveExactManifest($release);
        $plan = $this->rehydrateService->buildPlan((int) $exactManifest->getKey(), null, $disk);
        if ((int) data_get($plan, 'summary.missing_locations', 0) > 0) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_REMOTE_COVERAGE_INCOMPLETE');
        }

        /** @var Collection<int,array<string,mixed>> $files */
        $files = collect(is_array($plan['files'] ?? null) ? $plan['files'] : [])
            ->filter(static fn ($file): bool => is_array($file))
            ->values();
        if ($files->isEmpty()) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_PLAN_EMPTY');
        }

        return [
            'available' => true,
            'exact_manifest_id' => (int) $exactManifest->getKey(),
            'exact_identity_hash' => (string) $exactManifest->exact_identity_hash,
            'source_kind' => (string) $exactManifest->source_kind,
            'manifest_hash' => strtolower(trim((string) $exactManifest->manifest_hash)),
            'reason' => null,
        ];
    }

    private function resolveExactManifest(object $release): ContentReleaseExactManifest
    {
        $releaseId = trim((string) ($release->id ?? ''));
        if ($releaseId === '') {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_RELEASE_ID_REQUIRED');
        }

        $manifestHash = strtolower(trim((string) ($release->manifest_hash ?? '')));
        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_STORAGE_PATH_REQUIRED');
        }

        $query = ContentReleaseExactManifest::query()
            ->where('content_pack_release_id', $releaseId)
            ->whereIn('source_kind', self::ALLOWED_SOURCE_KINDS)
            ->orderBy('id');
        if ($manifestHash !== '') {
            $query->where('manifest_hash', $manifestHash);
        }

        $manifests = $query->get();
        if ($manifests->isEmpty()) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_EXACT_MANIFEST_NOT_FOUND');
        }

        $candidateRoots = $this->releaseStorageLocator->candidateRootsFromStoragePath($storagePath);
        foreach ($candidateRoots as $candidateRoot) {
            $matches = $manifests->filter(
                fn (ContentReleaseExactManifest $manifest): bool => $this->normalizeRoot((string) $manifest->source_storage_path) === $this->normalizeRoot($candidateRoot)
            )->values();

            if ($matches->count() === 1) {
                /** @var ContentReleaseExactManifest $selected */
                $selected = $matches->first();

                return $selected;
            }

            if ($matches->count() > 1) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_EXACT_MANIFEST_AMBIGUOUS');
            }
        }

        if ($manifests->count() === 1) {
            /** @var ContentReleaseExactManifest $selected */
            $selected = $manifests->first();

            return $selected;
        }

        throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_EXACT_MANIFEST_AMBIGUOUS');
    }

    private function isFresh(object $release, string $targetCompiledDir, string $sentinelPath): bool
    {
        if (! is_file($targetCompiledDir.'/manifest.json') || ! is_file($sentinelPath)) {
            return false;
        }

        $decoded = json_decode((string) File::get($sentinelPath), true);
        if (! is_array($decoded)) {
            return false;
        }

        return (string) ($decoded['storage_path'] ?? '') === $this->storagePath($release)
            && (string) ($decoded['manifest_hash'] ?? '') === $this->manifestHash($release);
    }

    private function writeSentinel(string $sentinelPath, object $release, ContentReleaseExactManifest $manifest): void
    {
        $encoded = json_encode([
            'release_id' => trim((string) ($release->id ?? '')),
            'storage_path' => $this->storagePath($release),
            'manifest_hash' => $this->manifestHash($release),
            'source_compiled_dir' => 'remote_rehydrate://exact_manifest/'.(int) $manifest->getKey(),
            'materialized_at' => now()->toIso8601String(),
            'remote_fallback' => true,
            'exact_manifest_id' => (int) $manifest->getKey(),
            'exact_identity_hash' => (string) $manifest->exact_identity_hash,
            'source_kind' => (string) $manifest->source_kind,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_SENTINEL_ENCODE_FAILED');
        }

        File::put($sentinelPath, $encoded.PHP_EOL);
    }

    /**
     * @param  array<string,mixed>  $file
     */
    private function verifyPayload(string $payload, array $file): void
    {
        $expectedHash = strtolower(trim((string) ($file['blob_hash'] ?? '')));
        if ($expectedHash === '' || hash('sha256', $payload) !== $expectedHash) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_BLOB_HASH_MISMATCH');
        }

        $expectedSize = max(0, (int) ($file['size_bytes'] ?? 0));
        if (strlen($payload) !== $expectedSize) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_SIZE_MISMATCH');
        }

        $checksum = trim((string) ($file['checksum'] ?? ''));
        if ($checksum !== '' && str_starts_with($checksum, 'sha256:')) {
            $expectedChecksum = strtolower(substr($checksum, strlen('sha256:')));
            if ($expectedChecksum !== '' && hash('sha256', $payload) !== $expectedChecksum) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_CHECKSUM_MISMATCH');
            }
        }
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $files
     */
    private function verifyTree(string $root, Collection $files, string $manifestHash): void
    {
        $actualPaths = collect(File::allFiles($root))
            ->map(fn (\SplFileInfo $file): string => str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($root)), '/\\')))
            ->sort()
            ->values()
            ->all();

        $expectedPaths = $files
            ->map(fn (array $file): string => $this->normalizeRelativePath((string) ($file['logical_path'] ?? '')))
            ->sort()
            ->values()
            ->all();

        if ($actualPaths !== $expectedPaths) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_LOGICAL_PATH_MISMATCH');
        }

        foreach ($files as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$this->normalizeRelativePath((string) ($file['logical_path'] ?? ''));
            if (! is_file($path)) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_FILE_MISSING');
            }

            $payload = (string) File::get($path);
            $this->verifyPayload($payload, $file);
        }

        if ($manifestHash !== '') {
            $manifestPath = $root.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'manifest.json';
            if (! is_file($manifestPath)) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_MANIFEST_PATH_MISSING');
            }

            if (hash_file('sha256', $manifestPath) !== $manifestHash) {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_MANIFEST_HASH_MISMATCH');
            }
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_LOGICAL_PATH_EMPTY');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $normalized) === 1) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_LOGICAL_PATH_ABSOLUTE');
        }

        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== ''));
        if ($segments === []) {
            throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_LOGICAL_PATH_EMPTY');
        }

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('PACKS2_REMOTE_REHYDRATE_LOGICAL_PATH_TRAVERSAL');
            }
        }

        return implode('/', $segments);
    }

    private function manifestHash(object $release): string
    {
        return strtolower(trim((string) ($release->manifest_hash ?? '')));
    }

    private function storagePath(object $release): string
    {
        return trim((string) ($release->storage_path ?? ''));
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
