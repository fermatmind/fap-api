<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class Packs2MigrateStoragePath extends Command
{
    protected $signature = 'packs2:migrate-storage-path
        {--dry-run : Plan migration only}
        {--execute : Execute migration and update DB storage_path}';

    protected $description = 'Migrate packs2 storage_path from content_packs_v2/* to private/packs_v2/*.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        $operations = $this->collectOperations();

        if ($dryRun) {
            $this->line('status=planned');
            $this->line('operations='.count($operations));

            return self::SUCCESS;
        }

        return $this->executeOperations($operations);
    }

    /**
     * @return list<array{release_id:string,source_storage_path:string,target_storage_path:string,source_abs:string,target_abs:string,pack_id:string,pack_version:string,manifest_hash:string}>
     */
    private function collectOperations(): array
    {
        if (! SchemaBaseline::hasTable('content_pack_releases')) {
            return [];
        }

        $rows = DB::table('content_pack_releases')
            ->whereNotNull('storage_path')
            ->orderBy('created_at')
            ->get();

        $operations = [];
        foreach ($rows as $row) {
            $releaseId = trim((string) ($row->id ?? ''));
            $storagePath = trim((string) ($row->storage_path ?? ''));
            if ($releaseId === '' || $storagePath === '') {
                continue;
            }

            $relativeLegacy = $this->legacyRelativePath($storagePath);
            if ($relativeLegacy === null) {
                continue;
            }

            $segments = explode('/', trim($relativeLegacy, '/'));
            if (count($segments) < 4) {
                continue;
            }

            $packId = strtoupper(trim((string) ($row->to_pack_id ?? ($segments[1] ?? ''))));
            $packVersion = trim((string) (($row->pack_version ?? null) ?: ($row->dir_alias ?? null) ?: ($segments[2] ?? '')));
            if ($packId === '' || $packVersion === '') {
                continue;
            }

            $manifestHash = strtolower(trim((string) ($row->manifest_hash ?? '')));
            if ($manifestHash === '') {
                $manifestHash = $releaseId;
            }

            $targetStoragePath = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$manifestHash;

            $operations[] = [
                'release_id' => $releaseId,
                'source_storage_path' => $storagePath,
                'target_storage_path' => $targetStoragePath,
                'source_abs' => $this->resolveStorageAbsolutePath($storagePath),
                'target_abs' => storage_path('app/'.$targetStoragePath),
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'manifest_hash' => $manifestHash,
            ];
        }

        return $operations;
    }

    /**
     * @param  list<array{release_id:string,source_storage_path:string,target_storage_path:string,source_abs:string,target_abs:string,pack_id:string,pack_version:string,manifest_hash:string}>  $operations
     */
    private function executeOperations(array $operations): int
    {
        if (! SchemaBaseline::hasTable('content_pack_releases')) {
            $this->error('content_pack_releases table unavailable.');

            return self::FAILURE;
        }

        $migrated = 0;
        $updatedRows = 0;
        $missingSource = 0;
        $failed = 0;

        foreach ($operations as $operation) {
            $sourceAbs = $operation['source_abs'];
            $targetAbs = $operation['target_abs'];

            $copied = false;
            if (! is_dir($targetAbs)) {
                if (! is_dir($sourceAbs)) {
                    $missingSource++;

                    continue;
                }

                File::ensureDirectoryExists(dirname($targetAbs));
                if (! File::copyDirectory($sourceAbs, $targetAbs)) {
                    $failed++;
                    $this->warn('copy_failed release_id='.$operation['release_id']);

                    continue;
                }

                $copied = true;
            }

            $updated = false;
            $releaseId = $operation['release_id'];
            $targetStoragePath = $operation['target_storage_path'];

            $affected = DB::table('content_pack_releases')
                ->where('id', $releaseId)
                ->update([
                    'storage_path' => $targetStoragePath,
                    'updated_at' => now(),
                ]);

            if ($affected > 0) {
                $updated = true;
                $updatedRows += $affected;
            }

            $migrated++;
            $this->writeAuditLog($operation, $copied, $updated, $this->targetManifestSha256($targetAbs));
        }

        $this->line('status=executed');
        $this->line('operations='.count($operations));
        $this->line('migrated='.$migrated);
        $this->line('updated_rows='.$updatedRows);
        $this->line('missing_source='.$missingSource);
        $this->line('failed='.$failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function targetManifestSha256(string $targetAbs): string
    {
        $candidates = [
            $targetAbs.'/manifest.json',
            $targetAbs.'/compiled/manifest.json',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                $hash = hash_file('sha256', $path);

                return is_string($hash) ? $hash : '';
            }
        }

        return '';
    }

    private function resolveStorageAbsolutePath(string $storagePath): string
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return storage_path('app');
        }

        if (str_starts_with($normalized, '/')) {
            return rtrim($normalized, '/');
        }

        $relative = ltrim($normalized, '/');
        if (str_starts_with($relative, 'app/')) {
            $relative = substr($relative, 4);
        }

        return rtrim(storage_path('app/'.$relative), '/');
    }

    private function legacyRelativePath(string $storagePath): ?string
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, '/storage/app/content_packs_v2/')) {
            $parts = explode('/storage/app/', $normalized, 2);
            $relative = $parts[1] ?? '';

            return str_starts_with($relative, 'content_packs_v2/') ? $relative : null;
        }

        $relative = ltrim($normalized, '/');
        if (str_starts_with($relative, 'app/')) {
            $relative = substr($relative, 4);
        }

        return str_starts_with($relative, 'content_packs_v2/') ? $relative : null;
    }

    /**
     * @param  array{release_id:string,source_storage_path:string,target_storage_path:string,source_abs:string,target_abs:string,pack_id:string,pack_version:string,manifest_hash:string}  $operation
     */
    private function writeAuditLog(array $operation, bool $copied, bool $updated, string $sha256): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $meta = [
            'release_id' => $operation['release_id'],
            'source_storage_path' => $operation['source_storage_path'],
            'target_storage_path' => $operation['target_storage_path'],
            'pack_id' => $operation['pack_id'],
            'pack_version' => $operation['pack_version'],
            'manifest_hash' => $operation['manifest_hash'],
            'copied' => $copied,
            'updated' => $updated,
            'sha256' => $sha256,
        ];

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($metaJson)) {
            $metaJson = '{}';
        }

        $row = [
            'action' => 'packs2_storage_path_migrate',
            'target_type' => 'content_pack_release',
            'target_id' => $operation['release_id'],
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
            $row['request_id'] = 'packs2:migrate-storage-path';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'reason')) {
            $row['reason'] = 'storage_governance_packs2_migrate';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'result')) {
            $row['result'] = $updated ? 'success' : 'skipped';
        }

        try {
            DB::table('audit_logs')->insert($row);
        } catch (\Throwable) {
            // keep storage path migration non-blocking when audit persistence is unavailable.
        }
    }
}
