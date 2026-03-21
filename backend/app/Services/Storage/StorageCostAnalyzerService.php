<?php

declare(strict_types=1);

namespace App\Services\Storage;

final class StorageCostAnalyzerService
{
    private const SCHEMA_VERSION = 'storage_cost_analyzer.v1';

    private const TOP_DIRECTORY_LIMIT = 20;

    /**
     * @var list<string>
     */
    private const CATEGORY_ORDER = [
        'control_plane_snapshots',
        'control_plane_plans',
        'control_plane_receipts',
        'quarantine_live_roots',
        'v2_materialized_cache',
        'offload_blob_copies',
        'framework_cache_or_sessions',
        'logs',
        'runtime_or_data_truth',
        'unknown',
    ];

    /**
     * @var array<string,array{risk_level:string,rationale:string,safe_next_action:string}>
     */
    private const CATEGORY_POLICY = [
        'control_plane_snapshots' => [
            'risk_level' => 'low',
            'rationale' => 'control-plane snapshots are read-only reporting artifacts and can be regenerated from the current storage state',
            'safe_next_action' => 'janitor snapshots',
        ],
        'control_plane_plans' => [
            'risk_level' => 'low',
            'rationale' => 'derived control-plane plan artifacts are rebuildable and do not represent runtime truth',
            'safe_next_action' => 'janitor plans',
        ],
        'control_plane_receipts' => [
            'risk_level' => 'manual_only',
            'rationale' => 'run receipts record operator-visible lifecycle history and should be retained unless a separate retention policy is approved',
            'safe_next_action' => 'retain',
        ],
        'quarantine_live_roots' => [
            'risk_level' => 'no_touch',
            'rationale' => 'quarantined release roots are live lifecycle inputs for restore or purge and must not be modified by analysis-only work',
            'safe_next_action' => 'retain; restore/purge inputs',
        ],
        'v2_materialized_cache' => [
            'risk_level' => 'medium',
            'rationale' => 'materialized v2 cache is rebuildable but impacts runtime warmness and needs a dedicated cache janitor contract',
            'safe_next_action' => 'future materialized-cache janitor PR',
        ],
        'offload_blob_copies' => [
            'risk_level' => 'manual_only',
            'rationale' => 'offloaded blob copies may mirror remote rollout state and should be evaluated separately before any deletion decision',
            'safe_next_action' => 'analyze separately before deletion',
        ],
        'framework_cache_or_sessions' => [
            'risk_level' => 'manual_review',
            'rationale' => 'framework cache and session trees can be regenerated or expired, but active runtime usage must be understood first',
            'safe_next_action' => 'analyze framework cache/session retention separately',
        ],
        'logs' => [
            'risk_level' => 'low',
            'rationale' => 'log files are operational artifacts and are typically the safest storage surface for a separate retention or rotation PR',
            'safe_next_action' => 'future log retention/rotation PR',
        ],
        'runtime_or_data_truth' => [
            'risk_level' => 'no_touch',
            'rationale' => 'runtime or data-truth trees hold canonical data and should not be targeted by storage cost reduction without a separate contract change',
            'safe_next_action' => 'retain',
        ],
        'unknown' => [
            'risk_level' => 'manual_review',
            'rationale' => 'unknown storage trees are not covered by the current classifier and require manual inspection before any action',
            'safe_next_action' => 'inspect before action',
        ],
    ];

    /**
     * @var list<string>
     */
    private const NO_TOUCH_CATEGORIES = [
        'quarantine_live_roots',
        'runtime_or_data_truth',
    ];

    /**
     * @var list<string>
     */
    private const ANCHOR_PREFIXES = [
        'app/private/control_plane_snapshots',
        'app/private/gc_plans',
        'app/private/offload_plans',
        'app/private/rehydrate_plans',
        'app/private/quarantine_plans',
        'app/private/quarantine_restore_plans',
        'app/private/quarantine_purge_plans',
        'app/private/retirement_plans',
        'app/private/prune_plans',
        'app/private/quarantine/restore_runs',
        'app/private/quarantine/purge_runs',
        'app/private/retirement_runs',
        'app/private/rehydrate_runs',
        'app/private/quarantine/release_roots',
        'app/private/packs_v2_materialized',
        'app/private/offload/blobs',
        'app/private/blobs',
        'app/private/content_releases',
        'app/private/reports',
        'app/private/artifacts',
        'app/private/packs_v2',
        'app/content_packs_v2',
        'app/public',
        'framework/cache',
        'framework/sessions',
        'framework/views',
        'framework/testing',
        'logs',
    ];

    /**
     * @return array<string,mixed>
     */
    public function analyze(?string $rootPath = null): array
    {
        $rootPath = $this->normalizePath($rootPath ?? storage_path());

        $summary = [
            'total_bytes' => 0,
            'total_files' => 0,
            'total_directories' => 0,
            'largest_category' => null,
            'largest_category_bytes' => 0,
        ];

        /** @var array<string,array{bytes:int,file_count:int,directory_count:int}> $byCategory */
        $byCategory = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $byCategory[$category] = [
                'bytes' => 0,
                'file_count' => 0,
                'directory_count' => 0,
            ];
        }

        /** @var array<string,array{path:string,bytes:int,file_count:int,directory_count:int,category:string,risk_level:string}> $topDirectoryIndex */
        $topDirectoryIndex = [];

        if (is_dir($rootPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if (! $item instanceof \SplFileInfo || $item->isLink()) {
                    continue;
                }

                $relativePath = $this->relativePath($rootPath, $item->getPathname());
                if ($relativePath === '') {
                    continue;
                }

                if ($item->isDir()) {
                    $summary['total_directories']++;

                    $category = $this->classifyRelativePath($relativePath);
                    $byCategory[$category]['directory_count']++;

                    $anchor = $this->anchorPathForRelativePath($relativePath, true);
                    $anchorStats = &$this->topDirectoryEntry($topDirectoryIndex, $anchor);
                    if ($relativePath !== $anchor) {
                        $anchorStats['directory_count']++;
                    }
                    unset($anchorStats);

                    continue;
                }

                if (! $item->isFile()) {
                    continue;
                }

                $size = max(0, (int) ($item->getSize() ?: 0));
                $summary['total_bytes'] += $size;
                $summary['total_files']++;

                $category = $this->classifyRelativePath($relativePath);
                $byCategory[$category]['bytes'] += $size;
                $byCategory[$category]['file_count']++;

                $anchor = $this->anchorPathForRelativePath($relativePath, false);
                $anchorStats = &$this->topDirectoryEntry($topDirectoryIndex, $anchor);
                $anchorStats['bytes'] += $size;
                $anchorStats['file_count']++;
                unset($anchorStats);
            }
        }

        $summary['largest_category'] = $this->largestCategory($byCategory);
        $summary['largest_category_bytes'] = $summary['largest_category'] === null
            ? 0
            : (int) $byCategory[$summary['largest_category']]['bytes'];

        $topDirectories = array_values(array_filter(
            $topDirectoryIndex,
            static fn (array $row): bool => (int) ($row['bytes'] ?? 0) > 0
        ));
        usort($topDirectories, fn (array $left, array $right): int => $this->compareDirectoryRows($left, $right));
        $topDirectories = array_slice($topDirectories, 0, self::TOP_DIRECTORY_LIMIT);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'generated_at' => now()->toIso8601String(),
            'root_path' => $rootPath,
            'summary' => $summary,
            'top_directories' => $topDirectories,
            'by_category' => $byCategory,
            'reclaim_candidates' => $this->buildReclaimCandidates($byCategory),
            'no_touch_categories' => self::NO_TOUCH_CATEGORIES,
            'suggested_next_actions' => $this->buildSuggestedNextActions($byCategory),
        ];
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return rtrim($normalized, '/');
    }

    private function relativePath(string $rootPath, string $fullPath): string
    {
        $rootPath = $this->normalizePath($rootPath);
        $fullPath = $this->normalizePath($fullPath);

        if ($fullPath === $rootPath) {
            return '';
        }

        if (! str_starts_with($fullPath, $rootPath.'/')) {
            return ltrim($fullPath, '/');
        }

        return ltrim(substr($fullPath, strlen($rootPath)), '/');
    }

    private function classifyRelativePath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

        return match (true) {
            $this->matchesPrefix($relativePath, 'app/private/control_plane_snapshots') => 'control_plane_snapshots',
            $this->matchesAnyPrefix($relativePath, [
                'app/private/gc_plans',
                'app/private/offload_plans',
                'app/private/rehydrate_plans',
                'app/private/quarantine_plans',
                'app/private/quarantine_restore_plans',
                'app/private/quarantine_purge_plans',
                'app/private/retirement_plans',
                'app/private/prune_plans',
            ]) => 'control_plane_plans',
            $this->matchesAnyPrefix($relativePath, [
                'app/private/quarantine/restore_runs',
                'app/private/quarantine/purge_runs',
                'app/private/retirement_runs',
                'app/private/rehydrate_runs',
            ]) => 'control_plane_receipts',
            $this->matchesPrefix($relativePath, 'app/private/quarantine/release_roots') => 'quarantine_live_roots',
            $this->matchesPrefix($relativePath, 'app/private/packs_v2_materialized') => 'v2_materialized_cache',
            $this->matchesPrefix($relativePath, 'app/private/offload/blobs') => 'offload_blob_copies',
            $this->matchesPrefix($relativePath, 'framework') => 'framework_cache_or_sessions',
            $this->matchesPrefix($relativePath, 'logs') => 'logs',
            $this->matchesAnyPrefix($relativePath, [
                'app/private/blobs',
                'app/private/content_releases',
                'app/private/reports',
                'app/private/artifacts',
                'app/private/packs_v2',
                'app/content_packs_v2',
                'app/public',
            ]) => 'runtime_or_data_truth',
            default => 'unknown',
        };
    }

    private function anchorPathForRelativePath(string $relativePath, bool $isDirectory): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $pathForAnchor = $isDirectory ? $relativePath : $this->parentDirectoryForFile($relativePath);

        foreach (self::ANCHOR_PREFIXES as $prefix) {
            if ($this->matchesPrefix($pathForAnchor, $prefix)) {
                return $prefix;
            }
        }

        if ($this->matchesPrefix($pathForAnchor, 'app/private/quarantine')) {
            return $this->slicePath($pathForAnchor, 4);
        }

        if ($this->matchesPrefix($pathForAnchor, 'app/private')) {
            return $this->slicePath($pathForAnchor, 3);
        }

        if ($this->matchesPrefix($pathForAnchor, 'app')) {
            return $this->slicePath($pathForAnchor, 2);
        }

        if ($this->matchesPrefix($pathForAnchor, 'framework')) {
            return $this->slicePath($pathForAnchor, 2);
        }

        if ($this->matchesPrefix($pathForAnchor, 'logs')) {
            return 'logs';
        }

        return $this->slicePath($pathForAnchor, 1);
    }

    private function parentDirectoryForFile(string $relativePath): string
    {
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '/.');

        if ($directory !== '') {
            return $directory;
        }

        return trim(basename($relativePath), '/');
    }

    private function matchesAnyPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($this->matchesPrefix($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPrefix(string $path, string $prefix): bool
    {
        $path = trim($path, '/');
        $prefix = trim($prefix, '/');

        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    private function slicePath(string $path, int $segments): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== ''));

        return implode('/', array_slice($parts, 0, max(1, $segments)));
    }

    /**
     * @param  array<string,array{path:string,bytes:int,file_count:int,directory_count:int,category:string,risk_level:string}>  $index
     * @return array{path:string,bytes:int,file_count:int,directory_count:int,category:string,risk_level:string}
     */
    private function &topDirectoryEntry(array &$index, string $anchor): array
    {
        if (! isset($index[$anchor])) {
            $category = $this->classifyRelativePath($anchor);
            $index[$anchor] = [
                'path' => $anchor,
                'bytes' => 0,
                'file_count' => 0,
                'directory_count' => 0,
                'category' => $category,
                'risk_level' => self::CATEGORY_POLICY[$category]['risk_level'],
            ];
        }

        return $index[$anchor];
    }

    /**
     * @param  array{path:string,bytes:int,file_count:int,directory_count:int,category:string,risk_level:string}  $left
     * @param  array{path:string,bytes:int,file_count:int,directory_count:int,category:string,risk_level:string}  $right
     */
    private function compareDirectoryRows(array $left, array $right): int
    {
        $bytes = $right['bytes'] <=> $left['bytes'];
        if ($bytes !== 0) {
            return $bytes;
        }

        return strcmp($left['path'], $right['path']);
    }

    /**
     * @param  array<string,array{bytes:int,file_count:int,directory_count:int}>  $byCategory
     */
    private function largestCategory(array $byCategory): ?string
    {
        $largest = null;

        foreach (self::CATEGORY_ORDER as $category) {
            if (! isset($byCategory[$category])) {
                continue;
            }

            if ($largest === null) {
                $largest = $category;

                continue;
            }

            $currentBytes = (int) $byCategory[$category]['bytes'];
            $largestBytes = (int) $byCategory[$largest]['bytes'];
            if ($currentBytes > $largestBytes) {
                $largest = $category;
            }
        }

        if ($largest === null || (int) $byCategory[$largest]['bytes'] === 0) {
            return null;
        }

        return $largest;
    }

    /**
     * @param  array<string,array{bytes:int,file_count:int,directory_count:int}>  $byCategory
     * @return list<array{category:string,bytes:int,rationale:string,safe_next_action:string,risk_level:string}>
     */
    private function buildReclaimCandidates(array $byCategory): array
    {
        $candidates = [];

        foreach (self::CATEGORY_ORDER as $category) {
            if (in_array($category, self::NO_TOUCH_CATEGORIES, true)) {
                continue;
            }

            $bytes = (int) ($byCategory[$category]['bytes'] ?? 0);
            if ($bytes <= 0) {
                continue;
            }

            $policy = self::CATEGORY_POLICY[$category];
            $candidates[] = [
                'category' => $category,
                'bytes' => $bytes,
                'rationale' => $policy['rationale'],
                'safe_next_action' => $policy['safe_next_action'],
                'risk_level' => $policy['risk_level'],
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            $bytes = $right['bytes'] <=> $left['bytes'];
            if ($bytes !== 0) {
                return $bytes;
            }

            return strcmp($left['category'], $right['category']);
        });

        return $candidates;
    }

    /**
     * @param  array<string,array{bytes:int,file_count:int,directory_count:int}>  $byCategory
     * @return list<array{priority:int,category:string,bytes:int,rationale:string,safe_next_action:string,risk_level:string}>
     */
    private function buildSuggestedNextActions(array $byCategory): array
    {
        $priorityOrder = [
            'low' => 0,
            'medium' => 1,
            'manual_review' => 2,
            'manual_only' => 3,
        ];

        $actions = [];

        foreach ($this->buildReclaimCandidates($byCategory) as $candidate) {
            if ($candidate['safe_next_action'] === 'retain') {
                continue;
            }

            $actions[] = $candidate;
        }

        usort($actions, static function (array $left, array $right) use ($priorityOrder): int {
            $leftPriority = $priorityOrder[$left['risk_level']] ?? 99;
            $rightPriority = $priorityOrder[$right['risk_level']] ?? 99;

            $priority = $leftPriority <=> $rightPriority;
            if ($priority !== 0) {
                return $priority;
            }

            $bytes = $right['bytes'] <=> $left['bytes'];
            if ($bytes !== 0) {
                return $bytes;
            }

            return strcmp($left['category'], $right['category']);
        });

        $prioritized = [];
        foreach (array_values($actions) as $index => $action) {
            $prioritized[] = [
                'priority' => $index + 1,
                'category' => $action['category'],
                'bytes' => $action['bytes'],
                'rationale' => $action['rationale'],
                'safe_next_action' => $action['safe_next_action'],
                'risk_level' => $action['risk_level'],
            ];
        }

        return $prioritized;
    }
}
