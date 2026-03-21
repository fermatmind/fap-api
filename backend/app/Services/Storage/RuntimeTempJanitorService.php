<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class RuntimeTempJanitorService
{
    private const SCHEMA = 'storage_janitor_runtime_temps.v1';

    /**
     * @return array<string,mixed>
     */
    public function run(bool $execute): array
    {
        $candidates = [];
        $skipped = [];
        $deletedPaths = [];
        $noTouchPaths = $this->existingRootPaths();

        foreach ($this->scannedFiles() as $path) {
            $entry = $this->classifyFile($path);
            if ((bool) ($entry['candidate'] ?? false) === true) {
                $candidates[] = $entry;

                continue;
            }

            $skipped[] = $entry;
            $noTouchPaths[] = $path;
        }

        if ($execute) {
            foreach ($candidates as $entry) {
                $path = (string) ($entry['path'] ?? '');
                if ($path === '' || ! is_file($path)) {
                    continue;
                }

                File::delete($path);
                if (! is_file($path)) {
                    $deletedPaths[] = $path;
                }
            }
        }

        sort($deletedPaths);
        $noTouchPaths = $this->uniqueSorted($noTouchPaths);
        $payload = [
            'schema' => self::SCHEMA,
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $execute ? 'executed' : 'planned',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'scanned_file_count' => count($candidates) + count($skipped),
                'candidate_delete_count' => count($candidates),
                'deleted_file_count' => count($deletedPaths),
                'skipped_file_count' => count($skipped),
            ],
            'candidates' => $candidates,
            'skipped' => $skipped,
            'deleted_paths' => $deletedPaths,
            'no_touch_paths' => $noTouchPaths,
            'skipped_reasons' => $this->countByReason($skipped),
        ];

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function existingRootPaths(): array
    {
        $roots = [];

        foreach ($this->rootDefinitions() as $definition) {
            $path = $this->normalizePath(storage_path($definition['relative_dir']));
            if ($path !== '' && is_dir($path)) {
                $roots[] = $path;
            }
        }

        $cacheDataRoot = $this->normalizePath(storage_path('framework/cache/data'));
        if ($cacheDataRoot !== '' && is_dir($cacheDataRoot)) {
            $roots[] = $cacheDataRoot;
        }

        return $this->uniqueSorted($roots);
    }

    /**
     * @return array<string,array{relative_dir:string,surface:string}>
     */
    private function rootDefinitions(): array
    {
        return [
            'logs' => [
                'relative_dir' => 'logs',
                'surface' => 'logs',
            ],
            'framework_cache' => [
                'relative_dir' => 'framework/cache',
                'surface' => 'framework_cache',
            ],
            'framework_sessions' => [
                'relative_dir' => 'framework/sessions',
                'surface' => 'framework_sessions',
            ],
            'framework_views' => [
                'relative_dir' => 'framework/views',
                'surface' => 'framework_views',
            ],
            'framework_testing' => [
                'relative_dir' => 'framework/testing',
                'surface' => 'framework_testing',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        $files = [];

        foreach ($this->rootDefinitions() as $definition) {
            $root = storage_path($definition['relative_dir']);
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $files[] = $this->normalizePath($file->getPathname());
            }
        }

        return $this->uniqueSorted($files);
    }

    /**
     * @return array<string,mixed>
     */
    private function classifyFile(string $path): array
    {
        $normalizedPath = $this->normalizePath($path);
        $basename = basename($normalizedPath);

        if ($basename === '.gitkeep') {
            return $this->skip($normalizedPath, 'GITKEEP_PRESERVED', $this->surfaceForPath($normalizedPath));
        }

        $surface = $this->surfaceForPath($normalizedPath);
        if (in_array($surface, ['framework_sessions', 'framework_testing'], true)) {
            return $this->skip($normalizedPath, 'RUNTIME_NO_TOUCH_SURFACE', $surface);
        }

        if ($this->isAllowedDeletePath($normalizedPath)) {
            return [
                'candidate' => true,
                'path' => $normalizedPath,
                'relative_path' => $this->relativeStoragePath($normalizedPath),
                'surface' => $surface,
                'reason' => 'ALLOWLIST_TEMP_FILE',
            ];
        }

        return $this->skip($normalizedPath, 'NON_ALLOWLIST_FILE', $surface);
    }

    private function isAllowedDeletePath(string $path): bool
    {
        return $this->isLogFile($path)
            || $this->isFacadeCacheFile($path)
            || $this->isCacheDataLeafFile($path)
            || $this->isViewLeafFile($path);
    }

    private function isLogFile(string $path): bool
    {
        $logsRoot = $this->normalizePath(storage_path('logs'));

        return dirname($path) === $logsRoot && str_ends_with(basename($path), '.log');
    }

    private function isFacadeCacheFile(string $path): bool
    {
        $cacheRoot = $this->normalizePath(storage_path('framework/cache'));
        $basename = basename($path);

        return dirname($path) === $cacheRoot
            && preg_match('/^facade\-.*\.php$/', $basename) === 1;
    }

    private function isCacheDataLeafFile(string $path): bool
    {
        $dataRoot = $this->normalizePath(storage_path('framework/cache/data'));

        return $dataRoot !== ''
            && str_starts_with($path, $dataRoot.'/')
            && is_file($path);
    }

    private function isViewLeafFile(string $path): bool
    {
        $viewsRoot = $this->normalizePath(storage_path('framework/views'));

        return $viewsRoot !== ''
            && str_starts_with($path, $viewsRoot.'/')
            && is_file($path);
    }

    private function surfaceForPath(string $path): string
    {
        foreach ($this->rootDefinitions() as $definition) {
            $root = $this->normalizePath(storage_path($definition['relative_dir']));
            if ($root !== '' && ($path === $root || str_starts_with($path, $root.'/'))) {
                return $definition['surface'];
            }
        }

        return 'unknown';
    }

    private function relativeStoragePath(string $path): string
    {
        $storageRoot = $this->normalizePath(storage_path());

        if ($storageRoot === '' || ! str_starts_with($path, $storageRoot.'/')) {
            return $path;
        }

        return substr($path, strlen($storageRoot) + 1);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim(trim($path), '/\\'));
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function countByReason(array $entries): array
    {
        $counts = [];

        foreach ($entries as $entry) {
            $reason = (string) ($entry['reason'] ?? 'UNKNOWN');
            $counts[$reason] = (int) ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function uniqueSorted(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string,mixed>
     */
    private function skip(string $path, string $reason, string $surface): array
    {
        return [
            'candidate' => false,
            'path' => $path,
            'relative_path' => $this->relativeStoragePath($path),
            'surface' => $surface,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $deletedPaths = is_array($payload['deleted_paths'] ?? null) ? $payload['deleted_paths'] : [];
        $skippedReasons = is_array($payload['skipped_reasons'] ?? null) ? $payload['skipped_reasons'] : [];

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_janitor_runtime_temps',
            'target_type' => 'storage',
            'target_id' => 'runtime_temps',
            'meta_json' => json_encode([
                'schema' => self::SCHEMA,
                'mode' => $payload['mode'] ?? null,
                'generated_at' => $payload['generated_at'] ?? null,
                'summary' => $payload['summary'] ?? [],
                'deleted_paths' => $deletedPaths,
                'skipped_reasons' => $skippedReasons,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_janitor_runtime_temps',
            'request_id' => null,
            'reason' => 'runtime_temp_retention',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
