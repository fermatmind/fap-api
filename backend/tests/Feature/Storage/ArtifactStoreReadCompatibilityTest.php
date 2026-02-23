<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ArtifactStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactStoreReadCompatibilityTest extends TestCase
{
    public function test_reads_legacy_report_json_via_fallback_paths(): void
    {
        $attemptId = (string) Str::uuid();
        $legacyPath = storage_path('app/private/reports/'.$attemptId.'/report.json');
        File::ensureDirectoryExists(dirname($legacyPath));
        File::put($legacyPath, json_encode(['ok' => true, 'attempt_id' => $attemptId], JSON_UNESCAPED_UNICODE));

        /** @var ArtifactStore $store */
        $store = app(ArtifactStore::class);
        $report = $store->getReportJson('MBTI', $attemptId);

        $this->assertIsArray($report);
        $this->assertSame($attemptId, (string) ($report['attempt_id'] ?? ''));

        File::deleteDirectory(storage_path('app/private/reports/'.$attemptId));
    }

    public function test_reads_legacy_private_private_pdf_via_fallback_candidates(): void
    {
        $attemptId = (string) Str::uuid();
        $scaleCode = 'BIG5_OCEAN';
        $manifestHash = 'legacyhash123';
        $legacyPath = "private/reports/{$scaleCode}/{$attemptId}/{$manifestHash}/report_free.pdf";
        $legacyPdf = '%PDF-1.4 legacy fallback';
        Storage::disk('local')->put($legacyPath, $legacyPdf);

        /** @var ArtifactStore $store */
        $store = app(ArtifactStore::class);
        $canonical = $store->pdfCanonicalPath($scaleCode, $attemptId, $manifestHash, 'free');
        $candidates = array_merge([$canonical], $store->pdfLegacyPaths($scaleCode, $attemptId, $manifestHash, 'free'));
        $resolved = $store->getFirstFile($candidates);

        $this->assertSame($legacyPdf, $resolved);

        Storage::disk('local')->deleteDirectory("private/reports/{$scaleCode}/{$attemptId}");
        Storage::disk('local')->deleteDirectory("artifacts/pdf/{$scaleCode}/{$attemptId}");
    }
}
