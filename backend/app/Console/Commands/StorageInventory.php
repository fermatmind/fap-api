<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageInventory extends Command
{
    protected $signature = 'storage:inventory {--json : JSON output}';

    protected $description = 'Inventory storage usage by scope (files/bytes/mtime/top dirs/files/duplicates).';

    private const TOP_LIMIT = 5;

    private const DUPLICATE_GROUP_LIMIT = 5;

    private const DUPLICATE_SAMPLE_LIMIT = 3;

    public function handle(): int
    {
        $rows = [
            $this->collectScope('reports', storage_path('app/private/reports')),
            $this->collectScope('artifacts', storage_path('app/private/artifacts')),
            $this->collectScope('content_releases', storage_path('app/private/content_releases')),
            $this->collectScope('packs_v2', storage_path('app/private/packs_v2')),
            $this->collectScope('content_packs_v2', storage_path('app/content_packs_v2')),
            $this->collectScope('logs', storage_path('logs')),
        ];
        $payload = $this->buildPayload($rows);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $display = array_map(static function (array $row): array {
                return [
                    'scope' => (string) $row['scope'],
                    'files' => (int) $row['files'],
                    'bytes' => (int) $row['bytes'],
                    'oldest_mtime' => (string) $row['oldest_mtime'],
                    'newest_mtime' => (string) $row['newest_mtime'],
                    'top_dirs' => (string) json_encode($row['top_dirs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }, $rows);

            $this->table(['scope', 'files', 'bytes', 'oldest_mtime', 'newest_mtime', 'top_dirs'], $display);
        }

        $this->recordAudit($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   scope:string,
     *   root_path:string,
     *   files:int,
     *   bytes:int,
     *   oldest_mtime:?string,
     *   newest_mtime:?string,
     *   top_dirs:array<string,int>,
     *   top_dirs_by_bytes:list<array{path:string,files:int,bytes:int}>,
     *   top_files:list<array{path:string,bytes:int,mtime:?string}>,
     *   duplicate_summary:array{
     *     groups:int,
     *     files:int,
     *     bytes:int,
     *     wasted_bytes:int,
     *     top_groups:list<array{
     *       sha256:string,
     *       files:int,
     *       bytes:int,
     *       total_bytes:int,
     *       wasted_bytes:int,
     *       sample_paths:list<string>
     *     }>
     *   }
     * }
     */
    private function collectScope(string $scope, string $root): array
    {
        if (! is_dir($root)) {
            return [
                'scope' => $scope,
                'root_path' => $root,
                'files' => 0,
                'bytes' => 0,
                'oldest_mtime' => null,
                'newest_mtime' => null,
                'top_dirs' => [],
                'top_dirs_by_bytes' => [],
                'top_files' => [],
                'duplicate_summary' => $this->emptyDuplicateSummary(),
            ];
        }

        $files = 0;
        $bytes = 0;
        $oldest = null;
        $newest = null;
        $topDirs = [];
        $dirBytes = [];
        $topFiles = [];
        $duplicateCandidates = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $files++;
            $size = max(0, (int) ($file->getSize() ?: 0));
            $bytes += $size;

            $mtime = (int) ($file->getMTime() ?: 0);
            if ($oldest === null || $mtime < $oldest) {
                $oldest = $mtime;
            }
            if ($newest === null || $mtime > $newest) {
                $newest = $mtime;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            $rootNorm = rtrim(str_replace('\\', '/', $root), '/').'/';
            $relative = str_starts_with($fullPath, $rootNorm)
                ? substr($fullPath, strlen($rootNorm))
                : basename($fullPath);
            $relative = ltrim((string) $relative, '/');
            $first = explode('/', $relative)[0] ?? '.';
            $first = trim((string) $first) !== '' ? (string) $first : '.';
            $topDirs[$first] = (int) (($topDirs[$first] ?? 0) + 1);
            $dirBytes[$first] = [
                'path' => $first,
                'files' => (int) (($dirBytes[$first]['files'] ?? 0) + 1),
                'bytes' => (int) (($dirBytes[$first]['bytes'] ?? 0) + $size),
            ];
            $topFiles[] = [
                'path' => $relative,
                'bytes' => $size,
                'mtime' => $mtime > 0 ? date(DATE_ATOM, $mtime) : null,
            ];
            $duplicateCandidates[$size][] = [
                'path' => $relative,
                'full_path' => $fullPath,
            ];
        }

        arsort($topDirs);
        $topDirs = array_slice($topDirs, 0, self::TOP_LIMIT, true);

        uasort($dirBytes, static function (array $left, array $right): int {
            $bytes = (int) $right['bytes'] <=> (int) $left['bytes'];
            if ($bytes !== 0) {
                return $bytes;
            }

            $files = (int) $right['files'] <=> (int) $left['files'];
            if ($files !== 0) {
                return $files;
            }

            return strcmp((string) $left['path'], (string) $right['path']);
        });
        $dirBytes = array_values(array_slice($dirBytes, 0, self::TOP_LIMIT));

        usort($topFiles, static function (array $left, array $right): int {
            $bytes = (int) $right['bytes'] <=> (int) $left['bytes'];
            if ($bytes !== 0) {
                return $bytes;
            }

            return strcmp((string) $left['path'], (string) $right['path']);
        });
        $topFiles = array_slice($topFiles, 0, self::TOP_LIMIT);

        return [
            'scope' => $scope,
            'root_path' => $root,
            'files' => $files,
            'bytes' => $bytes,
            'oldest_mtime' => $oldest !== null ? date(DATE_ATOM, $oldest) : null,
            'newest_mtime' => $newest !== null ? date(DATE_ATOM, $newest) : null,
            'top_dirs' => $topDirs,
            'top_dirs_by_bytes' => $dirBytes,
            'top_files' => $topFiles,
            'duplicate_summary' => $this->buildDuplicateSummary($duplicateCandidates),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{
     *   schema_version:int,
     *   generated_at:string,
     *   scope_count:int,
     *   totals:array{files:int,bytes:int,oldest_mtime:?string,newest_mtime:?string},
     *   scope_totals:array<string,array{files:int,bytes:int,duplicate_groups:int,wasted_bytes:int}>,
     *   focus_scopes:array<string,array<string,mixed>>,
     *   scopes:list<array<string,mixed>>
     * }
     */
    private function buildPayload(array $rows): array
    {
        return [
            'schema_version' => 2,
            'generated_at' => now()->toAtomString(),
            'scope_count' => count($rows),
            'totals' => $this->summarizeTotals($rows),
            'scope_totals' => $this->buildScopeTotals($rows),
            'focus_scopes' => $this->buildFocusScopes($rows),
            'scopes' => $rows,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{files:int,bytes:int,oldest_mtime:?string,newest_mtime:?string}
     */
    private function summarizeTotals(array $rows): array
    {
        $files = 0;
        $bytes = 0;
        $oldest = null;
        $newest = null;

        foreach ($rows as $row) {
            $files += (int) ($row['files'] ?? 0);
            $bytes += (int) ($row['bytes'] ?? 0);

            $rowOldest = $this->toUnixTimestamp($row['oldest_mtime'] ?? null);
            if ($rowOldest !== null && ($oldest === null || $rowOldest < $oldest)) {
                $oldest = $rowOldest;
            }

            $rowNewest = $this->toUnixTimestamp($row['newest_mtime'] ?? null);
            if ($rowNewest !== null && ($newest === null || $rowNewest > $newest)) {
                $newest = $rowNewest;
            }
        }

        return [
            'files' => $files,
            'bytes' => $bytes,
            'oldest_mtime' => $oldest !== null ? date(DATE_ATOM, $oldest) : null,
            'newest_mtime' => $newest !== null ? date(DATE_ATOM, $newest) : null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array<string,mixed>>
     */
    private function buildFocusScopes(array $rows): array
    {
        $focus = [];
        $wanted = ['logs', 'artifacts', 'content_releases'];

        foreach ($rows as $row) {
            $scope = (string) ($row['scope'] ?? '');
            if (in_array($scope, $wanted, true)) {
                $focus[$scope] = $row;
            }
        }

        return $focus;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array{files:int,bytes:int,duplicate_groups:int,wasted_bytes:int}>
     */
    private function buildScopeTotals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $scope = (string) ($row['scope'] ?? '');
            if ($scope === '') {
                continue;
            }

            $totals[$scope] = [
                'files' => (int) ($row['files'] ?? 0),
                'bytes' => (int) ($row['bytes'] ?? 0),
                'duplicate_groups' => (int) data_get($row, 'duplicate_summary.groups', 0),
                'wasted_bytes' => (int) data_get($row, 'duplicate_summary.wasted_bytes', 0),
            ];
        }

        return $totals;
    }

    /**
     * @param  array<int,list<array{path:string,full_path:string}>>  $duplicateCandidates
     * @return array{
     *   groups:int,
     *   files:int,
     *   bytes:int,
     *   wasted_bytes:int,
     *   top_groups:list<array{
     *     sha256:string,
     *     files:int,
     *     bytes:int,
     *     total_bytes:int,
     *     wasted_bytes:int,
     *     sample_paths:list<string>
     *   }>
     * }
     */
    private function buildDuplicateSummary(array $duplicateCandidates): array
    {
        $summary = $this->emptyDuplicateSummary();
        $topGroups = [];

        foreach ($duplicateCandidates as $size => $entries) {
            $size = max(0, (int) $size);
            if ($size === 0 || count($entries) < 2) {
                continue;
            }

            $hashGroups = [];

            foreach ($entries as $entry) {
                $hash = @hash_file('sha256', $entry['full_path']);
                if (! is_string($hash) || $hash === '') {
                    continue;
                }

                $hashGroups[$hash][] = $entry['path'];
            }

            foreach ($hashGroups as $hash => $paths) {
                $count = count($paths);
                if ($count < 2) {
                    continue;
                }

                $totalBytes = $count * $size;
                $wastedBytes = ($count - 1) * $size;
                $summary['groups']++;
                $summary['files'] += $count;
                $summary['bytes'] += $totalBytes;
                $summary['wasted_bytes'] += $wastedBytes;
                $topGroups[] = [
                    'sha256' => $hash,
                    'files' => $count,
                    'bytes' => $size,
                    'total_bytes' => $totalBytes,
                    'wasted_bytes' => $wastedBytes,
                    'sample_paths' => array_values(array_slice($paths, 0, self::DUPLICATE_SAMPLE_LIMIT)),
                ];
            }
        }

        usort($topGroups, static function (array $left, array $right): int {
            $wasted = (int) $right['wasted_bytes'] <=> (int) $left['wasted_bytes'];
            if ($wasted !== 0) {
                return $wasted;
            }

            $files = (int) $right['files'] <=> (int) $left['files'];
            if ($files !== 0) {
                return $files;
            }

            return strcmp((string) $left['sha256'], (string) $right['sha256']);
        });

        $summary['top_groups'] = array_slice($topGroups, 0, self::DUPLICATE_GROUP_LIMIT);

        return $summary;
    }

    /**
     * @return array{
     *   groups:int,
     *   files:int,
     *   bytes:int,
     *   wasted_bytes:int,
     *   top_groups:list<array{
     *     sha256:string,
     *     files:int,
     *     bytes:int,
     *     total_bytes:int,
     *     wasted_bytes:int,
     *     sample_paths:list<string>
     *   }>
     * }
     */
    private function emptyDuplicateSummary(): array
    {
        return [
            'groups' => 0,
            'files' => 0,
            'bytes' => 0,
            'wasted_bytes' => 0,
            'top_groups' => [],
        ];
    }

    private function toUnixTimestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : null;
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
            'action' => 'storage_inventory',
            'target_type' => 'storage',
            'target_id' => 'inventory',
            'meta_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_inventory',
            'request_id' => null,
            'reason' => 'scheduled_or_manual',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
