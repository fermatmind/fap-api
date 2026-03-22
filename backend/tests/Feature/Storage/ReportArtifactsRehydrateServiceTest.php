<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ReportArtifactsRehydrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportArtifactsRehydrateServiceTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-rehydrate-service-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-rehydrate-service-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-rehydrate.test');
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

    public function test_service_rehydrates_archived_report_and_pdf_to_exact_local_canonical_paths(): void
    {
        $reportBytes = json_encode(['attempt' => 'rehydrate-report'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);
        $reportPath = 'artifacts/reports/MBTI/rehydrate-report/report.json';
        $reportSha = hash('sha256', $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/rehydrate-report/report.json', $reportBytes);

        $pdfBytes = '%PDF-1.4 rehydrate full';
        $pdfPath = 'artifacts/pdf/BIG5/rehydrate-pdf/nohash/report_full.pdf';
        $pdfSha = hash('sha256', $pdfBytes);
        Storage::disk('s3')->put('report_artifacts_archive/pdf/BIG5/rehydrate-pdf/nohash/report_full.pdf', $pdfBytes);

        $legacyPath = 'private/reports/BIG5/rehydrate-pdf/nohash/report_full.pdf';
        Storage::disk('local')->put($legacyPath, '%PDF legacy untouched');

        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $reportPath, 'report_artifacts_archive/reports/MBTI/rehydrate-report/report.json', $reportSha, strlen($reportBytes), 'copied', 'MBTI', 'rehydrate-report'),
            $this->archiveResult('report_full_pdf', $pdfPath, 'report_artifacts_archive/pdf/BIG5/rehydrate-pdf/nohash/report_full.pdf', $pdfSha, strlen($pdfBytes), 'already_archived', 'BIG5', 'rehydrate-pdf'),
        ]);

        /** @var ReportArtifactsRehydrateService $service */
        $service = app(ReportArtifactsRehydrateService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame('storage_rehydrate_report_artifacts_plan.v1', $plan['schema']);
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(0, data_get($plan, 'summary.skipped_count'));
        $this->assertSame(0, data_get($plan, 'summary.blocked_count'));

        $dryRunAudit = DB::table('audit_logs')
            ->where('action', 'storage_rehydrate_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($dryRunAudit);
        $this->assertSame('success', $dryRunAudit->result);
        $dryRunMeta = json_decode((string) $dryRunAudit->meta_json, true);
        $this->assertIsArray($dryRunMeta);
        $this->assertSame('dry_run', $dryRunMeta['mode'] ?? null);
        $this->assertSame(2, $dryRunMeta['candidate_count'] ?? null);
        $this->assertSame(0, $dryRunMeta['results_count'] ?? null);
        $this->assertSame('audit_logs.meta_json', $dryRunMeta['durable_receipt_source'] ?? null);

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/test-plan.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, data_get($result, 'summary.rehydrated_count'));
        $this->assertSame(2, data_get($result, 'summary.verified_count'));
        $this->assertSame(0, data_get($result, 'summary.failed_count'));
        $this->assertFileExists((string) ($result['run_path'] ?? ''));

        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
        $this->assertSame($reportSha, hash('sha256', (string) Storage::disk('local')->get($reportPath)));
        $this->assertSame($pdfSha, hash('sha256', (string) Storage::disk('local')->get($pdfPath)));
        $this->assertTrue(Storage::disk('local')->exists($legacyPath));
    }

    public function test_service_blocks_missing_remote_and_excludes_incomplete_archive_truth(): void
    {
        $validPath = 'artifacts/reports/MBTI/blocked-report/report.json';
        $validSha = hash('sha256', '{"ok":true}');

        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $validPath, 'report_artifacts_archive/reports/MBTI/blocked-report/report.json', $validSha, 11, 'copied', 'MBTI', 'blocked-report'),
            [
                'status' => 'copied',
                'kind' => 'report_json',
                'source_path' => 'artifacts/reports/MBTI/incomplete/report.json',
                'target_disk' => 's3',
                'target_object_key' => '',
                'source_sha256' => '',
                'source_bytes' => 0,
            ],
        ]);

        /** @var ReportArtifactsRehydrateService $service */
        $service = app(ReportArtifactsRehydrateService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame(1, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_count'));
        $this->assertSame($validPath, data_get($plan, 'candidates.0.source_path'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/blocked.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(0, data_get($result, 'summary.rehydrated_count'));
        $this->assertSame(1, data_get($result, 'summary.blocked_count'));
        $this->assertSame('blocked', data_get($result, 'results.0.status'));
        $this->assertSame('REMOTE_OBJECT_MISSING', data_get($result, 'results.0.reason'));
        $this->assertFalse(Storage::disk('local')->exists($validPath));
    }

    public function test_service_skips_existing_local_and_fails_hash_mismatch_visibly(): void
    {
        $existingPath = 'artifacts/reports/MBTI/existing-report/report.json';
        Storage::disk('local')->put($existingPath, '{"existing":true}');
        $existingBytes = '{"existing":true}';
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/existing-report/report.json', $existingBytes);

        $mismatchPath = 'artifacts/pdf/BIG5/mismatch-pdf/nohash/report_free.pdf';
        Storage::disk('s3')->put('report_artifacts_archive/pdf/BIG5/mismatch-pdf/nohash/report_free.pdf', '%PDF-real');

        $legacyPath = 'private/reports/BIG5/mismatch-pdf/nohash/report_free.pdf';
        Storage::disk('local')->put($legacyPath, '%PDF legacy');

        $this->seedArchiveAudit([
            $this->archiveResult('report_json', $existingPath, 'report_artifacts_archive/reports/MBTI/existing-report/report.json', hash('sha256', $existingBytes), strlen($existingBytes), 'copied', 'MBTI', 'existing-report'),
            $this->archiveResult('report_free_pdf', $mismatchPath, 'report_artifacts_archive/pdf/BIG5/mismatch-pdf/nohash/report_free.pdf', hash('sha256', '%PDF-wrong'), strlen('%PDF-real'), 'copied', 'BIG5', 'mismatch-pdf'),
        ]);

        /** @var ReportArtifactsRehydrateService $service */
        $service = app(ReportArtifactsRehydrateService::class);

        $plan = $service->buildPlan('s3');
        $this->assertSame(2, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.skipped_count'));

        $plan['_meta'] = ['plan_path' => storage_path('app/private/report_artifact_rehydrate_plans/mixed.json')];
        $result = $service->executePlan($plan);

        $this->assertSame('partial_failure', $result['status']);
        $this->assertSame(1, data_get($result, 'summary.skipped_count'));
        $this->assertSame(1, data_get($result, 'summary.failed_count'));
        $this->assertContains(
            ['status' => 'skipped', 'reason' => 'LOCAL_CANONICAL_ALREADY_EXISTS'],
            array_map(
                static fn (array $item): array => [
                    'status' => (string) ($item['status'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                ],
                data_get($result, 'results', [])
            )
        );
        $this->assertContains(
            ['status' => 'failed', 'reason' => 'LOCAL_HASH_MISMATCH'],
            array_map(
                static fn (array $item): array => [
                    'status' => (string) ($item['status'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                ],
                data_get($result, 'results', [])
            )
        );
        $this->assertSame('{"existing":true}', (string) Storage::disk('local')->get($existingPath));
        $this->assertFalse(Storage::disk('local')->exists($mismatchPath));
        $this->assertTrue(Storage::disk('local')->exists($legacyPath));

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_rehydrate_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('partial_failure', $audit->result);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame(1, $meta['skipped_count'] ?? null);
        $this->assertSame(1, $meta['failed_count'] ?? null);
        $this->assertSame(2, $meta['results_count'] ?? null);
        $this->assertSame('audit_logs.meta_json', $meta['durable_receipt_source'] ?? null);
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
        string $sourceSha256,
        int $sourceBytes,
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
            'source_sha256' => $sourceSha256,
            'source_bytes' => $sourceBytes,
            'target_bytes' => $sourceBytes,
            'scale_code' => $scaleCode,
            'attempt_id' => $attemptId,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
