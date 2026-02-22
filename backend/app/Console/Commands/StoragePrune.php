<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class StoragePrune extends Command
{
    private const SCOPE_ALL = 'all';

    private const SCOPE_REPORTS_BACKUPS = 'reports_backups';

    private const SCOPE_PDF_EXPIRED = 'pdf_expired';

    private const SCOPE_RELEASES_EXPIRED = 'releases_expired';

    private const SCOPE_LOGS_EXPIRED = 'logs_expired';

    /** @var list<string> */
    private const SUPPORTED_SCOPES = [
        self::SCOPE_REPORTS_BACKUPS,
        self::SCOPE_PDF_EXPIRED,
        self::SCOPE_RELEASES_EXPIRED,
        self::SCOPE_LOGS_EXPIRED,
    ];

    protected $signature = 'storage:prune
        {--dry-run : Generate prune plan only}
        {--execute : Execute prune plan}
        {--scope=all : Prune scope (all|reports_backups|pdf_expired|releases_expired|logs_expired)}
        {--plan= : Plan json path for execute mode}';

    protected $description = 'Generate/execute storage prune plans.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $scope = $this->normalizeScope((string) $this->option('scope'));

        if (! $this->isSupportedScope($scope, true)) {
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
        $entries = $this->collectEntries($scope);
        $plan = $this->buildPlan($scope, $entries);
        $planPath = $this->writePlanFile($plan, $scope);
        if ($planPath === null) {
            $this->error('failed to encode prune plan json.');

            return self::FAILURE;
        }

        $this->line('status=planned');
        $this->line('scope='.$scope);
        $this->line('plan='.$planPath);
        $this->line('files='.(int) ($plan['summary']['files'] ?? 0));
        $this->line('bytes='.(int) ($plan['summary']['bytes'] ?? 0));

        return self::SUCCESS;
    }

    private function executePlan(string $scope): int
    {
        $planOption = trim((string) $this->option('plan'));
        if ($planOption === '') {
            $entries = $this->collectEntries($scope);
            $generatedPlan = $this->buildPlan($scope, $entries);
            $generatedPlanPath = $this->writePlanFile($generatedPlan, $scope);
            if ($generatedPlanPath === null) {
                $this->error('failed to generate plan in --execute mode.');

                return self::FAILURE;
            }

            $planPath = $generatedPlanPath;
        } else {
            $planPath = $this->resolvePlanPath($planOption);
        }

        if (! is_file($planPath)) {
            $this->error('plan not found: '.$planPath);

            return self::FAILURE;
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            $this->error('invalid plan json: '.$planPath);

            return self::FAILURE;
        }

        $planScope = $this->normalizeScope((string) ($decoded['scope'] ?? ''));
        if ($scope !== self::SCOPE_ALL && $planScope !== '' && $planScope !== $scope && $planScope !== self::SCOPE_ALL) {
            $this->error('plan scope mismatch: plan='.$planScope.' cli='.$scope);

            return self::FAILURE;
        }

        $entries = $this->entriesFromPlan($decoded, $planScope);
        $disk = Storage::disk('local');

        $deletedFiles = 0;
        $deletedBytes = 0;
        $missingFiles = 0;
        $skippedFiles = 0;

        /** @var array<string,array{files:int,bytes:int}> $deletedByScope */
        $deletedByScope = [];

        foreach ($entries as $entry) {
            $entryScope = $this->normalizeScope((string) ($entry['scope'] ?? ''));
            $path = trim((string) ($entry['path'] ?? ''));
            $mode = strtolower(trim((string) ($entry['mode'] ?? 'local')));
            if ($path === '' || ! $this->isSupportedScope($entryScope, false)) {
                $skippedFiles++;

                continue;
            }

            if ($scope !== self::SCOPE_ALL && $entryScope !== $scope) {
                $skippedFiles++;

                continue;
            }

            if (! isset($deletedByScope[$entryScope])) {
                $deletedByScope[$entryScope] = ['files' => 0, 'bytes' => 0];
            }

            if ($mode === 'absolute') {
                if (! $this->isPrunableAbsolutePathForScope($entryScope, $path)) {
                    $skippedFiles++;

                    continue;
                }

                if (! is_file($path)) {
                    $missingFiles++;

                    continue;
                }

                $bytes = (int) (filesize($path) ?: 0);
                if (! @unlink($path)) {
                    $this->error('delete failed: '.$path);

                    return self::FAILURE;
                }

                $deletedFiles++;
                $deletedBytes += max(0, $bytes);
                $deletedByScope[$entryScope]['files']++;
                $deletedByScope[$entryScope]['bytes'] += max(0, $bytes);

                continue;
            }

            if (! $this->isPrunablePathForScope($entryScope, $path)) {
                $skippedFiles++;

                continue;
            }

            if (! $disk->exists($path)) {
                $missingFiles++;

                continue;
            }

            $absPath = storage_path('app/private/'.$path);
            $bytes = is_file($absPath) ? (int) (filesize($absPath) ?: 0) : 0;
            if (! $disk->delete($path)) {
                $this->error('delete failed: '.$path);

                return self::FAILURE;
            }

            $deletedFiles++;
            $deletedBytes += max(0, $bytes);
            $deletedByScope[$entryScope]['files']++;
            $deletedByScope[$entryScope]['bytes'] += max(0, $bytes);
        }

        $auditScopes = $scope === self::SCOPE_ALL ? self::SUPPORTED_SCOPES : [$scope];
        foreach ($auditScopes as $auditScope) {
            $scopeSummary = $deletedByScope[$auditScope] ?? ['files' => 0, 'bytes' => 0];
            $this->writeAuditLog(
                $auditScope,
                (int) ($scopeSummary['files'] ?? 0),
                (int) ($scopeSummary['bytes'] ?? 0),
                $planPath
            );
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
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectEntries(string $scope): array
    {
        $scopes = $scope === self::SCOPE_ALL ? self::SUPPORTED_SCOPES : [$scope];

        $entries = [];
        foreach ($scopes as $itemScope) {
            $entries = array_merge($entries, $this->collectEntriesForScope($itemScope));
        }

        usort($entries, static function (array $a, array $b): int {
            $scopeCmp = strcmp((string) ($a['scope'] ?? ''), (string) ($b['scope'] ?? ''));
            if ($scopeCmp !== 0) {
                return $scopeCmp;
            }

            return strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''));
        });

        return $entries;
    }

    /**
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectEntriesForScope(string $scope): array
    {
        return match ($scope) {
            self::SCOPE_REPORTS_BACKUPS => $this->collectReportBackupEntries(),
            self::SCOPE_PDF_EXPIRED => $this->collectExpiredPdfEntries(),
            self::SCOPE_RELEASES_EXPIRED => $this->collectExpiredReleaseEntries(),
            self::SCOPE_LOGS_EXPIRED => $this->collectExpiredLogEntries(),
            default => [],
        };
    }

    /**
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectReportBackupEntries(): array
    {
        $entries = [];

        foreach ($this->scanLocalFiles('reports') as $file) {
            $relPath = (string) ($file['path'] ?? '');
            if (! $this->isReportTimestampBackupPath($relPath)) {
                continue;
            }

            $entries[] = [
                'scope' => self::SCOPE_REPORTS_BACKUPS,
                'path' => $relPath,
                'bytes' => max(0, (int) ($file['bytes'] ?? 0)),
                'mode' => 'local',
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectExpiredPdfEntries(): array
    {
        $entries = [];
        $freeKeepDays = max(0, (int) config('storage_retention.pdf.keep_days_free', 30));
        $paidKeepDays = max(0, (int) config('storage_retention.pdf.keep_days_paid', 365));

        $freeCutoff = now()->subDays($freeKeepDays)->getTimestamp();
        $paidCutoff = now()->subDays($paidKeepDays)->getTimestamp();

        foreach ($this->scanLocalFiles('artifacts/pdf') as $file) {
            $relPath = (string) ($file['path'] ?? '');
            $mtime = max(0, (int) ($file['mtime'] ?? 0));

            if (preg_match('#^artifacts/pdf/[^/]+/[^/]+/[^/]+/report_(free|full)\.pdf$#i', $relPath, $m) !== 1) {
                continue;
            }

            $variant = strtolower((string) ($m[1] ?? 'free'));
            $cutoff = $variant === 'full' ? $paidCutoff : $freeCutoff;
            if ($mtime > $cutoff) {
                continue;
            }

            $entries[] = [
                'scope' => self::SCOPE_PDF_EXPIRED,
                'path' => $relPath,
                'bytes' => max(0, (int) ($file['bytes'] ?? 0)),
                'mode' => 'local',
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectExpiredReleaseEntries(): array
    {
        $keepDays = max(0, (int) config('storage_retention.releases.keep_days', 180));
        $keepLastN = max(0, (int) config('storage_retention.releases.keep_last_n', 20));
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        /** @var list<array{path:string,bytes:int,mtime:int,release_key:?string,parent_key:?string}> $records */
        $records = [];
        foreach (['releases', 'content_releases'] as $root) {
            foreach ($this->scanLocalFiles($root) as $file) {
                $path = (string) ($file['path'] ?? '');
                if ($path === '' || str_ends_with($path, '/.gitkeep')) {
                    continue;
                }

                $keys = $this->resolveReleaseKeys($path);

                $records[] = [
                    'path' => $path,
                    'bytes' => max(0, (int) ($file['bytes'] ?? 0)),
                    'mtime' => max(0, (int) ($file['mtime'] ?? 0)),
                    'release_key' => $keys['release_key'],
                    'parent_key' => $keys['parent_key'],
                ];
            }
        }

        /** @var array<string,int> $releaseLatest */
        $releaseLatest = [];
        /** @var array<string,string> $releaseParent */
        $releaseParent = [];
        foreach ($records as $record) {
            $releaseKey = $record['release_key'];
            $parentKey = $record['parent_key'];
            if ($releaseKey === null) {
                continue;
            }

            $releaseLatest[$releaseKey] = isset($releaseLatest[$releaseKey])
                ? max($releaseLatest[$releaseKey], $record['mtime'])
                : $record['mtime'];

            $releaseParent[$releaseKey] = $parentKey ?? '_';
        }

        /** @var array<string,array<string,int>> $grouped */
        $grouped = [];
        foreach ($releaseLatest as $releaseKey => $mtime) {
            $parent = $releaseParent[$releaseKey] ?? '_';
            if (! isset($grouped[$parent])) {
                $grouped[$parent] = [];
            }
            $grouped[$parent][$releaseKey] = $mtime;
        }

        /** @var array<string,bool> $protectedReleaseKeys */
        $protectedReleaseKeys = [];
        foreach ($grouped as $items) {
            arsort($items, SORT_NUMERIC);
            $index = 0;
            foreach ($items as $releaseKey => $_mtime) {
                if ($index < $keepLastN) {
                    $protectedReleaseKeys[$releaseKey] = true;
                }
                $index++;
            }
        }

        $entries = [];
        foreach ($records as $record) {
            if ($record['mtime'] > $cutoff) {
                continue;
            }

            $releaseKey = $record['release_key'];
            if ($releaseKey !== null && isset($protectedReleaseKeys[$releaseKey])) {
                continue;
            }

            $entries[] = [
                'scope' => self::SCOPE_RELEASES_EXPIRED,
                'path' => $record['path'],
                'bytes' => $record['bytes'],
                'mode' => 'local',
            ];
        }

        return $entries;
    }

    /**
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function collectExpiredLogEntries(): array
    {
        $root = storage_path('logs');
        if (! is_dir($root)) {
            return [];
        }

        $keepDays = max(0, (int) config('storage_retention.logs.keep_days', 30));
        $cutoff = now()->subDays($keepDays)->getTimestamp();

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            if (str_ends_with($fullPath, DIRECTORY_SEPARATOR.'.gitkeep')) {
                continue;
            }

            $mtime = max(0, (int) ($file->getMTime() ?: 0));
            if ($mtime > $cutoff) {
                continue;
            }

            $entries[] = [
                'scope' => self::SCOPE_LOGS_EXPIRED,
                'path' => $fullPath,
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
                'mode' => 'absolute',
            ];
        }

        return $entries;
    }

    /**
     * @return array{release_key:?string,parent_key:?string}
     */
    private function resolveReleaseKeys(string $path): array
    {
        if (preg_match('#^releases/([^/]+)/([^/]+)(?:/|$)#', $path, $m) === 1) {
            return [
                'release_key' => 'releases/'.$m[1].'/'.$m[2],
                'parent_key' => 'releases/'.$m[1],
            ];
        }

        if (preg_match('#^content_releases/backups/([^/]+)(?:/|$)#', $path, $m) === 1) {
            return [
                'release_key' => 'content_releases/backups/'.$m[1],
                'parent_key' => 'content_releases/backups',
            ];
        }

        if (preg_match('#^content_releases/([^/]+)/([^/]+)(?:/|$)#', $path, $m) === 1) {
            return [
                'release_key' => 'content_releases/'.$m[1].'/'.$m[2],
                'parent_key' => 'content_releases/'.$m[1],
            ];
        }

        return [
            'release_key' => null,
            'parent_key' => null,
        ];
    }

    /**
     * @return list<array{path:string,bytes:int,mtime:int}>
     */
    private function scanLocalFiles(string $relativeRoot): array
    {
        $relativeRoot = trim(str_replace('\\', '/', $relativeRoot), '/');
        if ($relativeRoot === '') {
            return [];
        }

        $root = storage_path('app/private/'.$relativeRoot);
        if (! is_dir($root)) {
            return [];
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $privateRoot = rtrim(storage_path('app/private'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            if (! str_starts_with($fullPath, $privateRoot)) {
                continue;
            }

            $relPath = str_replace('\\', '/', substr($fullPath, strlen($privateRoot)));
            $entries[] = [
                'path' => $relPath,
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
                'mtime' => max(0, (int) ($file->getMTime() ?: 0)),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return list<array{scope:string,path:string,bytes:int,mode:string}>
     */
    private function entriesFromPlan(array $decoded, string $planScope): array
    {
        $entries = [];

        if (is_array($decoded['entries'] ?? null)) {
            foreach ($decoded['entries'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $scope = $this->normalizeScope((string) ($entry['scope'] ?? $planScope));
                $path = trim((string) ($entry['path'] ?? ''));
                $bytes = max(0, (int) ($entry['bytes'] ?? 0));
                $mode = strtolower(trim((string) ($entry['mode'] ?? 'local')));
                if ($path === '' || $mode === '') {
                    continue;
                }

                $entries[] = [
                    'scope' => $scope,
                    'path' => $path,
                    'bytes' => $bytes,
                    'mode' => $mode,
                ];
            }

            return $entries;
        }

        if (! is_array($decoded['files'] ?? null)) {
            return [];
        }

        $fallbackScope = $this->isSupportedScope($planScope, true) ? $planScope : self::SCOPE_REPORTS_BACKUPS;
        foreach ($decoded['files'] as $fileEntry) {
            $path = trim((string) (is_array($fileEntry) ? ($fileEntry['path'] ?? '') : $fileEntry));
            if ($path === '') {
                continue;
            }

            $bytes = max(0, (int) (is_array($fileEntry) ? ($fileEntry['bytes'] ?? 0) : 0));
            $entries[] = [
                'scope' => $fallbackScope,
                'path' => $path,
                'bytes' => $bytes,
                'mode' => 'local',
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array{scope:string,path:string,bytes:int,mode:string}>  $entries
     * @return array{
     *     schema:string,
     *     scope:string,
     *     generated_at:string,
     *     entries:list<array{scope:string,path:string,bytes:int,mode:string}>,
     *     summary:array{files:int,bytes:int,scopes:array<string,array{files:int,bytes:int}>}
     * }
     */
    private function buildPlan(string $scope, array $entries): array
    {
        /** @var array<string,array{files:int,bytes:int}> $summaryByScope */
        $summaryByScope = [];
        $totalBytes = 0;

        foreach ($entries as $entry) {
            $entryScope = (string) ($entry['scope'] ?? '');
            $bytes = max(0, (int) ($entry['bytes'] ?? 0));

            if (! isset($summaryByScope[$entryScope])) {
                $summaryByScope[$entryScope] = [
                    'files' => 0,
                    'bytes' => 0,
                ];
            }

            $summaryByScope[$entryScope]['files']++;
            $summaryByScope[$entryScope]['bytes'] += $bytes;
            $totalBytes += $bytes;
        }

        return [
            'schema' => 'storage_prune_plan.v2',
            'scope' => $scope,
            'generated_at' => now()->toISOString(),
            'entries' => $entries,
            'summary' => [
                'files' => count($entries),
                'bytes' => $totalBytes,
                'scopes' => $summaryByScope,
            ],
        ];
    }

    /**
     * @param  array{
     *     schema:string,
     *     scope:string,
     *     generated_at:string,
     *     entries:list<array{scope:string,path:string,bytes:int,mode:string}>,
     *     summary:array{files:int,bytes:int,scopes:array<string,array{files:int,bytes:int}>}
     * }  $plan
     */
    private function writePlanFile(array $plan, string $scope): ?string
    {
        $planDir = storage_path('app/private/prune_plans');
        File::ensureDirectoryExists($planDir);

        $random = bin2hex(random_bytes(4));
        $planName = now()->format('Ymd_His').'_'.$scope.'_'.substr($random, 0, 8).'.json';
        $planPath = $planDir.DIRECTORY_SEPARATOR.$planName;

        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            return null;
        }

        File::put($planPath, $encoded.PHP_EOL);

        return $planPath;
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
            self::SCOPE_PDF_EXPIRED => preg_match('#^artifacts/pdf/[^/]+/[^/]+/[^/]+/report_(free|full)\.pdf$#i', $relPath) === 1,
            self::SCOPE_RELEASES_EXPIRED => (
                str_starts_with($relPath, 'releases/')
                || str_starts_with($relPath, 'content_releases/')
            ) && ! str_ends_with($relPath, '/.gitkeep'),
            default => false,
        };
    }

    private function isPrunableAbsolutePathForScope(string $scope, string $path): bool
    {
        if ($scope !== self::SCOPE_LOGS_EXPIRED) {
            return false;
        }

        $logsRoot = rtrim(storage_path('logs'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $logsRoot)
            && is_file($path)
            && ! str_ends_with($path, DIRECTORY_SEPARATOR.'.gitkeep');
    }

    private function isReportTimestampBackupPath(string $relPath): bool
    {
        return preg_match('#^reports/[^/]+/report\.\d{8}_\d{6}\.json$#', $relPath) === 1;
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));

        return $scope !== '' ? $scope : self::SCOPE_ALL;
    }

    private function isSupportedScope(string $scope, bool $allowAll): bool
    {
        if ($allowAll && $scope === self::SCOPE_ALL) {
            return true;
        }

        return in_array($scope, self::SUPPORTED_SCOPES, true);
    }

    private function writeAuditLog(string $scope, int $deletedFiles, int $deletedBytes, string $planPath): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $meta = [
            'scope' => $scope,
            'deleted_files_count' => max(0, $deletedFiles),
            'deleted_bytes' => max(0, $deletedBytes),
            'plan_path' => $planPath,
        ];

        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($metaJson)) {
            $metaJson = '{}';
        }

        $row = [
            'action' => 'storage_prune',
            'target_type' => 'storage',
            'target_id' => null,
            'meta_json' => $metaJson,
            'created_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('audit_logs', 'org_id')) {
            $row['org_id'] = 0;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'actor_admin_id')) {
            $row['actor_admin_id'] = null;
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'ip')) {
            $row['ip'] = '127.0.0.1';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'user_agent')) {
            $row['user_agent'] = 'artisan';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'request_id')) {
            $row['request_id'] = 'storage:prune';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'reason')) {
            $row['reason'] = 'storage_governance_prune';
        }
        if (SchemaBaseline::hasColumn('audit_logs', 'result')) {
            $row['result'] = 'success';
        }

        try {
            DB::table('audit_logs')->insert($row);
        } catch (\Throwable) {
            // keep prune flow non-blocking when audit table shape is unavailable in test/runtime.
        }
    }
}
