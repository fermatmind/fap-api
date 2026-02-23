<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageInventory extends Command
{
    protected $signature = 'storage:inventory {--json : JSON output}';

    protected $description = 'Inventory storage usage by scope (files/bytes/mtime/top dirs).';

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

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

        $this->recordAudit($rows);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   scope:string,
     *   files:int,
     *   bytes:int,
     *   oldest_mtime:?string,
     *   newest_mtime:?string,
     *   top_dirs:array<string,int>
     * }
     */
    private function collectScope(string $scope, string $root): array
    {
        if (! is_dir($root)) {
            return [
                'scope' => $scope,
                'files' => 0,
                'bytes' => 0,
                'oldest_mtime' => null,
                'newest_mtime' => null,
                'top_dirs' => [],
            ];
        }

        $files = 0;
        $bytes = 0;
        $oldest = null;
        $newest = null;
        $topDirs = [];

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
        }

        arsort($topDirs);
        $topDirs = array_slice($topDirs, 0, 5, true);

        return [
            'scope' => $scope,
            'files' => $files,
            'bytes' => $bytes,
            'oldest_mtime' => $oldest !== null ? date(DATE_ATOM, $oldest) : null,
            'newest_mtime' => $newest !== null ? date(DATE_ATOM, $newest) : null,
            'top_dirs' => $topDirs,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function recordAudit(array $rows): void
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
            'meta_json' => json_encode([
                'scopes' => $rows,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_inventory',
            'request_id' => null,
            'reason' => 'scheduled_or_manual',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
