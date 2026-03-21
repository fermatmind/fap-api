<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ExactRootRetirementOrchestratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageRetireExactRoots extends Command
{
    protected $signature = 'storage:retire-exact-roots
        {--dry-run : Build a retirement plan only}
        {--execute : Execute retirement from a validated plan}
        {--action= : Retirement action: quarantine or purge}
        {--disk= : Remote disk, defaults to storage_rollout.blob_offload_disk}
        {--plan= : Optional retirement plan path for execute mode}
        {--source-kind=* : Optional source_kind filters for dry-run mode}
        {--limit= : Optional max candidate count for dry-run mode}';

    protected $description = 'Batch-plan or batch-execute exact root retirement without changing runtime reader contracts.';

    public function __construct(
        private readonly ExactRootRetirementOrchestratorService $service,
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

        $action = trim((string) ($this->option('action') ?? ''));
        if (! in_array($action, ['quarantine', 'purge'], true)) {
            $this->error('--action must be quarantine or purge.');

            return self::FAILURE;
        }

        $disk = trim((string) ($this->option('disk') ?? ''));
        if ($disk === '') {
            $disk = trim((string) config('storage_rollout.blob_offload_disk', 's3'));
        }

        $planOption = trim((string) ($this->option('plan') ?? ''));
        $sourceKinds = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), (array) ($this->option('source-kind') ?? [])),
            static fn (string $value): bool => $value !== ''
        ));
        $limitOption = trim((string) ($this->option('limit') ?? ''));

        if ($dryRun && $planOption !== '') {
            $this->error('--plan is only supported with --execute.');

            return self::FAILURE;
        }

        if ($execute && $planOption === '') {
            $this->error('--execute requires --plan.');

            return self::FAILURE;
        }

        if ($execute && ($sourceKinds !== [] || $limitOption !== '')) {
            $this->error('--source-kind and --limit are only supported with --dry-run.');

            return self::FAILURE;
        }

        $limit = null;
        if ($limitOption !== '') {
            if (! ctype_digit($limitOption) || (int) $limitOption <= 0) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }

            $limit = (int) $limitOption;
        }

        try {
            $planPath = '';
            if ($dryRun) {
                $plan = $this->service->buildPlan($action, $disk, $sourceKinds, $limit);
                $planPath = $this->persistPlan($plan);
                $this->emitDryRunOutput($planPath, $plan);
                $this->recordAudit('planned', $planPath, $plan, [
                    'status' => 'planned',
                    'run_id' => null,
                    'run_dir' => null,
                    'success_count' => 0,
                    'failure_count' => 0,
                    'blocked_count' => (int) data_get($plan, 'summary.blocked_count', 0),
                    'skipped_count' => (int) data_get($plan, 'summary.skipped_count', 0),
                    'results' => [],
                ], 'success');

                return self::SUCCESS;
            }

            $planPath = $planOption;
            $plan = $this->loadPlan($planPath, $disk);
            $result = $this->service->executePlan($plan);
            $resultLabel = (int) ($result['failure_count'] ?? 0) > 0 ? 'failure' : 'success';
            $this->emitExecuteOutput($planPath, $plan, $result);
            $this->recordAudit('executed', $planPath, $plan, $result, $resultLabel);

            return $resultLabel === 'success' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            if (isset($planPath, $plan) && is_array($plan)) {
                $this->recordAudit('failed', $planPath, $plan, [
                    'status' => 'failure',
                    'run_id' => null,
                    'run_dir' => null,
                    'success_count' => 0,
                    'failure_count' => 1,
                    'blocked_count' => 0,
                    'skipped_count' => 0,
                    'results' => [],
                    'error' => $e->getMessage(),
                ], 'failure');
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPlan(string $planPath, string $disk): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('retirement plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('failed to decode retirement plan json.');
        }

        $schema = trim((string) ($decoded['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.retirement_plan_schema_version', 'storage_retire_exact_roots_plan.v1')) {
            throw new \RuntimeException('invalid retirement plan schema: '.$schema);
        }

        if (trim((string) ($decoded['disk'] ?? '')) !== $disk) {
            throw new \RuntimeException('retirement plan disk does not match requested disk.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/retirement_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_retire_exact_roots_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode retirement plan json.');
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

        $this->line('status=planned');
        $this->line('action='.(string) ($plan['action'] ?? ''));
        $this->line('disk='.(string) ($plan['disk'] ?? ''));
        $this->line('candidate_count='.(int) ($summary['candidate_count'] ?? 0));
        $this->line('blocked_count='.(int) ($summary['blocked_count'] ?? 0));
        $this->line('skipped_count='.(int) ($summary['skipped_count'] ?? 0));
        $this->line('candidate_file_count='.(int) ($summary['candidate_file_count'] ?? 0));
        $this->line('candidate_total_size_bytes='.(int) ($summary['candidate_total_size_bytes'] ?? 0));
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function emitExecuteOutput(string $planPath, array $plan, array $result): void
    {
        $this->line('status='.(string) ($result['status'] ?? 'failure'));
        $this->line('action='.(string) ($plan['action'] ?? ''));
        $this->line('disk='.(string) ($plan['disk'] ?? ''));
        $this->line('success_count='.(int) ($result['success_count'] ?? 0));
        $this->line('failure_count='.(int) ($result['failure_count'] ?? 0));
        $this->line('blocked_count='.(int) ($result['blocked_count'] ?? 0));
        $this->line('skipped_count='.(int) ($result['skipped_count'] ?? 0));
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
            'action' => 'storage_retire_exact_roots',
            'target_type' => 'storage',
            'target_id' => 'exact_roots',
            'meta_json' => json_encode([
                'mode' => $mode,
                'plan_path' => $planPath,
                'plan' => $plan,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_retire_exact_roots',
            'request_id' => null,
            'reason' => 'exact_root_retirement',
            'result' => $resultLabel,
            'created_at' => now(),
        ]);
    }
}
