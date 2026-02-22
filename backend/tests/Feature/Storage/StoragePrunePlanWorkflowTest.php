<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StoragePrunePlanWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $attemptId;

    private string $attemptDir;

    private string $planPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attemptId = (string) Str::uuid();
        $this->attemptDir = storage_path('app/private/reports/'.$this->attemptId);
        $this->planPath = storage_path('app/private/prune_plans/storage_prune_plan_workflow_'.$this->attemptId.'.json');

        File::ensureDirectoryExists($this->attemptDir);
        File::ensureDirectoryExists(dirname($this->planPath));

        File::put($this->attemptDir.'/report.json', json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($this->attemptDir.'/report.20260101_010101.json', json_encode(['stale' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($this->attemptDir.'/report.20260101_010102.json', json_encode(['stale' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $plan = [
            'schema' => 'storage_prune_plan.v2',
            'scope' => 'reports_backups',
            'generated_at' => now()->toISOString(),
            'entries' => [
                [
                    'scope' => 'reports_backups',
                    'path' => 'reports/'.$this->attemptId.'/report.20260101_010101.json',
                    'bytes' => 0,
                    'mode' => 'local',
                ],
            ],
            'summary' => [
                'files' => 1,
                'bytes' => 0,
                'scopes' => [
                    'reports_backups' => [
                        'files' => 1,
                        'bytes' => 0,
                    ],
                ],
            ],
        ];

        File::put(
            $this->planPath,
            (string) json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->attemptDir)) {
            File::deleteDirectory($this->attemptDir);
        }

        if (is_file($this->planPath)) {
            @unlink($this->planPath);
        }

        parent::tearDown();
    }

    public function test_execute_consumes_plan_only_without_rescanning_scope(): void
    {
        $this->assertFileExists($this->attemptDir.'/report.json');
        $this->assertFileExists($this->attemptDir.'/report.20260101_010101.json');
        $this->assertFileExists($this->attemptDir.'/report.20260101_010102.json');

        $this->artisan('storage:prune --execute --scope=reports_backups --plan='.$this->planPath)
            ->assertExitCode(0);

        $this->assertFileExists($this->attemptDir.'/report.json');
        $this->assertFileDoesNotExist($this->attemptDir.'/report.20260101_010101.json');
        $this->assertFileExists($this->attemptDir.'/report.20260101_010102.json');

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_prune')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) ($audit->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('reports_backups', (string) ($meta['scope'] ?? ''));
        $this->assertSame(1, (int) ($meta['deleted_files_count'] ?? -1));
    }
}
