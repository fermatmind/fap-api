<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\StorageBlobLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class OffloadLocalCopyShrinkService
{
    private const PLAN_SCHEMA = 'storage_shrink_offload_local_copies_plan.v1';

    private const RUN_SCHEMA = 'storage_shrink_offload_local_copies_run.v1';

    private const LOCATION_KIND_REMOTE_COPY = 'remote_copy';

    private const AUDIT_ACTION = 'storage_shrink_offload_local_copies';

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $targetDisk): array
    {
        $targetDisk = $this->normalizeDisk($targetDisk);
        $localRowsByHash = $this->verifiedRowsByHash('local');
        $targetRowsByHash = $this->verifiedRowsByHash($targetDisk);
        $distribution = $this->buildDistributionSummary($localRowsByHash, $targetRowsByHash, $targetDisk);

        $candidates = [];
        $blocked = [];
        $blockedReasonCounts = [];

        foreach ($localRowsByHash as $blobHash => $localRows) {
            $targetRows = $targetRowsByHash[$blobHash] ?? [];

            if ($targetRows === []) {
                $blocked[] = $this->blockedEntry($blobHash, 'LOCAL_ONLY_TARGET_VERIFIED_ROW_MISSING');
                $blockedReasonCounts['LOCAL_ONLY_TARGET_VERIFIED_ROW_MISSING'] = (int) ($blockedReasonCounts['LOCAL_ONLY_TARGET_VERIFIED_ROW_MISSING'] ?? 0) + 1;

                continue;
            }

            if (count($localRows) !== 1) {
                $blocked[] = $this->blockedEntry($blobHash, 'LOCAL_VERIFIED_ROW_CARDINALITY_INVALID', [
                    'local_row_ids' => array_map(static fn (StorageBlobLocation $row): int => (int) $row->getKey(), $localRows),
                    'target_row_ids' => array_map(static fn (StorageBlobLocation $row): int => (int) $row->getKey(), $targetRows),
                ]);
                $blockedReasonCounts['LOCAL_VERIFIED_ROW_CARDINALITY_INVALID'] = (int) ($blockedReasonCounts['LOCAL_VERIFIED_ROW_CARDINALITY_INVALID'] ?? 0) + 1;

                continue;
            }

            if (count($targetRows) !== 1) {
                $blocked[] = $this->blockedEntry($blobHash, 'TARGET_VERIFIED_ROW_CARDINALITY_INVALID', [
                    'local_row_ids' => array_map(static fn (StorageBlobLocation $row): int => (int) $row->getKey(), $localRows),
                    'target_row_ids' => array_map(static fn (StorageBlobLocation $row): int => (int) $row->getKey(), $targetRows),
                ]);
                $blockedReasonCounts['TARGET_VERIFIED_ROW_CARDINALITY_INVALID'] = (int) ($blockedReasonCounts['TARGET_VERIFIED_ROW_CARDINALITY_INVALID'] ?? 0) + 1;

                continue;
            }

            $localRow = $localRows[0];
            $targetRow = $targetRows[0];
            $localStoragePath = trim((string) $localRow->storage_path);
            $expectedLocalStoragePath = $this->localOffloadPathForHash($blobHash);

            if ($localStoragePath === '' || $localStoragePath !== $expectedLocalStoragePath) {
                $blocked[] = $this->blockedEntry($blobHash, 'LOCAL_STORAGE_PATH_OUT_OF_SCOPE', [
                    'local_location_row_id' => (int) $localRow->getKey(),
                    'local_storage_path' => $localStoragePath,
                    'expected_local_storage_path' => $expectedLocalStoragePath,
                    'target_location_row_id' => (int) $targetRow->getKey(),
                ]);
                $blockedReasonCounts['LOCAL_STORAGE_PATH_OUT_OF_SCOPE'] = (int) ($blockedReasonCounts['LOCAL_STORAGE_PATH_OUT_OF_SCOPE'] ?? 0) + 1;

                continue;
            }

            $targetStoragePath = trim((string) $targetRow->storage_path);
            $expectedTargetStoragePath = $this->targetOffloadPathForHash($blobHash);
            if ($targetStoragePath === '' || $targetStoragePath !== $expectedTargetStoragePath) {
                $blocked[] = $this->blockedEntry($blobHash, 'TARGET_STORAGE_PATH_OUT_OF_SCOPE', [
                    'local_location_row_id' => (int) $localRow->getKey(),
                    'target_location_row_id' => (int) $targetRow->getKey(),
                    'target_storage_path' => $targetStoragePath,
                    'expected_target_storage_path' => $expectedTargetStoragePath,
                ]);
                $blockedReasonCounts['TARGET_STORAGE_PATH_OUT_OF_SCOPE'] = (int) ($blockedReasonCounts['TARGET_STORAGE_PATH_OUT_OF_SCOPE'] ?? 0) + 1;

                continue;
            }

            if (! Storage::disk('local')->exists($localStoragePath)) {
                $blocked[] = $this->blockedEntry($blobHash, 'LOCAL_FILE_MISSING', [
                    'local_location_row_id' => (int) $localRow->getKey(),
                    'local_storage_path' => $localStoragePath,
                    'target_location_row_id' => (int) $targetRow->getKey(),
                ]);
                $blockedReasonCounts['LOCAL_FILE_MISSING'] = (int) ($blockedReasonCounts['LOCAL_FILE_MISSING'] ?? 0) + 1;

                continue;
            }

            $candidates[] = [
                'blob_hash' => $blobHash,
                'local_file_path' => $localStoragePath,
                'local_location_row_id' => (int) $localRow->getKey(),
                'target_location_row_id' => (int) $targetRow->getKey(),
                'local_storage_path' => $localStoragePath,
                'target_storage_path' => $targetStoragePath,
                'target_disk' => $targetDisk,
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => strcmp((string) ($left['blob_hash'] ?? ''), (string) ($right['blob_hash'] ?? '')));
        usort($blocked, static fn (array $left, array $right): int => strcmp((string) ($left['blob_hash'] ?? ''), (string) ($right['blob_hash'] ?? '')));
        ksort($blockedReasonCounts);

        return [
            'schema' => self::PLAN_SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'summary' => [
                'verified_remote_copy_counts_by_disk' => $distribution['verified_remote_copy_counts_by_disk'],
                'local_only_count' => $distribution['local_only_count'],
                'target_only_count' => $distribution['target_only_count'],
                'both_count' => $distribution['both_count'],
                'both_candidate_count' => count($candidates),
                'blocked_count' => count($blocked),
                'deleted_local_files_count' => 0,
                'deleted_local_rows_count' => 0,
            ],
            'candidates' => array_values($candidates),
            'blocked' => array_values($blocked),
            'reasons' => $blockedReasonCounts,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $this->assertPlanSchema($plan);

        $targetDisk = $this->normalizeDisk((string) ($plan['target_disk'] ?? $plan['disk'] ?? ''));
        $planPath = trim((string) ($plan['_meta']['plan_path'] ?? ''));
        $runDir = $this->runDirectory();
        File::ensureDirectoryExists($runDir);

        $results = [];
        $summary = [
            'both_candidate_count' => count(is_array($plan['candidates'] ?? null) ? $plan['candidates'] : []),
            'blocked_count' => 0,
            'deleted_local_files_count' => 0,
            'deleted_local_rows_count' => 0,
            'partial_failure_count' => 0,
            'failed_count' => 0,
            'local_only_count' => (int) data_get($plan, 'summary.local_only_count', 0),
            'target_only_count' => (int) data_get($plan, 'summary.target_only_count', 0),
        ];

        $candidates = is_array($plan['candidates'] ?? null) ? $plan['candidates'] : [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $result = $this->executeCandidate($candidate, $targetDisk);
            $results[] = $result;

            $status = (string) ($result['status'] ?? '');
            if ($status === 'deleted') {
                $summary['deleted_local_files_count']++;
                $summary['deleted_local_rows_count']++;

                continue;
            }

            if ($status === 'blocked') {
                $summary['blocked_count']++;

                continue;
            }

            if ($status === 'partial_failure') {
                if ((bool) ($result['local_row_deleted'] ?? false)) {
                    $summary['deleted_local_rows_count']++;
                }
                if ((bool) ($result['local_file_deleted'] ?? false)) {
                    $summary['deleted_local_files_count']++;
                }
                $summary['partial_failure_count']++;

                continue;
            }

            $summary['failed_count']++;
        }

        $status = 'executed';
        if ($summary['partial_failure_count'] > 0 || $summary['failed_count'] > 0) {
            $status = 'partial_failure';
        }

        $payload = [
            'schema' => self::RUN_SCHEMA,
            'mode' => 'execute',
            'status' => $status,
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'plan' => $planPath,
            'summary' => $summary,
            'results' => $results,
        ];

        $runPath = $runDir.DIRECTORY_SEPARATOR.'run.json';
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode offload local copy shrink run receipt.');
        }
        File::put($runPath, $encoded.PHP_EOL);

        $payload['run_path'] = $runPath;
        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executeCandidate(array $candidate, string $targetDisk): array
    {
        $blobHash = strtolower(trim((string) ($candidate['blob_hash'] ?? '')));
        $localStoragePath = trim((string) ($candidate['local_storage_path'] ?? $candidate['local_file_path'] ?? ''));
        $localRowId = (int) ($candidate['local_location_row_id'] ?? 0);
        $targetRowId = (int) ($candidate['target_location_row_id'] ?? 0);

        $baseResult = [
            'blob_hash' => $blobHash,
            'local_file_path' => $localStoragePath,
            'local_location_row_id' => $localRowId,
            'target_location_row_id' => $targetRowId,
            'target_disk' => $targetDisk,
            'local_row_deleted' => false,
            'local_file_deleted' => false,
        ];

        $localRow = $this->resolveVerifiedRow($localRowId, $blobHash, 'local');
        if ($localRow === null) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'LOCAL_VERIFIED_ROW_MISSING_AT_EXECUTE',
            ];
        }

        $targetRow = $this->resolveVerifiedRow($targetRowId, $blobHash, $targetDisk);
        if ($targetRow === null) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'TARGET_VERIFIED_ROW_MISSING_AT_EXECUTE',
            ];
        }

        if (trim((string) $localRow->storage_path) !== $localStoragePath) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'LOCAL_STORAGE_PATH_CHANGED_AT_EXECUTE',
            ];
        }

        if (! Storage::disk('local')->exists($localStoragePath)) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'LOCAL_FILE_MISSING_AT_EXECUTE',
            ];
        }

        if (! $this->hasVerifiedRowForHash($blobHash, 'local') || ! $this->hasVerifiedRowForHash($blobHash, $targetDisk)) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'BOTH_OVERLAP_LOST_AT_EXECUTE',
            ];
        }

        $deletedLocalRows = DB::transaction(function () use ($localRowId, $blobHash): int {
            return StorageBlobLocation::query()
                ->whereKey($localRowId)
                ->where('blob_hash', $blobHash)
                ->where('disk', 'local')
                ->where('location_kind', self::LOCATION_KIND_REMOTE_COPY)
                ->whereNotNull('verified_at')
                ->delete();
        });

        if ($deletedLocalRows !== 1) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'LOCAL_VERIFIED_ROW_DELETE_BLOCKED',
            ];
        }

        $fileDeleteResult = Storage::disk('local')->delete($localStoragePath);
        $localFileStillExists = Storage::disk('local')->exists($localStoragePath);
        if (! $fileDeleteResult && $localFileStillExists) {
            return $baseResult + [
                'status' => 'partial_failure',
                'reason' => 'LOCAL_FILE_DELETE_FAILED_AFTER_ROW_DELETE',
                'local_row_deleted' => true,
                'local_file_deleted' => false,
            ];
        }

        return $baseResult + [
            'status' => 'deleted',
            'reason' => 'LOCAL_SIDE_REMOVED',
            'local_row_deleted' => true,
            'local_file_deleted' => true,
        ];
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
            'action' => self::AUDIT_ACTION,
            'target_type' => 'storage',
            'target_id' => 'offload_local_copies',
            'meta_json' => json_encode([
                'schema' => $payload['schema'] ?? null,
                'mode' => $payload['mode'] ?? null,
                'target_disk' => $payload['target_disk'] ?? null,
                'plan' => $payload['plan'] ?? null,
                'run_path' => $payload['run_path'] ?? null,
                'summary' => $payload['summary'] ?? [],
                'results' => $payload['results'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'artisan/storage:shrink-offload-local-copies',
            'request_id' => null,
            'reason' => 'manual_cleanup',
            'result' => ($payload['status'] ?? 'executed') === 'executed' ? 'success' : 'partial_failure',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function assertPlanSchema(array $plan): void
    {
        if ((string) ($plan['schema'] ?? '') !== self::PLAN_SCHEMA) {
            throw new \RuntimeException('offload local copy shrink plan schema mismatch.');
        }
    }

    /**
     * @param  array<string,array<int,StorageBlobLocation>>  $localRowsByHash
     * @param  array<string,array<int,StorageBlobLocation>>  $targetRowsByHash
     * @return array{
     *   verified_remote_copy_counts_by_disk:array<string,int>,
     *   local_only_count:int,
     *   target_only_count:int,
     *   both_count:int
     * }
     */
    private function buildDistributionSummary(array $localRowsByHash, array $targetRowsByHash, string $targetDisk): array
    {
        $allHashes = array_values(array_unique(array_merge(array_keys($localRowsByHash), array_keys($targetRowsByHash))));
        sort($allHashes);

        $localOnlyCount = 0;
        $targetOnlyCount = 0;
        $bothCount = 0;

        foreach ($allHashes as $blobHash) {
            $hasLocal = isset($localRowsByHash[$blobHash]) && $localRowsByHash[$blobHash] !== [];
            $hasTarget = isset($targetRowsByHash[$blobHash]) && $targetRowsByHash[$blobHash] !== [];

            if ($hasLocal && $hasTarget) {
                $bothCount++;

                continue;
            }

            if ($hasLocal) {
                $localOnlyCount++;

                continue;
            }

            if ($hasTarget) {
                $targetOnlyCount++;
            }
        }

        return [
            'verified_remote_copy_counts_by_disk' => [
                'local' => count($localRowsByHash),
                $targetDisk => count($targetRowsByHash),
            ],
            'local_only_count' => $localOnlyCount,
            'target_only_count' => $targetOnlyCount,
            'both_count' => $bothCount,
        ];
    }

    /**
     * @return array<string,array<int,StorageBlobLocation>>
     */
    private function verifiedRowsByHash(string $disk): array
    {
        $rows = StorageBlobLocation::query()
            ->where('disk', $disk)
            ->where('location_kind', self::LOCATION_KIND_REMOTE_COPY)
            ->whereNotNull('verified_at')
            ->orderBy('blob_hash')
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $blobHash = strtolower(trim((string) $row->blob_hash));
            if (! preg_match('/^[a-f0-9]{64}$/', $blobHash)) {
                continue;
            }

            $grouped[$blobHash] ??= [];
            $grouped[$blobHash][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function blockedEntry(string $blobHash, string $reason, array $context = []): array
    {
        return [
            'blob_hash' => $blobHash,
            'reason' => $reason,
            'context' => $context,
        ];
    }

    private function hasVerifiedRowForHash(string $blobHash, string $disk): bool
    {
        return StorageBlobLocation::query()
            ->where('blob_hash', $blobHash)
            ->where('disk', $disk)
            ->where('location_kind', self::LOCATION_KIND_REMOTE_COPY)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function resolveVerifiedRow(int $rowId, string $blobHash, string $disk): ?StorageBlobLocation
    {
        if ($rowId <= 0) {
            return null;
        }

        return StorageBlobLocation::query()
            ->whereKey($rowId)
            ->where('blob_hash', $blobHash)
            ->where('disk', $disk)
            ->where('location_kind', self::LOCATION_KIND_REMOTE_COPY)
            ->whereNotNull('verified_at')
            ->first();
    }

    private function normalizeDisk(string $disk): string
    {
        $normalized = trim($disk);
        if ($normalized === '') {
            throw new \RuntimeException('target disk is required.');
        }

        return $normalized;
    }

    private function targetOffloadPathForHash(string $blobHash): string
    {
        $prefix = trim((string) config('storage_rollout.blob_offload_prefix', 'rollout/blobs'), '/');

        return $prefix.'/sha256/'.substr($blobHash, 0, 2).'/'.$blobHash;
    }

    private function localOffloadPathForHash(string $blobHash): string
    {
        return 'offload/blobs/sha256/'.substr($blobHash, 0, 2).'/'.$blobHash;
    }

    private function runDirectory(): string
    {
        $dir = storage_path('app/private/offload_local_copy_shrink_runs/'.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
