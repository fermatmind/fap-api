<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\AttemptReceiptBackfillService;
use Illuminate\Console\Command;

final class StorageBackfillAttemptReceipts extends Command
{
    protected $signature = 'storage:backfill-attempt-receipts
        {--dry-run : Scan only and report summary}
        {--execute : Execute idempotent attempt receipt backfill}
        {--attempt-id= : Narrow to a single attempt_id}
        {--limit= : Limit the number of candidates processed}';

    protected $description = 'Backfill attempt receipts from durable audit evidence without changing primary behavior.';

    public function __construct(
        private readonly AttemptReceiptBackfillService $service,
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

        $filters = $this->filters();
        $payload = $execute ? $this->service->executeReplay($filters) : $this->service->buildReplayPlan($filters);

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('audit_rows_scanned='.(int) ($payload['audit_rows_scanned'] ?? 0));
        $this->line('receipt_candidates='.(int) ($payload['receipt_candidates'] ?? 0));
        $this->line('receipt_types='.json_encode($payload['receipt_types'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('actions='.json_encode($payload['actions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if ($execute) {
            $this->line('attempt_receipts_inserted='.(int) ($payload['attempt_receipts_inserted'] ?? 0));
            $this->line('attempt_receipts_reused='.(int) ($payload['attempt_receipts_reused'] ?? 0));
        } else {
            $this->line('unique_attempt_ids='.(int) ($payload['unique_attempt_ids'] ?? 0));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'attempt_id' => $this->option('attempt-id'),
            'limit' => $this->option('limit'),
        ];
    }
}
