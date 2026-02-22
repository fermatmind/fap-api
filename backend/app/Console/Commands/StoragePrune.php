<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class StoragePrune extends Command
{
    private const SCOPE_REPORTS_BACKUPS = 'reports_backups';

    /** @var list<string> */
    private const SUPPORTED_SCOPES = [
        self::SCOPE_REPORTS_BACKUPS,
    ];

    protected $signature = 'storage:prune
        {--dry-run : Generate prune plan only}
        {--execute : Execute prune plan}
        {--scope=reports_backups : Prune scope}
        {--plan= : Plan json path for execute mode}';

    protected $description = 'Generate/execute storage prune plans.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $scope = strtolower(trim((string) $this->option('scope')));

        if (! in_array($scope, self::SUPPORTED_SCOPES, true)) {
            $this->error('unsupported --scope: '.$scope);

            return self::FAILURE;
        }

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        if ($dryRun) {
            return $this->dryRun($scope);
        }

        return $this->executePlan($scope);
    }

    private function dryRun(string $scope): int
    {
        $entries = match ($scope) {
            self::SCOPE_REPORTS_BACKUPS => $this->collectReportBackupEntries(),
            default => [],
        };

        $totalBytes = 0;
        $paths = [];
        foreach ($entries as $entry) {
            $path = trim((string) ($entry['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $bytes = max(0, (int) ($entry['bytes'] ?? 0));
            $paths[] = [
                'path' => $path,
                'bytes' => $bytes,
            ];
            $totalBytes += $bytes;
        }

        $plan = [
            'schema' => 'storage_prune_plan.v1',
            'scope' => $scope,
            'generated_at' => now()->toISOString(),
            'files' => $paths,
            'summary' => [
                'files' => count($paths),
                'bytes' => $totalBytes,
            ],
        ];

        $planDir = storage_path('app/private/prune_plans');
        File::ensureDirectoryExists($planDir);

        $planName = now()->format('Ymd_His').'_'.$scope.'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $planPath = $planDir.DIRECTORY_SEPARATOR.$planName;
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (! is_string($encoded)) {
            $this->error('failed to encode prune plan json.');

            return self::FAILURE;
        }

        File::put($planPath, $encoded.PHP_EOL);

        $this->line('status=planned');
        $this->line('scope='.$scope);
        $this->line('plan='.$planPath);
        $this->line('files='.count($paths));
        $this->line('bytes='.$totalBytes);

        return self::SUCCESS;
    }

    private function executePlan(string $scope): int
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

        $planScope = strtolower(trim((string) ($decoded['scope'] ?? '')));
        if ($planScope !== $scope) {
            $this->error('plan scope mismatch: plan='.$planScope.' cli='.$scope);

            return self::FAILURE;
        }

        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        $disk = Storage::disk('local');

        $deletedFiles = 0;
        $deletedBytes = 0;
        $missingFiles = 0;
        $skippedFiles = 0;

        foreach ($files as $fileEntry) {
            $relPath = trim((string) (is_array($fileEntry) ? ($fileEntry['path'] ?? '') : $fileEntry));
            if ($relPath === '') {
                continue;
            }

            if (! $this->isPrunablePathForScope($scope, $relPath)) {
                $skippedFiles++;
                continue;
            }

            if (! $disk->exists($relPath)) {
                $missingFiles++;
                continue;
            }

            $absPath = storage_path('app/private/'.$relPath);
            $bytes = is_file($absPath) ? (int) (filesize($absPath) ?: 0) : 0;
            if (! $disk->delete($relPath)) {
                $this->error('delete failed: '.$relPath);

                return self::FAILURE;
            }

            $deletedFiles++;
            $deletedBytes += max(0, $bytes);
        }

        $this->line('status=executed');
        $this->line('scope='.$scope);
        $this->line('plan='.$planPath);
        $this->line('deleted_files='.$deletedFiles);
        $this->line('deleted_bytes='.$deletedBytes);
        $this->line('missing_files='.$missingFiles);
        $this->line('skipped_files='.$skippedFiles);

        return self::SUCCESS;
    }

    /**
     * @return list<array{path:string,bytes:int}>
     */
    private function collectReportBackupEntries(): array
    {
        $root = storage_path('app/private/reports');
        if (! is_dir($root)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $prefix = rtrim(storage_path('app/private'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if (! str_starts_with($fullPath, $prefix)) {
                continue;
            }

            $relPath = str_replace('\\', '/', substr($fullPath, strlen($prefix)));
            if (! $this->isReportTimestampBackupPath($relPath)) {
                continue;
            }

            $items[] = [
                'path' => $relPath,
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $items;
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

    private function isPrunablePathForScope(string $scope, string $relPath): bool
    {
        return match ($scope) {
            self::SCOPE_REPORTS_BACKUPS => $this->isReportTimestampBackupPath($relPath),
            default => false,
        };
    }

    private function isReportTimestampBackupPath(string $relPath): bool
    {
        return preg_match('#^reports/[^/]+/report\.\d{8}_\d{6}\.json$#', $relPath) === 1;
    }
}
