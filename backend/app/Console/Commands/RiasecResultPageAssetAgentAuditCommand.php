<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Riasec\AssetAgent\RiasecResultPageAssetAgent;
use Illuminate\Console\Command;
use Throwable;

final class RiasecResultPageAssetAgentAuditCommand extends Command
{
    protected $signature = 'riasec:result-page-v2-agent
        {action=audit : Supported action: audit}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/riasec_result_page_v2_agent}
        {--content-asset-root= : Optional content asset root for tests}
        {--source-ledger-dir= : Optional source ledger directory for tests}
        {--strict : Return non-zero when inventory, source-ledger, validation, or leak checks fail}
        {--json : Emit machine-readable summary}';

    protected $description = 'Read-only RIASEC Result Page content asset agent audit harness.';

    public function handle(RiasecResultPageAssetAgent $agent): int
    {
        try {
            $action = (string) $this->argument('action');
            if ($action !== 'audit') {
                $this->error('Unsupported action. Supported action: audit');

                return self::FAILURE;
            }

            $summary = $agent->audit([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'content_asset_root' => trim((string) $this->option('content-asset-root')),
                'source_ledger_dir' => trim((string) $this->option('source-ledger-dir')),
                'strict' => (bool) $this->option('strict'),
            ]);

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->renderSummary($summary);
            }

            return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
        $this->line('run_id='.(string) ($summary['run_id'] ?? ''));
        $this->line('artifact_dir='.(string) ($summary['artifact_dir'] ?? ''));
        $this->line('asset_file_count='.(string) data_get($summary, 'summary.asset_file_count', 0));
        $this->line('validation_error_count='.(string) data_get($summary, 'summary.validation_error_count', 0));
        $this->line('leak_hit_count='.(string) data_get($summary, 'summary.leak_hit_count', 0));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }
        foreach ((array) ($summary['strict_failures'] ?? []) as $failure) {
            $this->line('strict_failure='.(string) $failure);
        }
    }
}
