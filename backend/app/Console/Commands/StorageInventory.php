<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class StorageInventory extends Command
{
    protected $signature = 'storage:inventory
        {--json : Output inventory as json}';

    protected $description = 'Inspect storage usage snapshot and persist audit log.';

    public function handle(): int
    {
        $snapshot = $this->buildSnapshot();

        if ((bool) $this->option('json')) {
            $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($encoded)) {
                $this->error('failed_to_encode_inventory_json');

                return self::FAILURE;
            }

            $this->line($encoded);
        } else {
            $rows = [];
            $scopes = is_array($snapshot['scopes'] ?? null) ? $snapshot['scopes'] : [];
            foreach ($scopes as $scope => $data) {
                if (! is_array($data)) {
                    continue;
                }

                $rows[] = [
                    (string) $scope,
                    (string) ((int) ($data['files_count'] ?? 0)),
                    (string) ((int) ($data['bytes_total'] ?? 0)),
                    (string) ($data['oldest_mtime'] ?? ''),
                    (string) ($data['newest_mtime'] ?? ''),
                ];
            }

            $this->table(['scope', 'files_count', 'bytes_total', 'oldest_mtime', 'newest_mtime'], $rows);
        }

        $this->writeAuditLog($snapshot);

        return self::SUCCESS;
    }

    /**
     * @return array{schema:string,generated_at:string,scopes:array<string,array<string,mixed>>}
     */
    private function buildSnapshot(): array
    {
        $roots = [
            'artifacts' => storage_path('app/private/artifacts'),
            'reports' => storage_path('app/private/reports'),
            'packs_v2' => storage_path('app/private/packs_v2'),
            'releases' => storage_path('app/private/releases'),
            'content_releases' => storage_path('app/private/content_releases'),
            'prune_plans' => storage_path('app/private/prune_plans'),
            'logs' => storage_path('logs'),
        ];

        $scopes = [];
        foreach ($roots as $scope => $root) {
            $scopes[$scope] = $this->buildDirectoryStats($root);
        }

        return [
            'schema' => 'storage_inventory_snapshot.v1',
            'generated_at' => now()->toISOString(),
            'scopes' => $scopes,
        ];
    }

    /**
     * @return array{files_count:int,bytes_total:int,oldest_mtime:?string,newest_mtime:?string,top_children:list<array{name:string,files_count:int,bytes_total:int}>}
     */
    private function buildDirectoryStats(string $root): array
    {
        if (! is_dir($root)) {
            return [
                'files_count' => 0,
                'bytes_total' => 0,
                'oldest_mtime' => null,
                'newest_mtime' => null,
                'top_children' => [],
            ];
        }

        $filesCount = 0;
        $bytesTotal = 0;
        $oldestTs = null;
        $newestTs = null;

        /** @var array<string,array{files_count:int,bytes_total:int}> $children */
        $children = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relPath = ltrim(str_replace('\\', '/', substr($fullPath, strlen(rtrim($root, DIRECTORY_SEPARATOR)))), '/');

            $bytes = max(0, (int) ($file->getSize() ?: 0));
            $mtime = max(0, (int) ($file->getMTime() ?: 0));

            $filesCount++;
            $bytesTotal += $bytes;
            $oldestTs = $oldestTs === null ? $mtime : min($oldestTs, $mtime);
            $newestTs = $newestTs === null ? $mtime : max($newestTs, $mtime);

            $child = $this->topChildName($relPath);
            if (! isset($children[$child])) {
                $children[$child] = [
                    'files_count' => 0,
                    'bytes_total' => 0,
                ];
            }
            $children[$child]['files_count']++;
            $children[$child]['bytes_total'] += $bytes;
        }

        $childRows = [];
        foreach ($children as $name => $stats) {
            $childRows[] = [
                'name' => $name,
                'files_count' => (int) ($stats['files_count'] ?? 0),
                'bytes_total' => (int) ($stats['bytes_total'] ?? 0),
            ];
        }

        usort($childRows, static function (array $a, array $b): int {
            $bytesCmp = ((int) ($b['bytes_total'] ?? 0)) <=> ((int) ($a['bytes_total'] ?? 0));
            if ($bytesCmp !== 0) {
                return $bytesCmp;
            }

            $filesCmp = ((int) ($b['files_count'] ?? 0)) <=> ((int) ($a['files_count'] ?? 0));
            if ($filesCmp !== 0) {
                return $filesCmp;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return [
            'files_count' => $filesCount,
            'bytes_total' => $bytesTotal,
            'oldest_mtime' => $oldestTs !== null ? date(DATE_ATOM, $oldestTs) : null,
            'newest_mtime' => $newestTs !== null ? date(DATE_ATOM, $newestTs) : null,
            'top_children' => array_slice($childRows, 0, 5),
        ];
    }

    private function topChildName(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            return '.';
        }

        $parts = explode('/', $relPath, 2);

        return trim((string) ($parts[0] ?? '.')) ?: '.';
    }

    /**
     * @param  array{schema:string,generated_at:string,scopes:array<string,array<string,mixed>>}  $snapshot
     */
    private function writeAuditLog(array $snapshot): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $metaJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($metaJson)) {
            $metaJson = '{}';
        }

        $row = [
            'action' => 'storage_inventory_snapshot',
            'target_type' => 'storage',
            'target_id' => null,
            'meta_json' => $metaJson,
            'created_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('audit_logs', 'org_id')) {
            $row['org_id'] = 0;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'actor_admin_id')) {
            $row['actor_admin_id'] = null;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'ip')) {
            $row['ip'] = '127.0.0.1';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'user_agent')) {
            $row['user_agent'] = 'artisan';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'request_id')) {
            $row['request_id'] = 'storage:inventory';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'reason')) {
            $row['reason'] = 'storage_governance_inventory';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'result')) {
            $row['result'] = 'success';
        }

        try {
            DB::table('audit_logs')->insert($row);
        } catch (\Throwable $e) {
            Log::warning('storage_inventory_audit_log_insert_failed', [
                'command' => 'storage:inventory',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
