<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\BlobReachabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class StorageBlobGc extends Command
{
    protected $signature = 'storage:blob-gc
        {--dry-run : Build conservative reachability plan only}';

    protected $description = 'Build a conservative blob reachability plan without executing destructive delete.';

    public function __construct(
        private readonly BlobReachabilityService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) $this->option('dry-run')) {
            $this->error('--dry-run is required. PR-14 does not implement execute mode.');

            return self::FAILURE;
        }

        $plan = $this->service->buildPlan();
        $planDir = storage_path('app/private/gc_plans');
        File::ensureDirectoryExists($planDir);
        $planPath = $planDir.DIRECTORY_SEPARATOR.now()->format('Ymd_His').'_blob_gc_'.substr(bin2hex(random_bytes(4)), 0, 8).'.json';
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed to encode gc plan json.');

            return self::FAILURE;
        }

        File::put($planPath, $encoded.PHP_EOL);

        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];
        $this->line('status=planned');
        $this->line('plan='.$planPath);
        $this->line('reachable_blobs='.(int) ($summary['reachable_blob_count'] ?? 0));
        $this->line('unreachable_blobs='.(int) ($summary['unreachable_blob_count'] ?? 0));
        $this->line('planned_deletions='.(int) ($summary['planned_deletion_count'] ?? 0));
        $this->line('dry_run_only=1');

        $this->recordAudit($planPath, $plan);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(string $planPath, array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_blob_gc',
            'target_type' => 'storage',
            'target_id' => 'blob_gc',
            'meta_json' => json_encode([
                'schema' => $payload['schema'] ?? null,
                'plan' => $planPath,
                'root_summary' => [
                    'root_release_count' => (int) ($summary['root_release_count'] ?? 0),
                    'root_activation_count' => (int) ($summary['root_activation_count'] ?? 0),
                    'root_snapshot_release_ref_count' => (int) ($summary['root_snapshot_release_ref_count'] ?? 0),
                    'root_artifact_count' => (int) ($summary['root_artifact_count'] ?? 0),
                ],
                'reachable_blob_count' => (int) ($summary['reachable_blob_count'] ?? 0),
                'unreachable_blob_count' => (int) ($summary['unreachable_blob_count'] ?? 0),
                'planned_deletion_count' => (int) ($summary['planned_deletion_count'] ?? 0),
                'reachable_bytes' => (int) ($summary['reachable_bytes'] ?? 0),
                'unreachable_bytes' => (int) ($summary['unreachable_bytes'] ?? 0),
                'planned_deletion_bytes' => (int) ($summary['planned_deletion_bytes'] ?? 0),
                'dry_run_only' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_blob_gc',
            'request_id' => null,
            'reason' => 'reachability_plan',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
