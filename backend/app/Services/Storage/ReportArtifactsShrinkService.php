<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

final class ReportArtifactsShrinkService
{
    public function __construct(
        private readonly ArtifactLifecycleLedgerWriter $ledgerWriter,
        private readonly UnifiedAccessProjectionWriter $accessProjections,
    ) {}

    private const PLAN_SCHEMA = 'storage_shrink_archived_report_artifacts_plan.v1';

    private const RUN_SCHEMA = 'storage_shrink_archived_report_artifacts_run.v1';

    private const ARCHIVE_AUDIT_ACTION = 'storage_archive_report_artifacts';

    private const SHRINK_AUDIT_ACTION = 'storage_shrink_archived_report_artifacts';

    /**
     * @var array<string,bool>
     */
    private array $remoteArchiveIndexLoaded = [];

    /**
     * @var array<string,array<string,bool>>
     */
    private array $remoteArchiveObjectsByDisk = [];

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $targetDisk): array
    {
        $targetDisk = $this->normalizeDisk($targetDisk);
        $localFiles = $this->collectLocalCanonicalFiles();
        $archiveProofsByPath = $this->collectArchiveProofsByPath($targetDisk);

        $candidates = [];
        $blocked = [];
        $summary = [
            'candidate_count' => 0,
            'deleted_count' => 0,
            'skipped_missing_local_count' => 0,
            'blocked_missing_remote_count' => 0,
            'blocked_missing_archive_proof_count' => 0,
            'blocked_missing_rehydrate_proof_count' => 0,
            'blocked_hash_mismatch_count' => 0,
            'failed_count' => 0,
        ];

        foreach ($localFiles as $localFile) {
            $evaluation = $this->evaluateLocalFile($localFile, $archiveProofsByPath, $targetDisk);

            if (($evaluation['status'] ?? null) === 'candidate') {
                $candidates[] = $evaluation['candidate'];
                $summary['candidate_count']++;

                continue;
            }

            $blocked[] = $evaluation['blocked'];
            $reason = (string) ($evaluation['blocked']['status'] ?? '');
            if ($reason === 'blocked_missing_remote') {
                $summary['blocked_missing_remote_count']++;

                continue;
            }

            if ($reason === 'blocked_missing_archive_proof') {
                $summary['blocked_missing_archive_proof_count']++;

                continue;
            }

            if ($reason === 'blocked_missing_rehydrate_proof') {
                $summary['blocked_missing_rehydrate_proof_count']++;

                continue;
            }

            if ($reason === 'blocked_hash_mismatch') {
                $summary['blocked_hash_mismatch_count']++;
            }
        }

        usort($candidates, static fn (array $left, array $right): int => strcmp((string) ($left['local_path'] ?? ''), (string) ($right['local_path'] ?? '')));
        usort($blocked, static fn (array $left, array $right): int => strcmp((string) ($left['local_path'] ?? ''), (string) ($right['local_path'] ?? '')));

        $payload = [
            'schema' => self::PLAN_SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'summary' => $summary,
            'candidates' => $candidates,
            'blocked' => $blocked,
        ];

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function executePlan(array $plan): array
    {
        $this->assertPlanSchema($plan);

        $targetDisk = $this->normalizeDisk((string) ($plan['target_disk'] ?? $plan['disk'] ?? ''));
        $candidates = is_array($plan['candidates'] ?? null) ? array_values($plan['candidates']) : [];
        $summary = [
            'candidate_count' => count($candidates),
            'deleted_count' => 0,
            'skipped_missing_local_count' => 0,
            'blocked_missing_remote_count' => 0,
            'blocked_missing_archive_proof_count' => 0,
            'blocked_missing_rehydrate_proof_count' => 0,
            'blocked_hash_mismatch_count' => 0,
            'failed_count' => 0,
        ];
        $results = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $result = $this->executeCandidate($candidate, $targetDisk);
            $results[] = $result;

            $status = (string) ($result['status'] ?? '');
            if ($status === 'deleted') {
                $summary['deleted_count']++;

                continue;
            }

            if ($status === 'skipped_missing_local') {
                $summary['skipped_missing_local_count']++;

                continue;
            }

            if ($status === 'blocked_missing_remote') {
                $summary['blocked_missing_remote_count']++;

                continue;
            }

            if ($status === 'blocked_missing_archive_proof') {
                $summary['blocked_missing_archive_proof_count']++;

                continue;
            }

            if ($status === 'blocked_missing_rehydrate_proof') {
                $summary['blocked_missing_rehydrate_proof_count']++;

                continue;
            }

            if ($status === 'blocked_hash_mismatch') {
                $summary['blocked_hash_mismatch_count']++;

                continue;
            }

            $summary['failed_count']++;
        }

        $status = $summary['failed_count'] > 0 ? 'partial_failure' : 'executed';
        $runDir = $this->runDirectory();
        $runPath = $runDir.DIRECTORY_SEPARATOR.'run.json';

        $payload = [
            'schema' => self::RUN_SCHEMA,
            'mode' => 'execute',
            'status' => $status,
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'plan' => trim((string) data_get($plan, '_meta.plan_path', '')),
            'plan_path' => trim((string) data_get($plan, '_meta.plan_path', '')),
            'summary' => $summary,
            'results' => $results,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode report artifact shrink run receipt.');
        }

        File::put($runPath, $encoded.PHP_EOL);
        $payload['run_path'] = $runPath;

        $this->recordAudit($payload);
        $this->writeSidecars($plan, $payload);

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectLocalCanonicalFiles(): array
    {
        $artifactsRoot = storage_path('app/private/artifacts');
        if (! is_dir($artifactsRoot)) {
            return [];
        }

        $files = [];

        foreach (['reports', 'pdf'] as $subdir) {
            $root = $artifactsRoot.DIRECTORY_SEPARATOR.$subdir;
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $candidate = $this->localCanonicalFileFromSplFile($file, $artifactsRoot);
                if ($candidate === null) {
                    continue;
                }

                $files[] = $candidate;
            }
        }

        usort($files, static fn (array $left, array $right): int => strcmp((string) ($left['local_path'] ?? ''), (string) ($right['local_path'] ?? '')));

        return $files;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $payload
     */
    private function writeSidecars(array $plan, array $payload): void
    {
        try {
            $this->ledgerWriter->recordShrinkExecution($plan, $payload);
        } catch (\Throwable $e) {
            Log::warning('SHRINK_LEDGER_SIDE_CAR_WRITE_FAILED', [
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($this->resultItems($payload) as $item) {
            $status = (string) ($item['status'] ?? '');
            if (! in_array($status, ['deleted', 'skipped_missing_local'], true)) {
                continue;
            }

            $this->refreshProjectionForResult('artifact_shrunk', 'archived', $item, $payload);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function resultItems(array $payload): array
    {
        $items = is_array($payload['results'] ?? null) ? array_values($payload['results']) : [];

        return array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $payload
     */
    private function refreshProjectionForResult(string $reasonCode, string $artifactState, array $item, array $payload): void
    {
        $attemptId = trim((string) ($item['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return;
        }

        $kind = trim((string) ($item['kind'] ?? ''));
        $patch = $this->projectionPatchForKind($kind, $artifactState);
        if ($patch === []) {
            return;
        }

        try {
            $this->accessProjections->refreshAttemptProjection(
                $attemptId,
                $patch + [
                    'reason_code' => $reasonCode,
                ],
                [
                    'source_system' => 'artifact_lifecycle',
                    'source_ref' => trim((string) ($payload['run_path'] ?? data_get($payload, '_meta.plan_path', 'shrink_archived_report_artifacts'))),
                    'actor_type' => 'system',
                    'actor_id' => 'shrink_archived_report_artifacts',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('SHRINK_ACCESS_PROJECTION_WRITE_FAILED', [
                'attempt_id' => $attemptId,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,string>
     */
    private function projectionPatchForKind(string $kind, string $artifactState): array
    {
        return match ($kind) {
            'report_json' => ['report_state' => $artifactState],
            'report_free_pdf', 'report_full_pdf' => ['pdf_state' => $artifactState],
            default => [],
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function localCanonicalFileFromSplFile(SplFileInfo $file, string $artifactsRoot): ?array
    {
        if (! $file->isFile()) {
            return null;
        }

        $absolutePath = $file->getPathname();
        $relativePath = $this->relativePathWithinArtifacts($absolutePath, $artifactsRoot);
        if ($relativePath === '') {
            return null;
        }

        $context = $this->canonicalContextForRelativePath($relativePath);
        if ($context === null) {
            return null;
        }

        $bytes = max(0, (int) ($file->getSize() ?: 0));
        $sha256 = hash_file('sha256', $absolutePath);
        if (! is_string($sha256) || $sha256 === '') {
            throw new \RuntimeException('failed to hash local canonical artifact: '.$absolutePath);
        }

        return [
            'kind' => $context['kind'],
            'local_path' => 'artifacts/'.$relativePath,
            'source_path' => 'artifacts/'.$relativePath,
            'local_sha256' => $sha256,
            'local_bytes' => $bytes,
            'scale_code' => $context['scale_code'],
            'attempt_id' => $context['attempt_id'],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectArchiveProofsByPath(string $targetDisk): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = DB::table('audit_logs')
            ->where('action', self::ARCHIVE_AUDIT_ACTION)
            ->orderByDesc('id')
            ->get(['id', 'created_at', 'meta_json']);

        $proofs = [];

        foreach ($rows as $row) {
            $meta = $this->decodeJsonObject($row->meta_json ?? null);
            if (($meta['mode'] ?? null) !== 'execute') {
                continue;
            }

            $results = is_array($meta['results'] ?? null) ? array_values($meta['results']) : [];
            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $proof = $this->archiveProofFromResult($result, $targetDisk, (int) $row->id, $row->created_at);
                if ($proof === null) {
                    continue;
                }

                $sourcePath = (string) $proof['local_path'];
                if (isset($proofs[$sourcePath])) {
                    continue;
                }

                $proofs[$sourcePath] = $proof;
            }
        }

        return $proofs;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    private function archiveProofFromResult(array $result, string $targetDisk, int $auditId, mixed $createdAt): ?array
    {
        $archivedStatus = $this->normalizeOptionalString($result['status'] ?? null);
        if (! in_array($archivedStatus, ['copied', 'already_archived'], true)) {
            return null;
        }

        $localPath = $this->normalizeOptionalString($result['source_path'] ?? null);
        if ($localPath === null || ! $this->isExactCanonicalLocalPath($localPath)) {
            return null;
        }

        $resultTargetDisk = $this->normalizeOptionalString($result['target_disk'] ?? null);
        if ($resultTargetDisk !== null && $resultTargetDisk !== $targetDisk) {
            return null;
        }

        $targetObjectKey = $this->normalizeOptionalString($result['target_object_key'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($result['source_sha256'] ?? $result['planned_sha256'] ?? null);
        $sourceBytes = $this->normalizeNullableInt($result['source_bytes'] ?? $result['planned_bytes'] ?? null);
        $context = $this->canonicalContextForLocalPath($localPath);
        if ($context === null) {
            return null;
        }

        return [
            'kind' => $this->normalizeOptionalString($result['kind'] ?? null) ?? $context['kind'],
            'local_path' => $localPath,
            'target_disk' => $targetDisk,
            'target_object_key' => $targetObjectKey,
            'source_sha256' => $sourceSha256,
            'source_bytes' => $sourceBytes,
            'archived_status' => $archivedStatus,
            'archive_audit_id' => $auditId,
            'archive_recorded_at' => $this->normalizeTimestamp($createdAt),
            'rehydrate_ready' => $targetObjectKey !== null && $sourceSha256 !== null && $sourceBytes !== null,
            'scale_code' => $result['scale_code'] ?? $context['scale_code'],
            'attempt_id' => $result['attempt_id'] ?? $context['attempt_id'],
        ];
    }

    /**
     * @param  array<string,mixed>  $localFile
     * @param  array<string,array<string,mixed>>  $archiveProofsByPath
     * @return array<string,mixed>
     */
    private function evaluateLocalFile(array $localFile, array $archiveProofsByPath, string $targetDisk): array
    {
        $localPath = (string) ($localFile['local_path'] ?? '');
        $archiveProof = $archiveProofsByPath[$localPath] ?? null;

        if ($archiveProof === null) {
            return [
                'status' => 'blocked',
                'blocked' => $this->blockedEntry($localFile, 'blocked_missing_archive_proof', 'ARCHIVE_EXECUTE_PROOF_MISSING'),
            ];
        }

        if (($archiveProof['rehydrate_ready'] ?? false) !== true) {
            return [
                'status' => 'blocked',
                'blocked' => $this->blockedEntry($localFile, 'blocked_missing_rehydrate_proof', 'REHYDRATE_PROOF_INCOMPLETE', $archiveProof),
            ];
        }

        $targetObjectKey = $this->normalizeOptionalString($archiveProof['target_object_key'] ?? null);
        if ($targetObjectKey === null || ! $this->remoteObjectExists($targetDisk, $targetObjectKey)) {
            return [
                'status' => 'blocked',
                'blocked' => $this->blockedEntry($localFile, 'blocked_missing_remote', 'REMOTE_OBJECT_MISSING', $archiveProof),
            ];
        }

        $localSha256 = $this->normalizeOptionalString($localFile['local_sha256'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($archiveProof['source_sha256'] ?? null);
        if ($localSha256 === null || $sourceSha256 === null || ! hash_equals($sourceSha256, $localSha256)) {
            return [
                'status' => 'blocked',
                'blocked' => $this->blockedEntry($localFile, 'blocked_hash_mismatch', 'LOCAL_HASH_MISMATCH', $archiveProof),
            ];
        }

        return [
            'status' => 'candidate',
            'candidate' => [
                'kind' => $localFile['kind'],
                'local_path' => $localPath,
                'target_disk' => $targetDisk,
                'target_object_key' => $targetObjectKey,
                'source_sha256' => $sourceSha256,
                'source_bytes' => (int) ($archiveProof['source_bytes'] ?? 0),
                'archive_audit_id' => (int) ($archiveProof['archive_audit_id'] ?? 0),
                'rehydrate_ready' => true,
                'archived_status' => $archiveProof['archived_status'] ?? null,
                'scale_code' => $archiveProof['scale_code'] ?? $localFile['scale_code'] ?? null,
                'attempt_id' => $archiveProof['attempt_id'] ?? $localFile['attempt_id'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $localFile
     * @param  array<string,mixed>|null  $archiveProof
     * @return array<string,mixed>
     */
    private function blockedEntry(array $localFile, string $status, string $reason, ?array $archiveProof = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'kind' => $localFile['kind'] ?? null,
            'local_path' => $localFile['local_path'] ?? null,
            'target_disk' => $archiveProof['target_disk'] ?? null,
            'target_object_key' => $archiveProof['target_object_key'] ?? null,
            'source_sha256' => $archiveProof['source_sha256'] ?? null,
            'source_bytes' => $archiveProof['source_bytes'] ?? null,
            'archive_audit_id' => $archiveProof['archive_audit_id'] ?? null,
            'rehydrate_ready' => $archiveProof['rehydrate_ready'] ?? false,
            'scale_code' => $archiveProof['scale_code'] ?? $localFile['scale_code'] ?? null,
            'attempt_id' => $archiveProof['attempt_id'] ?? $localFile['attempt_id'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executeCandidate(array $candidate, string $targetDisk): array
    {
        $baseResult = [
            'kind' => $candidate['kind'] ?? null,
            'local_path' => $candidate['local_path'] ?? null,
            'target_disk' => $targetDisk,
            'target_object_key' => $candidate['target_object_key'] ?? null,
            'source_sha256' => $candidate['source_sha256'] ?? null,
            'source_bytes' => $candidate['source_bytes'] ?? null,
            'archive_audit_id' => $candidate['archive_audit_id'] ?? null,
            'rehydrate_ready' => $candidate['rehydrate_ready'] ?? false,
            'scale_code' => $candidate['scale_code'] ?? null,
            'attempt_id' => $candidate['attempt_id'] ?? null,
        ];

        $archiveAuditId = (int) ($candidate['archive_audit_id'] ?? 0);
        $archivedStatus = $this->normalizeOptionalString($candidate['archived_status'] ?? null);
        if ($archiveAuditId <= 0 || ! in_array($archivedStatus, ['copied', 'already_archived'], true)) {
            return $baseResult + [
                'status' => 'blocked_missing_archive_proof',
                'reason' => 'ARCHIVE_EXECUTE_PROOF_MISSING',
            ];
        }

        $localPath = $this->normalizeOptionalString($candidate['local_path'] ?? null);
        $targetObjectKey = $this->normalizeOptionalString($candidate['target_object_key'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($candidate['source_sha256'] ?? null);
        $sourceBytes = $this->normalizeNullableInt($candidate['source_bytes'] ?? null);
        $rehydrateReady = ($candidate['rehydrate_ready'] ?? false) === true;

        if ($localPath === null || ! $this->isExactCanonicalLocalPath($localPath)) {
            return $baseResult + [
                'status' => 'blocked_missing_rehydrate_proof',
                'reason' => 'REHYDRATE_PROOF_INCOMPLETE',
            ];
        }

        if ($targetObjectKey === null || $sourceSha256 === null || $sourceBytes === null || ! $rehydrateReady) {
            return $baseResult + [
                'status' => 'blocked_missing_rehydrate_proof',
                'reason' => 'REHYDRATE_PROOF_INCOMPLETE',
            ];
        }

        $absoluteLocalPath = storage_path('app/private/'.$localPath);
        if (! is_file($absoluteLocalPath)) {
            return $baseResult + [
                'status' => 'skipped_missing_local',
                'reason' => 'LOCAL_CANONICAL_ALREADY_MISSING',
            ];
        }

        if (! $this->remoteObjectExists($targetDisk, $targetObjectKey)) {
            return $baseResult + [
                'status' => 'blocked_missing_remote',
                'reason' => 'REMOTE_OBJECT_MISSING',
            ];
        }

        $currentSha256 = hash_file('sha256', $absoluteLocalPath);
        if (! is_string($currentSha256) || $currentSha256 === '' || ! hash_equals($sourceSha256, $currentSha256)) {
            return $baseResult + [
                'status' => 'blocked_hash_mismatch',
                'reason' => 'LOCAL_HASH_MISMATCH',
                'local_sha256' => is_string($currentSha256) ? $currentSha256 : null,
            ];
        }

        $deleted = @unlink($absoluteLocalPath);
        if (! $deleted || is_file($absoluteLocalPath)) {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'LOCAL_DELETE_FAILED',
            ];
        }

        return $baseResult + [
            'status' => 'deleted',
            'reason' => 'LOCAL_CANONICAL_DELETED',
            'deleted_at' => now()->toIso8601String(),
            'verified_local_sha256' => $currentSha256,
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

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $results = is_array($payload['results'] ?? null) ? array_values($payload['results']) : [];
        $planPath = $this->normalizeOptionalString($payload['plan_path'] ?? $payload['plan'] ?? null);
        $runPath = $this->normalizeOptionalString($payload['run_path'] ?? null);
        $failedCount = (int) ($summary['failed_count'] ?? 0);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => self::SHRINK_AUDIT_ACTION,
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_shrink',
            'meta_json' => json_encode([
                'schema' => $payload['schema'] ?? null,
                'mode' => $payload['mode'] ?? null,
                'target_disk' => $payload['target_disk'] ?? null,
                'plan' => $planPath,
                'plan_path' => $planPath,
                'run_path' => $runPath,
                'candidate_count' => (int) ($summary['candidate_count'] ?? 0),
                'deleted_count' => (int) ($summary['deleted_count'] ?? 0),
                'skipped_missing_local_count' => (int) ($summary['skipped_missing_local_count'] ?? 0),
                'blocked_missing_remote_count' => (int) ($summary['blocked_missing_remote_count'] ?? 0),
                'blocked_missing_archive_proof_count' => (int) ($summary['blocked_missing_archive_proof_count'] ?? 0),
                'blocked_missing_rehydrate_proof_count' => (int) ($summary['blocked_missing_rehydrate_proof_count'] ?? 0),
                'blocked_hash_mismatch_count' => (int) ($summary['blocked_hash_mismatch_count'] ?? 0),
                'failed_count' => $failedCount,
                'results_count' => count($results),
                'durable_receipt_source' => 'audit_logs.meta_json',
                'summary' => $summary,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'artisan/storage:shrink-archived-report-artifacts',
            'request_id' => null,
            'reason' => 'manual_archive_backed_shrink',
            'result' => $failedCount > 0 ? 'partial_failure' : 'success',
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function assertPlanSchema(array $plan): void
    {
        if ((string) ($plan['schema'] ?? '') !== self::PLAN_SCHEMA) {
            throw new \RuntimeException('report artifact shrink plan schema mismatch.');
        }
    }

    private function normalizeDisk(string $disk): string
    {
        $normalized = trim($disk);
        if ($normalized === '') {
            throw new \RuntimeException('target disk is required.');
        }

        return $normalized;
    }

    private function runDirectory(): string
    {
        $dir = storage_path('app/private/report_artifact_shrink_runs/'.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function remoteObjectExists(string $targetDisk, string $targetObjectKey): bool
    {
        if (str_starts_with($targetObjectKey, 'report_artifacts_archive/')) {
            $this->primeRemoteArchiveIndex($targetDisk);

            return isset($this->remoteArchiveObjectsByDisk[$targetDisk][$targetObjectKey]);
        }

        return Storage::disk($targetDisk)->exists($targetObjectKey);
    }

    private function primeRemoteArchiveIndex(string $targetDisk): void
    {
        if (($this->remoteArchiveIndexLoaded[$targetDisk] ?? false) === true) {
            return;
        }

        $this->remoteArchiveIndexLoaded[$targetDisk] = true;

        try {
            $files = Storage::disk($targetDisk)->allFiles('report_artifacts_archive');
        } catch (\Throwable) {
            $files = [];
        }

        $index = [];
        foreach ($files as $file) {
            if (! is_string($file) || trim($file) === '') {
                continue;
            }

            $index[$file] = true;
        }

        $this->remoteArchiveObjectsByDisk[$targetDisk] = $index;
    }

    private function relativePathWithinArtifacts(string $absolutePath, string $artifactsRoot): string
    {
        $root = rtrim(str_replace('\\', '/', $artifactsRoot), '/');
        $path = str_replace('\\', '/', $absolutePath);
        $prefix = $root.'/';

        if (! str_starts_with($path, $prefix)) {
            return '';
        }

        return ltrim(substr($path, strlen($prefix)), '/');
    }

    private function isExactCanonicalLocalPath(string $localPath): bool
    {
        return $this->canonicalContextForLocalPath($localPath) !== null;
    }

    /**
     * @return array{kind:string,scale_code:string,attempt_id:string}|null
     */
    private function canonicalContextForLocalPath(string $localPath): ?array
    {
        if (preg_match('#^artifacts/reports/([^/]+)/([^/]+)/report\.json$#', $localPath, $matches) === 1) {
            return [
                'kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        if (preg_match('#^artifacts/pdf/([^/]+)/([^/]+)/[^/]+/report_(free|full)\.pdf$#', $localPath, $matches) === 1) {
            return [
                'kind' => 'report_'.$matches[3].'_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        return null;
    }

    /**
     * @return array{kind:string,scale_code:string,attempt_id:string}|null
     */
    private function canonicalContextForRelativePath(string $relativePath): ?array
    {
        return $this->canonicalContextForLocalPath('artifacts/'.$relativePath);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value) || $value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $timestamp = trim((string) $value);

        return $timestamp === '' ? null : $timestamp;
    }
}
