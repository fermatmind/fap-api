<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ArtifactStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class StorageMigrateLegacyArtifacts extends Command
{
    protected $signature = 'storage:migrate-legacy-artifacts
    {--dry-run : Plan only}
    {--execute : Execute copy plan}
    {--plan= : Existing plan path for execute mode}
    {--limit=0 : Max files to process}';

    protected $description = 'Migrate legacy report/pdf artifacts to canonical artifacts path (copy-only).';

    public function __construct(
        private readonly ArtifactStore $artifactStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $limit = max(0, (int) $this->option('limit'));

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        if ($dryRun) {
            return $this->dryRun($limit);
        }

        return $this->executePlan();
    }

    private function dryRun(int $limit): int
    {
        $entries = $this->collectEntries($limit);
        $totalBytes = 0;
        $files = [];

        foreach ($entries as $entry) {
            $bytes = max(0, (int) ($entry['bytes'] ?? 0));
            $files[] = [
                'source' => (string) ($entry['source'] ?? ''),
                'target' => (string) ($entry['target'] ?? ''),
                'scope' => (string) ($entry['scope'] ?? ''),
                'bytes' => $bytes,
            ];
            $totalBytes += $bytes;
        }

        $plan = [
            'schema' => 'storage_migrate_legacy_artifacts.v1',
            'generated_at' => now()->toISOString(),
            'files' => $files,
            'summary' => [
                'files' => count($files),
                'bytes' => $totalBytes,
            ],
        ];

        $planDir = storage_path('app/private/migration_plans');
        File::ensureDirectoryExists($planDir);
        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_storage_migrate_legacy_artifacts_'
            .substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed to encode migration plan json.');

            return self::FAILURE;
        }

        File::put($planPath, $encoded.PHP_EOL);

        $this->line('status=planned');
        $this->line('plan='.$planPath);
        $this->line('files='.count($files));
        $this->line('bytes='.$totalBytes);

        return self::SUCCESS;
    }

    private function executePlan(): int
    {
        $planOption = trim((string) $this->option('plan'));
        if ($planOption === '') {
            $this->error('--plan is required in --execute mode.');

            return self::FAILURE;
        }

        $planPath = $this->resolvePlanPath($planOption);
        if (! is_file($planPath)) {
            $this->error('plan not found: '.$planPath);

            return self::FAILURE;
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid plan json: '.$planPath);

            return self::FAILURE;
        }

        if ((string) ($decoded['schema'] ?? '') !== 'storage_migrate_legacy_artifacts.v1') {
            $this->error('plan schema mismatch.');

            return self::FAILURE;
        }

        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        $disk = Storage::disk('local');

        $copiedFiles = 0;
        $copiedBytes = 0;
        $missingFiles = 0;
        $skippedFiles = 0;
        $failedFiles = 0;

        foreach ($files as $entry) {
            $source = trim((string) (is_array($entry) ? ($entry['source'] ?? '') : ''));
            $target = trim((string) (is_array($entry) ? ($entry['target'] ?? '') : ''));

            if ($source === '' || $target === '') {
                $skippedFiles++;

                continue;
            }

            if (! $disk->exists($source)) {
                $missingFiles++;

                continue;
            }

            if ($disk->exists($target)) {
                $skippedFiles++;

                continue;
            }

            $content = $disk->get($source);
            if (! is_string($content) || $content === '') {
                $failedFiles++;

                continue;
            }

            if (! $disk->put($target, $content)) {
                $failedFiles++;

                continue;
            }

            $sha256 = hash('sha256', $content);
            $bytes = strlen($content);
            $copiedFiles++;
            $copiedBytes += $bytes;

            $this->recordAudit($source, $target, $sha256, $bytes);
        }

        $this->line('status=executed');
        $this->line('plan='.$planPath);
        $this->line('copied_files='.$copiedFiles);
        $this->line('copied_bytes='.$copiedBytes);
        $this->line('missing_files='.$missingFiles);
        $this->line('skipped_files='.$skippedFiles);
        $this->line('failed_files='.$failedFiles);

        return $failedFiles > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{source:string,target:string,scope:string,bytes:int}>
     */
    private function collectEntries(int $limit): array
    {
        $disk = Storage::disk('local');
        $entries = [];

        foreach ($disk->allFiles('reports') as $relPath) {
            if (preg_match('#^reports/([^/]+)/report\.json$#', $relPath, $matches) !== 1) {
                continue;
            }

            $attemptId = (string) $matches[1];
            $target = $this->artifactStore->reportCanonicalPath('MBTI', $attemptId);
            if ($target === $relPath || $disk->exists($target)) {
                continue;
            }

            $entries[$relPath] = [
                'source' => $relPath,
                'target' => $target,
                'scope' => 'report',
                'bytes' => $this->fileSizeFromRelativePath($relPath),
            ];
        }

        foreach (['private/reports', 'reports'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $relPath) {
                $parsed = $this->parseLegacyPdfPath($relPath);
                if ($parsed === null) {
                    continue;
                }

                $target = $this->artifactStore->pdfCanonicalPath(
                    (string) $parsed['scale'],
                    (string) $parsed['attempt'],
                    (string) $parsed['manifest_hash'],
                    (string) $parsed['variant']
                );

                if ($target === $relPath || $disk->exists($target)) {
                    continue;
                }

                $entries[$relPath] = [
                    'source' => $relPath,
                    'target' => $target,
                    'scope' => 'pdf',
                    'bytes' => $this->fileSizeFromRelativePath($relPath),
                ];
            }
        }

        ksort($entries);
        $items = array_values($entries);

        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }

    /**
     * @return array{scale:string,attempt:string,manifest_hash:string,variant:string}|null
     */
    private function parseLegacyPdfPath(string $relPath): ?array
    {
        if (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $relPath, $m) === 1) {
            return [
                'scale' => (string) $m[1],
                'attempt' => (string) $m[2],
                'manifest_hash' => (string) $m[3],
                'variant' => strtolower((string) $m[4]),
            ];
        }

        if (preg_match('#^(?:private/)?reports/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $relPath, $m) === 1) {
            return [
                'scale' => (string) $m[1],
                'attempt' => (string) $m[2],
                'manifest_hash' => 'nohash',
                'variant' => strtolower((string) $m[3]),
            ];
        }

        return null;
    }

    private function resolvePlanPath(string $planOption): string
    {
        if (str_starts_with($planOption, DIRECTORY_SEPARATOR)) {
            return $planOption;
        }

        $basePathCandidate = base_path($planOption);
        if (is_file($basePathCandidate)) {
            return $basePathCandidate;
        }

        return storage_path('app/private/'.ltrim($planOption, '/\\'));
    }

    private function fileSizeFromRelativePath(string $relPath): int
    {
        $abs = storage_path('app/private/'.$relPath);

        return is_file($abs) ? max(0, (int) (filesize($abs) ?: 0)) : 0;
    }

    private function recordAudit(string $source, string $target, string $sha256, int $bytes): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $attemptId = $this->extractAttemptId($target);

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_migrate_legacy_artifacts',
            'target_type' => 'attempt',
            'target_id' => $attemptId,
            'meta_json' => json_encode([
                'source' => $source,
                'target' => $target,
                'sha256' => $sha256,
                'bytes' => $bytes,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_migrate_legacy_artifacts',
            'request_id' => null,
            'reason' => 'legacy_artifact_copy',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }

    private function extractAttemptId(string $target): string
    {
        if (preg_match('#^artifacts/reports/[^/]+/([^/]+)/report\.json$#', $target, $m) === 1) {
            return (string) $m[1];
        }

        if (preg_match('#^artifacts/pdf/[^/]+/([^/]+)/#', $target, $m) === 1) {
            return (string) $m[1];
        }

        return $target;
    }
}
