<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_outputs_json_fields_and_writes_audit_log(): void
    {
        $attemptId = (string) Str::uuid();
        Storage::disk('local')->put("artifacts/reports/MBTI/{$attemptId}/report.json", '{"ok":true}');
        Storage::disk('local')->put("artifacts/pdf/MBTI/{$attemptId}/nohash/report_free.pdf", '%PDF-1.4');
        Storage::disk('local')->put('content_releases/test-release/source_pack/compiled/manifest.json', '{"ok":true}');

        $this->assertSame(0, Artisan::call('storage:inventory', [
            '--json' => true,
        ]));
        $output = (string) Artisan::output();
        $this->assertStringContainsString('"scope":"reports"', $output);
        $this->assertStringContainsString('"scope":"artifacts"', $output);
        $this->assertStringContainsString('"scope":"content_releases"', $output);
        $this->assertStringContainsString('"files"', $output);
        $this->assertStringContainsString('"bytes"', $output);
        $this->assertStringContainsString('"oldest_mtime"', $output);
        $this->assertStringContainsString('"newest_mtime"', $output);
        $this->assertStringContainsString('"top_dirs"', $output);

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'storage_inventory')->exists(),
            'expected storage_inventory audit log'
        );

        Storage::disk('local')->deleteDirectory("artifacts/reports/MBTI/{$attemptId}");
        Storage::disk('local')->deleteDirectory("artifacts/pdf/MBTI/{$attemptId}");
        Storage::disk('local')->deleteDirectory('content_releases/test-release');
    }
}
