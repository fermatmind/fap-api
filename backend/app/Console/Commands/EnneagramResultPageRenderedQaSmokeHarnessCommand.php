<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageRenderedQaSmokeHarness;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageRenderedQaSmokeHarnessCommand extends Command
{
    protected $signature = 'enneagram:result-page-rendered-qa-smoke-harness
        {action=audit : Only audit is supported in this harness PR}
        {--run-id=rendered-qa-smoke : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root}
        {--contract-path= : Optional harness contract path}
        {--candidate-dir= : Candidate package directory}
        {--web-repo-dir= : fap-web checkout path used for rendered QA command rendering}
        {--evidence-dir= : Optional directory containing *_report.json evidence files}
        {--release-id= : Inactive release id used for rollback simulation planning}
        {--mode=auto-to-report : auto-to-staging or auto-to-report}
        {--strict : Return non-zero when validation errors are present}
        {--json : Emit machine-readable summary}';

    protected $description = 'Prepare Enneagram rendered QA, API smoke, and rollback simulation evidence bundles without production rollout.';

    public function handle(EnneagramResultPageRenderedQaSmokeHarness $harness): int
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
                'candidate_dir' => trim((string) $this->option('candidate-dir')),
                'web_repo_dir' => trim((string) $this->option('web-repo-dir')),
                'evidence_dir' => trim((string) $this->option('evidence-dir')),
                'release_id' => trim((string) $this->option('release-id')),
                'mode' => trim((string) $this->option('mode')),
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
        $this->line('candidate_dir_valid='.(((bool) data_get($summary, 'summary.candidate_dir_valid', false)) ? 'true' : 'false'));
        $this->line('evidence_valid='.(((bool) data_get($summary, 'summary.evidence_valid', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
