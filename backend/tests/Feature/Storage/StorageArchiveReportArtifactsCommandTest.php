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

        $this->artisan('storage:archive-report-artifacts --execute --disk=s3 --plan=fake-plan.json --attempt-id=attempt-1')
            ->expectsOutputToContain('--attempt-id and --limit are only supported with --dry-run.')
            ->assertExitCode(1);

        $this->artisan('storage:archive-report-artifacts --dry-run --disk=s3 --limit=0')
            ->expectsOutputToContain('--limit must be a positive integer.')
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
        $this->assertStringContainsString('selection_scope=full_scan', $dryRunOutput);
        $this->assertStringContainsString('requested_attempt_ids=', $dryRunOutput);
        $this->assertStringContainsString('requested_limit=', $dryRunOutput);
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
        $this->assertSame('full_scan', $dryRunMeta['selection_scope'] ?? null);
        $this->assertSame([], $dryRunMeta['requested_attempt_ids'] ?? null);
        $this->assertNull($dryRunMeta['requested_limit'] ?? null);
        $this->assertSame(2, $dryRunMeta['candidate_count'] ?? null);
        $this->assertSame(0, $dryRunMeta['results_count'] ?? null);

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('selection_scope=full_scan', $executeOutput);
        $this->assertStringContainsString('requested_attempt_ids=', $executeOutput);
        $this->assertStringContainsString('requested_limit=', $executeOutput);
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
        $this->assertSame('full_scan', $executeMeta['selection_scope'] ?? null);
        $this->assertSame([], $executeMeta['requested_attempt_ids'] ?? null);
        $this->assertNull($executeMeta['requested_limit'] ?? null);
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
        $this->assertSame('full_scan', $jsonPayload['selection_scope'] ?? null);
        $this->assertSame([], $jsonPayload['requested_attempt_ids'] ?? null);
        $this->assertNull($jsonPayload['requested_limit'] ?? null);
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

    public function test_command_can_build_attempt_scoped_dry_run_plan(): void
    {
        $this->seedCanonicalReportArtifact('attempt-b');
        $this->seedCanonicalReportArtifact('attempt-a');

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['attempt-b', 'attempt-a'],
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('attempt_scoped', $payload['selection_scope'] ?? null);
        $this->assertSame(['attempt-a', 'attempt-b'], $payload['requested_attempt_ids'] ?? null);
        $this->assertNull($payload['requested_limit'] ?? null);
        $this->assertSame(2, $payload['candidate_count'] ?? null);
        $this->assertSame(2, data_get($payload, 'summary.candidate_count'));
        $this->assertSame(['attempt-a', 'attempt-b'], array_column((array) ($payload['candidates'] ?? []), 'attempt_id'));
    }

    public function test_command_can_build_limit_scoped_plan(): void
    {
        $this->seedCanonicalReportArtifact('limit-b');
        $this->seedCanonicalReportArtifact('limit-a');

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--limit' => '1',
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('limit_scoped', $payload['selection_scope'] ?? null);
        $this->assertSame([], $payload['requested_attempt_ids'] ?? null);
        $this->assertSame(1, $payload['requested_limit'] ?? null);
        $this->assertSame(1, $payload['candidate_count'] ?? null);
        $this->assertSame(1, data_get($payload, 'summary.candidate_count'));
        $this->assertSame('limit-a', data_get($payload, 'candidates.0.attempt_id'));
    }

    public function test_command_combines_attempt_filter_before_limit(): void
    {
        $this->seedCanonicalReportArtifact('combo-b');
        $this->seedCanonicalReportArtifact('combo-a');
        $this->seedCanonicalReportArtifact('combo-c');

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['combo-c', 'combo-a'],
            '--limit' => '1',
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('attempt_scoped_limited', $payload['selection_scope'] ?? null);
        $this->assertSame(['combo-a', 'combo-c'], $payload['requested_attempt_ids'] ?? null);
        $this->assertSame(1, $payload['requested_limit'] ?? null);
        $this->assertSame(1, $payload['candidate_count'] ?? null);
        $this->assertSame('combo-a', data_get($payload, 'candidates.0.attempt_id'));
    }

    public function test_command_execute_marks_legacy_plan_as_unscoped_for_compatibility(): void
    {
        $this->seedCanonicalReportArtifact('legacy-plan-attempt');
        $planPath = storage_path('app/private/report_artifact_archive_plans/legacy-plan.json');
        File::ensureDirectoryExists(dirname($planPath));
        File::put($planPath, json_encode([
            'schema' => 'storage_archive_report_artifacts_plan.v1',
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => 's3',
            'target_disk' => 's3',
            'summary' => [
                'candidate_count' => 1,
                'candidate_bytes' => strlen('{"attempt":"legacy-plan-attempt"}'),
                'kind_counts' => ['report_json' => 1],
                'copied_count' => 0,
                'verified_count' => 0,
                'already_archived_count' => 0,
                'failed_count' => 0,
            ],
            'candidates' => [[
                'kind' => 'report_json',
                'source_path' => 'artifacts/reports/MBTI/legacy-plan-attempt/report.json',
                'relative_path' => 'reports/MBTI/legacy-plan-attempt/report.json',
                'target_disk' => 's3',
                'target_object_key' => 'report_artifacts_archive/reports/MBTI/legacy-plan-attempt/report.json',
                'bytes' => strlen('{"attempt":"legacy-plan-attempt"}'),
                'sha256' => hash('sha256', '{"attempt":"legacy-plan-attempt"}'),
                'scale_code' => 'MBTI',
                'attempt_id' => 'legacy-plan-attempt',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('selection_scope=legacy_unscoped_plan', $output);
        $this->assertStringContainsString('requested_attempt_ids=', $output);
        $this->assertStringContainsString('requested_limit=', $output);
    }

    public function test_command_execute_surfaces_scoped_plan_metadata_for_auditability(): void
    {
        $this->seedCanonicalReportArtifact('scoped-plan-attempt');

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['scoped-plan-attempt'],
        ]));
        preg_match('/^plan=(.+)$/m', Artisan::output(), $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('selection_scope=attempt_scoped', $output);
        $this->assertStringContainsString('requested_attempt_ids=scoped-plan-attempt', $output);
        $this->assertStringContainsString('requested_limit=', $output);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_archive_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame('attempt_scoped', $meta['selection_scope'] ?? null);
        $this->assertSame(['scoped-plan-attempt'], $meta['requested_attempt_ids'] ?? null);
        $this->assertNull($meta['requested_limit'] ?? null);
    }

    private function seedCanonicalReportArtifact(string $attemptId): void
    {
        Storage::disk('local')->put(
            'artifacts/reports/MBTI/'.$attemptId.'/report.json',
            json_encode(['attempt' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
