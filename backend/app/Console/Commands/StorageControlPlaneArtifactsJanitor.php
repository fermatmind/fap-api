<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\StorageControlPlaneArtifactsJanitorService;
use Illuminate\Console\Command;

final class StorageControlPlaneArtifactsJanitor extends Command
{
    protected $signature = 'storage:janitor-control-plane-artifacts
        {--dry-run : Preview eligible control-plane artifact deletions only}
        {--execute : Delete eligible control-plane artifact files}';

    protected $description = 'Janitor rebuildable control-plane snapshots and plan artifacts without touching runtime or receipt paths.';

    public function __construct(
        private readonly StorageControlPlaneArtifactsJanitorService $service,
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

        $payload = $this->service->run($execute);
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('schema='.(string) ($payload['schema'] ?? ''));
        $this->line('generated_at='.(string) ($payload['generated_at'] ?? ''));
        $this->line('scanned_file_count='.(int) ($summary['scanned_file_count'] ?? 0));
        $this->line('kept_file_count='.(int) ($summary['kept_file_count'] ?? 0));
        $this->line('candidate_delete_count='.(int) ($summary['candidate_delete_count'] ?? 0));
        $this->line('deleted_file_count='.(int) ($summary['deleted_file_count'] ?? 0));

        return self::SUCCESS;
    }
}
