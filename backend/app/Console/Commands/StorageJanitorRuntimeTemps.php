<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\RuntimeTempJanitorService;
use Illuminate\Console\Command;

final class StorageJanitorRuntimeTemps extends Command
{
    protected $signature = 'storage:janitor-runtime-temps
        {--dry-run : Preview allowlisted runtime temp file deletions only}
        {--execute : Delete allowlisted runtime temp files}
        {--json : Emit the full janitor payload as JSON}';

    protected $description = 'Manual-only janitor for allowlisted runtime temp files under storage/logs and storage/framework cache/views.';

    public function __construct(
        private readonly RuntimeTempJanitorService $service,
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
                $this->error('failed to encode runtime temp janitor json.');

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
        $this->line('scanned_file_count='.(int) ($summary['scanned_file_count'] ?? 0));
        $this->line('candidate_delete_count='.(int) ($summary['candidate_delete_count'] ?? 0));
        $this->line('deleted_file_count='.(int) ($summary['deleted_file_count'] ?? 0));
        $this->line('skipped_file_count='.(int) ($summary['skipped_file_count'] ?? 0));

        return self::SUCCESS;
    }
}
