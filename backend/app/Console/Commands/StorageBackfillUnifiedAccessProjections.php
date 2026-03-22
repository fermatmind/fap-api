<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\UnifiedAccessProjectionBackfillService;
use Illuminate\Console\Command;

final class StorageBackfillUnifiedAccessProjections extends Command
{
    protected $signature = 'storage:backfill-unified-access-projections
        {--dry-run : Scan only and report summary}
        {--execute : Execute idempotent unified access projection backfill}
        {--attempt-id= : Narrow to a single attempt_id}
        {--limit= : Limit the number of candidates processed}';

    protected $description = 'Backfill historical unified access projections from entitlement and report evidence.';

    public function __construct(
        private readonly UnifiedAccessProjectionBackfillService $service,
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
        $payload = $execute ? $this->service->executeBackfill($filters) : $this->service->buildPlan($filters);

        $this->line('status='.(string) ($payload['status'] ?? ''));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('attempt_count='.(int) ($payload['attempt_count'] ?? 0));
        $this->line('access_ready_count='.(int) ($payload['access_ready_count'] ?? 0));
        $this->line('report_ready_count='.(int) ($payload['report_ready_count'] ?? 0));
        $this->line('pdf_ready_count='.(int) ($payload['pdf_ready_count'] ?? 0));
        $this->line('projection_count='.(int) ($payload['projection_count'] ?? 0));
        $this->line('grant_count='.(int) ($payload['grant_count'] ?? 0));
        $this->line('order_count='.(int) ($payload['order_count'] ?? 0));
        $this->line('payment_event_count='.(int) ($payload['payment_event_count'] ?? 0));
        $this->line('share_count='.(int) ($payload['share_count'] ?? 0));
        $this->line('report_snapshot_count='.(int) ($payload['report_snapshot_count'] ?? 0));
        $this->line('slot_count='.(int) ($payload['slot_count'] ?? 0));

        if ($execute) {
            $this->line('attempt_receipts_inserted='.(int) ($payload['attempt_receipts_inserted'] ?? 0));
            $this->line('attempt_receipts_reused='.(int) ($payload['attempt_receipts_reused'] ?? 0));
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
