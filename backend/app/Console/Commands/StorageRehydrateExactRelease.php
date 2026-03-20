<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ExactReleaseRehydrateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageRehydrateExactRelease extends Command
{
    protected $signature = 'storage:rehydrate-exact-release
        {--dry-run : Build a rehydrate plan only}
        {--execute : Execute rehydrate into a verify-only run directory}
        {--exact-manifest-id= : Exact release manifest id}
        {--release-id= : Content pack release id}
        {--disk= : Remote disk, defaults to storage_rollout.blob_offload_disk}
        {--target-root= : Optional safe base directory for rehydrate run output}';

    protected $description = 'Rehydrate an exact content release root from verified remote blob locations into a new verify-only run directory.';

    public function __construct(
        private readonly ExactReleaseRehydrateService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if (($dryRun && $execute) || (! $dryRun && ! $execute)) {
            $this->error('exactly one of --dry-run or --execute is required.');

            return self::FAILURE;
        }

        $disk = trim((string) ($this->option('disk') ?? ''));
        if ($disk === '') {
            $disk = trim((string) config('storage_rollout.blob_offload_disk', 's3'));
        }

        try {
            $exactManifestId = $this->parseExactManifestId();
            $releaseId = trim((string) ($this->option('release-id') ?? ''));
            if ($exactManifestId === null && $releaseId === '') {
                throw new \RuntimeException('either --exact-manifest-id or --release-id is required.');
            }

            $this->ensureTargetDiskIsRemote($disk);
            $targetRoot = $this->resolveTargetRoot(trim((string) ($this->option('target-root') ?? '')));
            $this->ensureSafeTargetRoot($targetRoot);

            $plan = $this->service->buildPlan($exactManifestId, $releaseId !== '' ? $releaseId : null, $disk, $targetRoot);
            $planPath = $this->persistPlan($plan);

            if ($dryRun) {
                $this->emitDryRunOutput($planPath, $plan);
                $this->recordAudit('planned', $disk, $planPath, $plan, [
                    'rehydrated_files' => 0,
                    'verified_files' => 0,
                    'bytes' => 0,
                    'run_dir' => null,
                ], 'success');

                return self::SUCCESS;
            }

            $this->ensureExecuteDiskConfigured($disk);
            $result = $this->service->executePlan($plan);
            $this->emitExecuteOutput($planPath, $plan, $result);
            $this->recordAudit('executed', $disk, $planPath, $plan, $result, 'success');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if (isset($planPath) && isset($plan) && is_array($plan)) {
                $this->recordAudit('failed', $disk, $planPath, $plan, [
                    'rehydrated_files' => 0,
                    'verified_files' => 0,
                    'bytes' => 0,
                    'run_dir' => null,
                    'error' => $e->getMessage(),
                ], 'failure');
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function parseExactManifestId(): ?int
    {
        $value = trim((string) ($this->option('exact-manifest-id') ?? ''));
        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value)) {
            throw new \RuntimeException('--exact-manifest-id must be a positive integer.');
        }

        return (int) $value;
    }

    private function ensureTargetDiskIsRemote(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver === '') {
            throw new \RuntimeException('rehydrate disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('rehydrate target disk must be remote: local disks are not allowed.');
        }
    }

    private function ensureExecuteDiskConfigured(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver !== 's3') {
            return;
        }

        $bucket = trim((string) config('filesystems.disks.'.$disk.'.bucket', ''));
        if ($bucket === '') {
            throw new \RuntimeException('s3 rehydrate disk is not configured: filesystems.disks.'.$disk.'.bucket is empty.');
        }
    }

    private function resolveTargetRoot(string $targetRootOption): string
    {
        if ($targetRootOption === '') {
            return storage_path('app/private/rehydrate_runs');
        }

        if (str_starts_with($targetRootOption, DIRECTORY_SEPARATOR)) {
            return $this->normalizePath($targetRootOption);
        }

        return $this->normalizePath(storage_path('app/private/'.ltrim($targetRootOption, '/\\')));
    }

    private function ensureSafeTargetRoot(string $targetRoot): void
    {
        $forbiddenRoots = [
            storage_path('app/private/content_releases'),
            storage_path('app/private/packs_v2'),
            storage_path('app/content_packs_v2'),
            storage_path('app/private/packs_v2_materialized'),
            storage_path('app/private/blobs'),
        ];

        foreach ($forbiddenRoots as $forbiddenRoot) {
            $forbiddenRoot = $this->normalizePath($forbiddenRoot);
            if ($targetRoot === $forbiddenRoot || str_starts_with($targetRoot.'/', $forbiddenRoot.'/') || str_starts_with($forbiddenRoot.'/', $targetRoot.'/')) {
                throw new \RuntimeException('unsafe target root for rehydrate runs: '.$targetRoot);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/rehydrate_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_exact_release_rehydrate_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode rehydrate plan json.');
        }

        File::put($planPath, $encoded.PHP_EOL);

        return $planPath;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function emitDryRunOutput(string $planPath, array $plan): void
    {
        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];
        $manifest = is_array($plan['exact_manifest'] ?? null) ? $plan['exact_manifest'] : [];

        $this->line('status=planned');
        $this->line('exact_manifest_id='.(int) ($manifest['id'] ?? 0));
        $this->line('disk='.(string) ($plan['disk'] ?? ''));
        $this->line('file_count='.(int) ($summary['file_count'] ?? 0));
        $this->line('total_bytes='.(int) ($summary['total_bytes'] ?? 0));
        $this->line('missing_locations='.(int) ($summary['missing_locations'] ?? 0));
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function emitExecuteOutput(string $planPath, array $plan, array $result): void
    {
        $manifest = is_array($plan['exact_manifest'] ?? null) ? $plan['exact_manifest'] : [];

        $this->line('status=executed');
        $this->line('exact_manifest_id='.(int) ($manifest['id'] ?? 0));
        $this->line('disk='.(string) ($plan['disk'] ?? ''));
        $this->line('rehydrated_files='.(int) ($result['rehydrated_files'] ?? 0));
        $this->line('verified_files='.(int) ($result['verified_files'] ?? 0));
        $this->line('bytes='.(int) ($result['bytes'] ?? 0));
        $this->line('run_dir='.(string) ($result['run_dir'] ?? ''));
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function recordAudit(string $mode, string $disk, string $planPath, array $plan, array $result, string $resultLabel): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_rehydrate_exact_release',
            'target_type' => 'storage',
            'target_id' => (string) (($plan['exact_manifest']['id'] ?? 'exact_manifest')),
            'meta_json' => json_encode([
                'mode' => $mode,
                'disk' => $disk,
                'plan_path' => $planPath,
                'plan' => $plan,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_rehydrate_exact_release',
            'request_id' => null,
            'reason' => 'exact_release_rehydrate_verify',
            'result' => $resultLabel,
            'created_at' => now(),
        ]);
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim(trim($path), '/\\'));
    }
}
