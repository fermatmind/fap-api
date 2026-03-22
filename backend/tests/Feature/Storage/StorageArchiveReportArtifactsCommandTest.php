<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageArchiveReportArtifacts;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageArchiveReportArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-archive-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-archive-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-archive-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageArchiveReportArtifacts::class)
        );
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');
        Storage::forgetDisk('s3');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_command_requires_exactly_one_mode_and_plan_for_execute(): void
    {
        $this->artisan('storage:archive-report-artifacts')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:archive-report-artifacts --dry-run --execute')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:archive-report-artifacts --execute --disk=s3')
            ->expectsOutputToContain('--execute requires --plan.')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_execute_json_and_partial_failure_are_visible(): void
    {
        $reportBytes = json_encode(['attempt' => 'command-report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);

        $reportSourcePath = 'artifacts/reports/MBTI/command-report/report.json';
        $pdfSourcePath = 'artifacts/pdf/BIG5/command-pdf/manifest-123/report_full.pdf';
        $legacySourcePath = 'reports/MBTI/command-report/report.json';

        Storage::disk('local')->put($reportSourcePath, $reportBytes);
        Storage::disk('local')->put($pdfSourcePath, '%PDF-1.4 full');
        Storage::disk('local')->put($legacySourcePath, json_encode(['legacy' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=2', $dryRunOutput);
        $this->assertStringContainsString('copied_count=0', $dryRunOutput);
        $this->assertStringContainsString('already_archived_count=0', $dryRunOutput);

        preg_match('/^plan=(.+)$/m', $dryRunOutput, $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $dryRunAudit = DB::table('audit_logs')
            ->where('action', 'storage_archive_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($dryRunAudit);
        $dryRunMeta = json_decode((string) $dryRunAudit->meta_json, true);
        $this->assertIsArray($dryRunMeta);
        $this->assertSame('dry_run', $dryRunMeta['mode'] ?? null);
        $this->assertSame('audit_logs.meta_json', $dryRunMeta['durable_receipt_source'] ?? null);
        $this->assertSame(2, $dryRunMeta['candidate_count'] ?? null);
        $this->assertSame(0, $dryRunMeta['results_count'] ?? null);

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('candidate_count=2', $executeOutput);
        $this->assertStringContainsString('copied_count=2', $executeOutput);
        $this->assertStringContainsString('verified_count=2', $executeOutput);
        $this->assertStringContainsString('run_path=', $executeOutput);

        $executeAudit = DB::table('audit_logs')
            ->where('action', 'storage_archive_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($executeAudit);
        $executeMeta = json_decode((string) $executeAudit->meta_json, true);
        $this->assertIsArray($executeMeta);
        $this->assertSame('execute', $executeMeta['mode'] ?? null);
        $this->assertSame($planPath, $executeMeta['plan_path'] ?? null);
        $this->assertSame(2, $executeMeta['verified_count'] ?? null);
        $this->assertSame(2, $executeMeta['results_count'] ?? null);

        Storage::disk('s3')->assertExists('report_artifacts_archive/reports/MBTI/command-report/report.json');
        Storage::disk('s3')->assertExists('report_artifacts_archive/pdf/BIG5/command-pdf/manifest-123/report_full.pdf');
        $this->assertTrue(Storage::disk('local')->exists($reportSourcePath));
        $this->assertTrue(Storage::disk('local')->exists($pdfSourcePath));
        $this->assertTrue(Storage::disk('local')->exists($legacySourcePath));

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--json' => true,
        ]));
        $jsonPayload = json_decode(Artisan::output(), true);
        $this->assertIsArray($jsonPayload);
        $this->assertSame('storage_archive_report_artifacts_plan.v1', $jsonPayload['schema'] ?? null);
        $this->assertSame(2, data_get($jsonPayload, 'summary.candidate_count'));

        $secondPlanPath = storage_path('app/private/report_artifact_archive_plans/failure.json');
        File::ensureDirectoryExists(dirname($secondPlanPath));
        File::copy($planPath, $secondPlanPath);
        Storage::disk('local')->delete($reportSourcePath);

        $this->assertSame(1, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $secondPlanPath,
        ]));
        $failureOutput = Artisan::output();
        $this->assertStringContainsString('status=partial_failure', $failureOutput);
        $this->assertStringContainsString('failed_count=1', $failureOutput);
    }
}
