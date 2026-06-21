<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageOpsControlPlane;
use Illuminate\Console\Command;
use Throwable;

final class EnneagramResultPageOpsControlPlaneCommand extends Command
{
    protected $signature = 'enneagram:result-page-ops-control-plane
        {action=audit : Only audit is supported in this control-plane PR}
        {--run-id= : Stable run identifier for the artifact directory}
        {--artifact-dir= : Optional artifact root; defaults to backend/artifacts/enneagram_result_page_ops_control_plane}
        {--contract-path= : Optional control-plane contract path; defaults to backend/content_assets/enneagram/result_page/ops_agent_control_plane/control_plane_v0_1.json}
        {--mode=auto-to-report : Requested mode: auto-to-pr, auto-to-staging, auto-to-report, production-manual-gate}
        {--simulate-production-rollout : Prove automatic production rollout is blocked}
        {--release-id= : Prepared manual approval release id}
        {--confirm-release-id= : Prepared manual approval confirm release id}
        {--candidate-manifest-sha256= : Prepared manual approval candidate manifest hash}
        {--runtime-registry-sha256= : Prepared manual approval runtime registry hash}
        {--rollback-window= : Prepared manual approval rollback window}
        {--post-activation-smoke-plan= : Prepared manual approval smoke plan}
        {--strict : Return non-zero when any control-plane validation error is present}
        {--json : Emit machine-readable summary}';

    protected $description = 'Read-only Enneagram result page ops agent control-plane audit.';

    public function handle(EnneagramResultPageOpsControlPlane $controlPlane): int
    {
        try {
            if ($this->argument('action') !== 'audit') {
                $this->error('Unsupported action. This PR supports only: audit');

                return self::FAILURE;
            }

            $summary = $controlPlane->audit([
                'run_id' => trim((string) $this->option('run-id')),
                'artifact_dir' => trim((string) $this->option('artifact-dir')),
                'contract_path' => trim((string) $this->option('contract-path')),
                'mode' => trim((string) $this->option('mode')),
                'strict' => (bool) $this->option('strict'),
                'simulate_production_rollout' => (bool) $this->option('simulate-production-rollout'),
                'approval' => [
                    'release_id' => trim((string) $this->option('release-id')),
                    'confirm_release_id' => trim((string) $this->option('confirm-release-id')),
                    'candidate_manifest_sha256' => trim((string) $this->option('candidate-manifest-sha256')),
                    'runtime_registry_sha256' => trim((string) $this->option('runtime-registry-sha256')),
                    'rollback_window' => trim((string) $this->option('rollback-window')),
                    'post_activation_smoke_plan' => trim((string) $this->option('post-activation-smoke-plan')),
                ],
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
        $this->line('artifact_dir='.(string) ($summary['artifact_dir'] ?? ''));
        $this->line('contract_valid='.(((bool) data_get($summary, 'summary.contract_valid', false)) ? 'true' : 'false'));
        $this->line('production_execution_allowed_for_agent='.(((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true)) ? 'true' : 'false'));
        $this->line('manual_approval_required_for_production='.(((bool) data_get($summary, 'summary.manual_approval_required_for_production', false)) ? 'true' : 'false'));

        foreach ((array) ($summary['artifacts'] ?? []) as $filename => $artifact) {
            $this->line('artifact['.$filename.']='.(string) data_get($artifact, 'relative_path', ''));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            $this->line('error='.(string) $error);
        }
    }
}
