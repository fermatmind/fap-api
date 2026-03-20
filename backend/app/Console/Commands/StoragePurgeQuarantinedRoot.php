<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\QuarantinedRootPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StoragePurgeQuarantinedRoot extends Command
{
    protected $signature = 'storage:purge-quarantined-root
        {--dry-run : Build a purge plan only}
        {--execute : Execute purge from a validated plan}
        {--item-root= : Quarantined item root directory}
        {--plan= : Optional purge plan path}
        {--disk= : Remote disk, defaults to storage_rollout.blob_offload_disk}';

    protected $description = 'Permanently delete a quarantined legacy source_pack root after restore-safe revalidation.';

    public function __construct(
        private readonly QuarantinedRootPurgeService $service,
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

        $itemRootOption = $this->normalizeRoot((string) ($this->option('item-root') ?? ''));
        $planOption = trim((string) ($this->option('plan') ?? ''));
        $disk = trim((string) ($this->option('disk') ?? ''));
        if ($disk === '') {
            $disk = trim((string) config('storage_rollout.blob_offload_disk', 's3'));
        }

        if ($dryRun && $itemRootOption === '') {
            $this->error('--dry-run requires --item-root.');

            return self::FAILURE;
        }

        if ($dryRun && $planOption !== '') {
            $this->error('--plan is only supported with --execute.');

            return self::FAILURE;
        }

        if ($execute && $planOption === '') {
            $this->error('--execute requires --plan.');

            return self::FAILURE;
        }

        try {
            $planPath = '';
            if ($execute) {
                $planPath = $planOption;
                $loadedPlan = $this->loadPlan($planPath, $disk);
                $resolvedItemRoot = $this->resolveItemRoot($itemRootOption, $loadedPlan);
                $plan = $this->service->buildPlan($resolvedItemRoot, $disk);
                $result = $this->service->executePlan($loadedPlan, $resolvedItemRoot);
                $status = (string) ($result['status'] ?? 'failure');
                $this->emitExecuteOutput($planPath, $plan, $result, $status);
                $this->recordAudit('executed', $planPath, $plan, $result, $status === 'success' ? 'success' : 'failure');

                return $status === 'success' ? self::SUCCESS : self::FAILURE;
            }

            $plan = $this->service->buildPlan($itemRootOption, $disk);
            $planPath = $this->persistPlan($plan);
            $status = (string) ($plan['status'] ?? 'blocked');
            $this->emitDryRunOutput($planPath, $plan, $status);
            $this->recordAudit('planned', $planPath, $plan, [
                'run_id' => null,
                'run_dir' => null,
                'status' => $status,
                'error' => $status === 'planned' ? null : (string) ($plan['blocked_reason'] ?? 'purge blocked'),
            ], $status === 'planned' ? 'success' : 'failure');

            return $status === 'planned' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $result = [
                'run_id' => null,
                'run_dir' => null,
                'status' => 'failure',
                'error' => $e->getMessage(),
            ];

            if (isset($planPath, $plan) && is_array($plan)) {
                $this->recordAudit('failed', $planPath, $plan, $result, 'failure');
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $loadedPlan
     */
    private function resolveItemRoot(string $itemRootOption, array $loadedPlan): string
    {
        $planItemRoot = $this->normalizeRoot((string) ($loadedPlan['item_root'] ?? ''));
        if ($itemRootOption !== '' && $planItemRoot !== '' && $itemRootOption !== $planItemRoot) {
            throw new \RuntimeException('purge plan item_root does not match requested item root.');
        }

        $resolved = $itemRootOption !== '' ? $itemRootOption : $planItemRoot;
        if ($resolved === '') {
            throw new \RuntimeException('purge item root is required.');
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPlan(string $planPath, string $disk): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('purge plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('failed to decode purge plan json.');
        }

        $schema = trim((string) ($decoded['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.purge_plan_schema_version', 'storage_purge_quarantined_root_plan.v1')) {
            throw new \RuntimeException('invalid purge plan schema: '.$schema);
        }

        if (trim((string) ($decoded['disk'] ?? '')) !== $disk) {
            throw new \RuntimeException('purge plan disk does not match requested disk.');
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/quarantine_purge_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_quarantined_root_purge_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode purge plan json.');
        }

        File::put($planPath, $encoded.PHP_EOL);

        return $planPath;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function emitDryRunOutput(string $planPath, array $plan, string $status): void
    {
        $this->line('status='.$status);
        $this->line('item_root='.(string) ($plan['item_root'] ?? ''));
        $this->line('exact_manifest_id='.(string) ($plan['exact_manifest_id'] ?? ''));
        $this->line('source_kind='.(string) ($plan['source_kind'] ?? ''));
        $this->line('file_count='.(int) ($plan['file_count'] ?? 0));
        $this->line('total_bytes='.(int) ($plan['total_size_bytes'] ?? 0));
        if ($status !== 'planned') {
            $this->line('blocked_reason='.(string) ($plan['blocked_reason'] ?? 'purge blocked'));
        }
        $this->line('plan='.$planPath);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $result
     */
    private function emitExecuteOutput(string $planPath, array $plan, array $result, string $status): void
    {
        $this->line('status='.$status);
        $this->line('item_root='.(string) ($plan['item_root'] ?? ''));
        $this->line('exact_manifest_id='.(string) ($plan['exact_manifest_id'] ?? ''));
        $this->line('source_kind='.(string) ($plan['source_kind'] ?? ''));
        $this->line('purged_root_count='.(int) ($result['purged_root_count'] ?? 0));
        $this->line('failed_root_count='.(int) ($result['failed_root_count'] ?? 0));
        $this->line('purged_file_count='.(int) ($result['purged_file_count'] ?? 0));
        $this->line('purged_total_size_bytes='.(int) ($result['purged_total_size_bytes'] ?? 0));
        $this->line('run_dir='.(string) ($result['run_dir'] ?? ''));
        $this->line('receipt='.(string) ($result['receipt_path'] ?? ''));
        if ($status !== 'success') {
            $this->line('reason='.(string) ($result['error'] ?? 'purge failed'));
        }
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
            'action' => 'storage_purge_quarantined_root',
            'target_type' => 'storage',
            'target_id' => 'quarantined_root',
            'meta_json' => json_encode([
                'mode' => $mode,
                'plan_path' => $planPath,
                'plan' => $plan,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_purge_quarantined_root',
            'request_id' => null,
            'reason' => 'quarantined_root_purge',
            'result' => $resultLabel,
            'created_at' => now(),
        ]);
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
