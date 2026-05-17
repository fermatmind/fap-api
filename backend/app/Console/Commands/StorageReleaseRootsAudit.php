<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageReleaseRootsAudit extends Command
{
    protected $signature = 'storage:release-roots:audit {--format=json : Output format. Only json is supported.}';

    protected $description = 'Read-only audit of content release runtime roots before any local cleanup.';

    private const ROOT_RELATIVE_PATH = 'content_releases';

    private const CLASS_STRONG_KEEP = 'strong_keep';

    private const CLASS_DANGLING = 'dangling_ref_repair_required';

    private const CLASS_CURRENT_PACK_LOW_RISK = 'unreferenced_current_pack_low_risk_candidate';

    private const CLASS_PREVIOUS_PACK_REVIEW = 'unreferenced_previous_pack_review_required';

    private const CLASS_SOURCE_PACK_REVIEW = 'unreferenced_source_pack_review_required';

    private const CLASS_UNKNOWN_REVIEW = 'unknown_shape_review_required';

    private const CLASS_ROOT_MISSING = 'root_missing_no_action';

    private const CLASS_ROOT_EMPTY = 'root_empty_no_action';

    public function handle(): int
    {
        $format = strtolower(trim((string) $this->option('format')));
        if ($format !== 'json') {
            $this->error('unsupported --format: '.$format);

            return self::FAILURE;
        }

        $root = storage_path('app/private/'.self::ROOT_RELATIVE_PATH);
        $payload = $this->buildPayload($root);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed to encode storage release roots audit json.');

            return self::FAILURE;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(string $root): array
    {
        $rootExists = is_dir($root);
        $rootEmpty = $rootExists && $this->directoryIsEmpty($root);

        $dbScan = $this->scanDatabaseReferences();
        $roots = $rootExists && ! $rootEmpty ? $this->collectReleaseRoots($root) : [];
        $rootIndex = [];

        foreach ($roots as $index => $releaseRoot) {
            $rootIndex[(string) $releaseRoot['relative_path']] = $index;
        }

        $referencedRootIndexes = [];
        $danglingRefs = [];
        foreach ($dbScan['refs'] as $ref) {
            $matched = false;
            foreach ($rootIndex as $relativePath => $index) {
                if ($this->referenceMatchesRoot((string) $ref['value'], $relativePath, (string) $roots[$index]['path'])) {
                    $referencedRootIndexes[$index] = true;
                    $matched = true;
                }
            }

            if (! $matched) {
                $dangling = $this->classifyDanglingReference((string) $ref['value'], $root);
                if ($dangling !== null) {
                    $danglingRefs[] = $ref + [
                        'classification' => self::CLASS_DANGLING,
                        'referenced_root_kind' => $dangling['kind'],
                        'referenced_root_path' => $dangling['path'],
                        'referenced_root_exists' => false,
                    ];
                }
            }
        }

        foreach ($roots as $index => $releaseRoot) {
            $roots[$index]['classification'] = isset($referencedRootIndexes[$index])
                ? self::CLASS_STRONG_KEEP
                : $this->classificationForUnreferencedKind((string) $releaseRoot['kind']);
        }

        $rootStateClassification = null;
        if (! $rootExists) {
            $rootStateClassification = self::CLASS_ROOT_MISSING;
        } elseif ($rootEmpty) {
            $rootStateClassification = self::CLASS_ROOT_EMPTY;
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toAtomString(),
            'command' => 'storage:release-roots:audit',
            'root' => [
                'path' => $root,
                'relative_path' => self::ROOT_RELATIVE_PATH,
                'exists' => $rootExists,
                'empty' => $rootEmpty,
                'classification' => $rootStateClassification,
            ],
            'safety' => [
                'cleanup_executed' => false,
                'plan_file_written' => false,
                'db_mutated' => false,
                'prune_invoked' => false,
                'delete_move_quarantine_options_available' => false,
            ],
            'summary' => $this->buildSummary($roots, $danglingRefs, $rootStateClassification),
            'roots' => $roots,
            'db_refs' => [
                'tables_scanned' => $dbScan['tables_scanned'],
                'columns_scanned' => $dbScan['columns_scanned'],
                'content_release_related_refs' => $dbScan['content_release_related_refs'],
                'generic_content_releases_refs' => $dbScan['generic_content_releases_refs'],
                'dangling_refs' => $danglingRefs,
                'refs' => $dbScan['refs'],
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectReleaseRoots(string $root): array
    {
        $roots = [];
        $knownPaths = [];

        $this->collectNamedRoots($root, 'source_pack', $roots, $knownPaths);
        $this->collectNamedRoots($root, 'previous_pack', $roots, $knownPaths);
        $this->collectNamedRoots($root, 'current_pack', $roots, $knownPaths);
        $this->collectUnknownRoots($root, $roots, $knownPaths);

        usort($roots, static fn (array $left, array $right): int => strcmp((string) $left['relative_path'], (string) $right['relative_path']));

        return $roots;
    }

    /**
     * @param  list<array<string,mixed>>  $roots
     * @param  array<string,bool>  $knownPaths
     */
    private function collectNamedRoots(string $root, string $kind, array &$roots, array &$knownPaths): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isDir() || $item->getBasename() !== $kind) {
                continue;
            }

            $path = $this->normalizePath($item->getPathname());
            $knownPaths[$path] = true;
            $stats = $this->directoryStats($path);
            $roots[] = [
                'kind' => $kind,
                'path' => $path,
                'relative_path' => $this->relativeToPrivateStorage($path),
                'release_id' => basename(dirname($path)),
                'bytes' => $stats['bytes'],
                'file_count' => $stats['file_count'],
                'mtime' => $stats['mtime'],
                'classification' => null,
            ];
        }
    }

    /**
     * @param  list<array<string,mixed>>  $roots
     * @param  array<string,bool>  $knownPaths
     */
    private function collectUnknownRoots(string $root, array &$roots, array $knownPaths): void
    {
        $children = glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*') ?: [];
        foreach ($children as $child) {
            $child = $this->normalizePath($child);
            if (! is_dir($child)) {
                $roots[] = $this->unknownRootEntry($child);

                continue;
            }

            if (basename($child) === 'backups') {
                $backupChildren = glob($child.DIRECTORY_SEPARATOR.'*') ?: [];
                foreach ($backupChildren as $backupChild) {
                    $backupChild = $this->normalizePath($backupChild);
                    if (! is_dir($backupChild)) {
                        $roots[] = $this->unknownRootEntry($backupChild);

                        continue;
                    }

                    $hasKnownBackupRoot = false;
                    foreach (['current_pack', 'previous_pack'] as $knownKind) {
                        if (isset($knownPaths[$this->normalizePath($backupChild.DIRECTORY_SEPARATOR.$knownKind)])) {
                            $hasKnownBackupRoot = true;
                        }
                    }

                    if (! $hasKnownBackupRoot) {
                        $roots[] = $this->unknownRootEntry($backupChild);
                    }
                }

                continue;
            }

            if (! isset($knownPaths[$this->normalizePath($child.DIRECTORY_SEPARATOR.'source_pack')])) {
                $roots[] = $this->unknownRootEntry($child);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function unknownRootEntry(string $path): array
    {
        $stats = is_dir($path) ? $this->directoryStats($path) : [
            'bytes' => is_file($path) ? max(0, (int) (filesize($path) ?: 0)) : 0,
            'file_count' => is_file($path) ? 1 : 0,
            'mtime' => file_exists($path) ? $this->formatMtime((int) (filemtime($path) ?: 0)) : null,
        ];

        return [
            'kind' => 'unknown',
            'path' => $path,
            'relative_path' => $this->relativeToPrivateStorage($path),
            'release_id' => basename($path),
            'bytes' => $stats['bytes'],
            'file_count' => $stats['file_count'],
            'mtime' => $stats['mtime'],
            'classification' => self::CLASS_UNKNOWN_REVIEW,
        ];
    }

    /**
     * @return array{bytes:int,file_count:int,mtime:?string}
     */
    private function directoryStats(string $path): array
    {
        $bytes = 0;
        $files = 0;
        $newest = is_dir($path) ? (int) (filemtime($path) ?: 0) : 0;

        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $files++;
                $bytes += max(0, (int) ($file->getSize() ?: 0));
                $mtime = (int) ($file->getMTime() ?: 0);
                if ($mtime > $newest) {
                    $newest = $mtime;
                }
            }
        }

        return [
            'bytes' => $bytes,
            'file_count' => $files,
            'mtime' => $this->formatMtime($newest),
        ];
    }

    /**
     * @return array{
     *   tables_scanned:int,
     *   columns_scanned:int,
     *   content_release_related_refs:int,
     *   generic_content_releases_refs:int,
     *   refs:list<array<string,mixed>>
     * }
     */
    private function scanDatabaseReferences(): array
    {
        $refs = [];
        $tablesScanned = 0;
        $columnsScanned = 0;
        $relatedRefs = 0;
        $genericRefs = 0;

        $tables = $this->databaseTables();
        foreach ($tables as $table) {
            $tablesScanned++;
            $columns = $this->textLikeColumns($table);

            foreach ($columns as $column) {
                $columnsScanned++;
                $count = $this->countColumnRefs($table, $column);
                if ($count <= 0) {
                    continue;
                }

                $isRelated = $this->isContentReleaseRelatedColumn($table, $column);
                if ($isRelated) {
                    $relatedRefs += $count;
                } else {
                    $genericRefs += $count;
                }

                foreach ($this->sampleColumnRefs($table, $column) as $value) {
                    $refs[] = [
                        'table' => $table,
                        'column' => $column,
                        'value' => $value,
                        'content_release_related' => $isRelated,
                    ];
                }
            }
        }

        return [
            'tables_scanned' => $tablesScanned,
            'columns_scanned' => $columnsScanned,
            'content_release_related_refs' => $relatedRefs,
            'generic_content_releases_refs' => $genericRefs,
            'refs' => $refs,
        ];
    }

    /**
     * @return list<string>
     */
    private function databaseTables(): array
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

            return array_values(array_map(static fn (object $row): string => (string) $row->name, $rows));
        }

        return array_values(array_filter(Schema::getTables(), static fn (array $table): bool => isset($table['name'])))
            ? array_values(array_map(static fn (array $table): string => (string) $table['name'], Schema::getTables()))
            : [];
    }

    /**
     * @return list<string>
     */
    private function textLikeColumns(string $table): array
    {
        $columns = [];
        foreach (Schema::getColumnListing($table) as $column) {
            $type = '';
            try {
                $type = strtolower((string) Schema::getColumnType($table, $column));
            } catch (\Throwable) {
                $type = '';
            }

            $name = strtolower($column);
            if (
                str_contains($type, 'string')
                || str_contains($type, 'text')
                || str_contains($type, 'char')
                || str_contains($type, 'json')
                || str_contains($name, 'path')
                || str_contains($name, 'file')
                || str_contains($name, 'uri')
                || str_contains($name, 'location')
                || str_contains($name, 'payload')
            ) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function countColumnRefs(string $table, string $column): int
    {
        try {
            return (int) DB::table($table)->where($column, 'like', '%'.self::ROOT_RELATIVE_PATH.'%')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return list<string>
     */
    private function sampleColumnRefs(string $table, string $column): array
    {
        try {
            return DB::table($table)
                ->where($column, 'like', '%'.self::ROOT_RELATIVE_PATH.'%')
                ->limit(50)
                ->pluck($column)
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function isContentReleaseRelatedColumn(string $table, string $column): bool
    {
        $table = strtolower($table);
        $column = strtolower($column);

        return str_contains($table, 'content_release')
            || str_contains($table, 'content_pack_release')
            || str_contains($table, 'manifest')
            || in_array($column, ['storage_path', 'source_storage_path', 'canonical_root', 'root_path', 'path'], true);
    }

    private function referenceMatchesRoot(string $value, string $relativePath, string $absolutePath): bool
    {
        $normalized = $this->normalizePath($value);
        $relativePath = $this->normalizePath($relativePath);
        $absolutePath = $this->normalizePath($absolutePath);

        return str_contains($normalized, $relativePath) || str_contains($normalized, $absolutePath);
    }

    /**
     * @return array{kind:string,path:string}|null
     */
    private function classifyDanglingReference(string $value, string $root): ?array
    {
        $normalized = $this->normalizePath($value);
        $patterns = [
            'source_pack' => '#content_releases/([^/]+)/source_pack#',
            'previous_pack' => '#content_releases/backups/([^/]+)/previous_pack#',
            'current_pack' => '#content_releases/backups/([^/]+)/current_pack#',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $normalized, $matches) !== 1) {
                continue;
            }

            $relative = $kind === 'source_pack'
                ? 'content_releases/'.$matches[1].'/source_pack'
                : 'content_releases/backups/'.$matches[1].'/'.$kind;
            $path = rtrim(dirname($root), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;

            return [
                'kind' => $kind,
                'path' => $this->normalizePath($path),
            ];
        }

        return null;
    }

    private function classificationForUnreferencedKind(string $kind): string
    {
        return match ($kind) {
            'current_pack' => self::CLASS_CURRENT_PACK_LOW_RISK,
            'previous_pack' => self::CLASS_PREVIOUS_PACK_REVIEW,
            'source_pack' => self::CLASS_SOURCE_PACK_REVIEW,
            default => self::CLASS_UNKNOWN_REVIEW,
        };
    }

    /**
     * @param  list<array<string,mixed>>  $roots
     * @param  list<array<string,mixed>>  $danglingRefs
     * @return array<string,mixed>
     */
    private function buildSummary(array $roots, array $danglingRefs, ?string $rootStateClassification): array
    {
        $byKind = [
            'source_pack' => 0,
            'previous_pack' => 0,
            'current_pack' => 0,
            'unknown' => 0,
        ];
        $byClassification = [];
        $bytes = 0;
        $files = 0;

        foreach ($roots as $root) {
            $kind = (string) ($root['kind'] ?? 'unknown');
            $byKind[$kind] = (int) (($byKind[$kind] ?? 0) + 1);
            $classification = (string) ($root['classification'] ?? self::CLASS_UNKNOWN_REVIEW);
            $byClassification[$classification] = (int) (($byClassification[$classification] ?? 0) + 1);
            $bytes += (int) ($root['bytes'] ?? 0);
            $files += (int) ($root['file_count'] ?? 0);
        }

        foreach ($danglingRefs as $danglingRef) {
            $classification = (string) ($danglingRef['classification'] ?? self::CLASS_DANGLING);
            $byClassification[$classification] = (int) (($byClassification[$classification] ?? 0) + 1);
        }

        if ($rootStateClassification !== null) {
            $byClassification[$rootStateClassification] = (int) (($byClassification[$rootStateClassification] ?? 0) + 1);
        }

        ksort($byClassification);

        return [
            'root_count' => count($roots),
            'bytes' => $bytes,
            'file_count' => $files,
            'by_kind' => $byKind,
            'by_classification' => $byClassification,
            'dangling_ref_count' => count($danglingRefs),
        ];
    }

    private function directoryIsEmpty(string $root): bool
    {
        $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);

        return ! $iterator->valid();
    }

    private function relativeToPrivateStorage(string $path): string
    {
        $privateRoot = $this->normalizePath(storage_path('app/private')).'/';
        $path = $this->normalizePath($path);

        return str_starts_with($path, $privateRoot) ? substr($path, strlen($privateRoot)) : $path;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function formatMtime(int $timestamp): ?string
    {
        return $timestamp > 0 ? date(DATE_ATOM, $timestamp) : null;
    }
}
