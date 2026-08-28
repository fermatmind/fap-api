<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\UrlTruth\MaterialAuthorityUrlTruthBackfillService;
use Illuminate\Console\Command;
use Throwable;

final class SeoPlatformUrlTruthMaterialBackfillCommand extends Command
{
    protected $signature = 'seo-intel:url-truth-material-backfill
        {--execute : Apply the bounded material-decision projection}
        {--max-records=5000 : Fail closed above this URL or decision bound}
        {--canary-size=10 : First controlled write/readback cohort (1-50)}
        {--json : Emit a sanitized machine-readable receipt}';

    protected $description = 'Dry-run or apply the bounded material-authority URL Truth backfill.';

    public function handle(MaterialAuthorityUrlTruthBackfillService $service): int
    {
        try {
            $receipt = $service->run(
                (bool) $this->option('execute'),
                (int) $this->option('max-records'),
                (int) $this->option('canary-size'),
            );
        } catch (Throwable) {
            $receipt = [
                'schema_version' => MaterialAuthorityUrlTruthBackfillService::SCHEMA_VERSION,
                'status' => 'blocked',
                'issues' => ['material_backfill_unavailable'],
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
        }

        return ($receipt['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
