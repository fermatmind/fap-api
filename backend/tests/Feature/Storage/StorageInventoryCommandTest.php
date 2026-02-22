<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marker = str_replace('-', '', strtolower((string) Str::uuid()));

        $reportPath = storage_path('app/private/artifacts/reports/INVENTORY_TEST/'.$this->marker.'/report.json');
        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $packManifestPath = storage_path('app/private/packs_v2/INVENTORY_TEST/v1/'.$this->marker.'/manifest.json');
        File::ensureDirectoryExists(dirname($packManifestPath));
        File::put($packManifestPath, json_encode(['compiled_hash' => $this->marker], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $logPath = storage_path('logs/'.$this->marker.'.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, 'inventory test');
    }

    protected function tearDown(): void
    {
        $paths = [
            storage_path('app/private/artifacts/reports/INVENTORY_TEST/'.$this->marker),
            storage_path('app/private/packs_v2/INVENTORY_TEST/v1/'.$this->marker),
        ];

        foreach ($paths as $path) {
            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }

        $logPath = storage_path('logs/'.$this->marker.'.log');
        if (is_file($logPath)) {
            @unlink($logPath);
        }

        parent::tearDown();
    }

    public function test_inventory_outputs_complete_non_negative_stats_and_writes_audit(): void
    {
        $exitCode = Artisan::call('storage:inventory', ['--json' => 1]);
        $this->assertSame(0, $exitCode);

        $payload = $this->decodeLastJsonLine(Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('storage_inventory_snapshot.v1', (string) ($payload['schema'] ?? ''));

        $scopes = is_array($payload['scopes'] ?? null) ? $payload['scopes'] : [];
        $requiredScopes = ['artifacts', 'reports', 'packs_v2', 'releases', 'content_releases', 'prune_plans', 'logs'];

        foreach ($requiredScopes as $scope) {
            $this->assertArrayHasKey($scope, $scopes);
            $stats = is_array($scopes[$scope] ?? null) ? $scopes[$scope] : [];

            $this->assertArrayHasKey('files_count', $stats);
            $this->assertArrayHasKey('bytes_total', $stats);
            $this->assertArrayHasKey('oldest_mtime', $stats);
            $this->assertArrayHasKey('newest_mtime', $stats);
            $this->assertArrayHasKey('top_children', $stats);

            $this->assertGreaterThanOrEqual(0, (int) ($stats['files_count'] ?? -1));
            $this->assertGreaterThanOrEqual(0, (int) ($stats['bytes_total'] ?? -1));

            $topChildren = is_array($stats['top_children'] ?? null) ? $stats['top_children'] : [];
            foreach ($topChildren as $child) {
                $this->assertIsArray($child);
                $this->assertArrayHasKey('name', $child);
                $this->assertArrayHasKey('files_count', $child);
                $this->assertArrayHasKey('bytes_total', $child);
                $this->assertGreaterThanOrEqual(0, (int) ($child['files_count'] ?? -1));
                $this->assertGreaterThanOrEqual(0, (int) ($child['bytes_total'] ?? -1));
            }
        }

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_inventory_snapshot')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('storage_inventory_snapshot.v1', (string) ($meta['schema'] ?? ''));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeLastJsonLine(string $output): ?array
    {
        $lines = preg_split('/\R/', trim($output));
        if (! is_array($lines)) {
            return null;
        }

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim((string) ($lines[$i] ?? ''));
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
