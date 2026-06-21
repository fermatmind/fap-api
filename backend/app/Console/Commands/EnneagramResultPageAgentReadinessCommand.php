<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentReadiness;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageAgentReadinessCommand extends Command
{
    protected $signature = 'enneagram:result-page-agent
        {action=audit : Only audit is supported in the readiness/control-packet PR}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/enneagram_result_page_agent}
        {--candidate-dir= : Optional existing Phase8B candidate directory to check for required artifact names}
        {--source-ledger-dir= : Optional source ledger directory; defaults to backend/content_assets/enneagram/result_page/source_ledger}
        {--strict : Return non-zero when the source ledger or provided candidate directory is invalid}
        {--json : Emit machine-readable summary}';

    protected $description = 'Read-only Enneagram result page content asset agent readiness/control-packet audit.';

    public function handle(EnneagramResultPageAgentReadiness $readiness): int
    {
        try {
            if ($this->argument('action') !== 'audit') {
                $this->error('Unsupported action. This PR supports only: audit');

                return self::FAILURE;
            }

            $summary = $readiness->audit([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'candidate_dir' => trim((string) $this->option('candidate-dir')),
                'source_ledger_dir' => trim((string) $this->option('source-ledger-dir')),
                'strict' => (bool) $this->option('strict'),
            ]);

            $this->render($summary);

            return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function render(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('status='.(string) ($summary['status'] ?? 'unknown'));
        $this->line('run_id='.(string) ($summary['run_id'] ?? ''));
        $this->line('artifact_dir='.(string) ($summary['artifact_dir'] ?? ''));
        $this->line('candidate_generation_allowed='.(((bool) data_get($summary, 'summary.candidate_generation_allowed', true)) ? 'true' : 'false'));
        $this->line('candidate_payload_count_expected='.(string) data_get($summary, 'summary.candidate_payload_count_expected', 0));
        $this->line('ready_for_activation='.(((bool) data_get($summary, 'summary.ready_for_activation', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', data_get($artifact, 'path', '')));
        }

        foreach ((array) ($summary['strict_failures'] ?? []) as $failure) {
            $this->line('strict_failure='.(string) $failure);
        }
    }
}
