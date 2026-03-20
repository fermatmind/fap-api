<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ExactReleaseFileSetCatalogService;
use App\Services\Storage\ReleaseStorageLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageBackfillExactReleaseFileSets extends Command
{
    protected $signature = 'storage:backfill-exact-release-file-sets
        {--dry-run : Scan only and report exact file-set coverage}
        {--execute : Execute idempotent exact file-set backfill}';

    protected $description = 'Backfill exact content release file-set authority from physical compiled roots without changing runtime readers.';

    public function __construct(
        private readonly ExactReleaseFileSetCatalogService $catalogService,
        private readonly ReleaseStorageLocator $releaseStorageLocator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        $payload = $this->runBackfill($execute);
        $warningCount = is_array($payload['warnings'] ?? null) ? count($payload['warnings']) : 0;

        $this->line('status='.(($payload['mode'] ?? '') === 'execute' ? 'executed' : 'planned'));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('release_rows_scanned='.(int) ($payload['release_rows_scanned'] ?? 0));
        if (($payload['mode'] ?? '') === 'execute') {
            $this->line('release_rows_backfilled='.(int) ($payload['release_rows_backfilled'] ?? 0));
            $this->line('backup_roots_backfilled='.(int) ($payload['backup_roots_backfilled'] ?? 0));
            $this->line('exact_manifests_upserted='.(int) ($payload['exact_manifests_upserted'] ?? 0));
            $this->line('exact_manifest_files_synced='.(int) ($payload['exact_manifest_files_synced'] ?? 0));
        } else {
            $this->line('release_rows_backfillable='.(int) ($payload['release_rows_backfillable'] ?? 0));
            $this->line('backup_roots_backfillable='.(int) ($payload['backup_roots_backfillable'] ?? 0));
        }
        $this->line('warnings='.$warningCount);

        $this->recordAudit($payload);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   schema_version:int,
     *   mode:string,
     *   generated_at:string,
     *   release_rows_scanned:int,
     *   release_rows_backfillable:int,
     *   release_rows_backfilled:int,
     *   release_rows_skipped:int,
     *   backup_roots_scanned:int,
     *   backup_roots_backfillable:int,
     *   backup_roots_backfilled:int,
     *   exact_manifests_upserted:int,
     *   exact_manifest_files_synced:int,
     *   missing_physical_sources:int,
     *   warnings:list<string>
     * }
     */
    private function runBackfill(bool $execute): array
    {
        $summary = [
            'schema_version' => 1,
            'mode' => $execute ? 'execute' : 'dry_run',
            'generated_at' => now()->toAtomString(),
            'release_rows_scanned' => 0,
            'release_rows_backfillable' => 0,
            'release_rows_backfilled' => 0,
            'release_rows_skipped' => 0,
            'backup_roots_scanned' => 0,
            'backup_roots_backfillable' => 0,
            'backup_roots_backfilled' => 0,
            'exact_manifests_upserted' => 0,
            'exact_manifest_files_synced' => 0,
            'missing_physical_sources' => 0,
            'warnings' => [],
        ];
        $processedRoots = [];

        $releases = DB::table('content_pack_releases')
            ->where('status', 'success')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($releases as $release) {
            $this->processRelease($release, $execute, $summary, $processedRoots);
        }

        foreach ($this->releaseStorageLocator->legacyBackupRoots() as $backupRoot) {
            $normalizedRoot = $this->normalizeRoot($backupRoot);
            if ($normalizedRoot === '' || isset($processedRoots[$normalizedRoot])) {
                continue;
            }

            $this->processBackupRoot($backupRoot, $execute, $summary, $processedRoots);
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,bool>  $processedRoots
     */
    private function processRelease(object $release, bool $execute, array &$summary, array &$processedRoots): void
    {
        $summary['release_rows_scanned']++;

        $source = $this->releaseStorageLocator->resolveReleaseSource($release);
        if ($source === null) {
            $summary['release_rows_skipped']++;
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'missing physical source for release '.trim((string) ($release->id ?? '')));

            return;
        }

        $normalizedRoot = $this->normalizeRoot((string) $source['root']);

        $compiledFiles = $this->releaseStorageLocator->collectCompiledFilesFromRoot((string) $source['root']);
        $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot((string) $source['root']);
        $manifestHash = trim((string) ($manifestMeta['manifest_hash'] ?? ''));
        if ($compiledFiles === [] || $manifestHash === '') {
            $summary['release_rows_skipped']++;
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'exact file-set unavailable for release '.trim((string) ($release->id ?? '')));

            return;
        }

        $summary['release_rows_backfillable']++;
        if (! $execute) {
            if ($normalizedRoot !== '') {
                $processedRoots[$normalizedRoot] = true;
            }

            return;
        }

        try {
            $this->catalogService->upsertExactManifest($this->buildManifestPayload(
                release: $release,
                root: (string) $source['root'],
                sourceKind: $this->sourceKindForRoot((string) $source['root'], (string) ($source['resolved_from'] ?? '')),
                manifestMeta: $manifestMeta
            ), $this->buildFilePayloads($compiledFiles));
            if ($normalizedRoot !== '') {
                $processedRoots[$normalizedRoot] = true;
            }
            $summary['exact_manifests_upserted']++;
            $summary['exact_manifest_files_synced'] += count($compiledFiles);
            $summary['release_rows_backfilled']++;
        } catch (\Throwable $e) {
            $this->appendWarning($summary, 'release exact seal failed for '.trim((string) ($release->id ?? '')).': '.$e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,bool>  $processedRoots
     */
    private function processBackupRoot(string $backupRoot, bool $execute, array &$summary, array &$processedRoots): void
    {
        $normalizedRoot = $this->normalizeRoot($backupRoot);
        if ($normalizedRoot === '') {
            return;
        }

        $processedRoots[$normalizedRoot] = true;
        $summary['backup_roots_scanned']++;

        $compiledFiles = $this->releaseStorageLocator->collectCompiledFilesFromRoot($backupRoot);
        $manifestMeta = $this->releaseStorageLocator->readManifestMetadataFromRoot($backupRoot);
        $manifestHash = trim((string) ($manifestMeta['manifest_hash'] ?? ''));
        if ($compiledFiles === [] || $manifestHash === '') {
            $summary['missing_physical_sources']++;
            $this->appendWarning($summary, 'exact file-set unavailable for backup root '.$backupRoot);

            return;
        }

        $summary['backup_roots_backfillable']++;
        if (! $execute) {
            return;
        }

        $backupContext = $this->releaseStorageLocator->backupRootContext($backupRoot);
        $contentPackReleaseId = trim((string) ($backupContext['release_id'] ?? ''));
        if ($contentPackReleaseId !== '' && ! DB::table('content_pack_releases')->where('id', $contentPackReleaseId)->exists()) {
            $contentPackReleaseId = '';
        }

        try {
            $this->catalogService->upsertExactManifest([
                'content_pack_release_id' => $contentPackReleaseId !== '' ? $contentPackReleaseId : null,
                'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
                'source_kind' => $this->sourceKindForBackupRoot($backupRoot),
                'source_disk' => 'local',
                'source_storage_path' => $normalizedRoot,
                'manifest_hash' => $manifestHash,
                'pack_id' => strtoupper(trim((string) ($manifestMeta['pack_id'] ?? ''))) ?: null,
                'pack_version' => trim((string) ($manifestMeta['pack_version'] ?? '')) ?: null,
                'compiled_hash' => trim((string) ($manifestMeta['compiled_hash'] ?? '')) ?: null,
                'content_hash' => trim((string) ($manifestMeta['content_hash'] ?? '')) ?: null,
                'norms_version' => trim((string) ($manifestMeta['norms_version'] ?? '')) ?: null,
                'source_commit' => null,
                'payload_json' => $manifestMeta['decoded'] !== [] ? $manifestMeta['decoded'] : null,
                'sealed_at' => now(),
                'last_verified_at' => now(),
            ], $this->buildFilePayloads($compiledFiles));
            $summary['exact_manifests_upserted']++;
            $summary['exact_manifest_files_synced'] += count($compiledFiles);
            $summary['backup_roots_backfilled']++;
        } catch (\Throwable $e) {
            $this->appendWarning($summary, 'backup exact seal failed for '.$backupRoot.': '.$e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $manifestMeta
     * @return array<string,mixed>
     */
    private function buildManifestPayload(object $release, string $root, string $sourceKind, array $manifestMeta): array
    {
        return [
            'content_pack_release_id' => trim((string) ($release->id ?? '')) ?: null,
            'schema_version' => (string) config('storage_rollout.exact_manifest_schema_version', 'storage_exact_manifest.v1'),
            'source_kind' => $sourceKind,
            'source_disk' => 'local',
            'source_storage_path' => $this->normalizeRoot($root),
            'manifest_hash' => trim((string) ($manifestMeta['manifest_hash'] ?? '')),
            'pack_id' => strtoupper(trim((string) ($release->to_pack_id ?? $manifestMeta['pack_id'] ?? ''))) ?: null,
            'pack_version' => $this->derivePackVersion($release, $manifestMeta['decoded']),
            'compiled_hash' => trim((string) ($release->compiled_hash ?? $manifestMeta['compiled_hash'] ?? '')) ?: null,
            'content_hash' => trim((string) ($release->content_hash ?? $manifestMeta['content_hash'] ?? '')) ?: null,
            'norms_version' => trim((string) ($release->norms_version ?? $manifestMeta['norms_version'] ?? '')) ?: null,
            'source_commit' => trim((string) ($release->source_commit ?? $release->git_sha ?? '')) ?: null,
            'payload_json' => $manifestMeta['decoded'] !== [] ? $manifestMeta['decoded'] : null,
            'sealed_at' => now(),
            'last_verified_at' => now(),
        ];
    }

    /**
     * @param  list<array{logical_path:string,hash:string,size_bytes:int,content_type:?string,role:?string}>  $compiledFiles
     * @return list<array<string,mixed>>
     */
    private function buildFilePayloads(array $compiledFiles): array
    {
        $files = [];
        foreach ($compiledFiles as $file) {
            $files[] = [
                'logical_path' => $file['logical_path'],
                'blob_hash' => $file['hash'],
                'size_bytes' => $file['size_bytes'],
                'role' => $file['role'],
                'content_type' => $file['content_type'],
                'encoding' => 'identity',
                'checksum' => 'sha256:'.$file['hash'],
            ];
        }

        return $files;
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function derivePackVersion(object $release, array $manifest): ?string
    {
        $version = trim((string) ($release->pack_version ?? ''));
        if ($version !== '') {
            return $version;
        }

        $version = trim((string) ($manifest['pack_version'] ?? $manifest['content_package_version'] ?? ''));
        if ($version !== '') {
            return $version;
        }

        $toVersionId = trim((string) ($release->to_version_id ?? ''));
        if ($toVersionId !== '') {
            $versionRow = DB::table('content_pack_versions')->where('id', $toVersionId)->first();
            if ($versionRow !== null) {
                $version = trim((string) ($versionRow->content_package_version ?? ''));
                if ($version !== '') {
                    return $version;
                }
            }
        }

        return null;
    }

    private function sourceKindForRoot(string $root, string $resolvedFrom): string
    {
        $normalizedRoot = $this->normalizeRoot($root);
        $backupsPrefix = $this->normalizeRoot(storage_path('app/private/content_releases/backups')).'/';
        if (str_starts_with($normalizedRoot, $backupsPrefix)) {
            return $this->sourceKindForBackupRoot($root);
        }

        if (preg_match('#/content_releases/[^/]+/source_pack$#', $normalizedRoot) === 1) {
            return 'legacy.source_pack';
        }

        if (preg_match('#/app/private/packs_v2/#', $normalizedRoot) === 1) {
            return 'v2.primary';
        }

        if (preg_match('#/app/content_packs_v2/#', $normalizedRoot) === 1) {
            return 'v2.mirror';
        }

        return $resolvedFrom !== '' ? $resolvedFrom : 'release.root';
    }

    private function sourceKindForBackupRoot(string $root): string
    {
        $context = $this->releaseStorageLocator->backupRootContext($root);

        return match ((string) ($context['leaf'] ?? '')) {
            'previous_pack' => 'legacy.previous_pack',
            'current_pack' => 'legacy.current_pack',
            default => 'legacy.backup_root',
        };
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function appendWarning(array &$summary, string $message): void
    {
        if (count($summary['warnings']) < 20) {
            $summary['warnings'][] = $message;
        }
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
            'action' => 'storage_backfill_exact_release_file_sets',
            'target_type' => 'storage',
            'target_id' => 'exact_release_file_sets',
            'meta_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_backfill_exact_release_file_sets',
            'request_id' => null,
            'reason' => 'exact_release_file_set_backfill',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
