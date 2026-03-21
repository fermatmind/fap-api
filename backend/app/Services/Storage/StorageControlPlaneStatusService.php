<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageControlPlaneStatusService
{
    private const SCHEMA_VERSION = 'storage_control_plane_status.v1';

    // Inventory is scheduled weekly, so allow a wider freshness window.
    private const INVENTORY_STALE_AFTER_SECONDS = 8 * 24 * 60 * 60;

    // Retention plans are expected to refresh more often than inventory.
    private const RETENTION_STALE_AFTER_SECONDS = 36 * 60 * 60;

    // Manual dry-run and operator-driven control-plane surfaces age out more slowly.
    private const MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS = 30 * 24 * 60 * 60;

    /**
     * @return array<string,mixed>
     */
    public function buildStatus(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'inventory' => $this->inventorySection(),
            'retention' => $this->retentionSection(),
            'blob_coverage' => $this->blobCoverageSection(),
            'exact_authority' => $this->exactAuthoritySection(),
            'rehydrate' => $this->rehydrateSection(),
            'quarantine' => $this->quarantineSection(),
            'restore' => $this->restoreSection(),
            'purge' => $this->purgeSection(),
            'retirement' => $this->retirementSection(),
            'runtime_truth' => $this->runtimeTruthSection(),
            'automation_readiness' => $this->automationReadinessSection(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function inventorySection(): array
    {
        $audit = $this->latestAuditForAction('storage_inventory');
        if ($audit === null) {
            return array_merge([
                'status' => 'not_available',
                'latest_action' => 'storage_inventory',
                'latest_generated_at' => null,
                'schema_version' => null,
                'focus_scopes' => [],
                'totals' => null,
                'latest_summary' => null,
            ], $this->freshnessFromTimestamp(null, 'audit-derived', self::INVENTORY_STALE_AFTER_SECONDS, 'not_available'));
        }

        $payload = $this->decodeAuditMeta($audit);
        $latestGeneratedAt = $this->normalizeTimestamp($payload['generated_at'] ?? $audit->created_at);

        return array_merge([
            'status' => 'ok',
            'latest_action' => 'storage_inventory',
            'latest_generated_at' => $latestGeneratedAt,
            'schema_version' => $payload['schema_version'] ?? null,
            'focus_scopes' => $this->normalizeScalarList((array) ($payload['focus_scopes'] ?? [])),
            'totals' => is_array($payload['totals'] ?? null) ? $payload['totals'] : null,
            'latest_summary' => [
                'areas' => (int) ($payload['area_count'] ?? 0),
                'files' => (int) data_get($payload, 'totals.files', 0),
                'bytes' => (int) data_get($payload, 'totals.bytes', 0),
                'duplicate_file_refs' => (int) data_get($payload, 'duplicate_summary.file_refs', 0),
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::INVENTORY_STALE_AFTER_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    private function retentionSection(): array
    {
        $configuredScopes = [
            'reports_backups' => [
                'keep_timestamp_backups' => (int) config('storage_retention.reports.keep_timestamp_backups', 0),
                'keep_days' => (int) config('storage_retention.reports.keep_days', 365),
            ],
            'content_releases_retention' => [
                'keep_last_n' => (int) config('storage_retention.content_releases.keep_last_n', 20),
                'keep_days' => (int) config('storage_retention.content_releases.keep_days', 180),
            ],
            'legacy_private_private_cleanup' => [
                'policy' => 'no_config',
            ],
        ];

        $scopes = [];
        $hasAnyRun = false;
        foreach ($configuredScopes as $scope => $policy) {
            $audit = $this->latestAuditForAction('storage_prune', static fn (array $meta, object $row): bool => (string) $row->target_id === $scope);
            $latestPlan = $this->latestJsonFileUnder(
                storage_path('app/private/prune_plans'),
                static fn (string $path, array $payload): bool => (string) ($payload['scope'] ?? '') === $scope
            );

            if ($audit === null && $latestPlan === null) {
                $scopes[$scope] = array_merge([
                    'status' => 'never_run',
                    'policy' => $policy,
                    'latest_generated_at' => null,
                    'latest_plan_path' => null,
                    'latest_execute_status' => null,
                    'latest_summary' => null,
                ], $this->freshnessFromTimestamp(null, 'mixed-derived', self::RETENTION_STALE_AFTER_SECONDS));

                continue;
            }

            $hasAnyRun = true;
            $meta = $audit === null ? [] : $this->decodeAuditMeta($audit);
            $status = $audit === null ? 'partial' : 'ok';
            $latestGeneratedAt = $this->normalizeTimestamp($latestPlan['payload']['generated_at'] ?? ($audit->created_at ?? null));
            $scopes[$scope] = array_merge([
                'status' => $status,
                'policy' => $policy,
                'latest_generated_at' => $latestGeneratedAt,
                'latest_plan_path' => $latestPlan['path'] ?? ($meta['plan'] ?? null),
                'latest_execute_status' => $audit?->result,
                'latest_summary' => [
                    'deleted_files_count' => (int) ($meta['deleted_files_count'] ?? 0),
                    'deleted_bytes' => (int) ($meta['deleted_bytes'] ?? 0),
                    'missing_files' => (int) ($meta['missing_files'] ?? 0),
                    'skipped_files' => (int) ($meta['skipped_files'] ?? 0),
                    'planned_files' => (int) data_get($latestPlan, 'payload.summary.files', 0),
                    'planned_bytes' => (int) data_get($latestPlan, 'payload.summary.bytes', 0),
                ],
            ], $this->freshnessFromTimestamp($latestGeneratedAt, 'mixed-derived', self::RETENTION_STALE_AFTER_SECONDS));
        }

        return [
            'status' => $hasAnyRun ? 'ok' : 'never_run',
            'configured_scopes' => array_keys($configuredScopes),
            'scopes' => $scopes,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blobCoverageSection(): array
    {
        $storageBlobCount = $this->tableCount('storage_blobs');
        $verifiedLocationCountsByDisk = [];
        if (Schema::hasTable('storage_blob_locations')) {
            $verifiedLocationCountsByDisk = DB::table('storage_blob_locations')
                ->selectRaw('disk, count(*) as aggregate')
                ->whereNotNull('verified_at')
                ->groupBy('disk')
                ->pluck('aggregate', 'disk')
                ->map(static fn (mixed $count): int => (int) $count)
                ->all();
        }

        $blobGcAudit = $this->latestAuditForAction('storage_blob_gc');
        $blobGcMeta = $blobGcAudit === null ? [] : $this->decodeAuditMeta($blobGcAudit);

        $offloadAudit = $this->latestAuditForAction('storage_blob_offload');
        $offloadMeta = $offloadAudit === null ? [] : $this->decodeAuditMeta($offloadAudit);

        return [
            'status' => 'ok',
            'counts' => [
                'storage_blobs' => $storageBlobCount,
                'verified_storage_blob_locations_by_disk' => $verifiedLocationCountsByDisk,
            ],
            'blob_gc' => $blobGcAudit === null
                ? array_merge([
                    'status' => 'never_run',
                    'latest_generated_at' => null,
                    'latest_summary' => null,
                ], $this->freshnessFromTimestamp(null, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS))
                : array_merge([
                    'status' => 'ok',
                    'latest_generated_at' => $this->normalizeTimestamp($blobGcAudit->created_at),
                    'latest_summary' => $blobGcMeta,
                ], $this->freshnessFromTimestamp($this->normalizeTimestamp($blobGcAudit->created_at), 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS)),
            'blob_offload' => $offloadAudit === null
                ? array_merge([
                    'status' => 'never_run',
                    'latest_generated_at' => null,
                    'latest_mode' => null,
                    'latest_summary' => null,
                ], $this->freshnessFromTimestamp(null, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS))
                : array_merge([
                    'status' => 'ok',
                    'latest_generated_at' => $this->normalizeTimestamp($offloadAudit->created_at),
                    'latest_mode' => $offloadMeta['mode'] ?? null,
                    'latest_summary' => $offloadMeta,
                ], $this->freshnessFromTimestamp($this->normalizeTimestamp($offloadAudit->created_at), 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function exactAuthoritySection(): array
    {
        $audit = $this->latestAuditForAction('storage_backfill_exact_release_file_sets');
        $meta = $audit === null ? [] : $this->decodeAuditMeta($audit);

        return [
            'status' => 'ok',
            'counts' => [
                'content_release_exact_manifests' => $this->tableCount('content_release_exact_manifests'),
                'content_release_exact_manifest_files' => $this->tableCount('content_release_exact_manifest_files'),
            ],
            'latest_backfill' => $audit === null
                ? array_merge([
                    'status' => 'never_run',
                    'latest_generated_at' => null,
                    'latest_summary' => null,
                ], $this->freshnessFromTimestamp(null, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS))
                : array_merge([
                    'status' => 'ok',
                    'latest_generated_at' => $this->normalizeTimestamp($audit->created_at),
                    'latest_summary' => $meta,
                ], $this->freshnessFromTimestamp($this->normalizeTimestamp($audit->created_at), 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rehydrateSection(): array
    {
        $audit = $this->latestAuditForAction('storage_rehydrate_exact_release');
        if ($audit === null) {
            return array_merge([
                'status' => 'never_run',
                'latest_generated_at' => null,
                'latest_mode' => null,
                'latest_plan_path' => null,
                'latest_run_path' => null,
                'latest_summary' => null,
            ], $this->freshnessFromTimestamp(null, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
        }

        $meta = $this->decodeAuditMeta($audit);
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        $latestGeneratedAt = $this->normalizeTimestamp($audit->created_at);

        return array_merge([
            'status' => 'ok',
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $meta['mode'] ?? null,
            'latest_plan_path' => $meta['plan_path'] ?? null,
            'latest_run_path' => $result['run_dir'] ?? null,
            'latest_summary' => [
                'status' => $result['status'] ?? null,
                'disk' => $meta['disk'] ?? null,
                'target_root' => data_get($meta, 'plan.target_root'),
                'verified_files' => (int) ($result['verified_files'] ?? 0),
                'verified_bytes' => (int) ($result['verified_bytes'] ?? 0),
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    private function quarantineSection(): array
    {
        $audit = $this->latestAuditForAction('storage_quarantine_exact_roots');
        $meta = $audit === null ? [] : $this->decodeAuditMeta($audit);
        $plan = is_array($meta['plan'] ?? null) ? $meta['plan'] : [];
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        $latestGeneratedAt = $this->normalizeTimestamp($audit?->created_at);

        return array_merge([
            'status' => $audit === null ? 'never_run' : 'ok',
            'item_root_count' => $this->countDirectoriesByGlob($this->quarantineRootBase().DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'root'),
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $meta['mode'] ?? null,
            'latest_plan_path' => $meta['plan_path'] ?? null,
            'latest_run_path' => $result['run_dir'] ?? null,
            'latest_summary' => $audit === null ? null : [
                'candidate_count' => (int) data_get($plan, 'summary.candidate_count', 0),
                'blocked_count' => (int) data_get($plan, 'summary.blocked_count', 0),
                'skipped_count' => (int) data_get($plan, 'summary.skipped_count', 0),
                'quarantined_count' => is_array($result['quarantined'] ?? null) ? count($result['quarantined']) : 0,
                'status' => $result['status'] ?? null,
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    private function restoreSection(): array
    {
        $audit = $this->latestAuditForAction('storage_restore_quarantined_root');
        $meta = $audit === null ? [] : $this->decodeAuditMeta($audit);
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        $latestGeneratedAt = $this->normalizeTimestamp($audit?->created_at);

        return array_merge([
            'status' => $audit === null ? 'never_run' : 'ok',
            'restore_run_count' => $this->countDirectoriesByGlob($this->restoreRootBase().DIRECTORY_SEPARATOR.'*'),
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $meta['mode'] ?? null,
            'latest_plan_path' => $meta['plan_path'] ?? null,
            'latest_run_path' => $result['run_dir'] ?? null,
            'latest_summary' => $audit === null ? null : [
                'status' => $result['status'] ?? null,
                'restored_root' => $result['restored_root'] ?? null,
                'target_root' => $result['target_root'] ?? null,
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    private function purgeSection(): array
    {
        $audit = $this->latestAuditForAction('storage_purge_quarantined_root');
        $meta = $audit === null ? [] : $this->decodeAuditMeta($audit);
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        $latestGeneratedAt = $this->normalizeTimestamp($audit?->created_at);

        return array_merge([
            'status' => $audit === null ? 'never_run' : 'ok',
            'purge_receipt_count' => $this->countFilesByGlob($this->purgeRootBase().DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'items'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'purge.json'),
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $meta['mode'] ?? null,
            'latest_plan_path' => $meta['plan_path'] ?? null,
            'latest_run_path' => $result['run_dir'] ?? null,
            'latest_summary' => $audit === null ? null : [
                'status' => $result['status'] ?? null,
                'receipt_path' => $result['receipt_path'] ?? null,
                'blocked_reason' => data_get($meta, 'plan.blocked_reason'),
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
    }

    /**
     * @return array<string,mixed>
     */
    private function retirementSection(): array
    {
        return [
            'status' => 'ok',
            'actions' => [
                'quarantine' => $this->latestRetirementSummaryForAction('quarantine'),
                'purge' => $this->latestRetirementSummaryForAction('purge'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeTruthSection(): array
    {
        $resolverMaterializationEnabled = (bool) config('storage_rollout.resolver_materialization_enabled', false);
        $packsV2RemoteRehydrateEnabled = (bool) config('storage_rollout.packs_v2_remote_rehydrate_enabled', false);

        $readiness = match (true) {
            $packsV2RemoteRehydrateEnabled => 'remote_rehydrate_enabled',
            $resolverMaterializationEnabled => 'materialization_enabled_only',
            default => 'local_only',
        };

        return [
            'status' => 'ok',
            'resolver_materialization_enabled' => $resolverMaterializationEnabled,
            'packs_v2_remote_rehydrate_enabled' => $packsV2RemoteRehydrateEnabled,
            'v2_readiness' => $readiness,
        ] + $this->unknownFreshness('config-derived');
    }

    /**
     * @return array<string,mixed>
     */
    private function automationReadinessSection(): array
    {
        return [
            'status' => 'ok',
            'auto_dry_run_ok' => [
                'storage:inventory',
                'storage:prune',
                'storage:blob-gc',
                'storage:blob-offload',
                'storage:backfill-release-metadata',
                'storage:backfill-exact-release-file-sets',
                'storage:quarantine-exact-roots',
                'storage:retire-exact-roots',
            ],
            'manual_execute_only' => [
                'storage:prune',
                'storage:blob-offload',
                'storage:rehydrate-exact-release',
                'storage:quarantine-exact-roots',
                'storage:restore-quarantined-root',
                'storage:purge-quarantined-root',
                'storage:retire-exact-roots',
                'storage:backfill-release-metadata',
                'storage:backfill-exact-release-file-sets',
            ],
            'not_in_scope_for_pr25' => [
                'scheduler_execute_automation',
                'batch_restore',
                'runtime_reader_changes',
                'remote_read_cutover',
                'storage:blob-gc execute',
            ],
        ] + $this->unknownFreshness('config-derived');
    }

    /**
     * @return array<string,mixed>
     */
    private function latestRetirementSummaryForAction(string $action): array
    {
        $audit = $this->latestAuditForAction(
            'storage_retire_exact_roots',
            static fn (array $meta, object $row): bool => (string) data_get($meta, 'plan.action', '') === $action
        );

        if ($audit === null) {
            return array_merge([
                'status' => 'never_run',
                'latest_generated_at' => null,
                'latest_mode' => null,
                'latest_plan_path' => null,
                'latest_run_path' => null,
                'latest_summary' => null,
            ], $this->freshnessFromTimestamp(null, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
        }

        $meta = $this->decodeAuditMeta($audit);
        $result = is_array($meta['result'] ?? null) ? $meta['result'] : [];
        $latestGeneratedAt = $this->normalizeTimestamp($audit->created_at);

        return array_merge([
            'status' => 'ok',
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $meta['mode'] ?? null,
            'latest_plan_path' => $meta['plan_path'] ?? null,
            'latest_run_path' => $result['run_dir'] ?? null,
            'latest_summary' => [
                'status' => $result['status'] ?? null,
                'success_count' => (int) ($result['success_count'] ?? 0),
                'failure_count' => (int) ($result['failure_count'] ?? 0),
                'blocked_count' => (int) ($result['blocked_count'] ?? 0),
                'skipped_count' => (int) ($result['skipped_count'] ?? 0),
            ],
        ], $this->freshnessFromTimestamp($latestGeneratedAt, 'audit-derived', self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS));
    }

    /**
     * @return array{
     *   last_updated_at:?string,
     *   freshness_age_seconds:?int,
     *   freshness_state:string,
     *   freshness_source_type:string
     * }
     */
    private function freshnessFromTimestamp(?string $timestamp, string $sourceType, ?int $staleAfterSeconds, string $missingState = 'never_run'): array
    {
        $timestamp = $this->normalizeTimestamp($timestamp);
        if ($timestamp === null) {
            return [
                'last_updated_at' => null,
                'freshness_age_seconds' => null,
                'freshness_state' => $missingState,
                'freshness_source_type' => $sourceType,
            ];
        }

        try {
            $updatedAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return $this->unknownFreshness($sourceType);
        }

        $ageSeconds = (int) $updatedAt->diffInSeconds(now());
        $state = $staleAfterSeconds !== null && $ageSeconds > $staleAfterSeconds ? 'stale' : 'fresh';

        return [
            'last_updated_at' => $updatedAt->toIso8601String(),
            'freshness_age_seconds' => $ageSeconds,
            'freshness_state' => $state,
            'freshness_source_type' => $sourceType,
        ];
    }

    /**
     * @return array{
     *   last_updated_at:null,
     *   freshness_age_seconds:null,
     *   freshness_state:string,
     *   freshness_source_type:string
     * }
     */
    private function unknownFreshness(string $sourceType = 'config-derived'): array
    {
        return [
            'last_updated_at' => null,
            'freshness_age_seconds' => null,
            'freshness_state' => 'unknown_freshness',
            'freshness_source_type' => $sourceType,
        ];
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (! is_scalar($value) || $value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function latestAuditForAction(string $action, ?callable $filter = null): ?object
    {
        if (! Schema::hasTable('audit_logs')) {
            return null;
        }

        $rows = DB::table('audit_logs')
            ->where('action', $action)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        foreach ($rows as $row) {
            $meta = $this->decodeAuditMeta($row);
            if ($filter === null || $filter($meta, $row) === true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAuditMeta(object $row): array
    {
        $decoded = json_decode((string) ($row->meta_json ?? '{}'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{path:string,payload:array<string,mixed>}|null
     */
    private function latestJsonFileUnder(string $dir, ?callable $filter = null): ?array
    {
        if (! is_dir($dir)) {
            return null;
        }

        $latest = null;
        foreach ($this->jsonFilesUnder($dir) as $path) {
            $payload = $this->safeJsonDecodeFile($path);
            if ($payload === null) {
                continue;
            }

            if ($filter !== null && $filter($path, $payload) !== true) {
                continue;
            }

            $mtime = @filemtime($path) ?: 0;
            if ($latest === null || $mtime > $latest['mtime']) {
                $latest = [
                    'mtime' => $mtime,
                    'path' => $path,
                    'payload' => $payload,
                ];
            }
        }

        if ($latest === null) {
            return null;
        }

        unset($latest['mtime']);

        return $latest;
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

    /**
     * @return list<string>
     */
    private function jsonFilesUnder(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private function countDirectoriesByGlob(string $pattern): int
    {
        $matches = glob($pattern, GLOB_ONLYDIR);

        return is_array($matches) ? count($matches) : 0;
    }

    private function countFilesByGlob(string $pattern): int
    {
        $matches = glob($pattern);
        if (! is_array($matches)) {
            return 0;
        }

        return count(array_filter($matches, 'is_file'));
    }

    private function tableCount(string $table): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) DB::table($table)->count();
    }

    /**
     * @param  array<int|string,mixed>  $values
     * @return list<string>
     */
    private function normalizeScalarList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (is_array($value) && array_key_exists('scope', $value)) {
                $normalized[] = (string) $value['scope'];

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[] = (string) $value;

                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $normalized[] = is_string($encoded) ? $encoded : '[complex]';
        }

        return array_values($normalized);
    }

    private function quarantineRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.quarantine_root_dir', 'app/private/quarantine/release_roots'));

        return storage_path(ltrim($relative, '/\\'));
    }

    private function restoreRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.restore_root_dir', 'app/private/quarantine/restore_runs'));

        return storage_path(ltrim($relative, '/\\'));
    }

    private function purgeRootBase(): string
    {
        $relative = trim((string) config('storage_rollout.purge_root_dir', 'app/private/quarantine/purge_runs'));

        return storage_path(ltrim($relative, '/\\'));
    }
}
