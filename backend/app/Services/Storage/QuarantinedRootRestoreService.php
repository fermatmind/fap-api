<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\ContentReleaseExactManifest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class QuarantinedRootRestoreService
{
    private const ALLOWED_SOURCE_KIND = 'legacy.source_pack';

    private const PLAN_SCHEMA = 'storage_restore_quarantined_root_plan.v1';

    private const RUN_SCHEMA = 'storage_restore_quarantined_root_run.v1';

    public function __construct(
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $itemRoot): array
    {
        $normalizedItemRoot = $this->normalizeRoot($itemRoot);

        try {
            $context = $this->inspectItemRoot($normalizedItemRoot);

            return [
                'schema' => (string) config('storage_rollout.restore_plan_schema_version', self::PLAN_SCHEMA),
                'generated_at' => now()->toAtomString(),
                'item_root' => $normalizedItemRoot,
                'exact_manifest_id' => (int) $context['exact_manifest_id'],
                'exact_identity_hash' => (string) $context['exact_identity_hash'],
                'source_kind' => (string) $context['source_kind'],
                'source_storage_path' => (string) $context['source_storage_path'],
                'target_root' => (string) $context['target_root'],
                'file_count' => (int) $context['file_count'],
                'total_bytes' => (int) $context['total_bytes'],
                'status' => 'planned',
                'blocked_reason' => null,
                'validation' => $context['validation'],
            ];
        } catch (\Throwable $e) {
            return [
                'schema' => (string) config('storage_rollout.restore_plan_schema_version', self::PLAN_SCHEMA),
                'generated_at' => now()->toAtomString(),
                'item_root' => $normalizedItemRoot,
                'exact_manifest_id' => null,
                'exact_identity_hash' => null,
                'source_kind' => null,
                'source_storage_path' => null,
                'target_root' => null,
                'file_count' => 0,
                'total_bytes' => 0,
                'status' => 'blocked',
                'blocked_reason' => $e->getMessage(),
                'validation' => [
                    'quarantine_item_under_base' => $this->isUnderPrefix($normalizedItemRoot, $this->quarantineRootBase()),
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan, ?string $expectedItemRoot = null): array
    {
        $resolvedPlan = $this->resolveExecutablePlan($plan, $expectedItemRoot);
        $itemRoot = $this->normalizeRoot((string) ($resolvedPlan['item_root'] ?? ''));
        $targetRoot = $this->normalizeRoot((string) ($resolvedPlan['target_root'] ?? ''));
        $runId = now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8);
        $runBase = $this->restoreRootBase().DIRECTORY_SEPARATOR.$runId;
        $stagingRoot = $runBase.DIRECTORY_SEPARATOR.'staging'.DIRECTORY_SEPARATOR.'root';
        File::ensureDirectoryExists(dirname($stagingRoot));

        $status = 'failure';
        $restoredAt = null;
        $error = null;
        $result = [
            'restored_root' => null,
            'staging_root' => null,
            'preserved_staging_root' => false,
        ];

        try {
            if (! File::moveDirectory($itemRoot, $stagingRoot)) {
                throw new \RuntimeException('failed to move quarantined root into restore staging.');
            }

            $result['staging_root'] = $stagingRoot;
            $sentinelPath = $stagingRoot.DIRECTORY_SEPARATOR.'.quarantine.json';
            if (is_file($sentinelPath)) {
                File::delete($sentinelPath);
            }

            $manifest = $this->loadExactManifest((int) $resolvedPlan['exact_manifest_id']);
            $files = $manifest->files()->orderBy('logical_path')->get();
            $this->verifyRootMatchesExactAuthority($stagingRoot, $files, (string) $manifest->manifest_hash, false);

            File::ensureDirectoryExists(dirname($targetRoot));
            if (file_exists($targetRoot)) {
                throw new \RuntimeException('restore target already exists: '.$targetRoot);
            }

            if (! File::moveDirectory($stagingRoot, $targetRoot)) {
                throw new \RuntimeException('failed to finalize restored root.');
            }

            $status = 'success';
            $restoredAt = now()->toAtomString();
            $result['restored_root'] = $targetRoot;
            $result['staging_root'] = null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $result['preserved_staging_root'] = is_dir($stagingRoot);
        }

        $run = [
            'schema' => (string) config('storage_rollout.restore_run_schema_version', self::RUN_SCHEMA),
            'generated_at' => now()->toAtomString(),
            'restored_at' => $restoredAt,
            'item_root' => $itemRoot,
            'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
            'exact_identity_hash' => (string) ($resolvedPlan['exact_identity_hash'] ?? ''),
            'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
            'source_storage_path' => (string) ($resolvedPlan['source_storage_path'] ?? ''),
            'target_root' => $targetRoot,
            'file_count' => (int) ($resolvedPlan['file_count'] ?? 0),
            'total_bytes' => (int) ($resolvedPlan['total_bytes'] ?? 0),
            'status' => $status,
            'validation' => $resolvedPlan['validation'] ?? [],
            'result' => $result + ['error' => $error],
        ];

        $encodedRun = json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encodedRun)) {
            throw new \RuntimeException('failed to encode restore run json.');
        }

        File::ensureDirectoryExists($runBase);
        File::put($runBase.DIRECTORY_SEPARATOR.'run.json', $encodedRun.PHP_EOL);

        return [
            'run_id' => $runId,
            'run_dir' => $runBase,
            'status' => $status,
            'exact_manifest_id' => (int) ($resolvedPlan['exact_manifest_id'] ?? 0),
            'source_kind' => (string) ($resolvedPlan['source_kind'] ?? ''),
            'target_root' => $targetRoot,
            'file_count' => (int) ($resolvedPlan['file_count'] ?? 0),
            'total_bytes' => (int) ($resolvedPlan['total_bytes'] ?? 0),
            'error' => $error,
            'result' => $result,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function resolveExecutablePlan(array $plan, ?string $expectedItemRoot): array
    {
        $schema = trim((string) ($plan['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.restore_plan_schema_version', self::PLAN_SCHEMA)) {
            throw new \RuntimeException('invalid restore plan schema: '.$schema);
        }

        $planItemRoot = $this->normalizeRoot((string) ($plan['item_root'] ?? ''));
        $requestedItemRoot = $expectedItemRoot !== null ? $this->normalizeRoot($expectedItemRoot) : $planItemRoot;
        if ($requestedItemRoot === '') {
            throw new \RuntimeException('restore item root is required.');
        }

        if ($planItemRoot !== '' && $requestedItemRoot !== '' && $planItemRoot !== $requestedItemRoot) {
            throw new \RuntimeException('restore plan item_root does not match requested item root.');
        }

        $freshPlan = $this->buildPlan($requestedItemRoot);
        if (($freshPlan['status'] ?? '') !== 'planned') {
            throw new \RuntimeException((string) ($freshPlan['blocked_reason'] ?? 'restore plan is blocked.'));
        }

        $comparisons = [
            'item_root',
            'exact_manifest_id',
            'exact_identity_hash',
            'source_kind',
            'source_storage_path',
            'target_root',
            'file_count',
            'total_bytes',
        ];
        foreach ($comparisons as $field) {
            if (($plan[$field] ?? null) !== ($freshPlan[$field] ?? null)) {
                throw new \RuntimeException('restore_plan_mismatch');
            }
        }

        return $freshPlan;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectItemRoot(string $itemRoot): array
    {
        if ($itemRoot === '') {
            throw new \RuntimeException('restore item root is required.');
        }

        if (! $this->isUnderPrefix($itemRoot, $this->quarantineRootBase())) {
            throw new \RuntimeException('restore item root must be under the quarantine root base.');
        }

        if (! is_dir($itemRoot)) {
            throw new \RuntimeException('quarantine item root does not exist.');
        }

        $sentinelPath = $itemRoot.DIRECTORY_SEPARATOR.'.quarantine.json';
        if (! is_file($sentinelPath)) {
            throw new \RuntimeException('quarantine sentinel is missing.');
        }

        $sentinel = json_decode((string) File::get($sentinelPath), true);
        if (! is_array($sentinel)) {
            throw new \RuntimeException('failed to decode quarantine sentinel json.');
        }

        $schema = trim((string) ($sentinel['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.quarantine_run_schema_version', 'storage_quarantine_exact_root_run.v1')) {
            throw new \RuntimeException('invalid quarantine sentinel schema: '.$schema);
        }

        $sourceKind = trim((string) ($sentinel['source_kind'] ?? ''));
        if ($sourceKind !== self::ALLOWED_SOURCE_KIND) {
            throw new \RuntimeException('restore source_kind is not allowed.');
        }

        $exactManifestId = (int) ($sentinel['exact_manifest_id'] ?? 0);
        if ($exactManifestId <= 0) {
            throw new \RuntimeException('quarantine sentinel exact_manifest_id is missing.');
        }

        $exactIdentityHash = trim((string) ($sentinel['exact_identity_hash'] ?? ''));
        if ($exactIdentityHash === '') {
            throw new \RuntimeException('quarantine sentinel exact_identity_hash is missing.');
        }

        $sourceDisk = trim((string) ($sentinel['source_disk'] ?? ''));
        if ($sourceDisk === '') {
            throw new \RuntimeException('quarantine sentinel source_disk is missing.');
        }

        $sourceStoragePath = $this->normalizeRoot((string) ($sentinel['source_storage_path'] ?? ''));
        if ($sourceStoragePath === '') {
            throw new \RuntimeException('quarantine sentinel source_storage_path is missing.');
        }

        $manifestHash = strtolower(trim((string) ($sentinel['manifest_hash'] ?? '')));
        if ($manifestHash === '') {
            throw new \RuntimeException('quarantine sentinel manifest_hash is missing.');
        }

        $manifest = $this->loadExactManifest($exactManifestId);
        $validation = [
            'quarantine_item_under_base' => true,
            'quarantine_sentinel_exists' => true,
            'quarantine_schema_valid' => true,
            'source_kind_allowed' => true,
            'exact_manifest_exists' => true,
        ];

        $this->assertSentinelMatchesManifest($sentinel, $manifest);
        $validation['sentinel_matches_exact_authority'] = true;

        $files = $manifest->files()->orderBy('logical_path')->get();
        if ($files->isEmpty()) {
            throw new \RuntimeException('exact manifest has no file rows.');
        }
        $validation['exact_child_rows_exist'] = true;

        $this->verifyRootMatchesExactAuthority($itemRoot, $files, $manifestHash, true);
        $validation['quarantined_root_exact_verified'] = true;

        $targetRoot = $sourceStoragePath;
        if (file_exists($targetRoot)) {
            throw new \RuntimeException('restore target already exists.');
        }
        $validation['target_path_absent'] = true;

        $this->assertTargetAllowed($targetRoot);
        $validation['target_path_allowlisted'] = true;
        $validation['target_path_not_in_danger_set'] = true;

        $releaseId = trim((string) ($manifest->content_pack_release_id ?? ''));
        if ($releaseId !== '') {
            $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
            if (! $release) {
                throw new \RuntimeException('linked release row is missing.');
            }
            $validation['linked_release_exists'] = true;

            $candidateRoots = $this->releaseStorageLocator->candidateRootsFromStoragePath(trim((string) ($release->storage_path ?? '')));
            $candidateRoots = array_map(fn (string $root): string => $this->normalizeRoot($root), $candidateRoots);
            if (! in_array($targetRoot, $candidateRoots, true)) {
                throw new \RuntimeException('linked release storage path no longer maps to restore target.');
            }
            $validation['linked_release_declares_target'] = true;

            $resolvedSource = $this->releaseStorageLocator->resolveReleaseSource($release);
            if ($resolvedSource !== null) {
                $resolvedRuntimeRoot = $this->normalizeRoot((string) ($resolvedSource['root'] ?? ''));
                if ($resolvedRuntimeRoot !== $targetRoot) {
                    throw new \RuntimeException('linked release currently resolves to a different runtime root.');
                }
            }
            $validation['linked_release_runtime_source_matches_target'] = $resolvedSource === null
                ? null
                : true;

            if (in_array($releaseId, $this->activeReleaseIds(), true)) {
                throw new \RuntimeException('linked release is active; restore is blocked.');
            }
            $validation['linked_release_inactive'] = true;

            if (in_array($releaseId, $this->snapshotReleaseIds(), true)) {
                throw new \RuntimeException('linked release is snapshot referenced; restore is blocked.');
            }
            $validation['linked_release_not_snapshot_referenced'] = true;
        }

        return [
            'item_root' => $itemRoot,
            'exact_manifest_id' => $exactManifestId,
            'exact_identity_hash' => $exactIdentityHash,
            'source_kind' => $sourceKind,
            'source_storage_path' => $sourceStoragePath,
            'target_root' => $targetRoot,
            'file_count' => (int) $files->count(),
            'total_bytes' => (int) $files->sum('size_bytes'),
            'validation' => $validation,
        ];
    }

    private function loadExactManifest(int $exactManifestId): ContentReleaseExactManifest
    {
        $manifest = ContentReleaseExactManifest::query()
            ->with('files')
            ->find($exactManifestId);

        if (! $manifest instanceof ContentReleaseExactManifest) {
            throw new \RuntimeException('exact manifest is missing.');
        }

        return $manifest;
    }

    /**
     * @param  array<string,mixed>  $sentinel
     */
    private function assertSentinelMatchesManifest(array $sentinel, ContentReleaseExactManifest $manifest): void
    {
        $pairs = [
            'exact_manifest_id' => (int) $manifest->getKey(),
            'exact_identity_hash' => trim((string) $manifest->exact_identity_hash),
            'source_kind' => trim((string) $manifest->source_kind),
            'source_disk' => trim((string) ($manifest->source_disk ?? '')),
            'source_storage_path' => $this->normalizeRoot((string) $manifest->source_storage_path),
            'manifest_hash' => strtolower(trim((string) $manifest->manifest_hash)),
        ];

        foreach ($pairs as $field => $expected) {
            $actual = $sentinel[$field] ?? null;
            if (is_string($expected)) {
                $actual = trim((string) $actual);
            } elseif (is_int($expected)) {
                $actual = (int) $actual;
            }

            if ($actual !== $expected) {
                throw new \RuntimeException('quarantine sentinel does not match exact authority.');
            }
        }
    }

    /**
     * @param  Collection<int,\App\Models\ContentReleaseExactManifestFile>  $files
     */
    private function verifyRootMatchesExactAuthority(string $root, Collection $files, string $manifestHash, bool $allowQuarantineSentinel): void
    {
        $actualPaths = collect(File::allFiles($root))
            ->map(function (\SplFileInfo $file) use ($root): string {
                return str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($root)), '/\\'));
            })
            ->reject(static function (string $path) use ($allowQuarantineSentinel): bool {
                return $allowQuarantineSentinel && $path === '.quarantine.json';
            })
            ->sort()
            ->values()
            ->all();

        $expectedPaths = $files
            ->map(fn ($file): string => $this->normalizeRelativePath((string) $file->logical_path))
            ->sort()
            ->values()
            ->all();

        if ($actualPaths !== $expectedPaths) {
            throw new \RuntimeException('root logical_path set does not match exact authority.');
        }

        foreach ($files as $file) {
            $logicalPath = $this->normalizeRelativePath((string) $file->logical_path);
            $absolutePath = $root.DIRECTORY_SEPARATOR.$logicalPath;
            if (! is_file($absolutePath)) {
                throw new \RuntimeException('expected file missing from root: '.$logicalPath);
            }

            $payload = (string) File::get($absolutePath);
            $expectedHash = strtolower(trim((string) $file->blob_hash));
            if ($expectedHash === '' || hash('sha256', $payload) !== $expectedHash) {
                throw new \RuntimeException('blob hash mismatch for '.$logicalPath);
            }

            $expectedSize = max(0, (int) $file->size_bytes);
            if (strlen($payload) !== $expectedSize) {
                throw new \RuntimeException('size mismatch for '.$logicalPath);
            }

            $checksum = trim((string) ($file->checksum ?? ''));
            if ($checksum !== '' && str_starts_with($checksum, 'sha256:')) {
                $expectedChecksum = strtolower(substr($checksum, strlen('sha256:')));
                if ($expectedChecksum !== '' && hash('sha256', $payload) !== $expectedChecksum) {
                    throw new \RuntimeException('checksum mismatch for '.$logicalPath);
                }
            }
        }

        $manifestPath = $root.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new \RuntimeException('compiled/manifest.json is missing.');
        }

        if (hash_file('sha256', $manifestPath) !== strtolower($manifestHash)) {
            throw new \RuntimeException('compiled/manifest.json hash does not match exact authority.');
        }
    }

    private function assertTargetAllowed(string $targetRoot): void
    {
        $contentReleasesRoot = $this->normalizeRoot(storage_path('app/private/content_releases'));
        $backupsRoot = $this->normalizeRoot(storage_path('app/private/content_releases/backups'));
        $defaultRoot = $this->normalizeRoot(rtrim((string) config('content_packs.root', ''), '/\\'));
        if ($defaultRoot !== '' && basename($defaultRoot) !== 'default') {
            $defaultRoot .= '/default';
        }
        $artifactRoots = [
            $this->normalizeRoot(storage_path('app/private/artifacts')),
            $this->normalizeRoot(storage_path('app/private/reports')),
            $this->normalizeRoot(storage_path('app/reports')),
        ];
        $materializedRoots = [
            $this->normalizeRoot(storage_path('app/private/packs_v2_materialized')),
            $this->normalizeRoot(storage_path('app/content_packs_v2_materialized')),
        ];
        $quarantineRoots = [
            $this->quarantineRootBase(),
            $this->restoreRootBase(),
        ];

        if (! $this->isUnderPrefix($targetRoot, $contentReleasesRoot)) {
            throw new \RuntimeException('restore target is outside the legacy source_pack allowlist.');
        }

        $contentReleasesPattern = '#^'.preg_quote($contentReleasesRoot, '#').'/[^/]+/source_pack$#';
        if (preg_match($contentReleasesPattern, $targetRoot) !== 1) {
            throw new \RuntimeException('restore target must match legacy content_releases/{release_id}/source_pack shape.');
        }

        $dangerRoots = array_filter(array_merge([$backupsRoot, $defaultRoot], $artifactRoots, $materializedRoots, $quarantineRoots));
        foreach ($dangerRoots as $dangerRoot) {
            if ($this->isUnderPrefix($targetRoot, $dangerRoot)) {
                throw new \RuntimeException('restore target falls under a runtime no-touch prefix.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function activeReleaseIds(): array
    {
        return DB::table('content_pack_activations')
            ->pluck('release_id')
            ->filter(static fn (mixed $value): bool => trim((string) $value) !== '')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function snapshotReleaseIds(): array
    {
        $columns = [
            'from_content_pack_release_id',
            'to_content_pack_release_id',
            'activation_before_release_id',
            'activation_after_release_id',
        ];

        $ids = [];
        foreach (DB::table('content_release_snapshots')->get($columns) as $row) {
            foreach ($columns as $column) {
                $value = trim((string) ($row->{$column} ?? ''));
                if ($value !== '') {
                    $ids[$value] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function isUnderPrefix(string $path, string $prefix): bool
    {
        $path = $this->normalizeRoot($path);
        $prefix = $this->normalizeRoot($prefix);
        if ($path === '' || $prefix === '') {
            return false;
        }

        return $path === $prefix || str_starts_with($path.'/', $prefix.'/');
    }

    private function quarantineRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.quarantine_root_dir', 'app/private/quarantine/release_roots'));
        $relative = ltrim($relative, '/\\');

        return $this->normalizeRoot(storage_path($relative));
    }

    private function restoreRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.restore_root_dir', 'app/private/quarantine/restore_runs'));
        $relative = ltrim($relative, '/\\');

        return $this->normalizeRoot(storage_path($relative));
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            throw new \RuntimeException('logical_path cannot be empty.');
        }

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $normalized) === 1) {
            throw new \RuntimeException('logical_path must be relative to the root directory.');
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
}
