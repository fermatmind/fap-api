<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class ReportArtifactsRehydrateService
{
    public function __construct(
        private readonly ArtifactLifecycleLedgerWriter $ledgerWriter,
        private readonly UnifiedAccessProjectionWriter $accessProjections,
    ) {}

    private const PLAN_SCHEMA = 'storage_rehydrate_report_artifacts_plan.v1';

    private const RUN_SCHEMA = 'storage_rehydrate_report_artifacts_run.v1';

    private const ARCHIVE_AUDIT_ACTION = 'storage_archive_report_artifacts';

    private const REHYDRATE_AUDIT_ACTION = 'storage_rehydrate_report_artifacts';

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
        $candidates = $this->collectCandidates($targetDisk);
        $summary = $this->buildPlanSummary($candidates, $targetDisk);

        $payload = [
            'schema' => self::PLAN_SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'summary' => $summary,
            'candidates' => $candidates,
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
            'rehydrated_count' => 0,
            'verified_count' => 0,
            'skipped_count' => 0,
            'blocked_count' => 0,
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
            if ($status === 'rehydrated') {
                $summary['rehydrated_count']++;
                $summary['verified_count']++;

                continue;
            }

            if ($status === 'skipped') {
                $summary['skipped_count']++;

                continue;
            }

            if ($status === 'blocked') {
                $summary['blocked_count']++;

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
            throw new \RuntimeException('failed to encode report artifact rehydrate run receipt.');
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
    private function collectCandidates(string $targetDisk): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = DB::table('audit_logs')
            ->where('action', self::ARCHIVE_AUDIT_ACTION)
            ->orderByDesc('id')
            ->get(['id', 'created_at', 'meta_json']);

        $candidatesBySourcePath = [];

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

                $candidate = $this->candidateFromArchiveResult($result, $targetDisk, (int) $row->id, $row->created_at);
                if ($candidate === null) {
                    continue;
                }

                $sourcePath = (string) $candidate['source_path'];
                if (isset($candidatesBySourcePath[$sourcePath])) {
                    continue;
                }

                $candidatesBySourcePath[$sourcePath] = $candidate;
            }
        }

        $candidates = array_values($candidatesBySourcePath);
        usort($candidates, static fn (array $left, array $right): int => strcmp((string) ($left['source_path'] ?? ''), (string) ($right['source_path'] ?? '')));

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>|null
     */
    private function candidateFromArchiveResult(array $result, string $targetDisk, int $auditId, mixed $createdAt): ?array
    {
        $archivedStatus = $this->normalizeOptionalString($result['status'] ?? null);
        if (! in_array($archivedStatus, ['copied', 'already_archived'], true)) {
            return null;
        }

        $sourcePath = $this->normalizeOptionalString($result['source_path'] ?? null);
        $targetObjectKey = $this->normalizeOptionalString($result['target_object_key'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($result['source_sha256'] ?? $result['planned_sha256'] ?? null);
        $sourceBytes = $this->normalizeNullableInt($result['source_bytes'] ?? $result['planned_bytes'] ?? null);

        if ($sourcePath === null || ! $this->isExactCanonicalSourcePath($sourcePath)) {
            return null;
        }

        if ($targetObjectKey === null || $sourceSha256 === null || $sourceBytes === null) {
            return null;
        }

        $resultTargetDisk = $this->normalizeOptionalString($result['target_disk'] ?? null);
        if ($resultTargetDisk !== null && $resultTargetDisk !== $targetDisk) {
            return null;
        }

        $context = $this->canonicalContextForSourcePath($sourcePath);
        if ($context === null) {
            return null;
        }

        return [
            'kind' => $context['kind'],
            'source_path' => $sourcePath,
            'target_disk' => $targetDisk,
            'target_object_key' => $targetObjectKey,
            'source_sha256' => $sourceSha256,
            'source_bytes' => $sourceBytes,
            'archived_status' => $archivedStatus,
            'scale_code' => $result['scale_code'] ?? $context['scale_code'],
            'attempt_id' => $result['attempt_id'] ?? $context['attempt_id'],
            'archive_audit_id' => $auditId,
            'archive_recorded_at' => $this->normalizeTimestamp($createdAt),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,int>
     */
    private function buildPlanSummary(array $candidates, string $targetDisk): array
    {
        $summary = [
            'candidate_count' => count($candidates),
            'rehydrated_count' => 0,
            'verified_count' => 0,
            'skipped_count' => 0,
            'blocked_count' => 0,
            'failed_count' => 0,
        ];

        foreach ($candidates as $candidate) {
            $precheck = $this->precheckCandidate($candidate, $targetDisk);
            $status = (string) ($precheck['status'] ?? '');
            if ($status === 'skipped') {
                $summary['skipped_count']++;

                continue;
            }

            if ($status === 'blocked') {
                $summary['blocked_count']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $payload
     */
    private function writeSidecars(array $plan, array $payload): void
    {
        if (! $this->isFrontDoorExecution($plan)) {
            try {
                $this->ledgerWriter->recordRehydrateExecution($plan, $payload);
            } catch (\Throwable $e) {
                Log::warning('REHYDRATE_LEDGER_SIDE_CAR_WRITE_FAILED', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->resultItems($payload) as $item) {
            $status = (string) ($item['status'] ?? '');
            if (! in_array($status, ['rehydrated', 'skipped'], true)) {
                continue;
            }

            $this->refreshProjectionForResult('artifact_rehydrated', 'ready', $item, $payload);
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function isFrontDoorExecution(array $plan): bool
    {
        return is_array($plan['_front_door'] ?? null)
            && isset($plan['_front_door']['job_id']);
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
                    'source_ref' => trim((string) ($payload['run_path'] ?? data_get($payload, '_meta.plan_path', 'rehydrate_report_artifacts'))),
                    'actor_type' => 'system',
                    'actor_id' => 'rehydrate_report_artifacts',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('REHYDRATE_ACCESS_PROJECTION_WRITE_FAILED', [
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
     * @param  array<string,mixed>  $candidate
     * @return array{status:string,reason:string}
     */
    private function precheckCandidate(array $candidate, string $targetDisk): array
    {
        $sourcePath = $this->normalizeOptionalString($candidate['source_path'] ?? null);
        $targetObjectKey = $this->normalizeOptionalString($candidate['target_object_key'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($candidate['source_sha256'] ?? null);
        $sourceBytes = $this->normalizeNullableInt($candidate['source_bytes'] ?? null);

        if ($sourcePath === null || $targetObjectKey === null || $sourceSha256 === null || $sourceBytes === null) {
            return [
                'status' => 'blocked',
                'reason' => 'ARCHIVE_PROOF_INCOMPLETE',
            ];
        }

        $absolutePath = storage_path('app/private/'.$sourcePath);
        if (is_file($absolutePath)) {
            return [
                'status' => 'skipped',
                'reason' => 'LOCAL_CANONICAL_ALREADY_EXISTS',
            ];
        }

        if (! $this->remoteObjectExists($targetDisk, $targetObjectKey)) {
            return [
                'status' => 'blocked',
                'reason' => 'REMOTE_OBJECT_MISSING',
            ];
        }

        return [
            'status' => 'ready',
            'reason' => 'READY_FOR_REHYDRATE',
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executeCandidate(array $candidate, string $targetDisk): array
    {
        $sourcePath = $this->normalizeOptionalString($candidate['source_path'] ?? null);
        $targetObjectKey = $this->normalizeOptionalString($candidate['target_object_key'] ?? null);
        $sourceSha256 = $this->normalizeOptionalString($candidate['source_sha256'] ?? null);
        $sourceBytes = $this->normalizeNullableInt($candidate['source_bytes'] ?? null);
        $kind = $this->normalizeOptionalString($candidate['kind'] ?? null) ?? 'unknown';

        $baseResult = [
            'kind' => $kind,
            'source_path' => $sourcePath,
            'target_disk' => $targetDisk,
            'target_object_key' => $targetObjectKey,
            'source_sha256' => $sourceSha256,
            'source_bytes' => $sourceBytes,
            'archived_status' => $candidate['archived_status'] ?? null,
            'scale_code' => $candidate['scale_code'] ?? null,
            'attempt_id' => $candidate['attempt_id'] ?? null,
        ];

        $precheck = $this->precheckCandidate($candidate, $targetDisk);
        if ($precheck['status'] === 'skipped') {
            return $baseResult + [
                'status' => 'skipped',
                'reason' => $precheck['reason'],
            ];
        }

        if ($precheck['status'] === 'blocked') {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => $precheck['reason'],
            ];
        }

        if ($sourcePath === null || $targetObjectKey === null || $sourceSha256 === null || $sourceBytes === null) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'ARCHIVE_PROOF_INCOMPLETE',
            ];
        }

        $absoluteTargetPath = storage_path('app/private/'.$sourcePath);
        $targetDirectory = dirname($absoluteTargetPath);
        File::ensureDirectoryExists($targetDirectory);
        $tmpPath = $absoluteTargetPath.'.rehydrate_tmp_'.substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $stream = Storage::disk($targetDisk)->readStream($targetObjectKey);
        } catch (\Throwable) {
            $stream = false;
        }

        if (! is_resource($stream)) {
            return $baseResult + [
                'status' => 'blocked',
                'reason' => 'REMOTE_OBJECT_MISSING',
            ];
        }

        $localStream = @fopen($tmpPath, 'wb');
        if ($localStream === false) {
            fclose($stream);

            return $baseResult + [
                'status' => 'failed',
                'reason' => 'LOCAL_TEMP_OPEN_FAILED',
            ];
        }

        try {
            stream_copy_to_stream($stream, $localStream);
        } finally {
            fclose($stream);
            fclose($localStream);
        }

        $rehydratedSha256 = hash_file('sha256', $tmpPath);
        if (! is_string($rehydratedSha256) || $rehydratedSha256 === '') {
            @unlink($tmpPath);

            return $baseResult + [
                'status' => 'failed',
                'reason' => 'LOCAL_HASH_FAILED',
            ];
        }

        if ($rehydratedSha256 !== $sourceSha256) {
            @unlink($tmpPath);

            return $baseResult + [
                'status' => 'failed',
                'reason' => 'LOCAL_HASH_MISMATCH',
                'rehydrated_sha256' => $rehydratedSha256,
            ];
        }

        if (is_file($absoluteTargetPath)) {
            @unlink($tmpPath);

            return $baseResult + [
                'status' => 'skipped',
                'reason' => 'LOCAL_CANONICAL_ALREADY_EXISTS',
            ];
        }

        if (! @rename($tmpPath, $absoluteTargetPath)) {
            @unlink($tmpPath);

            return $baseResult + [
                'status' => 'failed',
                'reason' => 'LOCAL_MOVE_FAILED',
            ];
        }

        return $baseResult + [
            'status' => 'rehydrated',
            'reason' => 'REMOTE_OBJECT_DOWNLOADED_AND_VERIFIED',
            'rehydrated_at' => now()->toIso8601String(),
            'verified_at' => now()->toIso8601String(),
            'rehydrated_sha256' => $rehydratedSha256,
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
            'action' => self::REHYDRATE_AUDIT_ACTION,
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_rehydrate',
            'meta_json' => json_encode([
                'schema' => $payload['schema'] ?? null,
                'mode' => $payload['mode'] ?? null,
                'target_disk' => $payload['target_disk'] ?? null,
                'plan' => $planPath,
                'plan_path' => $planPath,
                'run_path' => $runPath,
                'candidate_count' => (int) ($summary['candidate_count'] ?? 0),
                'rehydrated_count' => (int) ($summary['rehydrated_count'] ?? 0),
                'verified_count' => (int) ($summary['verified_count'] ?? 0),
                'skipped_count' => (int) ($summary['skipped_count'] ?? 0),
                'blocked_count' => (int) ($summary['blocked_count'] ?? 0),
                'failed_count' => $failedCount,
                'results_count' => count($results),
                'durable_receipt_source' => 'audit_logs.meta_json',
                'summary' => $summary,
                'results' => $results,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'artisan/storage:rehydrate-report-artifacts',
            'request_id' => null,
            'reason' => 'manual_archive_rehydrate',
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
            throw new \RuntimeException('report artifact rehydrate plan schema mismatch.');
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
        $dir = storage_path('app/private/report_artifact_rehydrate_runs/'.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8));
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

    private function isExactCanonicalSourcePath(string $sourcePath): bool
    {
        return $this->canonicalContextForSourcePath($sourcePath) !== null;
    }

    /**
     * @return array{kind:string,scale_code:string,attempt_id:string}|null
     */
    private function canonicalContextForSourcePath(string $sourcePath): ?array
    {
        if (preg_match('#^artifacts/reports/([^/]+)/([^/]+)/report\.json$#', $sourcePath, $matches) === 1) {
            return [
                'kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        if (preg_match('#^artifacts/pdf/([^/]+)/([^/]+)/[^/]+/report_(free|full)\.pdf$#', $sourcePath, $matches) === 1) {
            return [
                'kind' => 'report_'.$matches[3].'_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        return null;
    }
}
