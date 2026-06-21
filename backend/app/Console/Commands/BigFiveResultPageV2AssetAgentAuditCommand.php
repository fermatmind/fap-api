<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveResultPageV2AssetAgentAuditCommand extends Command
{
    protected $signature = 'big5:result-page-v2-agent
        {action=audit : Supported actions: audit, generate-candidates, stage-candidates}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/big5_result_page_v2_agent}
        {--content-asset-root= : Optional content asset root for tests}
        {--source-ledger-dir= : Optional source ledger directory for tests}
        {--candidate-dir= : Candidate artifact directory for stage-candidates}
        {--staging-output-dir= : Optional staging package output directory for stage-candidates}
        {--allow-staging-write : Permit stage-candidates to write the reviewed staging package}
        {--strict : Return non-zero when validator, inventory, source-ledger, or leak checks fail}
        {--json : Emit machine-readable summary}';

    protected $description = 'Read-only Big Five Result Page V2 content asset agent audit and candidate harness.';

    public function handle(BigFiveResultPageV2AssetAgent $agent): int
    {
        try {
            $action = (string) $this->argument('action');
            $summary = match ($action) {
                'audit' => $agent->audit([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'content_asset_root' => trim((string) $this->option('content-asset-root')),
                    'source_ledger_dir' => trim((string) $this->option('source-ledger-dir')),
                    'strict' => (bool) $this->option('strict'),
                ]),
                'generate-candidates' => $agent->generateCandidates([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'source_ledger_dir' => trim((string) $this->option('source-ledger-dir')),
                ]),
                'stage-candidates' => $agent->stageCandidates([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'candidate_dir' => trim((string) $this->option('candidate-dir')),
                    'staging_output_dir' => trim((string) $this->option('staging-output-dir')),
                    'allow_staging_write' => (bool) $this->option('allow-staging-write'),
                ]),
                default => null,
            };

            if (! is_array($summary)) {
                $this->error('Unsupported action. Supported actions: audit, generate-candidates, stage-candidates');

                return self::FAILURE;
            }

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
        $this->line('selector_asset_count='.(string) data_get($summary, 'summary.selector_asset_count', 0));
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
