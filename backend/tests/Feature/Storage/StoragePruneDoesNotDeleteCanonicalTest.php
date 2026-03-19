<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoragePruneDoesNotDeleteCanonicalTest extends TestCase
{
    private string $attemptId;

    private string $attemptDir;

    /** @var list<string> */
    private array $preExistingPlans = [];

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-prune-canonical-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');

        $this->attemptId = (string) Str::uuid();
        $this->attemptDir = storage_path('app/private/reports/'.$this->attemptId);

        File::ensureDirectoryExists($this->attemptDir);
        File::ensureDirectoryExists(storage_path('app/private/prune_plans'));

        File::put($this->attemptDir.'/report.json', json_encode(['ok' => true], JSON_UNESCAPED_UNICODE));
        File::put($this->attemptDir.'/report.20260222_123456.json', json_encode(['ok' => true], JSON_UNESCAPED_UNICODE));
        File::put($this->attemptDir.'/report.20260222.json', json_encode(['ok' => true], JSON_UNESCAPED_UNICODE));

        $this->preExistingPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        if (is_dir($this->attemptDir)) {
            File::deleteDirectory($this->attemptDir);
        }

        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_diff($currentPlans, $this->preExistingPlans);
        foreach ($newPlans as $planPath) {
            if (is_file($planPath)) {
                @unlink($planPath);
            }
        }

        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_prune_removes_timestamp_backup_and_keeps_canonical_report(): void
    {
        $this->artisan('storage:prune --dry-run --scope=reports_backups')
            ->assertExitCode(0);

        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_values(array_diff($currentPlans, $this->preExistingPlans));

        $this->assertNotSame([], $newPlans, 'expected at least one generated prune plan.');
        usort($newPlans, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $latestPlan = end($newPlans);
        $this->assertIsString($latestPlan);
        $this->assertTrue(is_file($latestPlan));

        $this->artisan('storage:prune --execute --scope=reports_backups --plan='.$latestPlan)
            ->assertExitCode(0);

        $this->assertFileExists($this->attemptDir.'/report.json');
        $this->assertFileDoesNotExist($this->attemptDir.'/report.20260222_123456.json');
        $this->assertFileExists($this->attemptDir.'/report.20260222.json');
    }
}
