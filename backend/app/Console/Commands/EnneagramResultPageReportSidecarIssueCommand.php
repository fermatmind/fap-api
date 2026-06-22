<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageReportSidecarIssueHarness;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageReportSidecarIssueCommand extends Command
{
    protected $signature = 'enneagram:result-page-report-sidecar
        {action=audit : Only audit is supported in this harness PR}
        {--run-id=report-sidecar : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root}
        {--contract-path= : Optional harness contract path}
        {--evidence-dir= : Optional evidence directory}
        {--blocker-source=none : none, external, or current_pr}
        {--blocker-reason= : Optional blocker summary}
        {--github-checks-green=1 : Whether required GitHub checks are green}
        {--scope-validation-green=1 : Whether current scope validation is green}
        {--strict : Include evidence inventory failures as blocking errors}
        {--json : Emit machine-readable summary}';

    protected $description = 'Generate Enneagram result page ops reports, sidecar issue payloads, and readiness summaries without production rollout.';

    public function handle(EnneagramResultPageReportSidecarIssueHarness $harness): int
    {
        try {
            if ($this->argument('action') !== 'audit') {
                $this->error('Unsupported action. This PR supports only: audit');

                return self::FAILURE;
            }

            $summary = $harness->run([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'contract_path' => trim((string) $this->option('contract-path')),
                'evidence_dir' => trim((string) $this->option('evidence-dir')),
                'blocker_source' => trim((string) $this->option('blocker-source')),
                'blocker_reason' => trim((string) $this->option('blocker-reason')),
                'github_checks_green' => $this->truthy($this->option('github-checks-green')),
                'scope_validation_green' => $this->truthy($this->option('scope-validation-green')),
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
        $this->line('train_can_continue='.(((bool) data_get($summary, 'summary.train_can_continue', false)) ? 'true' : 'false'));
        $this->line('release_ready_for_manual_production_gate='.(((bool) data_get($summary, 'summary.release_ready_for_manual_production_gate', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }
}
