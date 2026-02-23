<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class StoragePruneReleaseRetentionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $preExistingPlans = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private/prune_plans'));
        $this->preExistingPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('content_releases/release_new');
        Storage::disk('local')->deleteDirectory('content_releases/release_old');

        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_diff($currentPlans, $this->preExistingPlans);
        foreach ($newPlans as $planPath) {
            if (is_file($planPath)) {
                @unlink($planPath);
            }
        }

        parent::tearDown();
    }

    public function test_content_releases_retention_prunes_old_releases_with_plan_and_audit(): void
    {
        config()->set('storage_retention.content_releases.keep_last_n', 1);
        config()->set('storage_retention.content_releases.keep_days', 180);

        Storage::disk('local')->put('content_releases/release_new/source_pack/compiled/manifest.json', '{"new":true}');
        Storage::disk('local')->put('content_releases/release_old/source_pack/compiled/manifest.json', '{"old":true}');

        $newDir = storage_path('app/private/content_releases/release_new');
        $oldDir = storage_path('app/private/content_releases/release_old');
        $oldTs = now()->subDays(240)->getTimestamp();
        $newTs = now()->subDays(1)->getTimestamp();
        @touch($oldDir, $oldTs);
        @touch($newDir, $newTs);

        $this->artisan('storage:prune --dry-run --scope=content_releases_retention')
            ->assertExitCode(0);

        $currentPlans = glob(storage_path('app/private/prune_plans/*.json')) ?: [];
        $newPlans = array_values(array_diff($currentPlans, $this->preExistingPlans));
        $this->assertNotSame([], $newPlans, 'expected at least one generated prune plan.');
        usort($newPlans, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $latestPlan = (string) end($newPlans);
        $this->assertFileExists($latestPlan);

        $this->artisan('storage:prune --execute --scope=content_releases_retention --plan='.$latestPlan)
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists('content_releases/release_new/source_pack/compiled/manifest.json'));
        $this->assertFalse(Storage::disk('local')->exists('content_releases/release_old/source_pack/compiled/manifest.json'));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_prune')
            ->where('target_id', 'content_releases_retention')
            ->exists();
        $this->assertTrue($audit, 'expected storage_prune audit log for content_releases_retention');
    }
}
