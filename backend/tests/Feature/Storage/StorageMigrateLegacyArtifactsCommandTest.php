<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageMigrateLegacyArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $preExistingPlans = [];

    private string $attemptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptId = (string) Str::uuid();
        File::ensureDirectoryExists(storage_path('app/private/migration_plans'));
        $this->preExistingPlans = glob(storage_path('app/private/migration_plans/*.json')) ?: [];

        Storage::disk('local')->put("reports/{$this->attemptId}/report.json", json_encode([
            'ok' => true,
            'attempt_id' => $this->attemptId,
        ], JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put("private/reports/BIG5_OCEAN/{$this->attemptId}/legacyhash/report_free.pdf", '%PDF-1.4 legacy');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('reports/'.$this->attemptId);
        Storage::disk('local')->deleteDirectory('private/reports/BIG5_OCEAN/'.$this->attemptId);
        Storage::disk('local')->deleteDirectory('artifacts/reports/MBTI/'.$this->attemptId);
        Storage::disk('local')->deleteDirectory('artifacts/pdf/BIG5_OCEAN/'.$this->attemptId);

        $currentPlans = glob(storage_path('app/private/migration_plans/*.json')) ?: [];
        $newPlans = array_diff($currentPlans, $this->preExistingPlans);
        foreach ($newPlans as $planPath) {
            if (is_file($planPath)) {
                @unlink($planPath);
            }
        }

        parent::tearDown();
    }

    public function test_dry_run_and_execute_copy_legacy_artifacts_to_canonical_paths(): void
    {
        $this->artisan('storage:migrate-legacy-artifacts --dry-run')->assertExitCode(0);

        $currentPlans = glob(storage_path('app/private/migration_plans/*.json')) ?: [];
        $newPlans = array_values(array_diff($currentPlans, $this->preExistingPlans));
        $this->assertNotSame([], $newPlans, 'expected one migration plan.');
        usort($newPlans, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));
        $latestPlan = (string) end($newPlans);
        $this->assertFileExists($latestPlan);

        $plan = json_decode((string) file_get_contents($latestPlan), true);
        $this->assertIsArray($plan);
        $this->assertSame('storage_migrate_legacy_artifacts.v1', (string) ($plan['schema'] ?? ''));

        $this->artisan('storage:migrate-legacy-artifacts --execute --plan='.$latestPlan)->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists("artifacts/reports/MBTI/{$this->attemptId}/report.json"));
        $this->assertTrue(Storage::disk('local')->exists("artifacts/pdf/BIG5_OCEAN/{$this->attemptId}/legacyhash/report_free.pdf"));

        // migrate command is copy-only during compatibility window.
        $this->assertTrue(Storage::disk('local')->exists("reports/{$this->attemptId}/report.json"));
        $this->assertTrue(Storage::disk('local')->exists("private/reports/BIG5_OCEAN/{$this->attemptId}/legacyhash/report_free.pdf"));

        $auditCount = DB::table('audit_logs')
            ->where('action', 'storage_migrate_legacy_artifacts')
            ->count();
        $this->assertGreaterThanOrEqual(1, $auditCount);
    }
}
