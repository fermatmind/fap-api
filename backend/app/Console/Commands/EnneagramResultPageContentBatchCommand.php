<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageContentBatchAutomation;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageContentBatchCommand extends Command
{
    protected $signature = 'enneagram:result-page-content-batch
        {action=evaluate : Only evaluate is supported in this small-batch automation PR}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/enneagram_result_page_content_batch_automation}
        {--contract-path= : Optional content-batch automation contract path}
        {--source-ledger-path= : Optional source ledger JSON path}
        {--source-id=batch_1r_a_asset_stream : Source ledger source id}
        {--module-key=pilot_baseline_reflection : Target module key}
        {--result-type=type_1 : Target result type}
        {--scope=pilot : Target scope}
        {--public-payload-json= : Optional public payload JSON; defaults to a one-payload pilot fixture}
        {--strict : Return non-zero when validation errors are present}
        {--json : Emit machine-readable summary}';

    protected $description = 'Evaluate a tiny Enneagram result page content batch input through source mapping and safety gates.';

    public function handle(EnneagramResultPageContentBatchAutomation $automation): int
    {
        try {
            if ($this->argument('action') !== 'evaluate') {
                $this->error('Unsupported action. This PR supports only: evaluate');

                return self::FAILURE;
            }

            $payloadJson = trim((string) $this->option('public-payload-json'));
            $publicPayload = $payloadJson === '' ? null : json_decode($payloadJson, true);
            if ($payloadJson !== '' && ! is_array($publicPayload)) {
                $this->error('public-payload-json must decode to an object');

                return self::FAILURE;
            }

            $summary = $automation->evaluate([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'contract_path' => trim((string) $this->option('contract-path')),
                'source_ledger_path' => trim((string) $this->option('source-ledger-path')),
                'source_id' => trim((string) $this->option('source-id')),
                'module_key' => trim((string) $this->option('module-key')),
                'result_type' => trim((string) $this->option('result-type')),
                'scope' => trim((string) $this->option('scope')),
                'public_payload' => $publicPayload,
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
        $this->line('payload_count='.(string) data_get($summary, 'summary.payload_count', 0));
        $this->line('bulk_generation_allowed='.(((bool) data_get($summary, 'summary.bulk_generation_allowed', true)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
