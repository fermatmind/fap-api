<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ReportArtifactsRehydrateService;
use App\Services\Storage\ReportArtifactsShrinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportArtifactsShrinkServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-shrink-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-shrink-service-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-shrink.test');
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

    public function test_service_deletes_archived_report_and_pdf_and_rehydrate_still_restores_them(): void
    {
        $reportBytes = json_encode(['attempt' => 'shrink-report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);
        $reportPath = 'artifacts/reports/MBTI/shrink-report/report.json';
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/shrink-report/report.json', $reportBytes);

        $pdfBytes = '%PDF-1.4 shrink full';
        $pdfPath = 'artifacts/pdf/BIG5/shrink-pdf/hash/report_full.pdf';
        Storage::disk('local')->put($pdfPath, $pdfBytes);
        Storage::disk('s3')->put('report_artifacts_archive/pdf/BIG5/shrink-pdf/hash/report_full.pdf', $pdfBytes);

        $legacyPath = 'private/reports/BIG5/shrink-pdf/hash/report_full.pdf';
        Storage::disk('local')->put($legacyPath, '%PDF legacy untouched');

        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $reportPath, 'report_artifacts_archive/reports/MBTI/shrink-report/report.json', hash('sha256', $reportBytes), strlen($reportBytes), 'copied', 'MBTI', 'shrink-report'),
            $this->archiveResult('report_full_pdf', $pdfPath, 'report_artifacts_archive/pdf/BIG5/shrink-pdf/hash/report_full.pdf', hash('sha256', $pdfBytes), strlen($pdfBytes), 'already_archived', 'BIG5', 'shrink-pdf'),
        ]);

        /** @var ReportArtifactsShrinkService $service */
        $service = app(ReportArtifactsShrinkService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame('storage_shrink_archived_report_artifacts_plan.v1', $plan['schema']);
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(0, data_get($plan, 'summary.blocked_missing_archive_proof_count'));
        $this->assertSame(0, data_get($plan, 'summary.blocked_missing_remote_count'));
        $this->assertTrue((bool) data_get($plan, 'candidates.0.rehydrate_ready'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_shrink_plans/test-plan.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, data_get($result, 'summary.deleted_count'));
        $this->assertSame(0, data_get($result, 'summary.failed_count'));
        $this->assertFileExists((string) ($result['run_path'] ?? ''));
        $this->assertFalse(Storage::disk('local')->exists($reportPath));
        $this->assertFalse(Storage::disk('local')->exists($pdfPath));
        $this->assertTrue(Storage::disk('s3')->exists('report_artifacts_archive/reports/MBTI/shrink-report/report.json'));
        $this->assertTrue(Storage::disk('s3')->exists('report_artifacts_archive/pdf/BIG5/shrink-pdf/hash/report_full.pdf'));
        $this->assertTrue(Storage::disk('local')->exists($legacyPath));

        $shrinkAudit = DB::table('audit_logs')
            ->where('action', 'storage_shrink_archived_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($shrinkAudit);
        $shrinkMeta = json_decode((string) $shrinkAudit->meta_json, true);
        $this->assertIsArray($shrinkMeta);
        $this->assertSame(2, $shrinkMeta['deleted_count'] ?? null);
        $this->assertSame('audit_logs.meta_json', $shrinkMeta['durable_receipt_source'] ?? null);

        /** @var ReportArtifactsRehydrateService $rehydrateService */
        $rehydrateService = app(ReportArtifactsRehydrateService::class);
        $rehydratePlan = $rehydrateService->buildPlan('s3');
        $this->assertSame(2, data_get($rehydratePlan, 'summary.candidate_count'));
        $this->assertSame(0, data_get($rehydratePlan, 'summary.blocked_count'));
        $this->assertSame(0, data_get($rehydratePlan, 'summary.skipped_count'));

        $rehydratePlan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/restore-plan.json')];
        $rehydrateResult = $rehydrateService->executePlan($rehydratePlan);

        $this->assertSame('executed', $rehydrateResult['status']);
        $this->assertSame(2, data_get($rehydrateResult, 'summary.rehydrated_count'));
        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
        $this->assertSame(hash('sha256', $reportBytes), hash('sha256', (string) Storage::disk('local')->get($reportPath)));
        $this->assertSame(hash('sha256', $pdfBytes), hash('sha256', (string) Storage::disk('local')->get($pdfPath)));
    }

    public function test_service_blocks_missing_archive_proof_incomplete_rehydrate_proof_and_missing_remote(): void
    {
        $missingArchivePath = 'artifacts/reports/MBTI/no-archive/report.json';
        Storage::disk('local')->put($missingArchivePath, '{"missing":"archive"}');

        $missingRehydratePath = 'artifacts/pdf/BIG5/incomplete/hash/report_free.pdf';
        Storage::disk('local')->put($missingRehydratePath, '%PDF incomplete');

        $missingRemotePath = 'artifacts/reports/MBTI/missing-remote/report.json';
        $missingRemoteBytes = '{"missing":"remote"}';
        Storage::disk('local')->put($missingRemotePath, $missingRemoteBytes);

        $this->seedArchiveAudit([
            [
                'status' => 'copied',
                'kind' => 'report_free_pdf',
                'source_path' => $missingRehydratePath,
                'target_disk' => 's3',
                'target_object_key' => '',
                'source_sha256' => '',
                'source_bytes' => 0,
                'scale_code' => 'BIG5',
                'attempt_id' => 'incomplete',
            ],
            $this->archiveResult('report_json', $missingRemotePath, 'report_artifacts_archive/reports/MBTI/missing-remote/report.json', hash('sha256', $missingRemoteBytes), strlen($missingRemoteBytes), 'copied', 'MBTI', 'missing-remote'),
        ]);

        /** @var ReportArtifactsShrinkService $service */
        $service = app(ReportArtifactsShrinkService::class);

        $plan = $service->buildPlan('s3');

        $this->assertSame(0, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_missing_archive_proof_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_missing_rehydrate_proof_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_missing_remote_count'));
    }

    public function test_service_execute_skips_missing_local_and_blocks_hash_mismatch_visibly(): void
    {
        $skipPath = 'artifacts/reports/MBTI/missing-local/report.json';
        $skipBytes = '{"skip":true}';
        Storage::disk('local')->put($skipPath, $skipBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/missing-local/report.json', $skipBytes);

        $mismatchPath = 'artifacts/pdf/BIG5/hash-mismatch/hash/report_free.pdf';
        $mismatchBytes = '%PDF original bytes';
        Storage::disk('local')->put($mismatchPath, $mismatchBytes);
        Storage::disk('s3')->put('report_artifacts_archive/pdf/BIG5/hash-mismatch/hash/report_free.pdf', $mismatchBytes);

        $legacyPath = 'private/reports/BIG5/hash-mismatch/hash/report_free.pdf';
        Storage::disk('local')->put($legacyPath, '%PDF legacy');

        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $skipPath, 'report_artifacts_archive/reports/MBTI/missing-local/report.json', hash('sha256', $skipBytes), strlen($skipBytes), 'copied', 'MBTI', 'missing-local'),
            $this->archiveResult('report_free_pdf', $mismatchPath, 'report_artifacts_archive/pdf/BIG5/hash-mismatch/hash/report_free.pdf', hash('sha256', $mismatchBytes), strlen($mismatchBytes), 'copied', 'BIG5', 'hash-mismatch'),
        ]);

        /** @var ReportArtifactsShrinkService $service */
        $service = app(ReportArtifactsShrinkService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));

        Storage::disk('local')->delete($skipPath);
        Storage::disk('local')->put($mismatchPath, '%PDF mutated bytes');

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_shrink_plans/mixed.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(0, data_get($result, 'summary.deleted_count'));
        $this->assertSame(1, data_get($result, 'summary.skipped_missing_local_count'));
        $this->assertSame(1, data_get($result, 'summary.blocked_hash_mismatch_count'));
        $this->assertSame(0, data_get($result, 'summary.failed_count'));
        $this->assertTrue(Storage::disk('local')->exists($mismatchPath));
        $this->assertTrue(Storage::disk('local')->exists($legacyPath));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_shrink_archived_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame(1, $meta['skipped_missing_local_count'] ?? null);
        $this->assertSame(1, $meta['blocked_hash_mismatch_count'] ?? null);
        $this->assertSame(0, $meta['failed_count'] ?? null);
    }

    /**
     * @param  list<array<string,mixed>>  $results
     */
    private function seedArchiveAudit(array $results): void
    {
        DB::table('audit_logs')->insert([
            'org_id' => 0,
            'actor_admin_id' => null,
            'action' => 'storage_archive_report_artifacts',
            'target_type' => 'storage',
            'target_id' => 'report_artifacts_archive',
            'meta_json' => json_encode([
                'schema' => 'storage_archive_report_artifacts_run.v1',
                'mode' => 'execute',
                'target_disk' => 's3',
                'results' => $results,
                'summary' => [
                    'candidate_count' => count($results),
                    'copied_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'copied')),
                    'verified_count' => count(array_filter($results, static fn (array $result): bool => in_array((string) ($result['status'] ?? ''), ['copied', 'already_archived'], true))),
                    'already_archived_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'already_archived')),
                    'failed_count' => 0,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => null,
            'user_agent' => 'phpunit',
            'request_id' => null,
            'reason' => 'seed_archive_receipt',
            'result' => 'success',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function archiveResult(
        string $kind,
        string $sourcePath,
        string $targetObjectKey,
        string $sha256,
        int $bytes,
        string $status,
        string $scaleCode,
        string $attemptId,
    ): array {
        return [
            'status' => $status,
            'kind' => $kind,
            'source_path' => $sourcePath,
            'target_disk' => 's3',
            'target_object_key' => $targetObjectKey,
            'source_sha256' => $sha256,
            'source_bytes' => $bytes,
            'target_bytes' => $bytes,
            'scale_code' => $scaleCode,
            'attempt_id' => $attemptId,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
