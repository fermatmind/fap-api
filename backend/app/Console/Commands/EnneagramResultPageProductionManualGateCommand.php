<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageProductionManualGateRunbook;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageProductionManualGateCommand extends Command
{
    protected $signature = 'enneagram:result-page-production-manual-gate
        {action=audit : Only audit is supported in this runbook PR}
        {--run-id=production-manual-gate : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root}
        {--contract-path= : Optional runbook contract path}
        {--release-id= : Exact inactive release id}
        {--confirm-release-id= : Repeat exact inactive release id}
        {--candidate-manifest-sha256= : Exact candidate manifest SHA256}
        {--runtime-registry-sha256= : Exact runtime registry SHA256}
        {--rollback-window= : Human-approved rollback window}
        {--post-activation-smoke-acknowledged : Acknowledge post-activation smoke plan}
        {--strict : Return non-zero when manual approval packet is incomplete or mismatched}
        {--json : Emit machine-readable summary}';

    protected $description = 'Prepare Enneagram production manual approval packets without executing production rollout.';

    public function handle(EnneagramResultPageProductionManualGateRunbook $runbook): int
    {
        try {
            if ($this->argument('action') !== 'audit') {
                $this->error('Unsupported action. This PR supports only: audit');

                return self::FAILURE;
            }

            $summary = $runbook->run([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'contract_path' => trim((string) $this->option('contract-path')),
                'release_id' => trim((string) $this->option('release-id')),
                'confirm_release_id' => trim((string) $this->option('confirm-release-id')),
                'candidate_manifest_sha256' => trim((string) $this->option('candidate-manifest-sha256')),
                'runtime_registry_sha256' => trim((string) $this->option('runtime-registry-sha256')),
                'rollback_window' => trim((string) $this->option('rollback-window')),
                'post_activation_smoke_acknowledged' => (bool) $this->option('post-activation-smoke-acknowledged'),
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
        $this->line('release_id='.(string) data_get($summary, 'summary.release_id', ''));
        $this->line('manual_approval_packet_valid='.(((bool) data_get($summary, 'summary.manual_approval_packet_valid', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
