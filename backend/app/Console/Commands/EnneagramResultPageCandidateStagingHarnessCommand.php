<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageCandidateStagingHarness;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageCandidateStagingHarnessCommand extends Command
{
    protected $signature = 'enneagram:result-page-candidate-staging-harness
        {action=audit : Only audit is supported in this harness PR}
        {--run-id=candidate-staging-harness : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root}
        {--contract-path= : Optional harness contract path}
        {--candidate-dir= : Candidate package directory}
        {--output-dir= : Output directory for export/import reports}
        {--expected-candidate-manifest-sha256= : Expected candidate manifest hash}
        {--expected-runtime-registry-sha256= : Expected runtime registry hash}
        {--run-export : Run the existing candidate payload exporter}
        {--run-staging-import : Run the existing inactive candidate importer}
        {--strict : Return non-zero when validation errors are present}
        {--json : Emit machine-readable summary}';

    protected $description = 'Validate Enneagram candidate export and staging-only inactive import gates without activation.';

    public function handle(EnneagramResultPageCandidateStagingHarness $harness): int
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
                'output_dir' => trim((string) $this->option('output-dir')),
                'expected_candidate_manifest_sha256' => trim((string) $this->option('expected-candidate-manifest-sha256')),
                'expected_runtime_registry_sha256' => trim((string) $this->option('expected-runtime-registry-sha256')),
                'run_export' => (bool) $this->option('run-export'),
                'run_staging_import' => (bool) $this->option('run-staging-import'),
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
        $this->line('candidate_contract_valid='.(((bool) data_get($summary, 'summary.candidate_contract_valid', false)) ? 'true' : 'false'));
        $this->line('candidate_payload_count='.(string) data_get($summary, 'summary.candidate_payload_count', 0));
        $this->line('run_export='.(((bool) data_get($summary, 'summary.run_export', false)) ? 'true' : 'false'));
        $this->line('run_staging_import='.(((bool) data_get($summary, 'summary.run_staging_import', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
