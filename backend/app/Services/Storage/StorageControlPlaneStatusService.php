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

    public function __construct(
        private readonly StorageCostAnalyzerService $costAnalyzer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildStatus(): array
    {
        $inventory = $this->inventorySection();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'inventory' => $inventory,
            'retention' => $this->retentionSection(),
            'report_artifacts_archive' => $this->reportArtifactsArchiveSection(),
            'report_artifacts_posture' => $this->reportArtifactsPostureSection(),
            'reports_artifacts_lifecycle' => $this->reportsArtifactsLifecycleSection($inventory),
            'blob_coverage' => $this->blobCoverageSection(),
            'exact_authority' => $this->exactAuthoritySection(),
            'rehydrate' => $this->rehydrateSection(),
            'quarantine' => $this->quarantineSection(),
            'restore' => $this->restoreSection(),
            'purge' => $this->purgeSection(),
            'retirement' => $this->retirementSection(),
            'materialized_cache' => $this->materializedCacheSection(),
            'cost_reclaim_posture' => $this->costReclaimPostureSection(),
            'runtime_truth' => $this->runtimeTruthSection(),
            'automation_readiness' => $this->automationReadinessSection(),
        ];

        $payload['attention_digest'] = $this->buildAttentionDigest($payload);

        return $payload;
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
     * @param  array<string,mixed>  $inventory
     * @return array<string,mixed>
     */
    private function reportsArtifactsLifecycleSection(array $inventory): array
    {
        if ($this->controlPlaneReadFromCatalogEnabled()) {
            $catalog = $this->catalogReportsArtifactsLifecycleSection($inventory);
            if ($catalog !== null) {
                return $catalog;
            }
        }

        $canonicalRootPath = storage_path('app/private/artifacts');
        $legacyRootPath = storage_path('app/private/reports');

        $canonicalReportJson = $this->matchingFilesStats(
            $canonicalRootPath,
            static fn (string $relativePath): bool => str_starts_with($relativePath, 'reports/')
                && str_ends_with($relativePath, '/report.json')
        );
        $canonicalPdf = $this->matchingFilesStats(
            $canonicalRootPath,
            static fn (string $relativePath): bool => str_starts_with($relativePath, 'pdf/')
                && (str_ends_with($relativePath, '/report_free.pdf') || str_ends_with($relativePath, '/report_full.pdf'))
        );
        $legacyReportJson = $this->matchingFilesStats(
            $legacyRootPath,
            static fn (string $relativePath): bool => str_ends_with($relativePath, '/report.json') || $relativePath === 'report.json'
        );
        $legacyPdf = $this->matchingFilesStats(
            $legacyRootPath,
            static fn (string $relativePath): bool => str_ends_with($relativePath, '/report_free.pdf')
                || str_ends_with($relativePath, '/report_full.pdf')
                || in_array($relativePath, ['report_free.pdf', 'report_full.pdf'], true)
        );
        $timestampBackups = $this->matchingFilesStats(
            $legacyRootPath,
            static fn (string $relativePath): bool => preg_match('#(^|/)report\.\d{8}_\d{6}\.json$#', $relativePath) === 1
        );

        $latestInventoryGeneratedAt = $this->normalizeTimestamp($inventory['latest_generated_at'] ?? null);

        return array_merge([
            'status' => $latestInventoryGeneratedAt === null ? 'not_available' : 'ok',
            'canonical_root_path' => $canonicalRootPath,
            'legacy_root_path' => $legacyRootPath,
            'canonical_user_output' => [
                'report_json_files' => $canonicalReportJson['files'],
                'pdf_files' => $canonicalPdf['files'],
                'bytes' => $canonicalReportJson['bytes'] + $canonicalPdf['bytes'],
            ],
            'legacy_fallback_live' => [
                'report_json_files' => $legacyReportJson['files'],
                'pdf_files' => $legacyPdf['files'],
                'bytes' => $legacyReportJson['bytes'] + $legacyPdf['bytes'],
            ],
            'safe_to_prune_derived' => [
                'timestamp_backup_json_files' => $timestampBackups['files'],
                'latest_reports_backups_policy' => [
                    'keep_days' => (int) config('storage_retention.reports.keep_days', 0),
                    'keep_timestamp_backups' => (int) config('storage_retention.reports.keep_timestamp_backups', 0),
                ],
            ],
            'archive_candidate_status' => 'none_proven',
        ], $this->freshnessFromTimestamp(
            $latestInventoryGeneratedAt,
            'audit-derived',
            self::INVENTORY_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function reportArtifactsArchiveSection(): array
    {
        if ($this->controlPlaneReadFromCatalogEnabled()) {
            $catalog = $this->catalogReportArtifactsArchiveSection();
            if ($catalog !== null) {
                return $catalog;
            }
        }

        $audit = $this->latestAuditForAction('storage_archive_report_artifacts');
        if ($audit === null) {
            return array_merge([
                'status' => 'not_available',
                'durable_receipt_source' => 'audit_logs.meta_json',
                'target_disk' => null,
                'latest_generated_at' => null,
                'latest_mode' => null,
                'latest_plan_path' => null,
                'latest_run_path' => null,
                'latest_run_path_exists' => false,
                'latest_summary' => [
                    'candidate_count' => 0,
                    'copied_count' => 0,
                    'verified_count' => 0,
                    'already_archived_count' => 0,
                    'failed_count' => 0,
                    'results_count' => 0,
                ],
            ], $this->freshnessFromTimestamp(
                null,
                'audit-derived',
                self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
                'not_available'
            ));
        }

        $meta = $this->decodeAuditMeta($audit);
        $latestGeneratedAt = $this->normalizeTimestamp($audit->created_at);
        $latestRunPath = $this->normalizeOptionalString($meta['run_path'] ?? null);

        return array_merge([
            'status' => 'ok',
            'durable_receipt_source' => (string) ($meta['durable_receipt_source'] ?? 'audit_logs.meta_json'),
            'target_disk' => $this->normalizeOptionalString($meta['target_disk'] ?? null),
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $this->normalizeOptionalString($meta['mode'] ?? null),
            'latest_plan_path' => $this->normalizeOptionalString($meta['plan_path'] ?? $meta['plan'] ?? null),
            'latest_run_path' => $latestRunPath,
            'latest_run_path_exists' => $latestRunPath !== null && file_exists($latestRunPath),
            'latest_summary' => [
                'candidate_count' => (int) ($meta['candidate_count'] ?? data_get($meta, 'summary.candidate_count', 0)),
                'copied_count' => (int) ($meta['copied_count'] ?? data_get($meta, 'summary.copied_count', 0)),
                'verified_count' => (int) ($meta['verified_count'] ?? data_get($meta, 'summary.verified_count', 0)),
                'already_archived_count' => (int) ($meta['already_archived_count'] ?? data_get($meta, 'summary.already_archived_count', 0)),
                'failed_count' => (int) ($meta['failed_count'] ?? data_get($meta, 'summary.failed_count', 0)),
                'results_count' => (int) ($meta['results_count'] ?? count((array) ($meta['results'] ?? []))),
            ],
        ], $this->freshnessFromTimestamp(
            $latestGeneratedAt,
            'audit-derived',
            self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function reportArtifactsPostureSection(): array
    {
        if ($this->controlPlaneReadFromCatalogEnabled()) {
            $archive = $this->catalogReportArtifactsPostureNode('archive_report_artifacts', [
                'candidate_count',
                'copied_count',
                'verified_count',
                'already_archived_count',
                'failed_count',
                'results_count',
            ]);
            $rehydrate = $this->catalogReportArtifactsPostureNode('rehydrate_report_artifacts', [
                'candidate_count',
                'rehydrated_count',
                'verified_count',
                'skipped_count',
                'blocked_count',
                'failed_count',
                'results_count',
            ]);
            $shrink = $this->catalogReportArtifactsPostureNode('shrink_archived_report_artifacts', [
                'candidate_count',
                'deleted_count',
                'skipped_missing_local_count',
                'blocked_missing_remote_count',
                'blocked_missing_archive_proof_count',
                'blocked_missing_rehydrate_proof_count',
                'blocked_hash_mismatch_count',
                'failed_count',
                'results_count',
            ]);

            if ($archive !== null || $rehydrate !== null || $shrink !== null) {
                $archiveAudit = $this->latestAuditForAction('storage_archive_report_artifacts');
                $rehydrateAudit = $this->latestAuditForAction('storage_rehydrate_report_artifacts');
                $shrinkAudit = $this->latestAuditForAction('storage_shrink_archived_report_artifacts');

                $archive ??= $this->reportArtifactsPostureNode($archiveAudit, [
                    'candidate_count',
                    'copied_count',
                    'verified_count',
                    'already_archived_count',
                    'failed_count',
                    'results_count',
                ]);
                $rehydrate ??= $this->reportArtifactsPostureNode($rehydrateAudit, [
                    'candidate_count',
                    'rehydrated_count',
                    'verified_count',
                    'skipped_count',
                    'blocked_count',
                    'failed_count',
                    'results_count',
                ]);
                $shrink ??= $this->reportArtifactsPostureNode($shrinkAudit, [
                    'candidate_count',
                    'deleted_count',
                    'skipped_missing_local_count',
                    'blocked_missing_remote_count',
                    'blocked_missing_archive_proof_count',
                    'blocked_missing_rehydrate_proof_count',
                    'blocked_hash_mismatch_count',
                    'failed_count',
                    'results_count',
                ]);

                $statuses = [
                    $archive['status'] ?? 'not_available',
                    $rehydrate['status'] ?? 'not_available',
                    $shrink['status'] ?? 'not_available',
                ];

                $overallStatus = match (true) {
                    count(array_filter($statuses, static fn (string $status): bool => $status !== 'not_available')) === 0 => 'not_available',
                    in_array('not_available', $statuses, true) => 'partial',
                    default => 'ok',
                };

                $lastUpdatedAt = $this->latestTimestamp([
                    $archive['latest_generated_at'] ?? null,
                    $rehydrate['latest_generated_at'] ?? null,
                    $shrink['latest_generated_at'] ?? null,
                ]);

                return array_merge([
                    'status' => $overallStatus,
                    'durable_receipt_source' => 'attempt_receipts',
                    'target_disk' => $this->consistentReportArtifactsTargetDisk([$archiveAudit, $rehydrateAudit, $shrinkAudit]),
                    'archive' => $archive,
                    'rehydrate' => $rehydrate,
                    'shrink' => $shrink,
                ], $this->freshnessFromTimestamp(
                    $lastUpdatedAt,
                    'ledger-derived',
                    self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
                    'not_available'
                ));
            }
        }

        $archiveAudit = $this->latestAuditForAction('storage_archive_report_artifacts');
        $rehydrateAudit = $this->latestAuditForAction('storage_rehydrate_report_artifacts');
        $shrinkAudit = $this->latestAuditForAction('storage_shrink_archived_report_artifacts');

        $archive = $this->reportArtifactsPostureNode($archiveAudit, [
            'candidate_count',
            'copied_count',
            'verified_count',
            'already_archived_count',
            'failed_count',
            'results_count',
        ]);
        $rehydrate = $this->reportArtifactsPostureNode($rehydrateAudit, [
            'candidate_count',
            'rehydrated_count',
            'verified_count',
            'skipped_count',
            'blocked_count',
            'failed_count',
            'results_count',
        ]);
        $shrink = $this->reportArtifactsPostureNode($shrinkAudit, [
            'candidate_count',
            'deleted_count',
            'skipped_missing_local_count',
            'blocked_missing_remote_count',
            'blocked_missing_archive_proof_count',
            'blocked_missing_rehydrate_proof_count',
            'blocked_hash_mismatch_count',
            'failed_count',
            'results_count',
        ]);

        $statuses = [
            $archive['status'] ?? 'not_available',
            $rehydrate['status'] ?? 'not_available',
            $shrink['status'] ?? 'not_available',
        ];

        $overallStatus = match (true) {
            count(array_filter($statuses, static fn (string $status): bool => $status !== 'not_available')) === 0 => 'not_available',
            in_array('not_available', $statuses, true) => 'partial',
            default => 'ok',
        };

        $lastUpdatedAt = $this->latestTimestamp([
            $archive['latest_generated_at'] ?? null,
            $rehydrate['latest_generated_at'] ?? null,
            $shrink['latest_generated_at'] ?? null,
        ]);

        return array_merge([
            'status' => $overallStatus,
            'durable_receipt_source' => 'audit_logs.meta_json',
            'target_disk' => $this->consistentReportArtifactsTargetDisk([$archiveAudit, $rehydrateAudit, $shrinkAudit]),
            'archive' => $archive,
            'rehydrate' => $rehydrate,
            'shrink' => $shrink,
        ], $this->freshnessFromTimestamp(
            $lastUpdatedAt,
            'audit-derived',
            self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
            'not_available'
        ));
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
    private function materializedCacheSection(): array
    {
        $rootPath = storage_path('app/private/packs_v2_materialized');
        $bucketRoots = $this->materializedBucketRoots($rootPath);
        $stats = $this->directoryTreeStats($rootPath);

        return [
            'status' => 'ok',
            'root_path' => $rootPath,
            'bucket_count' => count($bucketRoots),
            'total_files' => $stats['total_files'],
            'total_bytes' => $stats['total_bytes'],
            'sample_bucket_paths' => array_slice($bucketRoots, 0, 5),
            'cache_key_contract' => 'storage_path + manifest_hash',
            'runtime_role' => 'derived_cache_return_surface',
            'source_of_truth' => false,
            'zero_state' => count($bucketRoots) === 0,
            'last_updated_at' => null,
            'freshness_age_seconds' => null,
            'freshness_state' => 'unknown_freshness',
            'freshness_source_type' => 'disk-derived',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function costReclaimPostureSection(): array
    {
        try {
            $payload = $this->costAnalyzer->analyze();
        } catch (\Throwable) {
            return [
                'status' => 'not_available',
                'source_schema_version' => null,
                'root_path' => storage_path(),
                'summary' => null,
                'by_category' => [],
                'no_touch_categories' => [],
                'reclaim_categories' => [],
                'last_updated_at' => null,
                'freshness_age_seconds' => null,
                'freshness_state' => 'unknown_freshness',
                'freshness_source_type' => 'disk-derived',
            ];
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : null;
        $byCategory = is_array($payload['by_category'] ?? null) ? $payload['by_category'] : [];
        $reclaimCategories = [];

        foreach ((array) ($payload['reclaim_candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $reclaimCategories[] = [
                'category' => (string) ($candidate['category'] ?? ''),
                'bytes' => (int) ($candidate['bytes'] ?? 0),
                'risk_level' => (string) ($candidate['risk_level'] ?? ''),
            ];
        }

        return [
            'status' => (string) ($payload['status'] ?? 'ok'),
            'source_schema_version' => (string) ($payload['schema_version'] ?? ''),
            'root_path' => (string) ($payload['root_path'] ?? storage_path()),
            'summary' => $summary,
            'by_category' => $byCategory,
            'no_touch_categories' => $this->normalizeScalarList((array) ($payload['no_touch_categories'] ?? [])),
            'reclaim_categories' => $reclaimCategories,
            'last_updated_at' => null,
            'freshness_age_seconds' => null,
            'freshness_state' => 'unknown_freshness',
            'freshness_source_type' => 'disk-derived',
        ];
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

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  list<string>  $summaryFields
     * @return array<string,mixed>
     */
    private function reportArtifactsPostureNode(?object $audit, array $summaryFields): array
    {
        $summary = [];
        foreach ($summaryFields as $field) {
            $summary[$field] = 0;
        }

        if ($audit === null) {
            return array_merge([
                'status' => 'not_available',
                'latest_generated_at' => null,
                'latest_mode' => null,
                'latest_plan_path' => null,
                'latest_run_path' => null,
                'latest_run_path_exists' => false,
                'latest_summary' => $summary,
            ], $this->freshnessFromTimestamp(
                null,
                'audit-derived',
                self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
                'not_available'
            ));
        }

        $meta = $this->decodeAuditMeta($audit);
        $latestGeneratedAt = $this->normalizeTimestamp($audit->created_at);
        $latestRunPath = $this->normalizeOptionalString($meta['run_path'] ?? null);

        foreach ($summaryFields as $field) {
            if ($field === 'results_count') {
                $summary[$field] = (int) ($meta['results_count'] ?? count((array) ($meta['results'] ?? [])));

                continue;
            }

            $summary[$field] = (int) ($meta[$field] ?? data_get($meta, 'summary.'.$field, 0));
        }

        return array_merge([
            'status' => 'ok',
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $this->normalizeOptionalString($meta['mode'] ?? null),
            'latest_plan_path' => $this->normalizeOptionalString($meta['plan_path'] ?? $meta['plan'] ?? null),
            'latest_run_path' => $latestRunPath,
            'latest_run_path_exists' => $latestRunPath !== null && file_exists($latestRunPath),
            'latest_summary' => $summary,
        ], $this->freshnessFromTimestamp(
            $latestGeneratedAt,
            'audit-derived',
            self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    private function controlPlaneReadFromCatalogEnabled(): bool
    {
        return (bool) config('storage_rollout.control_plane_read_from_catalog_enabled', false);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function catalogReportsArtifactsLifecycleSection(array $inventory): ?array
    {
        if (! Schema::hasTable('report_artifact_slots')) {
            return null;
        }

        $reportJsonSlotCount = $this->countCatalogSlots(['report_json_free', 'report_json_full']);
        $pdfSlotCount = $this->countCatalogSlots(['report_pdf_free', 'report_pdf_full']);
        $reportJsonBytes = $this->sumCatalogBytes(['report_json_free', 'report_json_full']);
        $pdfBytes = $this->sumCatalogBytes(['report_pdf_free', 'report_pdf_full']);

        if ($reportJsonSlotCount === 0 && $pdfSlotCount === 0 && $reportJsonBytes === 0 && $pdfBytes === 0) {
            return null;
        }

        $latestUpdatedAt = $this->latestTimestamp([
            $this->maxColumnTimestamp('report_artifact_slots', 'updated_at'),
            $this->maxColumnTimestamp('report_artifact_versions', 'updated_at'),
        ]);

        $latestInventoryGeneratedAt = $this->normalizeTimestamp($inventory['latest_generated_at'] ?? null) ?? $latestUpdatedAt;

        return array_merge([
            'status' => 'ok',
            'canonical_root_path' => storage_path('app/private/artifacts'),
            'legacy_root_path' => storage_path('app/private/reports'),
            'canonical_user_output' => [
                'report_json_files' => $reportJsonSlotCount,
                'pdf_files' => $pdfSlotCount,
                'bytes' => $reportJsonBytes + $pdfBytes,
            ],
            'legacy_fallback_live' => [
                'report_json_files' => $this->matchingFilesStats(storage_path('app/private/reports'), static fn (string $relativePath): bool => str_ends_with($relativePath, '/report.json') || $relativePath === 'report.json')['files'],
                'pdf_files' => $this->matchingFilesStats(storage_path('app/private/reports'), static fn (string $relativePath): bool => str_ends_with($relativePath, '/report_free.pdf')
                    || str_ends_with($relativePath, '/report_full.pdf')
                    || in_array($relativePath, ['report_free.pdf', 'report_full.pdf'], true))['files'],
                'bytes' => 0,
            ],
            'safe_to_prune_derived' => [
                'timestamp_backup_json_files' => 0,
                'latest_reports_backups_policy' => [
                    'keep_days' => (int) config('storage_retention.reports.keep_days', 0),
                    'keep_timestamp_backups' => (int) config('storage_retention.reports.keep_timestamp_backups', 0),
                ],
            ],
            'archive_candidate_status' => 'ledger_backed',
        ], $this->freshnessFromTimestamp(
            $latestInventoryGeneratedAt,
            'ledger-derived',
            self::INVENTORY_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function catalogReportArtifactsArchiveSection(): ?array
    {
        if (! Schema::hasTable('artifact_lifecycle_jobs')) {
            return null;
        }

        $job = DB::table('artifact_lifecycle_jobs')
            ->where('job_type', 'archive_report_artifacts')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($job === null) {
            return null;
        }

        $request = $this->decodeMaybeJson($job->request_payload_json ?? null);
        $result = $this->decodeMaybeJson($job->result_payload_json ?? null);
        $latestGeneratedAt = $this->normalizeTimestamp($job->finished_at ?? $job->updated_at ?? $job->created_at);
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        return array_merge([
            'status' => 'ok',
            'durable_receipt_source' => 'attempt_receipts',
            'target_disk' => $this->normalizeOptionalString(data_get($request, 'target_disk') ?? data_get($result, 'target_disk')),
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $this->normalizeOptionalString(data_get($request, 'mode') ?? data_get($result, 'mode')),
            'latest_plan_path' => $this->normalizeOptionalString(data_get($request, 'plan_path')),
            'latest_run_path' => $this->normalizeOptionalString(data_get($result, 'run_path')),
            'latest_run_path_exists' => is_string(data_get($result, 'run_path')) && file_exists((string) data_get($result, 'run_path')),
            'latest_summary' => [
                'candidate_count' => (int) data_get($summary, 'candidate_count', 0),
                'copied_count' => (int) data_get($summary, 'copied_count', 0),
                'verified_count' => (int) data_get($summary, 'verified_count', 0),
                'already_archived_count' => (int) data_get($summary, 'already_archived_count', 0),
                'failed_count' => (int) data_get($summary, 'failed_count', 0),
                'results_count' => count((array) ($result['results'] ?? [])),
            ],
        ], $this->freshnessFromTimestamp(
            $latestGeneratedAt,
            'ledger-derived',
            self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    /**
     * @param  list<string>  $slotCodes
     */
    private function countCatalogSlots(array $slotCodes): int
    {
        if (! Schema::hasTable('report_artifact_slots')) {
            return 0;
        }

        return (int) DB::table('report_artifact_slots')
            ->whereIn('slot_code', $slotCodes)
            ->whereNotNull('current_version_id')
            ->count();
    }

    /**
     * @param  list<string>  $slotCodes
     */
    private function sumCatalogBytes(array $slotCodes): int
    {
        if (! Schema::hasTable('report_artifact_slots') || ! Schema::hasTable('report_artifact_versions')) {
            return 0;
        }

        return (int) DB::table('report_artifact_slots')
            ->join('report_artifact_versions', 'report_artifact_versions.id', '=', 'report_artifact_slots.current_version_id')
            ->whereIn('report_artifact_slots.slot_code', $slotCodes)
            ->sum('report_artifact_versions.byte_size');
    }

    private function maxColumnTimestamp(string $table, string $column): ?string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        $value = DB::table($table)->max($column);

        return $this->normalizeTimestamp($value);
    }

    /**
     * @param  list<string>  $summaryFields
     * @return array<string,mixed>|null
     */
    private function catalogReportArtifactsPostureNode(string $jobType, array $summaryFields): ?array
    {
        if (! Schema::hasTable('artifact_lifecycle_jobs')) {
            return null;
        }

        $job = DB::table('artifact_lifecycle_jobs')
            ->where('job_type', $jobType)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        if ($job === null) {
            return null;
        }

        $request = $this->decodeMaybeJson($job->request_payload_json ?? null);
        $result = $this->decodeMaybeJson($job->result_payload_json ?? null);
        $latestGeneratedAt = $this->normalizeTimestamp($job->finished_at ?? $job->updated_at ?? $job->created_at);
        $latestRunPath = $this->normalizeOptionalString(data_get($result, 'run_path'));
        $summary = [];

        foreach ($summaryFields as $field) {
            if ($field === 'results_count') {
                $summary[$field] = count((array) ($result['results'] ?? []));

                continue;
            }

            $summary[$field] = (int) data_get($result, 'summary.'.$field, 0);
        }

        return array_merge([
            'status' => 'ok',
            'latest_generated_at' => $latestGeneratedAt,
            'latest_mode' => $this->normalizeOptionalString(data_get($request, 'mode') ?? data_get($result, 'mode')),
            'latest_plan_path' => $this->normalizeOptionalString(data_get($request, 'plan_path')),
            'latest_run_path' => $latestRunPath,
            'latest_run_path_exists' => $latestRunPath !== null && file_exists($latestRunPath),
            'latest_summary' => $summary,
        ], $this->freshnessFromTimestamp(
            $latestGeneratedAt,
            'ledger-derived',
            self::MANUAL_CONTROL_PLANE_STALE_AFTER_SECONDS,
            'not_available'
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMaybeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<object|null>  $audits
     */
    private function consistentReportArtifactsTargetDisk(array $audits): ?string
    {
        $disks = [];

        foreach ($audits as $audit) {
            if ($audit === null) {
                continue;
            }

            $disk = $this->normalizeOptionalString($this->decodeAuditMeta($audit)['target_disk'] ?? null);
            if ($disk !== null) {
                $disks[$disk] = true;
            }
        }

        if ($disks === [] || count($disks) > 1) {
            return null;
        }

        return array_key_first($disks);
    }

    /**
     * @param  list<?string>  $timestamps
     */
    private function latestTimestamp(array $timestamps): ?string
    {
        $latest = null;

        foreach ($timestamps as $timestamp) {
            $normalized = $this->normalizeTimestamp($timestamp);
            if ($normalized === null) {
                continue;
            }

            try {
                $candidate = Carbon::parse($normalized);
            } catch (\Throwable) {
                continue;
            }

            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return $latest?->toIso8601String();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildAttentionDigest(array $payload): array
    {
        $staleSections = [];
        $neverRunSections = [];
        $notAvailableSections = [];
        $attentionItems = [];

        foreach ($this->attentionDigestDescriptors() as $descriptor) {
            $node = data_get($payload, $descriptor['path']);
            if (! is_array($node)) {
                continue;
            }

            $state = (string) ($node['freshness_state'] ?? '');
            if (! in_array($state, ['stale', 'never_run', 'not_available'], true)) {
                continue;
            }

            if ($state === 'stale') {
                $staleSections[] = $descriptor['path'];
            } elseif ($state === 'never_run') {
                $neverRunSections[] = $descriptor['path'];
            } else {
                $notAvailableSections[] = $descriptor['path'];
            }

            $attentionItems[] = [
                'path' => $descriptor['path'],
                'freshness_state' => $state,
                'last_updated_at' => $node['last_updated_at'] ?? null,
                'message' => $this->attentionDigestMessage($descriptor['label'], $state),
            ];
        }

        $overallState = match (true) {
            $notAvailableSections !== [] => 'degraded',
            $staleSections !== [] || $neverRunSections !== [] => 'attention_required',
            default => 'healthy',
        };

        return [
            'overall_state' => $overallState,
            'stale_sections' => $staleSections,
            'never_run_sections' => $neverRunSections,
            'not_available_sections' => $notAvailableSections,
            'attention_items' => $attentionItems,
            'counts' => [
                'stale' => count($staleSections),
                'never_run' => count($neverRunSections),
                'not_available' => count($notAvailableSections),
            ],
        ];
    }

    /**
     * @return list<array{path:string,label:string}>
     */
    private function attentionDigestDescriptors(): array
    {
        return [
            ['path' => 'inventory', 'label' => 'inventory'],
            ['path' => 'retention.scopes.reports_backups', 'label' => 'reports backups retention dry-run'],
            ['path' => 'retention.scopes.content_releases_retention', 'label' => 'content releases retention dry-run'],
            ['path' => 'retention.scopes.legacy_private_private_cleanup', 'label' => 'legacy private cleanup dry-run'],
            ['path' => 'report_artifacts_posture.archive', 'label' => 'report artifacts archive posture'],
            ['path' => 'report_artifacts_posture.rehydrate', 'label' => 'report artifacts rehydrate posture'],
            ['path' => 'report_artifacts_posture.shrink', 'label' => 'report artifacts shrink posture'],
            ['path' => 'reports_artifacts_lifecycle', 'label' => 'reports artifacts lifecycle'],
            ['path' => 'blob_coverage.blob_gc', 'label' => 'blob gc dry-run'],
            ['path' => 'blob_coverage.blob_offload', 'label' => 'blob offload dry-run'],
            ['path' => 'exact_authority.latest_backfill', 'label' => 'exact authority backfill'],
            ['path' => 'rehydrate', 'label' => 'rehydrate'],
            ['path' => 'quarantine', 'label' => 'quarantine'],
            ['path' => 'restore', 'label' => 'restore'],
            ['path' => 'purge', 'label' => 'purge'],
            ['path' => 'retirement.actions.quarantine', 'label' => 'retirement quarantine action'],
            ['path' => 'retirement.actions.purge', 'label' => 'retirement purge action'],
            ['path' => 'runtime_truth', 'label' => 'runtime truth'],
            ['path' => 'automation_readiness', 'label' => 'automation readiness'],
        ];
    }

    private function attentionDigestMessage(string $label, string $state): string
    {
        return match ($state) {
            'stale' => $label.' is stale',
            'not_available' => $label.' is not available',
            default => $label.' has never run',
        };
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

    /**
     * @return list<string>
     */
    private function materializedBucketRoots(string $rootPath): array
    {
        if (! is_dir($rootPath)) {
            return [];
        }

        $matches = glob($rootPath.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
        if (! is_array($matches)) {
            return [];
        }

        $roots = array_values(array_filter(
            array_map(static fn (string $path): string => str_replace('\\', '/', $path), $matches),
            static fn (string $path): bool => $path !== '' && is_dir($path)
        ));

        sort($roots);

        return array_values(array_unique($roots));
    }

    /**
     * @return array{total_files:int,total_bytes:int}
     */
    private function directoryTreeStats(string $rootPath): array
    {
        if (! is_dir($rootPath)) {
            return [
                'total_files' => 0,
                'total_bytes' => 0,
            ];
        }

        $totalFiles = 0;
        $totalBytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $totalFiles++;
            $totalBytes += (int) $file->getSize();
        }

        return [
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * @param  callable(string):bool  $matcher
     * @return array{files:int,bytes:int}
     */
    private function matchingFilesStats(string $rootPath, callable $matcher): array
    {
        if (! is_dir($rootPath)) {
            return [
                'files' => 0,
                'bytes' => 0,
            ];
        }

        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $normalizedRootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
            $normalizedFilePath = str_replace('\\', '/', $file->getPathname());
            $relativePath = ltrim((string) preg_replace(
                '#^'.preg_quote($normalizedRootPath, '#').'/?#',
                '',
                $normalizedFilePath
            ), '/');
            if ($relativePath === '' || $matcher($relativePath) !== true) {
                continue;
            }

            $files++;
            $bytes += max(0, (int) ($file->getSize() ?: 0));
        }

        return [
            'files' => $files,
            'bytes' => $bytes,
        ];
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
