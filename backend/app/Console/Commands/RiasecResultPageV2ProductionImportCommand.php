<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Riasec\RiasecResultPageV2ProductionImportExecutor;
use Illuminate\Console\Command;
use Throwable;

final class RiasecResultPageV2ProductionImportCommand extends Command
{
    protected $signature = 'riasec:result-page-v2-production-import
        {--approved-snapshot= : Approved snapshot JSON path}
        {--approved-snapshot-id= : Exact approved snapshot id}
        {--approved-snapshot-sha256= : Exact approved snapshot SHA-256}
        {--approval-evidence= : Production import approval evidence JSON path}
        {--approval-evidence-id= : Exact approval evidence id}
        {--approval-evidence-sha256= : Exact approval evidence SHA-256}
        {--dry-run-artifact= : Authorized import-gate dry-run JSON path}
        {--dry-run-artifact-sha256= : Exact authorized dry-run artifact SHA-256}
        {--tenant-ids=single_owner_global : Exact comma-separated tenant scope}
        {--form-codes=riasec_60,riasec_140 : Exact comma-separated form scope}
        {--locales=zh-CN : Exact comma-separated locale scope}
        {--allowlist=owner_manual_import_only : Exact comma-separated allowlist scope}
        {--rollback-kill-switch-confirmed : Required for pass}
        {--kill-switch-ref=riasec_result_page_v2.production_emergency_disabled : Exact kill switch reference}
        {--post-deploy-smoke-procedure-id=riasec_result_page_v2_post_deploy_smoke_v0_1 : Exact post-deploy smoke procedure id}
        {--execute : Execute the controlled production import write}
        {--confirm-execute= : Required exact token for --execute}
        {--output-dir= : Optional directory for riasec_production_import_summary.json}
        {--json : Emit machine-readable JSON report}';

    protected $description = 'Controlled RIASEC Result Page V2 production import command; dry-run by default and never performs rollout.';

    public function handle(RiasecResultPageV2ProductionImportExecutor $executor): int
    {
        try {
            $summary = $executor->run([
                'approved_snapshot_path' => trim((string) ($this->option('approved-snapshot') ?: RiasecResultPageV2ProductionImportExecutor::DEFAULT_APPROVED_SNAPSHOT_PATH)),
                'approved_snapshot_id' => trim((string) $this->option('approved-snapshot-id')),
                'approved_snapshot_sha256' => trim((string) $this->option('approved-snapshot-sha256')),
                'approval_evidence_path' => trim((string) ($this->option('approval-evidence') ?: RiasecResultPageV2ProductionImportExecutor::DEFAULT_APPROVAL_EVIDENCE_PATH)),
                'approval_evidence_id' => trim((string) $this->option('approval-evidence-id')),
                'approval_evidence_sha256' => trim((string) $this->option('approval-evidence-sha256')),
                'dry_run_artifact_path' => trim((string) ($this->option('dry-run-artifact') ?: RiasecResultPageV2ProductionImportExecutor::DEFAULT_DRY_RUN_ARTIFACT_PATH)),
                'dry_run_artifact_sha256' => trim((string) $this->option('dry-run-artifact-sha256')),
                'tenant_ids' => trim((string) $this->option('tenant-ids')),
                'form_codes' => trim((string) $this->option('form-codes')),
                'locales' => trim((string) $this->option('locales')),
                'allowlist' => trim((string) $this->option('allowlist')),
                'rollback_kill_switch_confirmed' => (bool) $this->option('rollback-kill-switch-confirmed'),
                'kill_switch_ref' => trim((string) $this->option('kill-switch-ref')),
                'post_deploy_smoke_procedure_id' => trim((string) $this->option('post-deploy-smoke-procedure-id')),
                'execute' => (bool) $this->option('execute'),
                'confirm_execute' => trim((string) $this->option('confirm-execute')),
                'output_dir' => trim((string) $this->option('output-dir')),
            ]);

            return $this->finish($summary, ($summary['decision'] ?? null) === 'pass');
        } catch (Throwable $throwable) {
            return $this->finish([
                'decision' => 'fail',
                'errors' => [$throwable->getMessage()],
                'execution' => [
                    'production_import_command_run' => false,
                    'cms_write_performed' => false,
                    'production_import_performed' => false,
                    'production_rollout_performed' => false,
                ],
            ], false);
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function finish(array $summary, bool $success): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ($success) {
            $this->info('RIASEC Result Page V2 production import gate passed.');
            $this->line('mode='.(string) ($summary['mode'] ?? ''));
            $this->line('release_id='.(string) ($summary['release_id'] ?? ''));
            $this->line('production_import_performed='.(((bool) data_get($summary, 'execution.production_import_performed', false)) ? 'true' : 'false'));
            $this->line('production_rollout_performed='.(((bool) data_get($summary, 'execution.production_rollout_performed', false)) ? 'true' : 'false'));
            if (! (bool) data_get($summary, 'execution.production_import_performed', false)) {
                $this->line('expected_confirm_execute='.(string) ($summary['expected_confirm_execute'] ?? ''));
            }
        } else {
            $this->error('RIASEC Result Page V2 production import gate failed.');
            foreach ((array) ($summary['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
