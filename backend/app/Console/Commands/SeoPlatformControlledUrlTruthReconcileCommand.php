<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use App\Services\SeoIntel\UrlTruth\ControlledUrlTruthReconciliationService;
use Illuminate\Console\Command;
use Throwable;

final class SeoPlatformControlledUrlTruthReconcileCommand extends Command
{
    protected $signature = 'seo-intel:url-truth-controlled-reconcile
        {--execute : Run the controlled write and exact-input idempotency rerun}
        {--no-http : Disable bounded public consumer/canonical evidence}
        {--max-records=5000 : Fail closed above this authority record bound}
        {--batch-size=250 : Maximum cohort write batch size (1-250)}
        {--json : Emit a sanitized machine-readable receipt}';

    protected $description = 'Build and optionally execute the bounded all-family URL Truth reconciliation artifact.';

    public function handle(
        CurrentPublicUrlAuthoritySource $source,
        ControlledUrlTruthReconciliationService $service,
    ): int {
        try {
            $receipt = $service->run(
                $source->candidates(),
                $source->metadata(),
                (bool) $this->option('execute'),
                ! (bool) $this->option('no-http'),
                (int) $this->option('max-records'),
                (int) $this->option('batch-size'),
            );
        } catch (Throwable) {
            $receipt = [
                'schema_version' => ControlledUrlTruthReconciliationService::SCHEMA_VERSION,
                'status' => 'blocked',
                'issues' => ['controlled_reconciliation_unavailable'],
                'writes_committed' => false,
                'boundaries' => ['search_submission_allowed' => false, 'raw_error_output' => false],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status='.(string) ($receipt['status'] ?? 'blocked'));
            $this->line('mode='.(string) ($receipt['mode'] ?? 'unavailable'));
            $this->line('artifact_hash='.(string) data_get($receipt, 'artifact.artifact_hash'));
            $this->line('record_count='.(string) data_get($receipt, 'artifact.record_count'));
        }

        return ($receipt['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
