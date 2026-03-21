<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ArtifactStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class StoragePrune extends Command
{
    private const SCOPE_REPORTS_BACKUPS = 'reports_backups';

    private const SCOPE_CONTENT_RELEASES = 'content_releases_retention';

    private const SCOPE_LEGACY_PRIVATE_PRIVATE = 'legacy_private_private_cleanup';

    private const STRATEGY_STRICT = 'strict';

    private const STRATEGY_BROAD = 'broad';

    /** @var list<string> */
    private const SUPPORTED_SCOPES = [
        self::SCOPE_REPORTS_BACKUPS,
        self::SCOPE_CONTENT_RELEASES,
        self::SCOPE_LEGACY_PRIVATE_PRIVATE,
    ];

    /** @var list<string> */
    private const SUPPORTED_STRATEGIES = [
        self::STRATEGY_STRICT,
        self::STRATEGY_BROAD,
    ];

    protected $signature = 'storage:prune
        {--dry-run : Generate prune plan only}
        {--execute : Execute prune plan}
        {--scope=reports_backups : Prune scope}
        {--strategy=strict : reports_backups strategy(strict|broad)}
        {--plan= : Plan json path for execute mode}';

    protected $description = 'Generate/execute storage prune plans.';

    public function __construct(
        private readonly ArtifactStore $artifactStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');
        $scope = strtolower(trim((string) $this->option('scope')));
        $strategy = strtolower(trim((string) $this->option('strategy')));
        if ($strategy === '') {
            $strategy = self::STRATEGY_STRICT;
        }

        if (! in_array($scope, self::SUPPORTED_SCOPES, true)) {
            $this->error('unsupported --scope: '.$scope);

            return self::FAILURE;
        }

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        if ($scope === self::SCOPE_REPORTS_BACKUPS && ! in_array($strategy, self::SUPPORTED_STRATEGIES, true)) {
            $this->error('unsupported --strategy for reports_backups: '.$strategy);

            return self::FAILURE;
        }

        if ($scope === self::SCOPE_REPORTS_BACKUPS && $strategy === self::STRATEGY_BROAD && $execute) {
            $this->error('reports_backups strategy=broad is dry-run only in phased rollout.');

            return self::FAILURE;
        }

        if ($dryRun) {
            return $this->dryRun($scope, $strategy);
        }

        return $this->executePlan($scope, $strategy);
    }

    private function dryRun(string $scope, string $strategy): int
    {
        $entries = match ($scope) {
            self::SCOPE_REPORTS_BACKUPS => $this->collectReportBackupEntries($strategy),
            self::SCOPE_CONTENT_RELEASES => $this->collectContentReleasesRetentionEntries(),
            self::SCOPE_LEGACY_PRIVATE_PRIVATE => $this->collectLegacyPrivatePrivateEntries(),
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
            $item = [
                'path' => $path,
                'bytes' => $bytes,
            ];

            $canonical = trim((string) ($entry['canonical'] ?? ''));
            if ($canonical !== '') {
                $item['canonical'] = $canonical;
            }

            $paths[] = $item;
            $totalBytes += $bytes;
        }

        $plan = [
            'schema' => 'storage_prune_plan.v2',
            'scope' => $scope,
            'strategy' => $scope === self::SCOPE_REPORTS_BACKUPS ? $strategy : null,
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
        if ($scope === self::SCOPE_REPORTS_BACKUPS) {
            $this->line('strategy='.$strategy);
        }
        $this->line('plan='.$planPath);
        $this->line('files='.count($paths));
        $this->line('bytes='.$totalBytes);

        return self::SUCCESS;
    }

    private function executePlan(string $scope, string $strategy): int
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

        $planSchema = trim((string) ($decoded['schema'] ?? ''));
        if (! in_array($planSchema, ['storage_prune_plan.v1', 'storage_prune_plan.v2'], true)) {
            $this->error('unsupported plan schema: '.$planSchema);

            return self::FAILURE;
        }

        $planScope = strtolower(trim((string) ($decoded['scope'] ?? '')));
        if ($planScope !== $scope) {
            $this->error('plan scope mismatch: plan='.$planScope.' cli='.$scope);

            return self::FAILURE;
        }

        $planStrategy = strtolower(trim((string) ($decoded['strategy'] ?? self::STRATEGY_STRICT)));
        if ($scope === self::SCOPE_REPORTS_BACKUPS) {
            if (! in_array($planStrategy, self::SUPPORTED_STRATEGIES, true)) {
                $this->error('unsupported plan strategy: '.$planStrategy);

                return self::FAILURE;
            }
            if ($planStrategy !== $strategy) {
                $this->error('plan strategy mismatch: plan='.$planStrategy.' cli='.$strategy);

                return self::FAILURE;
            }
            if ($strategy === self::STRATEGY_BROAD) {
                $this->error('reports_backups strategy=broad is dry-run only in phased rollout.');

                return self::FAILURE;
            }
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

            $canonical = trim((string) (is_array($fileEntry) ? ($fileEntry['canonical'] ?? '') : ''));

            if (! $this->isPrunablePathForScope($scope, $relPath, $strategy)) {
                $skippedFiles++;

                continue;
            }

            if ($scope === self::SCOPE_LEGACY_PRIVATE_PRIVATE && $canonical !== '' && ! $disk->exists($canonical)) {
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

        if ($scope === self::SCOPE_LEGACY_PRIVATE_PRIVATE) {
            $this->cleanupEmptyDirectories(storage_path('app/private/private'));
        }

        $this->line('status=executed');
        $this->line('scope='.$scope);
        if ($scope === self::SCOPE_REPORTS_BACKUPS) {
            $this->line('strategy='.$strategy);
        }
        $this->line('plan='.$planPath);
        $this->line('deleted_files='.$deletedFiles);
        $this->line('deleted_bytes='.$deletedBytes);
        $this->line('missing_files='.$missingFiles);
        $this->line('skipped_files='.$skippedFiles);

        $this->recordAudit($scope, $strategy, $deletedFiles, $deletedBytes, $missingFiles, $skippedFiles, $planPath);

        return self::SUCCESS;
    }

    /**
     * @return list<array{path:string,bytes:int}>
     */
    private function collectReportBackupEntries(string $strategy): array
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
            $matches = $strategy === self::STRATEGY_STRICT
                ? $this->isReportTimestampBackupPathStrict($relPath)
                : $this->isReportTimestampBackupPathBroad($relPath);

            if (! $matches) {
                continue;
            }

            $items[] = [
                'path' => $relPath,
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
                'mtime' => max(0, (int) ($file->getMTime() ?: 0)),
                'group' => dirname($relPath),
            ];
        }

        $keepTimestampBackups = max(0, (int) config('storage_retention.reports.keep_timestamp_backups', 0));
        $keepDays = max(0, (int) config('storage_retention.reports.keep_days', 0));
        $threshold = now()->subDays($keepDays)->getTimestamp();

        $protectedPaths = [];
        if ($keepTimestampBackups > 0) {
            $grouped = [];
            foreach ($items as $item) {
                $grouped[$item['group']][] = $item;
            }

            foreach ($grouped as $groupItems) {
                usort($groupItems, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['path'], $b['path']));
                foreach (array_slice($groupItems, 0, $keepTimestampBackups) as $protectedItem) {
                    $protectedPaths[$protectedItem['path']] = true;
                }
            }
        }

        $items = array_values(array_filter($items, static function (array $item) use ($keepDays, $protectedPaths, $threshold): bool {
            if (isset($protectedPaths[$item['path']])) {
                return false;
            }

            if ($keepDays > 0 && $item['mtime'] >= $threshold) {
                return false;
            }

            return true;
        }));

        usort($items, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $items = array_map(static fn (array $item): array => [
            'path' => $item['path'],
            'bytes' => $item['bytes'],
        ], $items);

        return $items;
    }

    /**
     * @return list<array{path:string,bytes:int}>
     */
    private function collectContentReleasesRetentionEntries(): array
    {
        $root = storage_path('app/private/content_releases');
        if (! is_dir($root)) {
            return [];
        }

        $keepLastN = max(0, (int) config('storage_retention.content_releases.keep_last_n', 20));
        $keepDays = max(0, (int) config('storage_retention.content_releases.keep_days', 180));
        $threshold = now()->subDays($keepDays)->getTimestamp();

        $releaseDirs = $this->collectContentReleaseRetentionUnits($root);

        usort($releaseDirs, static fn (array $a, array $b): int => ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'], $b['name']));

        $items = [];
        foreach ($releaseDirs as $index => $dir) {
            $isProtectedByCount = $index < $keepLastN;
            $isProtectedByAge = ((int) $dir['mtime']) >= $threshold;
            if ($isProtectedByCount || $isProtectedByAge) {
                continue;
            }

            $items = array_merge($items, $this->collectFilesUnderPrivateDirectory((string) $dir['path']));
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $items;
    }

    /**
     * @return list<array{name:string,path:string,mtime:int}>
     */
    private function collectContentReleaseRetentionUnits(string $root): array
    {
        $units = [];

        foreach (new \DirectoryIterator($root) as $item) {
            if (! $item->isDir() || $item->isDot()) {
                continue;
            }

            $name = $item->getFilename();
            $dirPath = $item->getPathname();

            if ($name === 'backups') {
                foreach (new \DirectoryIterator($dirPath) as $backupItem) {
                    if (! $backupItem->isDir() || $backupItem->isDot()) {
                        continue;
                    }

                    $this->appendContentReleaseRetentionUnit(
                        $units,
                        'backups/'.$backupItem->getFilename(),
                        $backupItem->getPathname()
                    );
                }

                continue;
            }

            $this->appendContentReleaseRetentionUnit($units, $name, $dirPath);
        }

        return $units;
    }

    /**
     * @param  list<array{name:string,path:string,mtime:int}>  $units
     */
    private function appendContentReleaseRetentionUnit(array &$units, string $name, string $path): void
    {
        $units[] = [
            'name' => $name,
            'path' => $path,
            'mtime' => (int) (@filemtime($path) ?: 0),
        ];
    }

    /**
     * @return list<array{path:string,bytes:int}>
     */
    private function collectFilesUnderPrivateDirectory(string $dirPath): array
    {
        if (! is_dir($dirPath)) {
            return [];
        }

        $items = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS)
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

            $items[] = [
                'path' => str_replace('\\', '/', substr($fullPath, strlen($prefix))),
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
            ];
        }

        return $items;
    }

    /**
     * @return list<array{path:string,bytes:int,canonical:string}>
     */
    private function collectLegacyPrivatePrivateEntries(): array
    {
        $root = storage_path('app/private/private');
        if (! is_dir($root)) {
            return [];
        }

        $items = [];
        $disk = Storage::disk('local');
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
            if (! str_starts_with($relPath, 'private/')) {
                continue;
            }

            $candidates = $this->legacyCanonicalCandidates($relPath);
            $canonical = '';
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && $disk->exists($candidate)) {
                    $canonical = $candidate;
                    break;
                }
            }

            if ($canonical === '') {
                continue;
            }

            $items[] = [
                'path' => $relPath,
                'bytes' => max(0, (int) ($file->getSize() ?: 0)),
                'canonical' => $canonical,
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

    private function isPrunablePathForScope(string $scope, string $relPath, string $strategy): bool
    {
        return match ($scope) {
            self::SCOPE_REPORTS_BACKUPS => $strategy === self::STRATEGY_STRICT
                ? $this->isReportTimestampBackupPathStrict($relPath)
                : $this->isReportTimestampBackupPathBroad($relPath),
            self::SCOPE_CONTENT_RELEASES => str_starts_with($relPath, 'content_releases/'),
            self::SCOPE_LEGACY_PRIVATE_PRIVATE => str_starts_with($relPath, 'private/'),
            default => false,
        };
    }

    private function isReportTimestampBackupPathStrict(string $relPath): bool
    {
        return preg_match('#^reports/[^/]+/report\.\d{8}_\d{6}\.json$#', $relPath) === 1;
    }

    private function isReportTimestampBackupPathBroad(string $relPath): bool
    {
        if (preg_match('#^reports/[^/]+/(report|report_snapshot)\.json$#', $relPath) === 1) {
            return false;
        }

        return preg_match('#^reports/[^/]+/(report|report_snapshot)\..+\.json$#', $relPath) === 1;
    }

    /**
     * @return list<string>
     */
    private function legacyCanonicalCandidates(string $legacyPath): array
    {
        $candidates = [];

        $stripped = substr($legacyPath, strlen('private/'));
        if (is_string($stripped) && $stripped !== '') {
            $candidates[] = $stripped;
        }

        if (preg_match('#^private/reports/([^/]+)/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $legacyPath, $m) === 1) {
            $candidates[] = $this->artifactStore->pdfCanonicalPath(
                (string) $m[1],
                (string) $m[2],
                (string) $m[3],
                (string) $m[4]
            );
        } elseif (preg_match('#^private/reports/([^/]+)/([^/]+)/report_(free|full)\.pdf$#i', $legacyPath, $m) === 1) {
            $candidates[] = $this->artifactStore->pdfCanonicalPath(
                (string) $m[1],
                (string) $m[2],
                'nohash',
                (string) $m[3]
            );
        }

        if (preg_match('#^private/reports/([^/]+)/report\.json$#', $legacyPath, $m) === 1) {
            $candidates[] = $this->artifactStore->reportCanonicalPath('MBTI', (string) $m[1]);
        } elseif (preg_match('#^private/reports/([^/]+)/([^/]+)/report\.json$#', $legacyPath, $m) === 1) {
            $candidates[] = $this->artifactStore->reportCanonicalPath((string) $m[1], (string) $m[2]);
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $path): bool => $path !== '')));

        return $candidates;
    }

    private function cleanupEmptyDirectories(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo || ! $item->isDir()) {
                continue;
            }

            $dir = $item->getPathname();
            $children = @scandir($dir);
            if ($children === false) {
                continue;
            }

            if (count($children) === 2) {
                @rmdir($dir);
            }
        }

        $children = @scandir($root);
        if (is_array($children) && count($children) === 2) {
            @rmdir($root);
        }
    }

    private function recordAudit(
        string $scope,
        string $strategy,
        int $deletedFiles,
        int $deletedBytes,
        int $missingFiles,
        int $skippedFiles,
        string $planPath
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_prune',
            'target_type' => 'storage',
            'target_id' => $scope,
            'meta_json' => json_encode([
                'scope' => $scope,
                'strategy' => $scope === self::SCOPE_REPORTS_BACKUPS ? $strategy : null,
                'deleted_files_count' => $deletedFiles,
                'deleted_bytes' => $deletedBytes,
                'missing_files' => $missingFiles,
                'skipped_files' => $skippedFiles,
                'plan' => $planPath,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_prune',
            'request_id' => null,
            'reason' => 'retention_policy',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
