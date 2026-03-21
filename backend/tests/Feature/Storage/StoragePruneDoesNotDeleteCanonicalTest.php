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

    public function test_prune_respects_reports_backup_retention_window_without_touching_canonical_files(): void
    {
        config()->set('storage_retention.reports.keep_days', 7);
        config()->set('storage_retention.reports.keep_timestamp_backups', 1);

        $oldBackup = $this->attemptDir.'/report.20260101_010101.json';
        $recentBackup = $this->attemptDir.'/report.20260321_010101.json';
        $newestBackup = $this->attemptDir.'/report.20260321_020202.json';

        File::put($oldBackup, json_encode(['ok' => true, 'kind' => 'old'], JSON_UNESCAPED_UNICODE));
        File::put($recentBackup, json_encode(['ok' => true, 'kind' => 'recent'], JSON_UNESCAPED_UNICODE));
        File::put($newestBackup, json_encode(['ok' => true, 'kind' => 'newest'], JSON_UNESCAPED_UNICODE));

        touch($oldBackup, now()->subDays(30)->getTimestamp());
        touch($recentBackup, now()->subDays(2)->getTimestamp());
        touch($newestBackup, now()->subDay()->getTimestamp());

        $this->artisan('storage:prune --dry-run --scope=reports_backups')
            ->assertExitCode(0);

        $latestPlan = $this->latestGeneratedPlanPath();
        $this->assertIsString($latestPlan);
        $this->assertTrue(is_file($latestPlan));

        $plan = json_decode((string) file_get_contents($latestPlan), true);
        $this->assertIsArray($plan);
        $this->assertSame([
            [
                'path' => 'reports/'.$this->attemptId.'/report.20260101_010101.json',
                'bytes' => filesize($oldBackup),
            ],
        ], $plan['files'] ?? []);

        $this->artisan('storage:prune --execute --scope=reports_backups --plan='.$latestPlan)
            ->assertExitCode(0);

        $this->assertFileExists($this->attemptDir.'/report.json');
        $this->assertFileDoesNotExist($oldBackup);
        $this->assertFileExists($recentBackup);
        $this->assertFileExists($newestBackup);
        $this->assertFileExists($this->attemptDir.'/report.20260222.json');
    }

    private function latestGeneratedPlanPath(): ?string
    {
        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_values(array_diff($currentPlans, $this->preExistingPlans));
        if ($newPlans === []) {
            return null;
        }

        usort($newPlans, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        $latestPlan = end($newPlans);

        return is_string($latestPlan) ? $latestPlan : null;
    }
}
