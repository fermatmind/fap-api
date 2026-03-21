<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageControlPlaneArtifactsJanitorService
{
    private const SCHEMA = 'storage_control_plane_artifacts_janitor.v1';

    /**
     * @return array<string,mixed>
     */
    public function run(bool $execute): array
    {
        $directories = $this->directoryDefinitions();
        $results = [];
        $keptPaths = [];
        $candidateDeletePaths = [];
        $deletedPaths = [];

        foreach ($directories as $directoryKey => $definition) {
            $files = $this->jsonFilesUnder($definition['relative_dir']);
            $keepSet = $this->buildKeepSet($directoryKey, $files);
            $candidates = array_values(array_filter($files, static fn (string $path): bool => ! isset($keepSet[$path])));
            $deleted = [];

            if ($execute) {
                foreach ($candidates as $path) {
                    if (! $this->isEligiblePath($path, $definition['relative_dir'])) {
                        continue;
                    }

                    File::delete($path);
                    if (! is_file($path)) {
                        $deleted[] = $path;
                    }
                }
            }

            $results[$directoryKey] = [
                'relative_dir' => $definition['relative_dir'],
                'scanned_file_count' => count($files),
                'kept_file_count' => count($keepSet),
                'candidate_delete_count' => count($candidates),
                'deleted_file_count' => count($deleted),
                'kept_paths' => array_values(array_keys($keepSet)),
                'candidate_delete_paths' => $candidates,
                'deleted_paths' => $deleted,
            ];

            $keptPaths = array_merge($keptPaths, array_keys($keepSet));
            $candidateDeletePaths = array_merge($candidateDeletePaths, $candidates);
            $deletedPaths = array_merge($deletedPaths, $deleted);
        }

        sort($keptPaths);
        sort($candidateDeletePaths);
        sort($deletedPaths);

        $payload = [
            'schema' => self::SCHEMA,
            'mode' => $execute ? 'execute' : 'dry_run',
            'status' => $execute ? 'executed' : 'planned',
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'directory_count' => count($results),
                'scanned_file_count' => count($keptPaths) + count($candidateDeletePaths),
                'kept_file_count' => count($keptPaths),
                'candidate_delete_count' => count($candidateDeletePaths),
                'deleted_file_count' => count($deletedPaths),
            ],
            'directories' => $results,
            'kept_paths' => $keptPaths,
            'candidate_delete_paths' => $candidateDeletePaths,
            'deleted_paths' => $deletedPaths,
        ];

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @return array<string,array{relative_dir:string}>
     */
    private function directoryDefinitions(): array
    {
        return [
            'control_plane_snapshots' => ['relative_dir' => 'app/private/control_plane_snapshots'],
            'gc_plans' => ['relative_dir' => 'app/private/gc_plans'],
            'offload_plans' => ['relative_dir' => 'app/private/offload_plans'],
            'rehydrate_plans' => ['relative_dir' => 'app/private/rehydrate_plans'],
            'quarantine_plans' => ['relative_dir' => 'app/private/quarantine_plans'],
            'quarantine_restore_plans' => ['relative_dir' => 'app/private/quarantine_restore_plans'],
            'quarantine_purge_plans' => ['relative_dir' => 'app/private/quarantine_purge_plans'],
            'retirement_plans' => ['relative_dir' => 'app/private/retirement_plans'],
            'prune_plans' => ['relative_dir' => 'app/private/prune_plans'],
        ];
    }

    /**
     * @param  list<string>  $files
     * @return array<string,true>
     */
    private function buildKeepSet(string $directoryKey, array $files): array
    {
        return match ($directoryKey) {
            'control_plane_snapshots' => $this->keepSetForSnapshots($files),
            'prune_plans' => $this->keepSetForPrunePlans($files),
            default => $this->keepSetForGenericPlans($files, $this->auditReferencedPlanPathForDirectory($directoryKey)),
        };
    }

    /**
     * @param  list<string>  $files
     * @return array<string,true>
     */
    private function keepSetForSnapshots(array $files): array
    {
        $keep = [];

        foreach ($this->latestFiles($files, max(1, $this->snapshotKeepLastN())) as $path) {
            $keep[$path] = true;
        }

        if ($this->retainLatestAuditReferenced()) {
            $audit = $this->latestAuditForAction('storage_control_plane_snapshot');
            $snapshotPath = is_array($audit) ? $this->normalizeExistingFilePath((string) ($audit['snapshot_path'] ?? '')) : null;
            if ($snapshotPath !== null) {
                $keep[$snapshotPath] = true;
            }
        }

        return $keep;
    }

    /**
     * @param  list<string>  $files
     * @return array<string,true>
     */
    private function keepSetForGenericPlans(array $files, ?string $auditReferencedPath): array
    {
        $keep = [];

        foreach ($this->latestFiles($files, max(0, $this->planKeepLastN())) as $path) {
            $keep[$path] = true;
        }

        if ($this->retainLatestAuditReferenced() && $auditReferencedPath !== null) {
            $keep[$auditReferencedPath] = true;
        }

        return $keep;
    }

    /**
     * @param  list<string>  $files
     * @return array<string,true>
     */
    private function keepSetForPrunePlans(array $files): array
    {
        $keep = [];
        $keepLastPerScope = max(1, $this->prunePlansKeepLastNPerScope());

        foreach ($this->filesGroupedByPruneScope($files) as $scopeFiles) {
            foreach ($this->latestFiles($scopeFiles, $keepLastPerScope) as $path) {
                $keep[$path] = true;
            }
        }

        if (! $this->retainLatestAuditReferenced()) {
            return $keep;
        }

        foreach ($this->latestAuditReferencedPrunePlanPaths() as $path) {
            $keep[$path] = true;
        }

        return $keep;
    }

    /**
     * @param  list<string>  $files
     * @return array<string,list<string>>
     */
    private function filesGroupedByPruneScope(array $files): array
    {
        $grouped = [];

        foreach ($files as $path) {
            $payload = $this->safeJsonDecodeFile($path);
            $scope = trim((string) ($payload['scope'] ?? ''));
            if ($scope === '') {
                continue;
            }

            $grouped[$scope] ??= [];
            $grouped[$scope][] = $path;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    private function latestAuditReferencedPrunePlanPaths(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $paths = [];
        $rows = DB::table('audit_logs')
            ->where('action', 'storage_prune')
            ->orderByDesc('id')
            ->get();

        $latestByScope = [];
        foreach ($rows as $row) {
            $meta = $this->decodeAuditMeta($row);
            $scope = trim((string) ($meta['scope'] ?? $row->target_id ?? ''));
            if ($scope === '' || isset($latestByScope[$scope])) {
                continue;
            }

            $path = $this->normalizeExistingFilePath((string) ($meta['plan'] ?? ''));
            if ($path === null) {
                continue;
            }

            $latestByScope[$scope] = $path;
        }

        foreach ($latestByScope as $path) {
            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }

    private function auditReferencedPlanPathForDirectory(string $directoryKey): ?string
    {
        if (! $this->retainLatestAuditReferenced()) {
            return null;
        }

        $mapping = [
            'gc_plans' => ['action' => 'storage_blob_gc', 'field' => 'plan'],
            'offload_plans' => ['action' => 'storage_blob_offload', 'field' => 'plan'],
            'rehydrate_plans' => ['action' => 'storage_rehydrate_exact_release', 'field' => 'plan_path'],
            'quarantine_plans' => ['action' => 'storage_quarantine_exact_roots', 'field' => 'plan_path'],
            'quarantine_restore_plans' => ['action' => 'storage_restore_quarantined_root', 'field' => 'plan_path'],
            'quarantine_purge_plans' => ['action' => 'storage_purge_quarantined_root', 'field' => 'plan_path'],
            'retirement_plans' => ['action' => 'storage_retire_exact_roots', 'field' => 'plan_path'],
        ];

        $config = $mapping[$directoryKey] ?? null;
        if ($config === null) {
            return null;
        }

        $meta = $this->latestAuditForAction($config['action']);
        if ($meta === null) {
            return null;
        }

        return $this->normalizeExistingFilePath((string) ($meta[$config['field']] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function jsonFilesUnder(string $relativeDir): array
    {
        $absoluteDir = storage_path($relativeDir);
        if (! is_dir($absoluteDir)) {
            return [];
        }

        $files = glob($absoluteDir.DIRECTORY_SEPARATOR.'*.json');
        if (! is_array($files)) {
            return [];
        }

        $files = array_values(array_filter($files, static fn (string $path): bool => is_file($path)));

        usort($files, fn (string $left, string $right): int => $this->fileMTime($right) <=> $this->fileMTime($left));

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function latestFiles(array $files, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return array_slice($files, 0, $limit);
    }

    private function fileMTime(string $path): int
    {
        return (int) (@filemtime($path) ?: 0);
    }

    private function isEligiblePath(string $path, string $relativeDir): bool
    {
        if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'json') {
            return false;
        }

        $expectedDir = str_replace('\\', '/', rtrim(storage_path($relativeDir), '/\\'));
        $actualDir = str_replace('\\', '/', dirname($path));

        return $expectedDir === $actualDir;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function safeJsonDecodeFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeExistingFilePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestAuditForAction(string $action): ?array
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $row = DB::table('audit_logs')
            ->where('action', $action)
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->decodeAuditMeta($row);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAuditMeta(object $row): array
    {
        $decoded = json_decode((string) ($row->meta_json ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function snapshotKeepLastN(): int
    {
        return (int) config('storage_retention.control_plane_artifacts.control_plane_snapshots.keep_last_n', 30);
    }

    private function planKeepLastN(): int
    {
        return (int) config('storage_retention.control_plane_artifacts.plan_dirs.keep_last_n', 5);
    }

    private function prunePlansKeepLastNPerScope(): int
    {
        return (int) config('storage_retention.control_plane_artifacts.prune_plans.keep_last_n_per_scope', 3);
    }

    private function retainLatestAuditReferenced(): bool
    {
        return (bool) config('storage_retention.control_plane_artifacts.retain_latest_audit_referenced', true);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $directorySummary = Collection::make((array) ($payload['directories'] ?? []))
            ->map(static fn (array $entry): array => [
                'relative_dir' => (string) ($entry['relative_dir'] ?? ''),
                'scanned_file_count' => (int) ($entry['scanned_file_count'] ?? 0),
                'kept_file_count' => (int) ($entry['kept_file_count'] ?? 0),
                'candidate_delete_count' => (int) ($entry['candidate_delete_count'] ?? 0),
                'deleted_file_count' => (int) ($entry['deleted_file_count'] ?? 0),
            ])
            ->all();

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_janitor_control_plane_artifacts',
            'target_type' => 'storage',
            'target_id' => 'control_plane_artifacts',
            'meta_json' => json_encode([
                'schema' => self::SCHEMA,
                'mode' => $payload['mode'] ?? null,
                'generated_at' => $payload['generated_at'] ?? null,
                'summary' => $payload['summary'] ?? [],
                'directories' => $directorySummary,
                'candidate_delete_paths' => $payload['candidate_delete_paths'] ?? [],
                'deleted_paths' => $payload['deleted_paths'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_janitor_control_plane_artifacts',
            'request_id' => null,
            'reason' => 'control_plane_artifact_retention',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
