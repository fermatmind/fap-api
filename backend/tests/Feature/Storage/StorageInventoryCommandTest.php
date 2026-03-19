<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_outputs_json_fields_and_writes_audit_log(): void
    {
        $attemptId = (string) Str::uuid();
        $logPath = storage_path('logs/laravel.log');

        try {
            Storage::disk('local')->put("artifacts/reports/MBTI/{$attemptId}/report.json", '{"ok":true,"payload":"duplicate"}');
            Storage::disk('local')->put("artifacts/reports/MBTI/{$attemptId}/report_copy.json", '{"ok":true,"payload":"duplicate"}');
            Storage::disk('local')->put("artifacts/pdf/MBTI/{$attemptId}/nohash/report_free.pdf", str_repeat('%PDF-1.4', 32));
            Storage::disk('local')->put('content_releases/test-release/source_pack/compiled/manifest.json', '{"ok":true}');
            File::ensureDirectoryExists(dirname($logPath));
            File::put($logPath, str_repeat('{"message":"storage_inventory_test"}'.PHP_EOL, 4));

            $this->assertSame(0, Artisan::call('storage:inventory', [
                '--json' => true,
            ]));

            $payload = json_decode((string) Artisan::output(), true);
            $this->assertIsArray($payload);
            $this->assertSame(2, (int) ($payload['schema_version'] ?? 0));
            $this->assertArrayHasKey('generated_at', $payload);
            $this->assertArrayHasKey('scope_count', $payload);
            $this->assertArrayHasKey('totals', $payload);
            $this->assertArrayHasKey('scope_totals', $payload);
            $this->assertArrayHasKey('focus_scopes', $payload);
            $this->assertArrayHasKey('scopes', $payload);
            $this->assertSame(count($payload['scopes'] ?? []), (int) ($payload['scope_count'] ?? -1));

            $focusScopes = is_array($payload['focus_scopes'] ?? null) ? $payload['focus_scopes'] : [];
            $this->assertArrayHasKey('logs', $focusScopes);
            $this->assertArrayHasKey('artifacts', $focusScopes);
            $this->assertArrayHasKey('content_releases', $focusScopes);
            $this->assertSame(
                (int) data_get($payload, 'scope_totals.artifacts.files', -1),
                (int) data_get($focusScopes, 'artifacts.files', -2)
            );

            $scopes = collect($payload['scopes'] ?? [])->keyBy('scope');
            $this->assertTrue($scopes->has('reports'));
            $this->assertTrue($scopes->has('artifacts'));
            $this->assertTrue($scopes->has('content_releases'));
            $this->assertTrue($scopes->has('logs'));

            $artifacts = $scopes->get('artifacts');
            $this->assertIsArray($artifacts);
            $this->assertArrayHasKey('files', $artifacts);
            $this->assertArrayHasKey('bytes', $artifacts);
            $this->assertArrayHasKey('oldest_mtime', $artifacts);
            $this->assertArrayHasKey('newest_mtime', $artifacts);
            $this->assertArrayHasKey('top_dirs', $artifacts);
            $this->assertArrayHasKey('top_dirs_by_bytes', $artifacts);
            $this->assertArrayHasKey('top_files', $artifacts);
            $this->assertArrayHasKey('duplicate_summary', $artifacts);
            $this->assertNotEmpty($artifacts['top_files']);
            $this->assertGreaterThanOrEqual(1, (int) data_get($artifacts, 'duplicate_summary.groups', 0));
            $this->assertGreaterThanOrEqual(2, (int) data_get($artifacts, 'duplicate_summary.files', 0));

            $audit = DB::table('audit_logs')->where('action', 'storage_inventory')->first();
            $this->assertNotNull($audit, 'expected storage_inventory audit log');
            $auditPayload = json_decode((string) ($audit->meta_json ?? ''), true);
            $this->assertIsArray($auditPayload);
            $this->assertSame(2, (int) ($auditPayload['schema_version'] ?? 0));
            $this->assertArrayHasKey('focus_scopes', $auditPayload);
            $this->assertArrayHasKey('scopes', $auditPayload);
        } finally {
            Storage::disk('local')->deleteDirectory("artifacts/reports/MBTI/{$attemptId}");
            Storage::disk('local')->deleteDirectory("artifacts/pdf/MBTI/{$attemptId}");
            Storage::disk('local')->deleteDirectory('content_releases/test-release');
            File::delete($logPath);
        }
    }
}
