<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\OffloadLocalCopyShrinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class StorageShrinkOffloadLocalCopies extends Command
{
    protected $signature = 'storage:shrink-offload-local-copies
        {--dry-run : Build a shrink plan for local offload copies only}
        {--execute : Execute shrink from an existing plan}
        {--disk=s3 : Target non-local disk that already holds verified remote_copy coverage}
        {--plan= : Absolute or storage-relative plan path required for execute mode}
        {--json : Emit the full payload as JSON}';

    protected $description = 'Manual-only cleanup for local offload copies that are already fully covered by a non-local verified remote_copy.';

    public function __construct(
        private readonly OffloadLocalCopyShrinkService $service,
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

        $disk = trim((string) ($this->option('disk') ?? 's3'));

        try {
            $this->ensureTargetDiskIsRemote($disk);

            if ($dryRun) {
                $plan = $this->service->buildPlan($disk);
                $planPath = $this->persistPlan($plan);
                $payload = $plan + ['plan' => $planPath];

                return $this->emitPayload($payload);
            }

            $planPath = trim((string) ($this->option('plan') ?? ''));
            if ($planPath === '') {
                $this->error('--execute requires --plan.');

                return self::FAILURE;
            }

            $resolvedPlanPath = $this->resolvePlanPath($planPath);
            $plan = $this->readPlan($resolvedPlanPath);
            $planDisk = trim((string) ($plan['target_disk'] ?? $plan['disk'] ?? ''));
            if ($planDisk !== $disk) {
                $this->error('plan disk mismatch: '.$planDisk.' != '.$disk);

                return self::FAILURE;
            }

            $plan['_meta'] = [
                'plan_path' => $resolvedPlanPath,
            ];
            $payload = $this->service->executePlan($plan);

            return $this->emitPayload($payload);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function ensureTargetDiskIsRemote(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver === '') {
            throw new \RuntimeException('shrink target disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('shrink target disk must be remote: local disks are not allowed.');
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode offload local copy shrink json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('disk='.(string) ($payload['disk'] ?? ''));
        $this->line('target_disk='.(string) ($payload['target_disk'] ?? ''));
        $this->line('schema='.(string) ($payload['schema'] ?? ''));
        $this->line('plan='.(string) ($payload['plan'] ?? ''));
        $this->line('both_candidate_count='.(int) ($summary['both_candidate_count'] ?? 0));
        $this->line('blocked_count='.(int) ($summary['blocked_count'] ?? 0));
        $this->line('local_only_count='.(int) ($summary['local_only_count'] ?? 0));
        $this->line('target_only_count='.(int) ($summary['target_only_count'] ?? 0));
        $this->line('deleted_local_files_count='.(int) ($summary['deleted_local_files_count'] ?? 0));
        $this->line('deleted_local_rows_count='.(int) ($summary['deleted_local_rows_count'] ?? 0));

        if (isset($payload['run_path'])) {
            $this->line('run_path='.(string) $payload['run_path']);
        }

        return (($payload['status'] ?? 'executed') === 'partial_failure') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/offload_local_copy_shrink_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_offload_local_copy_shrink_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode offload local copy shrink plan json.');
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
            throw new \RuntimeException('invalid offload local copy shrink plan json: '.$planPath);
        }

        if ((string) ($decoded['schema'] ?? '') !== 'storage_shrink_offload_local_copies_plan.v1') {
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
}
