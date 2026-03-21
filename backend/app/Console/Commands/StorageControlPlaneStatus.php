<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\StorageControlPlaneStatusService;
use Illuminate\Console\Command;

final class StorageControlPlaneStatus extends Command
{
    protected $signature = 'storage:control-plane-status
        {--json : Emit the full control-plane status payload as JSON}';

    protected $description = 'Read-only storage control-plane status/reporting surface.';

    public function __construct(
        private readonly StorageControlPlaneStatusService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->service->buildStatus();

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode control-plane status json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $this->line('schema_version='.(string) ($payload['schema_version'] ?? ''));
        $this->line('generated_at='.(string) ($payload['generated_at'] ?? ''));
        $this->line('inventory.status='.(string) data_get($payload, 'inventory.status', 'not_available'));
        $this->line('retention.status='.(string) data_get($payload, 'retention.status', 'never_run'));
        $this->line('blob_coverage.storage_blobs='.(int) data_get($payload, 'blob_coverage.counts.storage_blobs', 0));
        $this->line('exact_authority.manifests='.(int) data_get($payload, 'exact_authority.counts.content_release_exact_manifests', 0));
        $this->line('rehydrate.status='.(string) data_get($payload, 'rehydrate.status', 'never_run'));
        $this->line('quarantine.item_root_count='.(int) data_get($payload, 'quarantine.item_root_count', 0));
        $this->line('restore.run_count='.(int) data_get($payload, 'restore.restore_run_count', 0));
        $this->line('purge.receipt_count='.(int) data_get($payload, 'purge.purge_receipt_count', 0));
        $this->line('retirement.quarantine.status='.(string) data_get($payload, 'retirement.actions.quarantine.status', 'never_run'));
        $this->line('retirement.purge.status='.(string) data_get($payload, 'retirement.actions.purge.status', 'never_run'));
        $this->line('runtime_truth.v2_readiness='.(string) data_get($payload, 'runtime_truth.v2_readiness', 'local_only'));
        $this->line('automation_readiness.auto_dry_run_ok='.(int) count((array) data_get($payload, 'automation_readiness.auto_dry_run_ok', [])));
        $this->line('automation_readiness.manual_execute_only='.(int) count((array) data_get($payload, 'automation_readiness.manual_execute_only', [])));
        $this->line('automation_readiness.not_in_scope_for_pr25='.(int) count((array) data_get($payload, 'automation_readiness.not_in_scope_for_pr25', [])));
        $this->line('attention_digest.overall_state='.(string) data_get($payload, 'attention_digest.overall_state', 'healthy'));
        $this->line('attention_digest.counts.stale='.(int) data_get($payload, 'attention_digest.counts.stale', 0));
        $this->line('attention_digest.counts.never_run='.(int) data_get($payload, 'attention_digest.counts.never_run', 0));
        $this->line('attention_digest.counts.not_available='.(int) data_get($payload, 'attention_digest.counts.not_available', 0));

        return self::SUCCESS;
    }
}
