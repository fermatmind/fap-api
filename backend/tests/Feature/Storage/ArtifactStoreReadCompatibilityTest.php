<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ArtifactStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArtifactStoreReadCompatibilityTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirs as $dir) {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        }

        parent::tearDown();
    }

    public function test_get_report_json_reads_legacy_reports_path(): void
    {
        $attemptId = (string) Str::uuid();
        $legacyDir = storage_path('app/private/reports/'.$attemptId);
        $this->cleanupDirs[] = $legacyDir;

        File::ensureDirectoryExists($legacyDir);
        File::put($legacyDir.'/report.json', json_encode([
            'ok' => true,
            'source' => 'legacy_reports',
        ], JSON_UNESCAPED_UNICODE));

        /** @var ArtifactStore $store */
        $store = app(ArtifactStore::class);
        $payload = $store->getReportJson('MBTI', $attemptId);

        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('legacy_reports', (string) ($payload['source'] ?? ''));
    }

    public function test_get_pdf_reads_legacy_private_reports_path(): void
    {
        $attemptId = (string) Str::uuid();
        $legacyDir = storage_path('app/private/private/reports/BIG5_OCEAN/'.$attemptId.'/hash_v1');
        $this->cleanupDirs[] = storage_path('app/private/private/reports/BIG5_OCEAN/'.$attemptId);

        File::ensureDirectoryExists($legacyDir);
        File::put($legacyDir.'/report_free.pdf', '%PDF legacy artifact bytes%');

        /** @var ArtifactStore $store */
        $store = app(ArtifactStore::class);
        $pdf = $store->getPdf('BIG5_OCEAN', $attemptId, 'hash_v1', 'free');

        $this->assertIsArray($pdf);
        $this->assertSame(
            'private/reports/BIG5_OCEAN/'.$attemptId.'/hash_v1/report_free.pdf',
            (string) ($pdf['path'] ?? '')
        );
        $this->assertSame('%PDF legacy artifact bytes%', (string) ($pdf['binary'] ?? ''));
    }
}
