<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveResultPageV2AssetAgentAuditCommand extends Command
{
    protected $signature = 'big5:result-page-v2-agent
        {action=audit : Supported actions: audit, generate-candidates, stage-candidates, plan-pr, inspect-ci, poll-github-checks, plan-merge-cleanup, execute-github-mutation, weekly-ops}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/big5_result_page_v2_agent}
        {--content-asset-root= : Optional content asset root for tests}
        {--source-ledger-dir= : Optional source ledger directory for tests}
        {--candidate-dir= : Candidate artifact directory for stage-candidates}
        {--staging-output-dir= : Optional staging package output directory for stage-candidates}
        {--source-run-dir= : Existing agent artifact run directory for plan-pr}
        {--pr-id= : Planned PR id for plan-pr}
        {--branch= : Planned branch for plan-pr}
        {--title= : Planned PR title for plan-pr}
        {--checks-json= : Exported GitHub statusCheckRollup JSON for inspect-ci}
        {--pr-state-json= : Exported GitHub PR state JSON for plan-merge-cleanup}
        {--pr-number= : GitHub PR number for poll-github-checks live read}
        {--execution-plan-json= : Auto PR or merge cleanup plan JSON for execute-github-mutation}
        {--repo-root= : Git repository root for execute-github-mutation}
        {--github-repo= : GitHub repository slug, for example fermatmind/fap-api}
        {--mutation-mode=simulate : GitHub mutation execution mode: simulate or live}
        {--production-ops-dir= : Production ops contract directory for weekly-ops}
        {--ops-source=report_snapshots : Weekly ops source: report_snapshots or contract}
        {--window-days=45 : Weekly ops reporting window in days}
        {--apply-mechanical-fixes : Permit inspect-ci to apply supported mechanical fixes}
        {--allow-github-mutation : Permit execute-github-mutation to perform live git/GitHub mutations}
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
                'plan-pr' => $agent->planPr([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'source_run_dir' => trim((string) $this->option('source-run-dir')),
                    'pr_id' => trim((string) $this->option('pr-id')),
                    'branch' => trim((string) $this->option('branch')),
                    'title' => trim((string) $this->option('title')),
                ]),
                'inspect-ci' => $agent->inspectCi([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'checks_json' => trim((string) $this->option('checks-json')),
                    'apply_mechanical_fixes' => (bool) $this->option('apply-mechanical-fixes'),
                ]),
                'poll-github-checks' => $agent->pollGithubChecks([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'pr_state_json' => trim((string) $this->option('pr-state-json')),
                    'pr_number' => trim((string) $this->option('pr-number')),
                    'github_repo' => trim((string) $this->option('github-repo')),
                ]),
                'plan-merge-cleanup' => $agent->planMergeCleanup([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'pr_state_json' => trim((string) $this->option('pr-state-json')),
                ]),
                'execute-github-mutation' => $agent->executeGithubMutation([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'execution_plan_json' => trim((string) $this->option('execution-plan-json')),
                    'repo_root' => trim((string) $this->option('repo-root')),
                    'github_repo' => trim((string) $this->option('github-repo')),
                    'mutation_mode' => trim((string) $this->option('mutation-mode')),
                    'allow_github_mutation' => (bool) $this->option('allow-github-mutation'),
                ]),
                'weekly-ops' => $agent->weeklyOps([
                    'run_id' => trim((string) $this->option('run-id')),
                    'artifact_dir' => trim((string) $this->option('artifact-dir')),
                    'production_ops_dir' => trim((string) $this->option('production-ops-dir')),
                    'ops_source' => trim((string) $this->option('ops-source')),
                    'window_days' => (int) $this->option('window-days'),
                ]),
                default => null,
            };

            if (! is_array($summary)) {
                $this->error('Unsupported action. Supported actions: audit, generate-candidates, stage-candidates, plan-pr, inspect-ci, poll-github-checks, plan-merge-cleanup, execute-github-mutation, weekly-ops');

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
