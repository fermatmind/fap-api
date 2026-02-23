<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoragePruneDoesNotDeleteCanonicalTest extends TestCase
{
    private string $attemptId;

    private string $attemptDir;

    /** @var list<string> */
    private array $preExistingPlans = [];

    protected function setUp(): void
    {
        parent::setUp();

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
