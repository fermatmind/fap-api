<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\Storage\ReportArtifactsShrinkService;
use App\Services\Storage\RetentionPolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetentionPolicyLegalHoldTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-retention-legal-hold-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'retention-legal-hold-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.retention-legal-hold.test');
        config()->set('storage_rollout.retention_policy_engine_enabled', true);
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

    public function test_retention_binding_is_created_and_legal_hold_blocks_shrink_candidates(): void
    {
        DB::table('retention_policies')->insert([
            'code' => 'default_attempt_artifact_policy',
            'subject_scope' => 'attempt',
            'artifact_scope' => 'report_artifact_domain',
            'archive_after_days' => 30,
            'shrink_after_days' => 60,
            'purge_after_days' => 365,
            'delete_behavior' => 'retain_catalog_only',
            'delete_remote_archive' => false,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attemptId = 'hold-blocked-shrink';
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        $reportBytes = '{"attempt_id":"hold-blocked-shrink"}';
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', $reportBytes);
        $this->seedArchiveAudit($attemptId, $reportPath, $reportBytes);

        /** @var RetentionPolicyResolver $resolver */
        $resolver = app(RetentionPolicyResolver::class);
        $binding = $resolver->ensureAttemptBinding($attemptId, 'phpunit');
        $this->assertNotNull($binding);
        $this->assertDatabaseHas('attempt_retention_bindings', [
            'attempt_id' => $attemptId,
            'bound_by' => 'phpunit',
        ]);

        DB::table('legal_holds')->insert([
            'scope_type' => 'attempt',
            'scope_id' => $attemptId,
            'reason_code' => 'LEGAL_HOLD_ACTIVE',
            'placed_by' => 'phpunit',
            'active_from' => now()->subMinute(),
            'released_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var ReportArtifactsShrinkService $service */
        $service = app(ReportArtifactsShrinkService::class);
        $plan = $service->buildPlan('s3');

        $this->assertSame(0, data_get($plan, 'summary.candidate_count'));
        $this->assertSame(1, data_get($plan, 'summary.blocked_legal_hold_count'));
        $this->assertSame('blocked_legal_hold', data_get($plan, 'blocked.0.status'));
        $this->assertSame('LEGAL_HOLD_ACTIVE', data_get($plan, 'blocked.0.reason'));
    }

    private function seedArchiveAudit(string $attemptId, string $reportPath, string $reportBytes): void
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
                'results' => [[
                    'status' => 'copied',
                    'kind' => 'report_json',
                    'source_path' => $reportPath,
                    'target_disk' => 's3',
                    'target_object_key' => 'report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json',
                    'source_sha256' => hash('sha256', $reportBytes),
                    'source_bytes' => strlen($reportBytes),
                    'target_bytes' => strlen($reportBytes),
                    'scale_code' => 'MBTI',
                    'attempt_id' => $attemptId,
                    'verified_at' => now()->toIso8601String(),
                ]],
                'summary' => [
                    'candidate_count' => 1,
                    'copied_count' => 1,
                    'verified_count' => 1,
                    'already_archived_count' => 0,
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
}
