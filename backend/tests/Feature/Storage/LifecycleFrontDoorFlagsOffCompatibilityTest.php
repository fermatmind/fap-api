<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageArchiveReportArtifacts;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LifecycleFrontDoorFlagsOffCompatibilityTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-lifecycle-front-door-flags-off-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'lifecycle-front-door-flags-off-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.lifecycle-front-door-flags-off.test');
        config()->set('storage_rollout.lifecycle_front_door_enabled', false);
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', false);
        config()->set('storage_rollout.lifecycle_ledger_dual_write_enabled', false);
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

    public function test_archive_command_preserves_old_behavior_when_front_door_flag_is_off(): void
    {
        $attemptId = 'front-door-flags-off';
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        Storage::disk('local')->put($reportPath, '{"attempt_id":"front-door-flags-off"}');

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

        $this->assertDatabaseCount('artifact_lifecycle_jobs', 0);
        $this->assertDatabaseCount('artifact_lifecycle_events', 0);
        $this->assertDatabaseCount('attempt_receipts', 0);
        Storage::disk('s3')->assertExists('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json');
    }
}
