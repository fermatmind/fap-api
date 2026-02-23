<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Report\Composer\ReportPersistence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportPersistenceStopsTimestampBackupsTest extends TestCase
{
    public function test_persist_only_writes_canonical_report_json(): void
    {
        $attemptId = (string) Str::uuid();
        $legacyDir = storage_path('app/private/reports/'.$attemptId);
        $canonicalDir = storage_path('app/private/artifacts/reports/MBTI/'.$attemptId);
        File::ensureDirectoryExists($legacyDir);

        app(ReportPersistence::class)->persist($attemptId, ['ok' => true]);

        $this->assertFileExists($canonicalDir.'/report.json');
        $this->assertFileDoesNotExist($legacyDir.'/report.json');
        $this->assertSame([], glob($legacyDir.'/report.*.json') ?: []);

        File::deleteDirectory($legacyDir);
        File::deleteDirectory($canonicalDir);
    }
}
