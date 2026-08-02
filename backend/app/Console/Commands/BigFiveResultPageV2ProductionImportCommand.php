<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\ResultPageV2\Production\BigFiveResultPageV2ProductionImportExecutor;
use Illuminate\Console\Command;
use Throwable;

final class BigFiveResultPageV2ProductionImportCommand extends Command
{
    protected $signature = 'bigfive:result-page-v2-production-import
        {--snapshot= : Immutable v0_4 snapshot JSON path}
        {--snapshot-id= : Exact snapshot id}
        {--snapshot-sha256= : Exact snapshot SHA-256}
        {--approval= : Import approval JSON path}
        {--approval-id= : Exact approval id}
        {--approval-sha256= : Exact approval SHA-256}
        {--org-ids=0 : Exact organization scope}
        {--form-codes=big5_90,big5_120 : Exact form scope}
        {--locales=zh-CN : Exact locale scope}
        {--rollback-kill-switch-confirmed : Required for pass}
        {--kill-switch-ref=big5_result_page_v2.production_emergency_disabled : Exact kill switch reference}
        {--post-deploy-smoke-procedure-id=big5_result_page_v2_post_deploy_smoke_v0_4 : Exact smoke procedure id}
        {--execute : Write the controlled release audit}
        {--confirm-execute= : Exact command-generated token required with --execute}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate Big Five v0_4 production import evidence; dry-run by default and never enables rollout.';

    public function handle(BigFiveResultPageV2ProductionImportExecutor $executor): int
    {
        try {
            $summary = $executor->run([
                'snapshot_path' => trim((string) ($this->option('snapshot') ?: BigFiveResultPageV2ProductionImportExecutor::DEFAULT_SNAPSHOT_PATH)),
                'snapshot_id' => trim((string) $this->option('snapshot-id')),
                'snapshot_sha256' => trim((string) $this->option('snapshot-sha256')),
                'approval_path' => trim((string) ($this->option('approval') ?: BigFiveResultPageV2ProductionImportExecutor::DEFAULT_APPROVAL_PATH)),
                'approval_id' => trim((string) $this->option('approval-id')),
                'approval_sha256' => trim((string) $this->option('approval-sha256')),
                'org_ids' => trim((string) $this->option('org-ids')),
                'form_codes' => trim((string) $this->option('form-codes')),
                'locales' => trim((string) $this->option('locales')),
                'rollback_kill_switch_confirmed' => (bool) $this->option('rollback-kill-switch-confirmed'),
                'kill_switch_ref' => trim((string) $this->option('kill-switch-ref')),
                'post_deploy_smoke_procedure_id' => trim((string) $this->option('post-deploy-smoke-procedure-id')),
                'execute' => (bool) $this->option('execute'),
                'confirm_execute' => trim((string) $this->option('confirm-execute')),
            ]);
        } catch (Throwable $throwable) {
            $summary = [
                'decision' => 'fail',
                'errors' => [$throwable->getMessage()],
                'execution' => ['production_import_performed' => false, 'production_rollout_performed' => false],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif (($summary['decision'] ?? null) === 'pass') {
            $this->info('Big Five Result Page V2 production import gate passed.');
            $this->line('mode='.(string) ($summary['mode'] ?? ''));
            if (! (bool) data_get($summary, 'execution.production_import_performed', false)) {
                $this->line('expected_confirm_execute='.(string) ($summary['expected_confirm_execute'] ?? ''));
            }
        } else {
            $this->error('Big Five Result Page V2 production import gate failed.');
            foreach ((array) ($summary['errors'] ?? []) as $error) {
                $this->line('- '.(string) $error);
            }
        }

        return ($summary['decision'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
