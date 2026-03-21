<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Content\ContentPackV2RemoteRehydrateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class MaterializedCacheJanitorService
{
    private const SCHEMA = 'storage_janitor_materialized_cache.v1';

    private const TARGET_RELATIVE_DIR = 'app/private/packs_v2_materialized';

    public function __construct(
        private readonly ReleaseStorageLocator $releaseStorageLocator,
        private readonly ContentPackV2RemoteRehydrateService $remoteRehydrate,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(bool $execute): array
    {
        $bucketRoots = $this->bucketRoots();
        $candidates = [];
        $skipped = [];
        $deletedPaths = [];
        $reasonCounts = [];

        foreach ($bucketRoots as $bucketRoot) {
            $inspection = $this->inspectBucket($bucketRoot);
            $reason = (string) ($inspection['reason'] ?? '');
            if ($reason !== '') {
                $reasonCounts[$reason] = (int) ($reasonCounts[$reason] ?? 0) + 1;
            }

            if ((bool) ($inspection['candidate'] ?? false)) {
                $candidate = $inspection;
                unset($candidate['candidate']);

                if ($execute) {
                    File::deleteDirectory($bucketRoot);
                    if (! is_dir($bucketRoot)) {
                        $deletedPaths[] = $bucketRoot;
                    }
                }

                $candidates[] = $candidate;

                continue;
            }

            $skipped[] = $inspection;
        }

        usort($candidates, static fn (array $left, array $right): int => strcmp((string) ($left['bucket_root'] ?? ''), (string) ($right['bucket_root'] ?? '')));
        usort($skipped, static fn (array $left, array $right): int => strcmp((string) ($left['bucket_root'] ?? ''), (string) ($right['bucket_root'] ?? '')));
        sort($deletedPaths);
        ksort($reasonCounts);

        $payload = [
            'schema' => self::SCHEMA,
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $execute ? 'executed' : 'planned',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'scanned_bucket_count' => count($bucketRoots),
                'candidate_delete_count' => count($candidates),
                'deleted_bucket_count' => count($deletedPaths),
                'skipped_bucket_count' => count($skipped),
            ],
            'candidates' => array_values($candidates),
            'skipped' => array_values($skipped),
            'reasons' => $reasonCounts,
            'deleted_paths' => $deletedPaths,
        ];

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function bucketRoots(): array
    {
        $baseRoot = $this->materializedBaseRoot();
        if (! is_dir($baseRoot)) {
            return [];
        }

        $glob = glob($baseRoot.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        if (! is_array($glob)) {
            return [];
        }

        $roots = array_values(array_filter(
            array_map(fn (string $path): string => $this->normalizePath($path), $glob),
            fn (string $path): bool => $path !== '' && is_dir($path)
        ));

        sort($roots);

        return array_values(array_unique($roots));
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectBucket(string $bucketRoot): array
    {
        $shape = $this->bucketShape($bucketRoot);
        if ($shape === null) {
            return $this->skip($bucketRoot, 'BUCKET_SHAPE_INVALID');
        }

        $sentinelPath = $bucketRoot.'/.materialization.json';
        if (! is_file($sentinelPath)) {
            return $this->skip($bucketRoot, 'MATERIALIZATION_SENTINEL_MISSING', $shape);
        }

        $sentinel = json_decode((string) File::get($sentinelPath), true);
        if (! is_array($sentinel)) {
            return $this->skip($bucketRoot, 'MATERIALIZATION_SENTINEL_INVALID', $shape);
        }

        $storagePath = trim((string) ($sentinel['storage_path'] ?? ''));
        if ($storagePath === '') {
            return $this->skip($bucketRoot, 'MATERIALIZATION_SENTINEL_STORAGE_PATH_MISSING', $shape);
        }

        $manifestHash = strtolower(trim((string) ($sentinel['manifest_hash'] ?? '')));
        if ($manifestHash === '') {
            return $this->skip($bucketRoot, 'MATERIALIZATION_SENTINEL_MANIFEST_HASH_MISSING', $shape, $storagePath);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $shape['storage_identity'])) {
            return $this->skip($bucketRoot, 'BUCKET_STORAGE_IDENTITY_INVALID', $shape, $storagePath, $manifestHash);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $shape['manifest_hash'])) {
            return $this->skip($bucketRoot, 'BUCKET_MANIFEST_HASH_INVALID', $shape, $storagePath, $manifestHash);
        }

        if ($shape['storage_identity'] !== hash('sha256', $storagePath)) {
            return $this->skip($bucketRoot, 'BUCKET_STORAGE_IDENTITY_MISMATCH', $shape, $storagePath, $manifestHash);
        }

        if ($shape['manifest_hash'] !== $manifestHash) {
            return $this->skip($bucketRoot, 'BUCKET_MANIFEST_HASH_MISMATCH', $shape, $storagePath, $manifestHash);
        }

        $bucketCompiledManifestPath = $bucketRoot.'/compiled/manifest.json';
        if (! is_file($bucketCompiledManifestPath)) {
            return $this->skip($bucketRoot, 'BUCKET_COMPILED_MANIFEST_MISSING', $shape, $storagePath, $manifestHash);
        }

        $bucketCompiledManifestHash = strtolower((string) hash_file('sha256', $bucketCompiledManifestPath));
        if ($bucketCompiledManifestHash !== $manifestHash) {
            return $this->skip($bucketRoot, 'BUCKET_COMPILED_MANIFEST_HASH_MISMATCH', $shape, $storagePath, $manifestHash);
        }

        $localProof = $this->proveLocal($storagePath, $manifestHash);
        if ((bool) ($localProof['ok'] ?? false)) {
            return [
                'candidate' => true,
                'bucket_root' => $bucketRoot,
                'pack_id' => $shape['pack_id'],
                'pack_version' => $shape['pack_version'],
                'storage_identity' => $shape['storage_identity'],
                'manifest_hash' => $manifestHash,
                'storage_path' => $storagePath,
                'release_id' => trim((string) ($sentinel['release_id'] ?? '')),
                'proof_kind' => 'local',
                'proof_reason' => (string) ($localProof['reason'] ?? 'LOCAL_SOURCE_CONFIRMED'),
                'proof_context' => $localProof['context'] ?? [],
                'reason' => 'LOCAL_SOURCE_CONFIRMED',
            ];
        }

        $remoteProof = $this->proveRemote(
            $shape['pack_id'],
            $shape['pack_version'],
            $storagePath,
            $manifestHash,
            trim((string) ($sentinel['release_id'] ?? ''))
        );

        if ((bool) ($remoteProof['ok'] ?? false)) {
            return [
                'candidate' => true,
                'bucket_root' => $bucketRoot,
                'pack_id' => $shape['pack_id'],
                'pack_version' => $shape['pack_version'],
                'storage_identity' => $shape['storage_identity'],
                'manifest_hash' => $manifestHash,
                'storage_path' => $storagePath,
                'release_id' => (string) ($remoteProof['release_id'] ?? trim((string) ($sentinel['release_id'] ?? ''))),
                'proof_kind' => 'remote',
                'proof_reason' => (string) ($remoteProof['reason'] ?? 'REMOTE_FALLBACK_CONFIRMED'),
                'proof_context' => $remoteProof['context'] ?? [],
                'reason' => 'REMOTE_FALLBACK_CONFIRMED',
            ];
        }

        return $this->skip(
            $bucketRoot,
            (string) ($remoteProof['reason'] ?? $localProof['reason'] ?? 'MATERIALIZED_BUCKET_NOT_PROVABLY_REBUILDABLE'),
            $shape,
            $storagePath,
            $manifestHash,
            ['local_context' => $localProof['context'] ?? [], 'remote_context' => $remoteProof['context'] ?? []]
        );
    }

    /**
     * @return array<string,string>|null
     */
    private function bucketShape(string $bucketRoot): ?array
    {
        $baseRoot = $this->materializedBaseRoot();
        $normalizedBase = $this->normalizePath($baseRoot);
        $normalizedBucket = $this->normalizePath($bucketRoot);
        if ($normalizedBase === '' || $normalizedBucket === '' || ! str_starts_with($normalizedBucket, $normalizedBase.'/')) {
            return null;
        }

        $relative = substr($normalizedBucket, strlen($normalizedBase) + 1);
        $segments = explode('/', $relative);
        if (count($segments) !== 4) {
            return null;
        }

        [$packId, $packVersion, $storageIdentity, $manifestHash] = $segments;
        if ($packId === '' || $packVersion === '' || $storageIdentity === '' || $manifestHash === '') {
            return null;
        }

        return [
            'pack_id' => $packId,
            'pack_version' => $packVersion,
            'storage_identity' => strtolower($storageIdentity),
            'manifest_hash' => strtolower($manifestHash),
        ];
    }

    /**
     * @return array{ok:bool,reason:string,context:array<string,mixed>}
     */
    private function proveLocal(string $storagePath, string $manifestHash): array
    {
        foreach ($this->releaseStorageLocator->candidateRootsFromStoragePath($storagePath) as $root) {
            $compiledDir = $this->releaseStorageLocator->compiledDirFromRoot($root);
            if ($compiledDir === null) {
                continue;
            }

            $manifestPath = $compiledDir.'/manifest.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            $currentManifestHash = strtolower((string) hash_file('sha256', $manifestPath));
            if ($currentManifestHash === $manifestHash) {
                return [
                    'ok' => true,
                    'reason' => 'LOCAL_SOURCE_CONFIRMED',
                    'context' => [
                        'source_root' => $root,
                        'source_compiled_dir' => $compiledDir,
                    ],
                ];
            }
        }

        return [
            'ok' => false,
            'reason' => 'LOCAL_SOURCE_NOT_REBUILDABLE',
            'context' => [],
        ];
    }

    /**
     * @return array{ok:bool,reason:string,context:array<string,mixed>,release_id?:string}
     */
    private function proveRemote(
        string $packId,
        string $packVersion,
        string $storagePath,
        string $manifestHash,
        string $sentinelReleaseId,
    ): array {
        $disk = trim((string) config('storage_rollout.blob_offload_disk', 's3'));
        if ($disk === '') {
            return [
                'ok' => false,
                'reason' => 'REMOTE_FALLBACK_DISK_MISSING',
                'context' => [],
            ];
        }

        $candidates = $this->candidateReleases($packId, $packVersion, $storagePath, $manifestHash, $sentinelReleaseId);
        if ($candidates === []) {
            return [
                'ok' => false,
                'reason' => 'REMOTE_FALLBACK_RELEASE_CONTEXT_MISSING',
                'context' => [],
            ];
        }

        $lastReason = 'REMOTE_FALLBACK_PROBE_FAILED';
        foreach ($candidates as $release) {
            try {
                $probe = $this->remoteRehydrate->probeRemoteFallback($release, $disk);
                if ((bool) ($probe['available'] ?? false) !== true) {
                    $lastReason = trim((string) ($probe['reason'] ?? $lastReason));

                    continue;
                }

                if (strtolower(trim((string) ($probe['manifest_hash'] ?? ''))) !== $manifestHash) {
                    $lastReason = 'REMOTE_FALLBACK_MANIFEST_HASH_MISMATCH';

                    continue;
                }

                return [
                    'ok' => true,
                    'reason' => 'REMOTE_FALLBACK_CONFIRMED',
                    'release_id' => trim((string) ($release->id ?? '')),
                    'context' => [
                        'disk' => $disk,
                        'release_id' => trim((string) ($release->id ?? '')),
                        'exact_manifest_id' => (int) ($probe['exact_manifest_id'] ?? 0),
                        'exact_identity_hash' => (string) ($probe['exact_identity_hash'] ?? ''),
                        'source_kind' => (string) ($probe['source_kind'] ?? ''),
                    ],
                ];
            } catch (\Throwable $e) {
                $message = trim($e->getMessage());
                $lastReason = $message !== '' ? $message : $lastReason;
            }
        }

        return [
            'ok' => false,
            'reason' => $lastReason,
            'context' => [
                'disk' => $disk,
            ],
        ];
    }

    /**
     * @return list<object>
     */
    private function candidateReleases(
        string $packId,
        string $packVersion,
        string $storagePath,
        string $manifestHash,
        string $sentinelReleaseId,
    ): array {
        $rows = [];
        $seen = [];

        if ($sentinelReleaseId !== '') {
            $row = DB::table('content_pack_releases')->where('id', $sentinelReleaseId)->first();
            if ($this->releaseMatchesBucket($row, $packId, $packVersion, $storagePath, $manifestHash)) {
                $rows[] = $row;
                $seen[(string) $row->id] = true;
            }
        }

        $queryRows = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where('storage_path', $storagePath)
            ->where('manifest_hash', $manifestHash)
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($queryRows as $row) {
            if (! $this->releaseMatchesBucket($row, $packId, $packVersion, $storagePath, $manifestHash)) {
                continue;
            }

            $releaseId = trim((string) ($row->id ?? ''));
            if ($releaseId === '' || isset($seen[$releaseId])) {
                continue;
            }

            $seen[$releaseId] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    private function releaseMatchesBucket(
        ?object $row,
        string $packId,
        string $packVersion,
        string $storagePath,
        string $manifestHash,
    ): bool {
        if ($row === null) {
            return false;
        }

        $rowPackVersion = trim((string) ($row->pack_version ?? $row->dir_alias ?? ''));

        return strtoupper(trim((string) ($row->to_pack_id ?? ''))) === strtoupper($packId)
            && $rowPackVersion === $packVersion
            && trim((string) ($row->storage_path ?? '')) === $storagePath
            && strtolower(trim((string) ($row->manifest_hash ?? ''))) === $manifestHash
            && strtolower(trim((string) ($row->status ?? ''))) === 'success';
    }

    private function materializedBaseRoot(): string
    {
        return storage_path(self::TARGET_RELATIVE_DIR);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim(trim($path), '/\\'));
    }

    /**
     * @param  array<string,string>|null  $shape
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function skip(
        string $bucketRoot,
        string $reason,
        ?array $shape = null,
        string $storagePath = '',
        string $manifestHash = '',
        array $extra = [],
    ): array {
        return array_merge([
            'candidate' => false,
            'bucket_root' => $bucketRoot,
            'pack_id' => (string) ($shape['pack_id'] ?? ''),
            'pack_version' => (string) ($shape['pack_version'] ?? ''),
            'storage_identity' => (string) ($shape['storage_identity'] ?? ''),
            'manifest_hash' => $manifestHash !== '' ? $manifestHash : (string) ($shape['manifest_hash'] ?? ''),
            'storage_path' => $storagePath,
            'reason' => $reason,
        ], $extra);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_janitor_materialized_cache',
            'target_type' => 'storage',
            'target_id' => 'materialized_cache',
            'meta_json' => json_encode([
                'schema' => self::SCHEMA,
                'mode' => $payload['mode'] ?? null,
                'generated_at' => $payload['generated_at'] ?? null,
                'summary' => $payload['summary'] ?? [],
                'reasons' => $payload['reasons'] ?? [],
                'candidates' => array_map(static fn (array $entry): array => [
                    'bucket_root' => (string) ($entry['bucket_root'] ?? ''),
                    'proof_kind' => (string) ($entry['proof_kind'] ?? ''),
                    'proof_reason' => (string) ($entry['proof_reason'] ?? ''),
                    'storage_path' => (string) ($entry['storage_path'] ?? ''),
                    'manifest_hash' => (string) ($entry['manifest_hash'] ?? ''),
                ], is_array($payload['candidates'] ?? null) ? $payload['candidates'] : []),
                'deleted_paths' => $payload['deleted_paths'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_janitor_materialized_cache',
            'request_id' => null,
            'reason' => 'materialized_cache_retention',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
