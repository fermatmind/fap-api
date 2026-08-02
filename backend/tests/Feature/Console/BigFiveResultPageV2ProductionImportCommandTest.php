<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPackRelease;
use App\Services\BigFive\ResultPageV2\Production\BigFiveResultPageV2ProductionImportExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BigFiveResultPageV2ProductionImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_defaults_to_exact_dry_run_without_writes(): void
    {
        $this->artisan('bigfive:result-page-v2-production-import', array_merge($this->baseOptions(), [
            '--json' => true,
        ]))->assertExitCode(0);

        $this->assertSame(0, ContentPackRelease::query()->count());
        $this->assertSame(0, DB::table('content_release_manifests')->count());
    }

    public function test_executor_generates_bound_token_and_dry_run_has_zero_writes(): void
    {
        $summary = $this->executor()->run($this->executorOptions());

        $this->assertSame('pass', $summary['decision']);
        $this->assertSame('production_import_dry_run', $summary['mode']);
        $this->assertSame($this->expectedToken(), $summary['expected_confirm_execute']);
        $this->assertFalse((bool) data_get($summary, 'execution.release_audit_written'));
        $this->assertFalse((bool) data_get($summary, 'execution.production_rollout_performed'));
        $this->assertSame(0, ContentPackRelease::query()->count());
    }

    public function test_execute_requires_exact_token_and_then_writes_audit_without_activation(): void
    {
        $bad = $this->executor()->run(array_merge($this->executorOptions(), [
            'execute' => true,
            'confirm_execute' => 'wrong-token',
        ]));
        $this->assertSame('fail', $bad['decision']);
        $this->assertContains('confirm_execute_token_mismatch', $bad['errors']);
        $this->assertSame(0, ContentPackRelease::query()->count());

        $summary = $this->executor()->run(array_merge($this->executorOptions(), [
            'execute' => true,
            'confirm_execute' => $this->expectedToken(),
        ]));

        $this->assertSame('pass', $summary['decision']);
        $this->assertTrue((bool) data_get($summary, 'execution.release_audit_written'));
        $this->assertFalse((bool) data_get($summary, 'execution.runtime_change_performed'));
        $this->assertFalse((bool) data_get($summary, 'execution.production_rollout_performed'));
        $this->assertSame(0, (int) data_get($summary, 'execution.activation_rows_created'));
        $this->assertDatabaseHas('content_pack_releases', [
            'id' => $summary['release_id'],
            'action' => BigFiveResultPageV2ProductionImportExecutor::RELEASE_ACTION,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => data_get($summary, 'execution.content_release_manifest_hash'),
            'pack_id' => 'BIG5_OCEAN',
            'pack_version' => 'result_page_v2_v0_4',
        ]);
    }

    public function test_hash_mismatch_fails_closed_without_writes(): void
    {
        $summary = $this->executor()->run(array_merge($this->executorOptions(), [
            'snapshot_sha256' => str_repeat('0', 64),
        ]));

        $this->assertSame('fail', $summary['decision']);
        $this->assertContains('snapshot_sha256_mismatch', $summary['errors']);
        $this->assertFalse((bool) data_get($summary, 'execution.release_audit_written'));
        $this->assertSame(0, ContentPackRelease::query()->count());
    }

    private function executor(): BigFiveResultPageV2ProductionImportExecutor
    {
        return app(BigFiveResultPageV2ProductionImportExecutor::class);
    }

    /** @return array<string,mixed> */
    private function executorOptions(): array
    {
        return [
            'snapshot_id' => BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_ID,
            'snapshot_sha256' => BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_SHA256,
            'approval_id' => BigFiveResultPageV2ProductionImportExecutor::APPROVAL_ID,
            'approval_sha256' => BigFiveResultPageV2ProductionImportExecutor::APPROVAL_SHA256,
            'org_ids' => '0',
            'form_codes' => 'big5_90,big5_120',
            'locales' => 'zh-CN',
            'rollback_kill_switch_confirmed' => true,
            'kill_switch_ref' => 'big5_result_page_v2.production_emergency_disabled',
            'post_deploy_smoke_procedure_id' => 'big5_result_page_v2_post_deploy_smoke_v0_4',
            'execute' => false,
            'confirm_execute' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function baseOptions(): array
    {
        return [
            '--snapshot-id' => BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_ID,
            '--snapshot-sha256' => BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_SHA256,
            '--approval-id' => BigFiveResultPageV2ProductionImportExecutor::APPROVAL_ID,
            '--approval-sha256' => BigFiveResultPageV2ProductionImportExecutor::APPROVAL_SHA256,
            '--org-ids' => '0',
            '--form-codes' => 'big5_90,big5_120',
            '--locales' => 'zh-CN',
            '--rollback-kill-switch-confirmed' => true,
            '--kill-switch-ref' => 'big5_result_page_v2.production_emergency_disabled',
            '--post-deploy-smoke-procedure-id' => 'big5_result_page_v2_post_deploy_smoke_v0_4',
        ];
    }

    private function expectedToken(): string
    {
        return BigFiveResultPageV2ProductionImportExecutor::expectedConfirmExecuteToken(
            BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_ID,
            BigFiveResultPageV2ProductionImportExecutor::SNAPSHOT_SHA256,
            BigFiveResultPageV2ProductionImportExecutor::APPROVAL_ID,
            BigFiveResultPageV2ProductionImportExecutor::APPROVAL_SHA256,
        );
    }
}
