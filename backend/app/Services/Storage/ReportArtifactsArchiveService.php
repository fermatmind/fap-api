<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\SplFileInfo;

final class ReportArtifactsArchiveService
{
    private const PLAN_SCHEMA = 'storage_archive_report_artifacts_plan.v1';

    private const RUN_SCHEMA = 'storage_archive_report_artifacts_run.v1';

    private const AUDIT_ACTION = 'storage_archive_report_artifacts';

    private const TARGET_PREFIX = 'report_artifacts_archive';

    /**
     * @return array<string,mixed>
     */
    public function buildPlan(string $targetDisk): array
    {
        $targetDisk = $this->normalizeDisk($targetDisk);
        $candidates = $this->collectCandidates($targetDisk);
        $summary = $this->buildSummary($candidates);

        return [
            'schema' => self::PLAN_SCHEMA,
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => $targetDisk,
            'target_disk' => $targetDisk,
            'summary' => $summary + [
                'copied_count' => 0,
                'verified_count' => 0,
                'already_archived_count' => 0,
                'failed_count' => 0,
            ],
            'candidates' => $candidates,
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
        $candidates = is_array($plan['candidates'] ?? null) ? array_values($plan['candidates']) : [];
        $results = [];
        $summary = [
            'candidate_count' => count($candidates),
            'candidate_bytes' => (int) data_get($plan, 'summary.candidate_bytes', 0),
            'kind_counts' => is_array(data_get($plan, 'summary.kind_counts')) ? data_get($plan, 'summary.kind_counts') : [],
            'copied_count' => 0,
            'verified_count' => 0,
            'already_archived_count' => 0,
            'failed_count' => 0,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $result = $this->executeCandidate($candidate, $targetDisk);
            $results[] = $result;

            $status = (string) ($result['status'] ?? '');
            if ($status === 'copied') {
                $summary['copied_count']++;
                $summary['verified_count']++;

                continue;
            }

            if ($status === 'already_archived') {
                $summary['already_archived_count']++;
                $summary['verified_count']++;

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
            'summary' => $summary,
            'results' => $results,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode report artifact archive run receipt.');
        }

        File::put($runPath, $encoded.PHP_EOL);
        $payload['run_path'] = $runPath;

        $this->recordAudit($payload);

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectCandidates(string $targetDisk): array
    {
        $artifactsRoot = storage_path('app/private/artifacts');
        if (! is_dir($artifactsRoot)) {
            return [];
        }

        $candidates = [];

        foreach (['reports', 'pdf'] as $subdir) {
            $root = $artifactsRoot.DIRECTORY_SEPARATOR.$subdir;
            if (! is_dir($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $candidate = $this->candidateFromFile($file, $artifactsRoot, $targetDisk);
                if ($candidate === null) {
                    continue;
                }

                $candidates[] = $candidate;
            }
        }

        usort($candidates, static fn (array $left, array $right): int => strcmp((string) ($left['relative_path'] ?? ''), (string) ($right['relative_path'] ?? '')));

        return $candidates;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function candidateFromFile(SplFileInfo $file, string $artifactsRoot, string $targetDisk): ?array
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
            throw new \RuntimeException('failed to hash canonical artifact source: '.$absolutePath);
        }

        return [
            'kind' => $context['kind'],
            'source_path' => 'artifacts/'.$relativePath,
            'relative_path' => $relativePath,
            'target_disk' => $targetDisk,
            'target_object_key' => self::TARGET_PREFIX.'/'.$relativePath,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'scale_code' => $context['scale_code'],
            'attempt_id' => $context['attempt_id'],
        ];
    }

    /**
     * @return array{kind:string,scale_code:?string,attempt_id:?string}|null
     */
    private function canonicalContextForRelativePath(string $relativePath): ?array
    {
        if (preg_match('#^reports/([^/]+)/([^/]+)/report\.json$#', $relativePath, $matches) === 1) {
            return [
                'kind' => 'report_json',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        if (preg_match('#^pdf/([^/]+)/([^/]+)/[^/]+/report_(free|full)\.pdf$#', $relativePath, $matches) === 1) {
            return [
                'kind' => 'report_'.$matches[3].'_pdf',
                'scale_code' => (string) $matches[1],
                'attempt_id' => (string) $matches[2],
            ];
        }

        return null;
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

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return array<string,mixed>
     */
    private function buildSummary(array $candidates): array
    {
        $candidateBytes = 0;
        $kindCounts = [];

        foreach ($candidates as $candidate) {
            $candidateBytes += (int) ($candidate['bytes'] ?? 0);
            $kind = (string) ($candidate['kind'] ?? 'unknown');
            $kindCounts[$kind] = (int) ($kindCounts[$kind] ?? 0) + 1;
        }

        ksort($kindCounts);

        return [
            'candidate_count' => count($candidates),
            'candidate_bytes' => $candidateBytes,
            'kind_counts' => $kindCounts,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function executeCandidate(array $candidate, string $targetDisk): array
    {
        $sourcePath = trim((string) ($candidate['source_path'] ?? ''));
        $relativePath = trim((string) ($candidate['relative_path'] ?? ''));
        $targetObjectKey = trim((string) ($candidate['target_object_key'] ?? ''));
        $kind = trim((string) ($candidate['kind'] ?? ''));
        $absoluteSourcePath = storage_path('app/private/'.$sourcePath);

        $baseResult = [
            'kind' => $kind,
            'source_path' => $sourcePath,
            'relative_path' => $relativePath,
            'target_disk' => $targetDisk,
            'target_object_key' => $targetObjectKey,
            'scale_code' => $candidate['scale_code'] ?? null,
            'attempt_id' => $candidate['attempt_id'] ?? null,
            'planned_sha256' => $candidate['sha256'] ?? null,
            'planned_bytes' => (int) ($candidate['bytes'] ?? 0),
        ];

        if ($sourcePath === '' || $relativePath === '' || $targetObjectKey === '') {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'CANDIDATE_METADATA_INVALID',
            ];
        }

        if (! is_file($absoluteSourcePath)) {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'SOURCE_MISSING_AT_EXECUTE',
            ];
        }

        $sourceBytes = max(0, (int) (filesize($absoluteSourcePath) ?: 0));
        $sourceSha256 = hash_file('sha256', $absoluteSourcePath);
        if (! is_string($sourceSha256) || $sourceSha256 === '') {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'SOURCE_HASH_FAILED_AT_EXECUTE',
            ];
        }

        $baseResult['source_bytes'] = $sourceBytes;
        $baseResult['source_sha256'] = $sourceSha256;

        $targetExists = Storage::disk($targetDisk)->exists($targetObjectKey);
        if ($targetExists) {
            $targetBytes = $this->sizeForDiskPath($targetDisk, $targetObjectKey);
            $targetSha256 = $this->hashForDiskPath($targetDisk, $targetObjectKey);

            if ($targetBytes === $sourceBytes && $targetSha256 === $sourceSha256) {
                return $baseResult + [
                    'status' => 'already_archived',
                    'reason' => 'TARGET_ALREADY_MATCHED',
                    'verified_at' => now()->toIso8601String(),
                    'target_bytes' => $targetBytes,
                    'target_sha256' => $targetSha256,
                ];
            }
        }

        $stream = @fopen($absoluteSourcePath, 'rb');
        if ($stream === false) {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'SOURCE_OPEN_FAILED',
            ];
        }

        try {
            $stored = Storage::disk($targetDisk)->put($targetObjectKey, $stream);
        } finally {
            fclose($stream);
        }

        if (! $stored) {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'TARGET_UPLOAD_FAILED',
            ];
        }

        $targetExistsAfterUpload = Storage::disk($targetDisk)->exists($targetObjectKey);
        $targetBytesAfterUpload = $this->sizeForDiskPath($targetDisk, $targetObjectKey);

        if (! $targetExistsAfterUpload || $targetBytesAfterUpload !== $sourceBytes) {
            return $baseResult + [
                'status' => 'failed',
                'reason' => 'TARGET_VERIFY_FAILED',
                'target_bytes' => $targetBytesAfterUpload,
            ];
        }

        return $baseResult + [
            'status' => 'copied',
            'reason' => 'TARGET_UPLOADED_AND_VERIFIED',
            'copied_at' => now()->toIso8601String(),
            'verified_at' => now()->toIso8601String(),
            'target_bytes' => $targetBytesAfterUpload,
        ];
    }

    private function sizeForDiskPath(string $disk, string $path): ?int
    {
        try {
            return max(0, (int) Storage::disk($disk)->size($path));
        } catch (\Throwable) {
            return null;
        }
    }

    private function hashForDiskPath(string $disk, string $path): ?string
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (\Throwable) {
            $stream = false;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
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
            'action' => self::AUDIT_ACTION,
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_archive',
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
            'user_agent' => 'artisan/storage:archive-report-artifacts',
            'request_id' => null,
            'reason' => 'manual_archive_copy',
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
            throw new \RuntimeException('report artifact archive plan schema mismatch.');
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
        $dir = storage_path('app/private/report_artifact_archive_runs/'.now()->format('Ymd_His').'_'.substr(bin2hex(random_bytes(4)), 0, 8));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
