<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\ReleaseMetadataBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageBackfillReleaseMetadata extends Command
{
    protected $signature = 'storage:backfill-release-metadata
        {--dry-run : Scan only and report summary}
        {--execute : Execute idempotent metadata backfill}';

    protected $description = 'Backfill historical release/artifact rollout metadata without touching runtime bytes.';

    public function __construct(
        private readonly ReleaseMetadataBackfillService $service,
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
        $warningCount = is_array($payload['warnings'] ?? null) ? count($payload['warnings']) : 0;

        $this->line('status='.(($payload['mode'] ?? '') === 'execute' ? 'executed' : 'planned'));
        $this->line('mode='.(string) ($payload['mode'] ?? ''));
        $this->line('release_rows_scanned='.(int) ($payload['release_rows_scanned'] ?? 0));
        if (($payload['mode'] ?? '') === 'execute') {
            $this->line('release_rows_backfilled='.(int) ($payload['release_rows_backfilled'] ?? 0));
            $this->line('manifests_upserted='.(int) ($payload['manifests_upserted'] ?? 0));
            $this->line('manifest_files_upserted='.(int) ($payload['manifest_files_upserted'] ?? 0));
            $this->line('blobs_upserted='.(int) ($payload['blobs_upserted'] ?? 0));
        } else {
            $this->line('release_rows_backfillable='.(int) ($payload['release_rows_backfillable'] ?? 0));
            $this->line('artifact_files_scanned='.(int) ($payload['artifact_files_scanned'] ?? 0));
        }
        $this->line('warnings='.$warningCount);

        $this->recordAudit($payload);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordAudit(array $payload): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_backfill_release_metadata',
            'target_type' => 'storage',
            'target_id' => 'release_metadata',
            'meta_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'cli/storage_backfill_release_metadata',
            'request_id' => null,
            'reason' => 'historical_metadata_backfill',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
