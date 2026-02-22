<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\Attempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageMigrateLegacyArtifactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $attemptId;

    private string $legacyReportPath;

    private string $legacyPdfPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $this->attemptId,
            'org_id' => 0,
            'anon_id' => 'anon_storage_migrate_test',
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'v1',
        ]);

        $legacyReportDir = storage_path('app/private/reports/'.$this->attemptId);
        File::ensureDirectoryExists($legacyReportDir);
        $this->legacyReportPath = $legacyReportDir.'/report.json';
        File::put($this->legacyReportPath, json_encode([
            'ok' => true,
            'report_id' => $this->attemptId,
        ], JSON_UNESCAPED_UNICODE));

        $legacyPdfDir = storage_path('app/private/private/reports/BIG5_OCEAN/'.$this->attemptId.'/hash_v1');
        File::ensureDirectoryExists($legacyPdfDir);
        $this->legacyPdfPath = $legacyPdfDir.'/report_free.pdf';
        File::put($this->legacyPdfPath, '%PDF legacy migrate source%');
    }

    protected function tearDown(): void
    {
        $reportDir = storage_path('app/private/reports/'.$this->attemptId);
        if (is_dir($reportDir)) {
            File::deleteDirectory($reportDir);
        }

        $legacyPdfRoot = storage_path('app/private/private/reports/BIG5_OCEAN/'.$this->attemptId);
        if (is_dir($legacyPdfRoot)) {
            File::deleteDirectory($legacyPdfRoot);
        }

        $newReportRoot = storage_path('app/private/artifacts/reports/BIG5_OCEAN/'.$this->attemptId);
        if (is_dir($newReportRoot)) {
            File::deleteDirectory($newReportRoot);
        }

        $newPdfRoot = storage_path('app/private/artifacts/pdf/BIG5_OCEAN/'.$this->attemptId);
        if (is_dir($newPdfRoot)) {
            File::deleteDirectory($newPdfRoot);
        }

        parent::tearDown();
    }

    public function test_migrate_command_dry_run_and_execute_with_audit(): void
    {
        $newReportPath = storage_path('app/private/artifacts/reports/BIG5_OCEAN/'.$this->attemptId.'/report.json');
        $newPdfPath = storage_path('app/private/artifacts/pdf/BIG5_OCEAN/'.$this->attemptId.'/hash_v1/report_free.pdf');

        $this->artisan('storage:migrate-legacy-artifacts --dry-run')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($newReportPath);
        $this->assertFileDoesNotExist($newPdfPath);

        $this->artisan('storage:migrate-legacy-artifacts --execute')
            ->assertExitCode(0);

        $this->assertFileExists($this->legacyReportPath);
        $this->assertFileExists($this->legacyPdfPath);
        $this->assertFileExists($newReportPath);
        $this->assertFileExists($newPdfPath);

        $auditCount = DB::table('audit_logs')
            ->where('action', 'storage_artifact_migrate')
            ->where('target_id', $this->attemptId)
            ->count();

        $this->assertGreaterThanOrEqual(2, $auditCount);
    }
}
