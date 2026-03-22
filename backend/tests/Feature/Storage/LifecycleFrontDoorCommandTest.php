<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageArchiveReportArtifacts;
use App\Console\Commands\StorageRehydrateReportArtifacts;
use App\Console\Commands\StorageShrinkArchivedReportArtifacts;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LifecycleFrontDoorCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-lifecycle-front-door-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'lifecycle-front-door-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.lifecycle-front-door-command.test');
        config()->set('storage_rollout.lifecycle_front_door_enabled', true);
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', false);
        config()->set('storage_rollout.lifecycle_ledger_dual_write_enabled', false);
        config()->set('storage_rollout.access_projection_dual_write_enabled', false);
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->registerCommand($this->app->make(StorageArchiveReportArtifacts::class));
        $kernel->registerCommand($this->app->make(StorageRehydrateReportArtifacts::class));
        $kernel->registerCommand($this->app->make(StorageShrinkArchivedReportArtifacts::class));
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

    public function test_archive_command_uses_front_door_when_flag_is_enabled(): void
    {
        $attemptId = 'front-door-archive';
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        Storage::disk('local')->put($reportPath, '{"attempt_id":"front-door-archive"}');

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        preg_match('/^plan=(.+)$/m', Artisan::output(), $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:archive-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $this->assertDatabaseHas('artifact_lifecycle_jobs', [
            'job_type' => 'archive_report_artifacts',
            'state' => 'succeeded',
            'attempt_id' => $attemptId,
        ]);
        $jobId = (int) DB::table('artifact_lifecycle_jobs')->value('id');
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'job_id' => $jobId,
            'event_type' => 'job_started',
        ]);
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'job_id' => $jobId,
            'event_type' => 'job_finished',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_archive_requested',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_archived',
        ]);
    }

    public function test_rehydrate_command_uses_front_door_when_flag_is_enabled(): void
    {
        $attemptId = 'front-door-rehydrate';
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        $reportBytes = '{"attempt_id":"front-door-rehydrate"}';
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', $reportBytes);
        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $reportPath, 'report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', hash('sha256', $reportBytes), strlen($reportBytes), 'copied', 'MBTI', $attemptId),
        ]);

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        preg_match('/^plan=(.+)$/m', Artisan::output(), $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:rehydrate-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertDatabaseHas('artifact_lifecycle_jobs', [
            'job_type' => 'rehydrate_report_artifacts',
            'state' => 'succeeded',
            'attempt_id' => $attemptId,
        ]);
        $jobId = (int) DB::table('artifact_lifecycle_jobs')->where('job_type', 'rehydrate_report_artifacts')->value('id');
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'job_id' => $jobId,
            'event_type' => 'job_started',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_rehydrate_requested',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_rehydrated',
        ]);
    }

    public function test_shrink_command_uses_front_door_when_flag_is_enabled(): void
    {
        $attemptId = 'front-door-shrink';
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        $reportBytes = '{"attempt_id":"front-door-shrink"}';
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', $reportBytes);
        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $reportPath, 'report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', hash('sha256', $reportBytes), strlen($reportBytes), 'copied', 'MBTI', $attemptId),
        ]);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        preg_match('/^plan=(.+)$/m', Artisan::output(), $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $this->assertFalse(Storage::disk('local')->exists($reportPath));
        $this->assertDatabaseHas('artifact_lifecycle_jobs', [
            'job_type' => 'shrink_archived_report_artifacts',
            'state' => 'succeeded',
            'attempt_id' => $attemptId,
        ]);
        $jobId = (int) DB::table('artifact_lifecycle_jobs')->where('job_type', 'shrink_archived_report_artifacts')->value('id');
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'job_id' => $jobId,
            'event_type' => 'job_finished',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_shrink_requested',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_shrunk',
        ]);
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
                    'copied_count' => count($results),
                    'verified_count' => count($results),
                    'already_archived_count' => 0,
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

    /**
     * @return array<string,mixed>
     */
    private function archiveResult(
        string $kind,
        string $sourcePath,
        string $targetObjectKey,
        string $sha256,
        int $bytes,
        string $status,
        string $scaleCode,
        string $attemptId
    ): array {
        return [
            'status' => $status,
            'kind' => $kind,
            'source_path' => $sourcePath,
            'target_disk' => 's3',
            'target_object_key' => $targetObjectKey,
            'source_sha256' => $sha256,
            'source_bytes' => $bytes,
            'target_bytes' => $bytes,
            'scale_code' => $scaleCode,
            'attempt_id' => $attemptId,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
