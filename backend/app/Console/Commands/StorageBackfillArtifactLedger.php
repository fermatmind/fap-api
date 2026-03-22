<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ArtifactLedgerBackfillService;
use Illuminate\Console\Command;

final class StorageBackfillArtifactLedger extends Command
{
    protected $signature = 'storage:backfill-artifact-ledger
        {--dry-run : Scan only and report summary}
        {--execute : Execute idempotent historical artifact ledger backfill}
        {--attempt-id= : Narrow to a single attempt_id}
        {--path-root= : Narrow the filesystem scan root}
        {--limit= : Limit the number of candidates processed}';

    protected $description = 'Backfill historical artifact ledger rows for report JSON/PDF storage and snapshots.';

    public function __construct(
        private readonly ArtifactLedgerBackfillService $service,
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
        $this->line('candidate_count='.(int) ($payload['candidate_count'] ?? 0));
        $this->line('classification_counts='.json_encode($payload['classification_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('slot_counts='.json_encode($payload['slot_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('source_counts='.json_encode($payload['source_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('alias_or_legacy_path_count='.(int) ($payload['alias_or_legacy_path_count'] ?? 0));
        $this->line('manual_or_test_owned_count='.(int) ($payload['manual_or_test_owned_count'] ?? 0));
        $this->line('nohash_count='.(int) ($payload['nohash_count'] ?? 0));

        if ($execute) {
            $this->line('slots_upserted='.(int) ($payload['slots_upserted'] ?? 0));
            $this->line('versions_inserted='.(int) ($payload['versions_inserted'] ?? 0));
            $this->line('versions_reused='.(int) ($payload['versions_reused'] ?? 0));
            $this->line('blobs_upserted='.(int) ($payload['blobs_upserted'] ?? 0));
            $this->line('blob_locations_upserted='.(int) ($payload['blob_locations_upserted'] ?? 0));
            $this->line('attempt_receipts_inserted='.(int) ($payload['attempt_receipts_inserted'] ?? 0));
            $this->line('attempt_receipts_reused='.(int) ($payload['attempt_receipts_reused'] ?? 0));
        } else {
            $this->line('slot_backfillable_count='.(int) ($payload['slot_backfillable_count'] ?? 0));
            $this->line('version_backfillable_count='.(int) ($payload['version_backfillable_count'] ?? 0));
            $this->line('attempt_receipts_backfillable_count='.(int) ($payload['attempt_receipts_backfillable_count'] ?? 0));
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
            'path_root' => $this->option('path-root'),
            'limit' => $this->option('limit'),
        ];
    }
}
