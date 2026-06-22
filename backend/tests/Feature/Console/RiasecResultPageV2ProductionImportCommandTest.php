<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ContentPackRelease;
use App\Services\Riasec\RiasecResultPageV2ProductionImportExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class RiasecResultPageV2ProductionImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_defaults_to_dry_run_without_writes(): void
    {
        $outputDir = $this->freshOutputDir('dry_run');

        $this->artisan('riasec:result-page-v2-production-import', array_merge($this->baseOptions(), [
            '--output-dir' => $outputDir,
            '--json' => true,
        ]))->assertExitCode(0);

        $summary = $this->readJsonFile($outputDir.'/riasec_production_import_summary.json');
        $this->assertSame('pass', $summary['decision'] ?? null);
        $this->assertSame('production_import_dry_run', $summary['mode'] ?? null);
        $this->assertFalse((bool) data_get($summary, 'execution.cms_write_performed'));
        $this->assertFalse((bool) data_get($summary, 'execution.production_import_performed'));
        $this->assertFalse(ContentPackRelease::query()->whereKey($summary['release_id'])->exists());
        $this->assertSame($this->expectedConfirmToken(), $summary['expected_confirm_execute'] ?? null);
    }

    public function test_execute_requires_exact_confirmation_token(): void
    {
        $outputDir = $this->freshOutputDir('bad_token');

        $this->artisan('riasec:result-page-v2-production-import', array_merge($this->baseOptions(), [
            '--execute' => true,
            '--confirm-execute' => 'wrong-token',
            '--output-dir' => $outputDir,
            '--json' => true,
        ]))->assertExitCode(1);

        $summary = $this->readJsonFile($outputDir.'/riasec_production_import_summary.json');
        $this->assertSame('fail', $summary['decision'] ?? null);
        $this->assertContains('confirm_execute_token_mismatch', $summary['errors'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'execution.cms_write_performed'));
        $this->assertFalse(ContentPackRelease::query()->whereKey($summary['release_id'])->exists());
    }

    public function test_execute_materializes_release_without_rollout(): void
    {
        $outputDir = $this->freshOutputDir('execute');

        $this->artisan('riasec:result-page-v2-production-import', array_merge($this->baseOptions(), [
            '--execute' => true,
            '--confirm-execute' => $this->expectedConfirmToken(),
            '--output-dir' => $outputDir,
            '--json' => true,
        ]))->assertExitCode(0);

        $summary = $this->readJsonFile($outputDir.'/riasec_production_import_summary.json');
        $releaseId = (string) ($summary['release_id'] ?? '');
        $this->assertSame('pass', $summary['decision'] ?? null);
        $this->assertSame('production_import_execute', $summary['mode'] ?? null);
        $this->assertTrue((bool) data_get($summary, 'execution.cms_write_performed'));
        $this->assertTrue((bool) data_get($summary, 'execution.production_import_performed'));
        $this->assertFalse((bool) data_get($summary, 'execution.runtime_change_performed'));
        $this->assertFalse((bool) data_get($summary, 'execution.production_rollout_performed'));
        $this->assertSame(0, (int) data_get($summary, 'execution.readback.activation_rows_created'));

        $release = ContentPackRelease::query()->find($releaseId);
        $this->assertNotNull($release);
        $this->assertSame(RiasecResultPageV2ProductionImportExecutor::RELEASE_ACTION, $release->action);
        $this->assertSame('success', $release->status);
        $this->assertSame(RiasecResultPageV2ProductionImportExecutor::PACK_ID, $release->to_pack_id);

        $this->assertDatabaseHas('content_release_manifests', [
            'manifest_hash' => data_get($summary, 'execution.content_release_manifest_hash'),
            'pack_id' => RiasecResultPageV2ProductionImportExecutor::PACK_ID,
            'pack_version' => RiasecResultPageV2ProductionImportExecutor::PACK_VERSION,
        ]);

        $storagePath = storage_path('app/'.data_get($summary, 'execution.storage_path'));
        $this->assertFileExists($storagePath.'/manifest.json');
        $this->assertFileExists($storagePath.'/approved_snapshot.json');
        $this->assertFileExists($storagePath.'/approval_evidence.json');
        $this->assertFileExists($storagePath.'/import_gate_dry_run.json');
        $this->assertSame(0, DB::table('content_pack_activations')
            ->where('pack_id', RiasecResultPageV2ProductionImportExecutor::PACK_ID)
            ->where('pack_version', RiasecResultPageV2ProductionImportExecutor::PACK_VERSION)
            ->count());
    }

    public function test_hash_mismatch_fails_closed_without_writes(): void
    {
        $outputDir = $this->freshOutputDir('hash_mismatch');

        $this->artisan('riasec:result-page-v2-production-import', array_merge($this->baseOptions(), [
            '--approved-snapshot-sha256' => str_repeat('0', 64),
            '--output-dir' => $outputDir,
            '--json' => true,
        ]))->assertExitCode(1);

        $summary = $this->readJsonFile($outputDir.'/riasec_production_import_summary.json');
        $this->assertSame('fail', $summary['decision'] ?? null);
        $this->assertContains('approved_snapshot_sha256_mismatch', $summary['errors'] ?? []);
        $this->assertFalse((bool) data_get($summary, 'execution.cms_write_performed'));
        $this->assertSame(0, ContentPackRelease::query()->count());
    }

    /**
     * @return array<string,mixed>
     */
    private function baseOptions(): array
    {
        return [
            '--approved-snapshot-id' => 'riasec_result_page_v2_prod_approved_2026_06_22_01',
            '--approved-snapshot-sha256' => '999dc22a4c01b50891b342d75713a2fda1ce99b79933470f91fe1073744e0741',
            '--approval-evidence-id' => 'riasec_result_page_v2_production_import_approval_2026_06_22_01',
            '--approval-evidence-sha256' => '1fecb849e2ee47d2234631ad10614e327463928be2a390a0836552acdff23095',
            '--dry-run-artifact-sha256' => '038f8118a992caf58112ff06e225272bfdaeda603e4d5f26ad3ac30aab89b55d',
            '--tenant-ids' => 'single_owner_global',
            '--form-codes' => 'riasec_60,riasec_140',
            '--locales' => 'zh-CN',
            '--allowlist' => 'owner_manual_import_only',
            '--rollback-kill-switch-confirmed' => true,
            '--kill-switch-ref' => 'riasec_result_page_v2.production_emergency_disabled',
            '--post-deploy-smoke-procedure-id' => 'riasec_result_page_v2_post_deploy_smoke_v0_1',
        ];
    }

    private function expectedConfirmToken(): string
    {
        return RiasecResultPageV2ProductionImportExecutor::expectedConfirmExecuteToken(
            'riasec_result_page_v2_prod_approved_2026_06_22_01',
            '999dc22a4c01b50891b342d75713a2fda1ce99b79933470f91fe1073744e0741',
        );
    }

    private function freshOutputDir(string $suffix): string
    {
        $dir = storage_path('framework/testing/riasec_production_import_command/'.$suffix);
        File::deleteDirectory($dir);

        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        return json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
