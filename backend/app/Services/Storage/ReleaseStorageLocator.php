<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

final class ReleaseStorageLocator
{
    /**
     * @var array<string,object|null>
     */
    private array $versionCache = [];

    /**
     * @var array<string,string>
     */
    private array $rollbackSourceReleaseCache = [];

    /**
     * @param  object|array<string,mixed>  $release
     * @return array{
     *   root:string,
     *   compiled_dir:string,
     *   storage_path_for_patch:string,
     *   resolved_from:string
     * }|null
     */
    public function resolveReleaseSource(object|array $release): ?array
    {
        $storagePath = trim((string) data_get($release, 'storage_path'));
        if ($storagePath !== '') {
            foreach ($this->candidateRootsFromStoragePath($storagePath) as $root) {
                $compiledDir = $this->compiledDirFromRoot($root);
                if ($compiledDir === null) {
                    continue;
                }

                return [
                    'root' => $root,
                    'compiled_dir' => $compiledDir,
                    'storage_path_for_patch' => $storagePath,
                    'resolved_from' => 'release.storage_path',
                ];
            }
        }

        $action = strtolower(trim((string) data_get($release, 'action')));
        if ($action === 'publish') {
            $versionId = trim((string) data_get($release, 'to_version_id'));
            if ($versionId !== '') {
                $legacyRoot = $this->legacySourcePackRoot($versionId);
                $compiledDir = $this->compiledDirFromRoot($legacyRoot);
                if ($compiledDir !== null) {
                    return [
                        'root' => $legacyRoot,
                        'compiled_dir' => $compiledDir,
                        'storage_path_for_patch' => $legacyRoot,
                        'resolved_from' => 'legacy.source_pack',
                    ];
                }

                $version = $this->versionRow($versionId);
                $extractedRelPath = trim((string) ($version?->extracted_rel_path ?? ''));
                if ($extractedRelPath !== '') {
                    $extractedRoot = $this->absoluteStorageRoot($extractedRelPath);
                    $compiledDir = $this->compiledDirFromRoot($extractedRoot);
                    if ($compiledDir !== null) {
                        return [
                            'root' => $extractedRoot,
                            'compiled_dir' => $compiledDir,
                            'storage_path_for_patch' => $extractedRoot,
                            'resolved_from' => 'legacy.extracted_rel_path',
                        ];
                    }
                }
            }
        }

        if ($action === 'rollback') {
            $releaseId = trim((string) data_get($release, 'id'));
            $sourceReleaseId = $this->rollbackSourceReleaseId($releaseId);
            if ($sourceReleaseId !== '') {
                $backupRoot = $this->legacyPreviousPackRoot($sourceReleaseId);
                $compiledDir = $this->compiledDirFromRoot($backupRoot);
                if ($compiledDir !== null) {
                    return [
                        'root' => $backupRoot,
                        'compiled_dir' => $compiledDir,
                        'storage_path_for_patch' => $backupRoot,
                        'resolved_from' => 'legacy.previous_pack',
                    ];
                }
            }
        }

        foreach ($this->candidateV2RootsFromRelease($release) as $candidate) {
            $compiledDir = $this->compiledDirFromRoot($candidate['root']);
            if ($compiledDir === null) {
                continue;
            }

            return [
                'root' => $candidate['root'],
                'compiled_dir' => $compiledDir,
                'storage_path_for_patch' => $candidate['storage_path_for_patch'],
                'resolved_from' => $candidate['resolved_from'],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *   raw_json:string,
     *   decoded:array<string,mixed>,
     *   manifest_hash:string,
     *   pack_id:string,
     *   pack_version:string,
     *   compiled_hash:string,
     *   content_hash:string,
     *   norms_version:string
     * }
     */
    public function readManifestMetadataFromRoot(string $root): array
    {
        $compiledDir = $this->compiledDirFromRoot($root);
        if ($compiledDir === null) {
            return [
                'raw_json' => '',
                'decoded' => [],
                'manifest_hash' => '',
                'pack_id' => '',
                'pack_version' => '',
                'compiled_hash' => '',
                'content_hash' => '',
                'norms_version' => '',
            ];
        }

        $manifestPath = $compiledDir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            return [
                'raw_json' => '',
                'decoded' => [],
                'manifest_hash' => '',
                'pack_id' => '',
                'pack_version' => '',
                'compiled_hash' => '',
                'content_hash' => '',
                'norms_version' => '',
            ];
        }

        $rawJson = (string) File::get($manifestPath);
        $decoded = json_decode($rawJson, true);
        $decoded = is_array($decoded) ? $decoded : [];

        return [
            'raw_json' => $rawJson,
            'decoded' => $decoded,
            'manifest_hash' => $rawJson !== '' ? hash('sha256', $rawJson) : '',
            'pack_id' => strtoupper(trim((string) ($decoded['pack_id'] ?? ''))),
            'pack_version' => trim((string) ($decoded['pack_version'] ?? $decoded['content_package_version'] ?? '')),
            'compiled_hash' => trim((string) ($decoded['compiled_hash'] ?? '')),
            'content_hash' => trim((string) ($decoded['content_hash'] ?? '')),
            'norms_version' => trim((string) ($decoded['norms_version'] ?? '')),
        ];
    }

    /**
     * @return list<array{logical_path:string,hash:string,size_bytes:int,content_type:?string,role:?string}>
     */
    public function collectCompiledFilesFromRoot(string $root): array
    {
        $compiledDir = $this->compiledDirFromRoot($root);
        if ($compiledDir === null) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($compiledDir) as $file) {
            $absolutePath = $file->getPathname();
            $logicalPath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, '/\\')))), '/');
            $bytes = (string) File::get($absolutePath);
            $files[] = [
                'logical_path' => $logicalPath,
                'hash' => hash('sha256', $bytes),
                'size_bytes' => strlen($bytes),
                'content_type' => $this->contentTypeForLogicalPath($logicalPath),
                'role' => $logicalPath === 'compiled/manifest.json' || $logicalPath === 'manifest.json' ? 'manifest' : null,
            ];
        }

        usort($files, static fn (array $left, array $right): int => strcmp($left['logical_path'], $right['logical_path']));

        return $files;
    }

    /**
     * @return list<string>
     */
    public function liveAliasRoots(): array
    {
        $packsRoot = rtrim((string) config('content_packs.root', ''), '/\\');
        if ($packsRoot === '' || ! is_dir($packsRoot)) {
            return [];
        }

        $defaultRoot = basename($packsRoot) === 'default'
            ? $packsRoot
            : $packsRoot.DIRECTORY_SEPARATOR.'default';
        if (! is_dir($defaultRoot)) {
            return [];
        }

        $roots = [];
        foreach (File::allFiles($defaultRoot) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! str_ends_with($path, '/compiled/manifest.json')) {
                continue;
            }

            $roots[] = dirname(dirname($path));
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return list<string>
     */
    public function legacyBackupRoots(): array
    {
        $backupsRoot = storage_path('app/private/content_releases/backups');
        if (! is_dir($backupsRoot)) {
            return [];
        }

        $roots = [];
        foreach (glob($backupsRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $releaseRoot) {
            foreach (['previous_pack', 'current_pack'] as $leaf) {
                $candidate = $releaseRoot.DIRECTORY_SEPARATOR.$leaf;
                if ($this->compiledDirFromRoot($candidate) !== null) {
                    $roots[] = $candidate;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return array{release_id:string,leaf:string}|null
     */
    public function backupRootContext(string $root): ?array
    {
        $pattern = '#^'.preg_quote(str_replace('\\', '/', storage_path('app/private/content_releases/backups')), '#').'/([^/]+)/(previous_pack|current_pack)$#';
        $normalizedRoot = str_replace('\\', '/', rtrim($root, '/\\'));
        if (preg_match($pattern, $normalizedRoot, $matches) !== 1) {
            return null;
        }

        return [
            'release_id' => (string) $matches[1],
            'leaf' => (string) $matches[2],
        ];
    }

    /**
     * @return list<string>
     */
    public function candidateRootsFromStoragePath(string $storagePath): array
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return [];
        }

        $candidates = [];
        if (str_starts_with($normalized, '/')) {
            $candidates[] = rtrim($normalized, '/');
        } else {
            $relative = ltrim($normalized, '/');
            if (str_starts_with($relative, 'app/')) {
                $relative = substr($relative, 4);
            }
            $relative = ltrim($relative, '/');
            if ($relative !== '') {
                $candidates[] = rtrim(storage_path('app/'.$relative), '/');
            }

            if (str_starts_with($relative, 'private/packs_v2/')) {
                $mirror = 'content_packs_v2/'.substr($relative, strlen('private/packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            } elseif (str_starts_with($relative, 'content_packs_v2/')) {
                $mirror = 'private/packs_v2/'.substr($relative, strlen('content_packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $root): bool => $root !== '')));
    }

    public function compiledDirFromRoot(string $root): ?string
    {
        $root = rtrim($root, '/\\');
        if ($root === '') {
            return null;
        }

        $compiledDir = $root.DIRECTORY_SEPARATOR.'compiled';
        if (is_file($compiledDir.DIRECTORY_SEPARATOR.'manifest.json')) {
            return $compiledDir;
        }

        if (is_file($root.DIRECTORY_SEPARATOR.'manifest.json')) {
            return $root;
        }

        return null;
    }

    private function legacySourcePackRoot(string $versionId): string
    {
        return storage_path('app/private/content_releases/'.$versionId.'/source_pack');
    }

    private function legacyPreviousPackRoot(string $sourceReleaseId): string
    {
        return storage_path('app/private/content_releases/backups/'.$sourceReleaseId.'/previous_pack');
    }

    /**
     * @param  object|array<string,mixed>  $release
     * @return list<array{root:string,storage_path_for_patch:string,resolved_from:string}>
     */
    private function candidateV2RootsFromRelease(object|array $release): array
    {
        $releaseId = trim((string) data_get($release, 'id'));
        $packId = strtoupper(trim((string) data_get($release, 'to_pack_id')));
        $packVersion = trim((string) data_get($release, 'pack_version'));
        if ($releaseId === '' || $packId === '' || $packVersion === '') {
            return [];
        }

        $primaryRelative = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;
        $mirrorRelative = 'content_packs_v2/'.$packId.'/'.$packVersion.'/'.$releaseId;

        return [
            [
                'root' => storage_path('app/'.$primaryRelative),
                'storage_path_for_patch' => $primaryRelative,
                'resolved_from' => 'v2.primary',
            ],
            [
                'root' => storage_path('app/'.$mirrorRelative),
                'storage_path_for_patch' => $mirrorRelative,
                'resolved_from' => 'v2.mirror',
            ],
        ];
    }

    private function versionRow(string $versionId): ?object
    {
        if ($versionId === '') {
            return null;
        }

        if (array_key_exists($versionId, $this->versionCache)) {
            return $this->versionCache[$versionId];
        }

        $this->versionCache[$versionId] = DB::table('content_pack_versions')->where('id', $versionId)->first();

        return $this->versionCache[$versionId];
    }

    private function rollbackSourceReleaseId(string $releaseId): string
    {
        if ($releaseId === '') {
            return '';
        }

        if (array_key_exists($releaseId, $this->rollbackSourceReleaseCache)) {
            return $this->rollbackSourceReleaseCache[$releaseId];
        }

        $sourceReleaseId = '';
        $rows = DB::table('audit_logs')
            ->where('target_type', 'content_pack_release')
            ->where('target_id', $releaseId)
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $meta = json_decode((string) ($row->meta_json ?? '{}'), true);
            if (! is_array($meta)) {
                continue;
            }

            $sourceReleaseId = trim((string) ($meta['source_release_id'] ?? ''));
            if ($sourceReleaseId !== '') {
                break;
            }
        }

        $this->rollbackSourceReleaseCache[$releaseId] = $sourceReleaseId;

        return $sourceReleaseId;
    }

    private function absoluteStorageRoot(string $storagePath): string
    {
        $relative = ltrim($storagePath, '/');
        if (str_starts_with($relative, 'app/')) {
            $relative = substr($relative, 4);
        }

        return storage_path('app/'.$relative);
    }

    private function contentTypeForLogicalPath(string $logicalPath): ?string
    {
        return str_ends_with(strtolower($logicalPath), '.json') ? 'application/json' : null;
    }
}
