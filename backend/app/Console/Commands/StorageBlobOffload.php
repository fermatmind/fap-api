<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\BlobOffloadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageBlobOffload extends Command
{
    protected $signature = 'storage:blob-offload
        {--dry-run : Build an offload plan only}
        {--execute : Upload blobs copy-only using a generated or supplied plan}
        {--disk= : Target disk, defaults to storage_rollout.blob_offload_disk}
        {--plan= : Existing offload plan path for execute mode}';

    protected $description = 'Copy rollout blobs to a remote location and record verified location metadata without changing runtime readers.';

    public function __construct(
        private readonly BlobOffloadService $service,
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
            $disk = trim((string) config('storage_rollout.blob_offload_disk', 'local'));
        }

        try {
            $this->ensureTargetDiskIsRemote($disk);

            if ($dryRun) {
                $plan = $this->service->buildPlan($disk);
                $planPath = $this->persistPlan($plan);
                $this->emitDryRunOutput($disk, $planPath, $plan);
                $this->recordAudit('planned', $disk, $planPath, $plan, [
                    'uploaded_count' => 0,
                    'verified_count' => 0,
                    'failed_count' => 0,
                    'bytes' => 0,
                ]);

                return self::SUCCESS;
            }

            $this->ensureExecuteDiskConfigured($disk);

            $planPath = trim((string) ($this->option('plan') ?? ''));
            if ($planPath !== '') {
                $planPath = $this->resolvePlanPath($planPath);
                $plan = $this->readPlan($planPath);
                $planDisk = trim((string) ($plan['disk'] ?? ''));
                if ($planDisk !== '' && $planDisk !== $disk) {
                    $this->error('plan disk mismatch: '.$planDisk.' != '.$disk);

                    return self::FAILURE;
                }
            } else {
                $plan = $this->service->buildPlan($disk);
                $planPath = $this->persistPlan($plan);
            }

            $result = $this->service->executePlan($plan);
            $this->emitExecuteOutput($disk, $planPath, $plan, $result);
            $this->recordAudit('executed', $disk, $planPath, $plan, $result);

            return (int) ($result['failed_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function ensureTargetDiskIsRemote(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver === '') {
            throw new \RuntimeException('offload disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('blob offload target disk must be remote: local disks are not allowed.');
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
            throw new \RuntimeException('s3 offload disk is not configured: filesystems.disks.'.$disk.'.bucket is empty.');
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/offload_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_blob_offload_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode blob offload plan json.');
        }

        File::put($planPath, $encoded.PHP_EOL);

        return $planPath;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPlan(string $planPath): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('invalid offload plan json: '.$planPath);
        }

        $expectedSchema = (string) config('storage_rollout.blob_offload_plan_schema_version', 'storage_blob_offload_plan.v1');
        if ((string) ($decoded['schema'] ?? '') !== $expectedSchema) {
            throw new \RuntimeException('plan schema mismatch.');
        }

        return $decoded;
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

    /**
     * @param  array<string,mixed>  $plan
     */
    private function emitDryRunOutput(string $disk, string $planPath, array $plan): void
    {
        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];

        $this->line('status=planned');
        $this->line('disk='.$disk);
        $this->line('target_disk='.(string) ($summary['target_disk'] ?? $disk));
        $this->line('copy_only=true');
        $this->line('surface=coverage_convergence_backfill');
        $this->line('plan='.$planPath);
        $this->line('reachable_blob_count='.(int) ($summary['reachable_blob_count'] ?? 0));
        $this->line('verified_remote_copy_counts_by_disk='.$this->formatCountsByDisk($summary['verified_remote_copy_counts_by_disk'] ?? []));
        $this->line('local_only_count='.(int) ($summary['local_only_count'] ?? 0));
        $this->line('target_only_count='.(int) ($summary['target_only_count'] ?? 0));
        $this->line('both_count='.(int) ($summary['both_count'] ?? 0));
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('skipped_count='.(int) ($summary['skipped_count'] ?? 0));
        $this->line('bytes='.(int) ($summary['bytes'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function emitExecuteOutput(string $disk, string $planPath, array $plan, array $result): void
    {
        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];

        $this->line('status=executed');
        $this->line('disk='.$disk);
        $this->line('target_disk='.(string) ($summary['target_disk'] ?? $disk));
        $this->line('copy_only=true');
        $this->line('surface=coverage_convergence_backfill');
        $this->line('plan='.$planPath);
        $this->line('reachable_blob_count='.(int) ($summary['reachable_blob_count'] ?? 0));
        $this->line('verified_remote_copy_counts_by_disk='.$this->formatCountsByDisk($summary['verified_remote_copy_counts_by_disk'] ?? []));
        $this->line('local_only_count='.(int) ($summary['local_only_count'] ?? 0));
        $this->line('target_only_count='.(int) ($summary['target_only_count'] ?? 0));
        $this->line('both_count='.(int) ($summary['both_count'] ?? 0));
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('uploaded_count='.(int) ($result['uploaded_count'] ?? 0));
        $this->line('verified_count='.(int) ($result['verified_count'] ?? 0));
        $this->line('skipped_count='.(int) ($result['skipped_count'] ?? 0));
        $this->line('failed_count='.(int) ($result['failed_count'] ?? 0));
        $this->line('bytes='.(int) ($result['bytes'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function recordAudit(string $mode, string $disk, string $planPath, array $plan, array $result): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_blob_offload',
            'target_type' => 'storage',
            'target_id' => 'blob_offload',
            'meta_json' => json_encode([
                'schema' => $plan['schema'] ?? null,
                'mode' => $mode,
                'disk' => $disk,
                'target_disk' => $summary['target_disk'] ?? $disk,
                'plan' => $planPath,
                'reachable_blob_count' => (int) ($summary['reachable_blob_count'] ?? 0),
                'verified_remote_copy_counts_by_disk' => is_array($summary['verified_remote_copy_counts_by_disk'] ?? null)
                    ? $summary['verified_remote_copy_counts_by_disk']
                    : [],
                'local_only_count' => (int) ($summary['local_only_count'] ?? 0),
                'target_only_count' => (int) ($summary['target_only_count'] ?? 0),
                'both_count' => (int) ($summary['both_count'] ?? 0),
                'candidate_count' => (int) ($summary['candidate_count'] ?? 0),
                'skipped_count' => $mode === 'planned'
                    ? (int) ($summary['skipped_count'] ?? 0)
                    : (int) ($result['skipped_count'] ?? 0),
                'bytes' => $mode === 'planned'
                    ? (int) ($summary['bytes'] ?? 0)
                    : (int) ($result['bytes'] ?? 0),
                'uploaded_count' => (int) ($result['uploaded_count'] ?? 0),
                'verified_count' => (int) ($result['verified_count'] ?? 0),
                'failed_count' => (int) ($result['failed_count'] ?? 0),
                'warnings' => $result['warnings'] ?? [],
                'copy_only' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_blob_offload',
            'request_id' => null,
            'reason' => 'blob_offload_copy_only',
            'result' => (int) ($result['failed_count'] ?? 0) > 0 ? 'failed' : 'success',
            'created_at' => now(),
        ]);
    }

    private function formatCountsByDisk(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'none';
        }

        $counts = [];
        foreach ($value as $disk => $count) {
            $name = trim((string) $disk);
            if ($name === '') {
                continue;
            }

            $counts[$name] = (int) $count;
        }

        if ($counts === []) {
            return 'none';
        }

        ksort($counts);

        return collect($counts)
            ->map(static fn (int $count, string $name): string => $name.':'.$count)
            ->implode(',');
    }
}
