<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\QuarantinedRootRestoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageRestoreQuarantinedRoot extends Command
{
    protected $signature = 'storage:restore-quarantined-root
        {--dry-run : Build a restore plan only}
        {--execute : Execute restore from a validated plan or item root}
        {--item-root= : Quarantined item root directory}
        {--plan= : Optional restore plan path}';

    protected $description = 'Restore a quarantined legacy source_pack root back to its original source_storage_path without changing runtime readers.';

    public function __construct(
        private readonly QuarantinedRootRestoreService $service,
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
        if ($itemRootOption === '' && $planOption === '') {
            $this->error('either --item-root or --plan is required.');

            return self::FAILURE;
        }

        try {
            $loadedPlan = null;
            if ($planOption !== '') {
                $loadedPlan = $this->loadPlan($planOption);
            }

            $resolvedItemRoot = $this->resolveItemRoot($itemRootOption, $loadedPlan);
            $plan = $this->service->buildPlan($resolvedItemRoot);
            $planPath = $this->persistPlan($plan);

            if ($dryRun) {
                $status = (string) ($plan['status'] ?? 'blocked');
                $this->emitDryRunOutput($planPath, $plan, $status);
                $this->recordAudit('planned', $planPath, $plan, [
                    'run_id' => null,
                    'run_dir' => null,
                    'status' => $status,
                    'error' => $status === 'planned' ? null : (string) ($plan['blocked_reason'] ?? 'restore blocked'),
                ], $status === 'planned' ? 'success' : 'failure');

                return $status === 'planned' ? self::SUCCESS : self::FAILURE;
            }

            if ($loadedPlan === null) {
                $loadedPlan = $plan;
            }

            $result = $this->service->executePlan($loadedPlan, $resolvedItemRoot);
            $status = (string) ($result['status'] ?? 'failure');
            $this->emitExecuteOutput($planPath, $plan, $result, $status);
            $this->recordAudit('executed', $planPath, $plan, $result, $status === 'success' ? 'success' : 'failure');

            return $status === 'success' ? self::SUCCESS : self::FAILURE;
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
     * @param  array<string,mixed>|null  $loadedPlan
     */
    private function resolveItemRoot(string $itemRootOption, ?array $loadedPlan): string
    {
        $planItemRoot = $this->normalizeRoot((string) ($loadedPlan['item_root'] ?? ''));
        if ($itemRootOption !== '' && $planItemRoot !== '' && $itemRootOption !== $planItemRoot) {
            throw new \RuntimeException('restore plan item_root does not match requested item root.');
        }

        $resolved = $itemRootOption !== '' ? $itemRootOption : $planItemRoot;
        if ($resolved === '') {
            throw new \RuntimeException('restore item root is required.');
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadPlan(string $planPath): array
    {
        if (! is_file($planPath)) {
            throw new \RuntimeException('restore plan not found: '.$planPath);
        }

        $decoded = json_decode((string) File::get($planPath), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('failed to decode restore plan json.');
        }

        $schema = trim((string) ($decoded['schema'] ?? ''));
        if ($schema !== (string) config('storage_rollout.restore_plan_schema_version', 'storage_restore_quarantined_root_plan.v1')) {
            throw new \RuntimeException('invalid restore plan schema: '.$schema);
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function persistPlan(array $plan): string
    {
        $planDir = storage_path('app/private/quarantine_restore_plans');
        File::ensureDirectoryExists($planDir);

        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_quarantined_root_restore_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode restore plan json.');
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
        $this->line('exact_manifest_id='.(string) ($plan['exact_manifest_id'] ?? ''));
        $this->line('source_kind='.(string) ($plan['source_kind'] ?? ''));
        $this->line('item_root='.(string) ($plan['item_root'] ?? ''));
        $this->line('target_root='.(string) ($plan['target_root'] ?? ''));
        $this->line('file_count='.(int) ($plan['file_count'] ?? 0));
        $this->line('total_bytes='.(int) ($plan['total_bytes'] ?? 0));
        if ($status !== 'planned') {
            $this->line('blocked_reason='.(string) ($plan['blocked_reason'] ?? 'restore blocked'));
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
        $this->line('exact_manifest_id='.(string) ($plan['exact_manifest_id'] ?? ''));
        $this->line('source_kind='.(string) ($plan['source_kind'] ?? ''));
        $this->line('item_root='.(string) ($plan['item_root'] ?? ''));
        $this->line('target_root='.(string) ($result['target_root'] ?? ($plan['target_root'] ?? '')));
        $this->line('restored_files='.(int) ($result['file_count'] ?? 0));
        $this->line('bytes='.(int) ($result['total_bytes'] ?? 0));
        $this->line('run_dir='.(string) ($result['run_dir'] ?? ''));
        if ($status !== 'success') {
            $this->line('error='.(string) ($result['error'] ?? 'restore failed'));
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
            'action' => 'storage_restore_quarantined_root',
            'target_type' => 'storage',
            'target_id' => 'quarantined_root',
            'meta_json' => json_encode([
                'mode' => $mode,
                'plan_path' => $planPath,
                'plan' => $plan,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_restore_quarantined_root',
            'request_id' => null,
            'reason' => 'quarantined_root_restore',
            'result' => $resultLabel,
            'created_at' => now(),
        ]);
    }

    private function normalizeRoot(string $root): string
    {
        return str_replace('\\', '/', rtrim(trim($root), '/\\'));
    }
}
