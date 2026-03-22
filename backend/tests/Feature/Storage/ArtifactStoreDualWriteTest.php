<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\Attempt;
use App\Services\Report\Pdf\ReportPdfArtifactStore;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Storage\ArtifactStore;
use App\Services\Storage\BlobCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactStoreDualWriteTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-artifact-dual-write-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath);
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
        config()->set('storage_rollout.blob_catalog_enabled', false);
        config()->set('storage_rollout.artifact_dual_write_enabled', false);
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', false);
        config()->set('storage_rollout.artifact_slot_version_dual_write_enabled', false);
        config()->set('storage_rollout.lifecycle_ledger_dual_write_enabled', false);
        config()->set('storage_rollout.access_projection_dual_write_enabled', false);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('local');
        $this->app->useStoragePath($this->originalStoragePath);
        config()->set('filesystems.disks.local.root', $this->originalLocalRoot);
        Storage::forgetDisk('local');

        if (is_dir($this->isolatedStoragePath)) {
            File::deleteDirectory($this->isolatedStoragePath);
        }

        parent::tearDown();
    }

    public function test_flags_disabled_canonical_report_and_pdf_writes_do_not_create_blob_rows(): void
    {
        $store = app(ArtifactStore::class);
        $pdfStore = app(ReportPdfArtifactStore::class);
        $attemptId = (string) Str::uuid();

        $reportPath = $store->putReportJson('MBTI', $attemptId, [
            'ok' => true,
            'attempt_id' => $attemptId,
        ]);
        $pdfPath = $store->pdfCanonicalPath('MBTI', $attemptId, 'manifesthash', 'free');
        $pdfStore->put($pdfPath, '%PDF-1.4 disabled');

        $this->assertTrue(Storage::disk('local')->exists($reportPath));
        $this->assertTrue(Storage::disk('local')->exists($pdfPath));
        $this->assertDatabaseCount('storage_blobs', 0);
        $this->assertDatabaseCount('storage_blob_locations', 0);
        $this->assertDatabaseCount('attempt_receipts', 0);
        $this->assertDatabaseCount('report_artifact_slots', 0);
        $this->assertDatabaseCount('report_artifact_versions', 0);
        $this->assertDatabaseCount('unified_access_projections', 0);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private/blobs');
    }

    public function test_flags_enabled_canonical_report_and_pdf_writes_create_blob_rows_and_same_bytes_do_not_duplicate(): void
    {
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.artifact_dual_write_enabled', true);
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.artifact_slot_version_dual_write_enabled', true);

        $store = app(ArtifactStore::class);
        $pdfStore = app(ReportPdfArtifactStore::class);
        $blobCatalog = app(BlobCatalogService::class);
        $attemptId = (string) Str::uuid();

        $reportPayload = [
            'ok' => true,
            'attempt_id' => $attemptId,
            'nested' => ['key' => 'value'],
        ];

        $reportPath = $store->putReportJson('MBTI', $attemptId, $reportPayload);
        $pdfPath = $store->pdfCanonicalPath('MBTI', $attemptId, 'manifesthash', 'free');
        $pdfStore->put($pdfPath, '%PDF-1.4 enabled');

        $store->putReportJson('MBTI', $attemptId, $reportPayload);
        $pdfStore->put($pdfPath, '%PDF-1.4 enabled');

        $reportBytes = (string) Storage::disk('local')->get($reportPath);
        $pdfBytes = (string) Storage::disk('local')->get($pdfPath);
        $reportHash = hash('sha256', $reportBytes);
        $pdfHash = hash('sha256', $pdfBytes);

        $this->assertDatabaseCount('storage_blobs', 2);
        $this->assertDatabaseCount('storage_blob_locations', 2);
        $this->assertDatabaseCount('attempt_receipts', 2);
        $this->assertDatabaseCount('report_artifact_slots', 2);
        $this->assertDatabaseCount('report_artifact_versions', 2);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $reportHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($reportHash),
            'size_bytes' => strlen($reportBytes),
            'content_type' => 'application/json',
            'encoding' => 'identity',
            'ref_count' => 0,
        ]);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $pdfHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($pdfHash),
            'size_bytes' => strlen($pdfBytes),
            'content_type' => 'application/pdf',
            'encoding' => 'identity',
            'ref_count' => 0,
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $reportHash,
            'disk' => 'local',
            'storage_path' => $reportPath,
            'location_kind' => 'canonical_file',
        ]);
        $this->assertDatabaseHas('storage_blob_locations', [
            'blob_hash' => $pdfHash,
            'disk' => 'local',
            'storage_path' => $pdfPath,
            'location_kind' => 'canonical_file',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'report_json_materialized',
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'report_pdf_materialized',
        ]);
        $this->assertDatabaseHas('report_artifact_slots', [
            'attempt_id' => $attemptId,
            'slot_code' => 'report_json_full',
            'render_state' => 'materialized',
            'delivery_state' => 'available',
            'integrity_state' => 'verified',
        ]);
        $this->assertDatabaseHas('report_artifact_slots', [
            'attempt_id' => $attemptId,
            'slot_code' => 'report_pdf_free',
            'render_state' => 'materialized',
            'delivery_state' => 'available',
            'integrity_state' => 'verified',
        ]);
        $this->assertDatabaseHas('report_artifact_versions', [
            'source_type' => 'report_json',
            'content_hash' => $reportHash,
        ]);
        $this->assertDatabaseHas('report_artifact_versions', [
            'source_type' => 'report_pdf',
            'content_hash' => $pdfHash,
        ]);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private/blobs');
    }

    public function test_flags_enabled_rewriting_same_canonical_report_path_with_new_bytes_is_safe_and_catalogs_a_new_hash(): void
    {
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.artifact_dual_write_enabled', true);

        $store = app(ArtifactStore::class);
        $blobCatalog = app(BlobCatalogService::class);
        $attemptId = (string) Str::uuid();

        $path = $store->putReportJson('MBTI', $attemptId, ['version' => 1]);
        $firstBytes = (string) Storage::disk('local')->get($path);
        $firstHash = hash('sha256', $firstBytes);

        $pathAgain = $store->putReportJson('MBTI', $attemptId, ['version' => 2]);
        $secondBytes = (string) Storage::disk('local')->get($pathAgain);
        $secondHash = hash('sha256', $secondBytes);

        $this->assertSame($path, $pathAgain);
        $this->assertNotSame($firstHash, $secondHash);
        $this->assertDatabaseCount('storage_blobs', 2);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $firstHash,
            'storage_path' => $blobCatalog->storagePathForHash($firstHash),
            'content_type' => 'application/json',
        ]);
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $secondHash,
            'storage_path' => $blobCatalog->storagePathForHash($secondHash),
            'content_type' => 'application/json',
        ]);
        $this->assertJsonStringEqualsJsonString('{"version":2}', $secondBytes);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private/blobs');
    }

    public function test_flags_enabled_pdf_legacy_backfill_also_dual_writes_blob_metadata(): void
    {
        config()->set('storage_rollout.blob_catalog_enabled', true);
        config()->set('storage_rollout.artifact_dual_write_enabled', true);

        $store = app(ArtifactStore::class);
        $service = app(ReportPdfDocumentService::class);
        $blobCatalog = app(BlobCatalogService::class);
        $attemptId = (string) Str::uuid();
        $manifestHash = 'legacyhash123';
        $legacyBytes = '%PDF-1.4 legacy backfill';
        $legacyPath = $store->pdfLegacyPaths('BIG5_OCEAN', $attemptId, $manifestHash, 'free')[0];
        Storage::disk('local')->put($legacyPath, $legacyBytes);

        $attempt = new Attempt([
            'id' => $attemptId,
            'scale_code' => 'BIG5_OCEAN',
            'answers_summary_json' => [
                'meta' => [
                    'pack_release_manifest_hash' => $manifestHash,
                ],
            ],
        ]);

        $resolved = $service->readArtifact($attempt, 'free');
        $canonicalPath = $store->pdfCanonicalPath('BIG5_OCEAN', $attemptId, $manifestHash, 'free');
        $pdfHash = hash('sha256', $legacyBytes);

        $this->assertSame($legacyBytes, $resolved);
        $this->assertTrue(Storage::disk('local')->exists($canonicalPath));
        $this->assertSame($legacyBytes, (string) Storage::disk('local')->get($canonicalPath));
        $this->assertDatabaseHas('storage_blobs', [
            'hash' => $pdfHash,
            'disk' => 'local',
            'storage_path' => $blobCatalog->storagePathForHash($pdfHash),
            'size_bytes' => strlen($legacyBytes),
            'content_type' => 'application/pdf',
            'encoding' => 'identity',
            'ref_count' => 0,
        ]);
        $this->assertDirectoryDoesNotExist($this->isolatedStoragePath.'/app/private/blobs');
    }
}
