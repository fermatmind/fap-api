<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\MaterializedCacheJanitorService;
use Illuminate\Console\Command;

final class StorageJanitorMaterializedCache extends Command
{
    protected $signature = 'storage:janitor-materialized-cache
        {--dry-run : Preview provably rebuildable materialized cache buckets only}
        {--execute : Delete provably rebuildable whole materialized cache buckets}
        {--json : Emit the full janitor payload as JSON}';

    protected $description = 'Manual-only janitor for whole materialized cache buckets that are currently provably rebuildable.';

    public function __construct(
        private readonly MaterializedCacheJanitorService $service,
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

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode materialized cache janitor json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('schema='.(string) ($payload['schema'] ?? ''));
        $this->line('generated_at='.(string) ($payload['generated_at'] ?? ''));
        $this->line('scanned_bucket_count='.(int) ($summary['scanned_bucket_count'] ?? 0));
        $this->line('candidate_delete_count='.(int) ($summary['candidate_delete_count'] ?? 0));
        $this->line('deleted_bucket_count='.(int) ($summary['deleted_bucket_count'] ?? 0));
        $this->line('skipped_bucket_count='.(int) ($summary['skipped_bucket_count'] ?? 0));

        return self::SUCCESS;
    }
}
