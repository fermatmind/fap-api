<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageOpsAgentRunOrchestrator;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageOpsRunnerCommand extends Command
{
    protected $signature = 'enneagram:result-page-ops-runner
        {action=plan : Only plan is supported in this runner/orchestrator PR}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/enneagram_result_page_ops_agent_runner}
        {--contract-path= : Optional run-orchestrator contract path}
        {--mode=auto-to-pr : Requested mode: auto-to-pr, auto-to-staging, auto-to-report}
        {--scope-id=ops-agent-runner : Stable scope id for deterministic run and branch naming}
        {--pr-title=Enneagram: add result page ops agent runner orchestrator : Planned PR title}
        {--base-branch=main : Planned base branch}
        {--changed-file=* : Planned changed file for scope validation}
        {--simulate-external-blocker : Record an external blocker as a non-blocking sidecar payload}
        {--simulate-current-scope-failure : Prove current PR scope failures block the train}
        {--strict : Return non-zero when validation errors are present}
        {--json : Emit machine-readable summary}';

    protected $description = 'Prepare a read-only Enneagram result page ops agent runner/orchestrator plan.';

    public function handle(EnneagramResultPageOpsAgentRunOrchestrator $orchestrator): int
    {
        try {
            if ($this->argument('action') !== 'plan') {
                $this->error('Unsupported action. This PR supports only: plan');

                return self::FAILURE;
            }

            $summary = $orchestrator->plan([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'contract_path' => trim((string) $this->option('contract-path')),
                'mode' => trim((string) $this->option('mode')),
                'scope_id' => trim((string) $this->option('scope-id')),
                'pr_title' => trim((string) $this->option('pr-title')),
                'base_branch' => trim((string) $this->option('base-branch')),
                'changed_files' => array_map('strval', (array) $this->option('changed-file')),
                'simulate_external_blocker' => (bool) $this->option('simulate-external-blocker'),
                'simulate_current_scope_failure' => (bool) $this->option('simulate-current-scope-failure'),
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
        $this->line('mode='.(string) ($summary['mode'] ?? ''));
        $this->line('scope_id='.(string) ($summary['scope_id'] ?? ''));
        $this->line('train_can_continue='.(((bool) data_get($summary, 'summary.train_can_continue', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
