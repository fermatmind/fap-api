<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ReportArtifactsArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportArtifactsArchiveServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-archive-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-archive-service-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-archive.test');
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

    public function test_service_archives_canonical_report_and_pdf_without_local_delete(): void
    {
        $report = $this->seedCanonicalReportJson('MBTI', 'attempt-report', ['ok' => true]);
        $pdf = $this->seedCanonicalPdf('BIG5', 'attempt-pdf', 'manifest-hash', 'free', '%PDF-1.4 free');
        $legacyPdfPath = 'reports/legacy-attempt/report_free.pdf';
        Storage::disk('local')->put($legacyPdfPath, '%PDF-1.4 legacy');

        /** @var ReportArtifactsArchiveService $service */
        $service = app(ReportArtifactsArchiveService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame('storage_archive_report_artifacts_plan.v1', $plan['schema']);
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.kind_counts.report_json'));
        $this->assertSame(1, data_get($plan, 'summary.kind_counts.report_free_pdf'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_archive_plans/test-plan.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, data_get($result, 'summary.copied_count'));
        $this->assertSame(2, data_get($result, 'summary.verified_count'));
        $this->assertSame(0, data_get($result, 'summary.failed_count'));
        $this->assertFileExists((string) ($result['run_path'] ?? ''));

        Storage::disk('s3')->assertExists('report_artifacts_archive/reports/MBTI/attempt-report/report.json');
        Storage::disk('s3')->assertExists('report_artifacts_archive/pdf/BIG5/attempt-pdf/manifest-hash/report_free.pdf');
        $this->assertTrue(Storage::disk('local')->exists($report['source_path']));
        $this->assertTrue(Storage::disk('local')->exists($pdf['source_path']));
        $this->assertTrue(Storage::disk('local')->exists($legacyPdfPath));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storage_archive_report_artifacts',
            'target_id' => 'report_artifacts_archive',
            'result' => 'success',
        ]);
    }

    public function test_service_excludes_non_canonical_files_and_marks_already_archived_when_target_matches(): void
    {
        $report = $this->seedCanonicalReportJson('MBTI', 'attempt-already', ['ok' => true]);
        Storage::disk('local')->put('reports/MBTI/attempt-already/report.json', json_encode(['legacy' => true], JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put('reports/MBTI/attempt-already/report.20260322_120000.json', json_encode(['backup' => true], JSON_UNESCAPED_UNICODE));
        Storage::disk('local')->put('artifacts/reports/.DS_Store', 'finder');
        Storage::disk('local')->put('artifacts/reports/.gitkeep', '');
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/attempt-already/report.json', $report['bytes']);

        /** @var ReportArtifactsArchiveService $service */
        $service = app(ReportArtifactsArchiveService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame(1, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(['reports/MBTI/attempt-already/report.json'], array_column((array) ($plan['candidates'] ?? []), 'relative_path'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_archive_plans/already.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(0, data_get($result, 'summary.copied_count'));
        $this->assertSame(1, data_get($result, 'summary.already_archived_count'));
        $this->assertSame(1, data_get($result, 'summary.verified_count'));
        $this->assertSame('already_archived', data_get($result, 'results.0.status'));
        $this->assertTrue(Storage::disk('local')->exists($report['source_path']));
    }

    public function test_service_records_partial_failure_when_source_disappears_after_plan_generation(): void
    {
        $report = $this->seedCanonicalReportJson('MBTI', 'attempt-fail', ['ok' => false]);

        /** @var ReportArtifactsArchiveService $service */
        $service = app(ReportArtifactsArchiveService::class);

        $plan = $service->buildPlan('s3');
        Storage::disk('local')->delete($report['source_path']);

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_archive_plans/failure.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('partial_failure', $result['status']);
        $this->assertSame(1, data_get($result, 'summary.failed_count'));
        $this->assertSame('failed', data_get($result, 'results.0.status'));
        $this->assertSame('SOURCE_MISSING_AT_EXECUTE', data_get($result, 'results.0.reason'));
        $this->assertFileExists((string) ($result['run_path'] ?? ''));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_archive_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('partial_failure', $audit->result);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame(1, data_get($meta, 'summary.failed_count'));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{source_path:string,bytes:string}
     */
    private function seedCanonicalReportJson(string $scaleCode, string $attemptId, array $payload): array
    {
        $bytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($bytes);

        $sourcePath = 'artifacts/reports/'.$scaleCode.'/'.$attemptId.'/report.json';
        Storage::disk('local')->put($sourcePath, $bytes);

        return [
            'source_path' => $sourcePath,
            'bytes' => $bytes,
        ];
    }

    /**
     * @return array{source_path:string,bytes:string}
     */
    private function seedCanonicalPdf(string $scaleCode, string $attemptId, string $manifestHash, string $variant, string $bytes): array
    {
        $sourcePath = 'artifacts/pdf/'.$scaleCode.'/'.$attemptId.'/'.$manifestHash.'/report_'.$variant.'.pdf';
        Storage::disk('local')->put($sourcePath, $bytes);

        return [
            'source_path' => $sourcePath,
            'bytes' => $bytes,
        ];
    }
}
