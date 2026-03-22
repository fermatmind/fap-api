<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ReportArtifactsArchiveService;
use App\Services\Storage\ReportArtifactsRehydrateService;
use App\Services\Storage\ReportArtifactsShrinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactLifecycleLedgerDualWriteTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-artifact-lifecycle-ledger-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'artifact-lifecycle-ledger-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.artifact-lifecycle-ledger.test');
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.lifecycle_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.access_projection_dual_write_enabled', true);
        Storage::forgetDisk('local');
        Storage::fake('s3');
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

    public function test_archive_execution_writes_job_event_receipt_and_access_projection_sidecars(): void
    {
        $attemptId = (string) Str::uuid();
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        $pdfPath = 'artifacts/pdf/MBTI/'.$attemptId.'/nohash/report_free.pdf';
        Storage::disk('local')->put($reportPath, json_encode(['attempt_id' => $attemptId, 'kind' => 'report_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 ledger');

        /** @var ReportArtifactsArchiveService $service */
        $service = app(ReportArtifactsArchiveService::class);
        $plan = $service->buildPlan('s3');
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_archive_plans/ledger.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, data_get($result, 'summary.copied_count'));
        $this->assertSame(2, data_get($result, 'summary.verified_count'));

        $job = DB::table('artifact_lifecycle_jobs')->first();
        $this->assertNotNull($job);
        $this->assertSame('archive_report_artifacts', $job->job_type);
        $this->assertSame('succeeded', $job->state);
        $this->assertSame(1, (int) $job->attempt_count);

        $this->assertDatabaseCount('artifact_lifecycle_events', 4);
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'event_type' => 'job_started',
            'job_id' => $job->id,
        ]);
        $this->assertDatabaseHas('artifact_lifecycle_events', [
            'event_type' => 'job_finished',
            'job_id' => $job->id,
        ]);
        $this->assertDatabaseCount('attempt_receipts', 4);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'artifact_archived',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'access_projection_refreshed',
        ]);

        $projection = DB::table('unified_access_projections')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($projection);
        $this->assertSame('locked', $projection->access_state);
        $this->assertSame('archived', $projection->report_state);
        $this->assertSame('archived', $projection->pdf_state);
        $this->assertSame('artifact_archived', $projection->reason_code);

        Storage::disk('s3')->assertExists('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json');
        Storage::disk('s3')->assertExists('report_artifacts_archive/pdf/MBTI/'.$attemptId.'/nohash/report_free.pdf');
    }

    public function test_rehydrate_and_shrink_execution_also_write_ledger_sidecars(): void
    {
        $attemptId = (string) Str::uuid();
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        $pdfPath = 'artifacts/pdf/MBTI/'.$attemptId.'/nohash/report_free.pdf';
        $reportBytes = json_encode(['attempt_id' => $attemptId, 'kind' => 'report_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('local')->put($pdfPath, '%PDF-1.4 ledger');

        /** @var ReportArtifactsArchiveService $archiveService */
        $archiveService = app(ReportArtifactsArchiveService::class);
        $archivePlan = $archiveService->buildPlan('s3');
        $archivePlan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_archive_plans/ledger-cycle.json')];
        $archiveResult = $archiveService->executePlan($archivePlan);
        $this->assertSame('executed', $archiveResult['status']);

        Storage::disk('local')->delete($reportPath);
        Storage::disk('local')->delete($pdfPath);

        /** @var ReportArtifactsRehydrateService $rehydrateService */
        $rehydrateService = app(ReportArtifactsRehydrateService::class);
        $rehydratePlan = $rehydrateService->buildPlan('s3');
        $this->assertSame(2, data_get($rehydratePlan, 'summary.candidate_count'));
        $rehydratePlan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/ledger-cycle.json')];
        $rehydrateResult = $rehydrateService->executePlan($rehydratePlan);
        $this->assertSame('executed', $rehydrateResult['status']);
        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));

        /** @var ReportArtifactsShrinkService $shrinkService */
        $shrinkService = app(ReportArtifactsShrinkService::class);
        $shrinkPlan = $shrinkService->buildPlan('s3');
        $this->assertSame(2, data_get($shrinkPlan, 'summary.candidate_count'));
        $shrinkPlan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_shrink_plans/ledger-cycle.json')];
        $shrinkResult = $shrinkService->executePlan($shrinkPlan);
        $this->assertSame('executed', $shrinkResult['status']);

        $this->assertFalse(Storage::disk('local')->exists($reportPath));
        $this->assertFalse(Storage::disk('local')->exists($pdfPath));
        $this->assertDatabaseCount('artifact_lifecycle_jobs', 3);
        $this->assertDatabaseCount('artifact_lifecycle_events', 12);
        $this->assertDatabaseCount('attempt_receipts', 12);

        $projection = DB::table('unified_access_projections')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($projection);
        $this->assertSame('locked', $projection->access_state);
        $this->assertSame('archived', $projection->report_state);
        $this->assertSame('archived', $projection->pdf_state);
        $this->assertSame('artifact_shrunk', $projection->reason_code);
    }
}
