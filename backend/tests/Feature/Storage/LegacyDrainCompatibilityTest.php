<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\Attempt;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Storage\ArtifactStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyDrainCompatibilityTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-legacy-drain-compat-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        Storage::forgetDisk('local');
        Storage::fake('local');
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

    public function test_legacy_pdf_read_backfills_canonical_path_when_drain_flag_is_off(): void
    {
        config()->set('storage_rollout.legacy_drain_enabled', false);

        $attemptId = (string) Str::uuid();
        $manifestHash = 'legacydrainoff';
        $legacyBytes = '%PDF-1.4 legacy drain off';
        /** @var ArtifactStore $artifactStore */
        $artifactStore = app(ArtifactStore::class);
        $legacyPath = $artifactStore->pdfLegacyPaths('BIG5_OCEAN', $attemptId, $manifestHash, 'free')[0];
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

        /** @var ReportPdfDocumentService $service */
        $service = app(ReportPdfDocumentService::class);
        $binary = $service->readArtifact($attempt, 'free');
        $canonicalPath = $service->resolveArtifactPath($attempt, 'free');

        $this->assertSame($legacyBytes, $binary);
        $this->assertTrue(Storage::disk('local')->exists($canonicalPath));
    }

    public function test_legacy_pdf_read_stops_canonical_write_back_when_drain_flag_is_on(): void
    {
        config()->set('storage_rollout.legacy_drain_enabled', true);

        $attemptId = (string) Str::uuid();
        $manifestHash = 'legacydrainon';
        $legacyBytes = '%PDF-1.4 legacy drain on';
        /** @var ArtifactStore $artifactStore */
        $artifactStore = app(ArtifactStore::class);
        $legacyPath = $artifactStore->pdfLegacyPaths('BIG5_OCEAN', $attemptId, $manifestHash, 'free')[0];
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

        /** @var ReportPdfDocumentService $service */
        $service = app(ReportPdfDocumentService::class);
        $binary = $service->readArtifact($attempt, 'free');
        $canonicalPath = $service->resolveArtifactPath($attempt, 'free');

        $this->assertSame($legacyBytes, $binary);
        $this->assertFalse(Storage::disk('local')->exists($canonicalPath));
    }
}
