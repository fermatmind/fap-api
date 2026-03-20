<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ExactRootQuarantineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageQuarantineExactRoots extends Command
{
    protected $signature = 'storage:quarantine-exact-roots
        {--dry-run : Build a quarantine plan only}
        {--execute : Execute root-centric quarantine from a validated plan or online recompute}
        {--disk= : Remote disk, defaults to storage_rollout.blob_offload_disk}
        {--plan= : Optional quarantine plan path for execute mode}';

    protected $description = 'Move eligible exact content roots into a reversible quarantine area without changing runtime readers.';

    public function __construct(
        private readonly ExactRootQuarantineService $service,
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

        $planOption = trim((string) ($this->option('plan') ?? ''));
        if ($dryRun && $planOption !== '') {
            $this->error('--plan is only supported with --execute.');

            return self::FAILURE;
        }

        try {
            $this->ensureTargetDiskIsRemote($disk);

            $planPath = '';
            if ($execute && $planOption !== '') {
                $planPath = $planOption;
                $plan = $this->loadPlan($planPath, $disk);
            } else {
                $plan = $this->service->buildPlan($disk);
                $planPath = $this->persistPlan($plan);
            }

            if ($dryRun) {
                $this->emitDryRunOutput($planPath, $plan);
                $this->recordAudit('planned', $planPath, $plan, [
                    'run_id' => null,
                    'run_dir' => null,
                    'quarantined_root_count' => 0,
                    'failed_root_count' => 0,
                    'blocked_root_count' => (int) data_get($plan, 'summary.blocked_count', 0),
                    'quarantined_file_count' => 0,
                    'quarantined_total_size_bytes' => 0,
                ], 'success');

                return self::SUCCESS;
            }

            $result = $this->service->executePlan($plan);
            $resultLabel = (int) ($result['failed_root_count'] ?? 0) > 0 ? 'failure' : 'success';
            $status = $resultLabel === 'success' ? 'executed' : 'failure';
            $this->emitExecuteOutput($planPath, $plan, $result, $status);
            $this->recordAudit('executed', $planPath, $plan, $result, $resultLabel);

            return $resultLabel === 'success' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if (isset($planPath, $plan) && is_array($plan)) {
                $this->recordAudit('failed', $planPath, $plan, [
                    'run_id' => null,
                    'run_dir' => null,
                    'quarantined_root_count' => 0,
                    'failed_root_count' => 0,
                    'blocked_root_count' => (int) data_get($plan, 'summary.blocked_count', 0),
                    'quarantined_file_count' => 0,
                    'quarantined_total_size_bytes' => 0,
                    'error' => $e->getMessage(),
                ], 'failure');
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function ensureTargetDiskIsRemote(string $disk): void
    {
        $driver = trim((string) config('filesystems.disks.'.$disk.'.driver', ''));
        if ($driver === '') {
            throw new \RuntimeException('quarantine target disk is not configured: filesystems.disks.'.$disk.' is missing.');
        }

        if ($driver === 'local') {
            throw new \RuntimeException('quarantine target disk must be remote: local disks are not allowed.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPlan(string $planPath, string $disk): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('quarantine plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('failed to decode quarantine plan json.');
        }

        $schema = trim((string) ($decoded['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.quarantine_plan_schema_version', 'storage_quarantine_exact_roots_plan.v1')) {
            throw new \RuntimeException('invalid quarantine plan schema: '.$schema);
        }

        if (trim((string) ($decoded['target_disk'] ?? '')) !== $disk) {
            throw new \RuntimeException('quarantine plan disk does not match requested disk.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/quarantine_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_exact_root_quarantine_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode quarantine plan json.');
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
        $totals = is_array($plan['totals'] ?? null) ? $plan['totals'] : [];

        $this->line('status=planned');
        $this->line('disk='.(string) ($plan['target_disk'] ?? ''));
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('blocked_count='.(int) ($summary['blocked_count'] ?? 0));
        $this->line('skipped_count='.(int) ($summary['skipped_count'] ?? 0));
        $this->line('candidate_file_count='.(int) ($totals['candidate_file_count'] ?? 0));
        $this->line('candidate_total_size_bytes='.(int) ($totals['candidate_total_size_bytes'] ?? 0));
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function emitExecuteOutput(string $planPath, array $plan, array $result, string $status): void
    {
        $this->line('status='.$status);
        $this->line('disk='.(string) ($plan['target_disk'] ?? ''));
        $this->line('quarantined_root_count='.(int) ($result['quarantined_root_count'] ?? 0));
        $this->line('failed_root_count='.(int) ($result['failed_root_count'] ?? 0));
        $this->line('blocked_root_count='.(int) ($result['blocked_root_count'] ?? 0));
        $this->line('quarantined_file_count='.(int) ($result['quarantined_file_count'] ?? 0));
        $this->line('quarantined_total_size_bytes='.(int) ($result['quarantined_total_size_bytes'] ?? 0));
        $this->line('run_dir='.(string) ($result['run_dir'] ?? ''));
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function recordAudit(string $mode, string $planPath, array $plan, array $result, string $resultLabel): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_quarantine_exact_roots',
            'target_type' => 'storage',
            'target_id' => 'exact_roots',
            'meta_json' => json_encode([
                'mode' => $mode,
                'plan_path' => $planPath,
                'plan' => $plan,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_quarantine_exact_roots',
            'request_id' => null,
            'reason' => 'exact_root_quarantine',
            'result' => $resultLabel,
            'created_at' => now(),
        ]);
    }
}
