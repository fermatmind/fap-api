<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoragePruneBackupsGranularityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $preExistingPlans = [];

    private string $isolatedStoragePath;

    private string $originalStoragePath;

    private string $originalLocalRoot;

    private string $freshBackupDir = 'content_releases/backups/retention_backup_fresh';

    private string $oldBackupDir = 'content_releases/backups/retention_backup_old';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-storage-prune-backups-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');

        File::ensureDirectoryExists(storage_path('app/private/prune_plans'));
        $this->preExistingPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory($this->freshBackupDir);
        Storage::disk('local')->deleteDirectory($this->oldBackupDir);

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

    public function test_content_release_backups_are_pruned_per_backup_directory_instead_of_backups_root(): void
    {
        config()->set('storage_retention.content_releases.keep_last_n', 0);
        config()->set('storage_retention.content_releases.keep_days', 180);

        Storage::disk('local')->put($this->freshBackupDir.'/previous_pack/compiled/manifest.json', '{"fresh":true}');
        Storage::disk('local')->put($this->oldBackupDir.'/previous_pack/compiled/manifest.json', '{"old":true}');

        $backupsRoot = storage_path('app/private/content_releases/backups');
        $freshDir = storage_path('app/private/'.$this->freshBackupDir);
        $oldDir = storage_path('app/private/'.$this->oldBackupDir);

        @touch($oldDir, now()->subDays(240)->getTimestamp());
        @touch($freshDir, now()->subDays(1)->getTimestamp());
        @touch($backupsRoot, now()->getTimestamp());

        $this->artisan('storage:prune --dry-run --scope=content_releases_retention')
            ->assertExitCode(0);

        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_values(array_diff($currentPlans, $this->preExistingPlans));
        $this->assertNotSame([], $newPlans, 'expected at least one generated prune plan.');
        usort($newPlans, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $latestPlan = (string) end($newPlans);
        $this->assertFileExists($latestPlan);

        $plan = json_decode((string) file_get_contents($latestPlan), true);
        $this->assertIsArray($plan);
        $planPaths = array_values(array_map(
            static fn (array $entry): string => (string) ($entry['path'] ?? ''),
            is_array($plan['files'] ?? null) ? $plan['files'] : []
        ));

        $this->assertSame([
            'content_releases/backups/retention_backup_old/previous_pack/compiled/manifest.json',
        ], $planPaths);

        $this->artisan('storage:prune --execute --scope=content_releases_retention --plan='.$latestPlan)
            ->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($this->oldBackupDir.'/previous_pack/compiled/manifest.json'));
        $this->assertTrue(Storage::disk('local')->exists($this->freshBackupDir.'/previous_pack/compiled/manifest.json'));
    }
}
