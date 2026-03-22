<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageRehydrateReportArtifacts;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageRehydrateReportArtifactsCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-rehydrate-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-rehydrate-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-rehydrate-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageRehydrateReportArtifacts::class)
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
        $this->artisan('storage:rehydrate-report-artifacts')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:rehydrate-report-artifacts --dry-run --execute')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:rehydrate-report-artifacts --execute --disk=s3')
            ->expectsOutputToContain('--execute requires --plan.')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_execute_json_and_skip_are_visible(): void
    {
        $reportBytes = json_encode(['attempt' => 'command-rehydrate'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);
        $reportPath = 'artifacts/reports/MBTI/command-rehydrate/report.json';
        $reportSha = hash('sha256', $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/command-rehydrate/report.json', $reportBytes);

        $this->seedArchiveAudit([
            [
                'status' => 'copied',
                'kind' => 'report_json',
                'source_path' => $reportPath,
                'target_disk' => 's3',
                'target_object_key' => 'report_artifacts_archive/reports/MBTI/command-rehydrate/report.json',
                'source_sha256' => $reportSha,
                'source_bytes' => strlen($reportBytes),
                'target_bytes' => strlen($reportBytes),
                'scale_code' => 'MBTI',
                'attempt_id' => 'command-rehydrate',
                'verified_at' => now()->toIso8601String(),
            ],
        ]);

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=1', $dryRunOutput);
        $this->assertStringContainsString('rehydrated_count=0', $dryRunOutput);
        $this->assertStringContainsString('skipped_count=0', $dryRunOutput);
        $this->assertStringContainsString('blocked_count=0', $dryRunOutput);

        preg_match('/^plan=(.+)$/m', $dryRunOutput, $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $dryRunAudit = DB::table('audit_logs')
            ->where('action', 'storage_rehydrate_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($dryRunAudit);
        $dryRunMeta = json_decode((string) $dryRunAudit->meta_json, true);
        $this->assertIsArray($dryRunMeta);
        $this->assertSame('dry_run', $dryRunMeta['mode'] ?? null);
        $this->assertSame(1, $dryRunMeta['candidate_count'] ?? null);
        $this->assertSame('audit_logs.meta_json', $dryRunMeta['durable_receipt_source'] ?? null);

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('candidate_count=1', $executeOutput);
        $this->assertStringContainsString('rehydrated_count=1', $executeOutput);
        $this->assertStringContainsString('verified_count=1', $executeOutput);
        $this->assertStringContainsString('run_path=', $executeOutput);

        $this->assertTrue(Storage::disk('local')->exists($reportPath));

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--json' => true,
        ]));
        $jsonPayload = json_decode(Artisan::output(), true);
        $this->assertIsArray($jsonPayload);
        $this->assertSame('storage_rehydrate_report_artifacts_plan.v1', $jsonPayload['schema'] ?? null);
        $this->assertSame(1, data_get($jsonPayload, 'summary.candidate_count'));
        $this->assertSame(1, data_get($jsonPayload, 'summary.skipped_count'));

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $skipOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $skipOutput);
        $this->assertStringContainsString('skipped_count=1', $skipOutput);
        $this->assertStringContainsString('rehydrated_count=0', $skipOutput);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_rehydrate_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame(1, $meta['skipped_count'] ?? null);
        $this->assertSame(0, $meta['failed_count'] ?? null);
        $this->assertSame('audit_logs.meta_json', $meta['durable_receipt_source'] ?? null);
    }

    /**
     * @param  list<array<string,mixed>>  $results
     */
    private function seedArchiveAudit(array $results): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_archive_report_artifacts',
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_archive',
            'meta_json' => json_encode([
                'schema' => 'storage_archive_report_artifacts_run.v1',
                'mode' => 'execute',
                'target_disk' => 's3',
                'results' => $results,
                'summary' => [
                    'candidate_count' => count($results),
                    'copied_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'copied')),
                    'verified_count' => count(array_filter($results, static fn (array $result): bool => in_array((string) ($result['status'] ?? ''), ['copied', 'already_archived'], true))),
                    'already_archived_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'already_archived')),
                    'failed_count' => 0,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'phpunit',
            'request_id' => null,
            'reason' => 'seed_archive_receipt',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }
}
