<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Console\Commands\StorageShrinkArchivedReportArtifacts;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StorageShrinkArchivedReportArtifactsCommandTest extends TestCase
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
        $this->isolatedStoragePath = sys_get_temp_dir().'/fap-report-artifacts-shrink-command-'.Str::uuid();
        File::ensureDirectoryExists($this->isolatedStoragePath.'/app/private');
        $this->app->useStoragePath($this->isolatedStoragePath);
        config()->set('filesystems.disks.local.root', $this->isolatedStoragePath.'/app/private');
        config()->set('filesystems.disks.s3.bucket', 'report-artifacts-shrink-command-bucket');
        config()->set('filesystems.disks.s3.region', 'ap-guangzhou');
        config()->set('filesystems.disks.s3.endpoint', 'https://cos.report-shrink-command.test');
        Storage::forgetDisk('local');
        Storage::fake('s3');

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(StorageShrinkArchivedReportArtifacts::class)
        );
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

    public function test_command_requires_exactly_one_mode_and_plan_for_execute(): void
    {
        $this->artisan('storage:shrink-archived-report-artifacts')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-archived-report-artifacts --dry-run --execute')
            ->expectsOutputToContain('exactly one of --dry-run or --execute is required.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-archived-report-artifacts --execute --disk=s3')
            ->expectsOutputToContain('--execute requires --plan.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-archived-report-artifacts --execute --disk=s3 --plan=fake-plan.json --attempt-id=attempt-1')
            ->expectsOutputToContain('--attempt-id and --limit are only supported with --dry-run.')
            ->assertExitCode(1);

        $this->artisan('storage:shrink-archived-report-artifacts --dry-run --disk=s3 --limit=0')
            ->expectsOutputToContain('--limit must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_command_dry_run_execute_json_and_skipped_missing_local_are_visible(): void
    {
        $this->seedCanonicalArchivedArtifact('command-shrink');

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
        ]));
        $dryRunOutput = Artisan::output();
        $this->assertStringContainsString('status=planned', $dryRunOutput);
        $this->assertStringContainsString('selection_scope=full_scan', $dryRunOutput);
        $this->assertStringContainsString('requested_attempt_ids=', $dryRunOutput);
        $this->assertStringContainsString('requested_limit=', $dryRunOutput);
        $this->assertStringContainsString('candidate_count=1', $dryRunOutput);
        $this->assertStringContainsString('deleted_count=0', $dryRunOutput);
        $this->assertStringContainsString('blocked_missing_archive_proof_count=0', $dryRunOutput);

        preg_match('/^plan=(.+)$/m', $dryRunOutput, $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $executeOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $executeOutput);
        $this->assertStringContainsString('selection_scope=full_scan', $executeOutput);
        $this->assertStringContainsString('deleted_count=1', $executeOutput);
        $this->assertStringContainsString('run_path=', $executeOutput);
        $this->assertFalse(Storage::disk('local')->exists('artifacts/reports/MBTI/command-shrink/report.json'));

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));
        $skipOutput = Artisan::output();
        $this->assertStringContainsString('status=executed', $skipOutput);
        $this->assertStringContainsString('deleted_count=0', $skipOutput);
        $this->assertStringContainsString('skipped_missing_local_count=1', $skipOutput);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--json' => true,
        ]));
        $jsonPayload = json_decode(Artisan::output(), true);
        $this->assertIsArray($jsonPayload);
        $this->assertSame('storage_shrink_archived_report_artifacts_plan.v1', $jsonPayload['schema'] ?? null);
        $this->assertSame(0, data_get($jsonPayload, 'summary.candidate_count'));
        $this->assertSame('full_scan', $jsonPayload['selection_scope'] ?? null);
        $this->assertSame([], $jsonPayload['requested_attempt_ids'] ?? null);
        $this->assertNull($jsonPayload['requested_limit'] ?? null);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_shrink_archived_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame('dry_run', $meta['mode'] ?? null);
        $this->assertSame('audit_logs.meta_json', $meta['durable_receipt_source'] ?? null);
        $this->assertSame('full_scan', $meta['selection_scope'] ?? null);
    }

    public function test_command_can_build_attempt_scoped_dry_run_plan(): void
    {
        $this->seedCanonicalArchivedArtifact('attempt-b');
        $this->seedCanonicalArchivedArtifact('attempt-a');

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['attempt-b', 'attempt-a'],
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('attempt_scoped', $payload['selection_scope'] ?? null);
        $this->assertSame(['attempt-a', 'attempt-b'], $payload['requested_attempt_ids'] ?? null);
        $this->assertNull($payload['requested_limit'] ?? null);
        $this->assertSame(2, $payload['candidate_count'] ?? null);
        $this->assertSame(2, data_get($payload, 'summary.candidate_count'));
        $this->assertSame(['attempt-a', 'attempt-b'], array_column((array) ($payload['candidates'] ?? []), 'attempt_id'));
    }

    public function test_command_can_build_limit_scoped_plan_without_hiding_blocked_summary(): void
    {
        $this->seedCanonicalArchivedArtifact('limit-a');
        $this->seedCanonicalArchivedArtifact('limit-b');
        Storage::disk('local')->put('artifacts/reports/MBTI/blocked-missing-proof/report.json', '{"attempt":"blocked-missing-proof"}');

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--limit' => '1',
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('limit_scoped', $payload['selection_scope'] ?? null);
        $this->assertSame([], $payload['requested_attempt_ids'] ?? null);
        $this->assertSame(1, $payload['requested_limit'] ?? null);
        $this->assertSame(1, $payload['candidate_count'] ?? null);
        $this->assertSame(1, data_get($payload, 'summary.candidate_count'));
        $this->assertSame(1, data_get($payload, 'summary.blocked_missing_archive_proof_count'));
        $this->assertSame('limit-a', data_get($payload, 'candidates.0.attempt_id'));
    }

    public function test_command_combines_attempt_filter_before_limit(): void
    {
        $this->seedCanonicalArchivedArtifact('combo-a');
        $this->seedCanonicalArchivedArtifact('combo-b');
        $this->seedCanonicalArchivedArtifact('combo-c');

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['combo-c', 'combo-a'],
            '--limit' => '1',
            '--json' => true,
        ]));

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('attempt_scoped_limited', $payload['selection_scope'] ?? null);
        $this->assertSame(['combo-a', 'combo-c'], $payload['requested_attempt_ids'] ?? null);
        $this->assertSame(1, $payload['requested_limit'] ?? null);
        $this->assertSame(1, $payload['candidate_count'] ?? null);
        $this->assertSame(1, data_get($payload, 'summary.candidate_count'));
        $this->assertSame('combo-a', data_get($payload, 'candidates.0.attempt_id'));
    }

    public function test_command_execute_marks_legacy_plan_as_unscoped_for_compatibility(): void
    {
        $this->seedCanonicalArchivedArtifact('legacy-plan-attempt');
        $planPath = storage_path('app/private/report_artifact_shrink_plans/legacy-plan.json');
        File::ensureDirectoryExists(dirname($planPath));
        File::put($planPath, json_encode([
            'schema' => 'storage_shrink_archived_report_artifacts_plan.v1',
            'mode' => 'dry_run',
            'status' => 'planned',
            'generated_at' => now()->toIso8601String(),
            'disk' => 's3',
            'target_disk' => 's3',
            'summary' => [
                'candidate_count' => 1,
                'deleted_count' => 0,
                'skipped_missing_local_count' => 0,
                'blocked_legal_hold_count' => 0,
                'blocked_missing_remote_count' => 0,
                'blocked_missing_archive_proof_count' => 0,
                'blocked_missing_rehydrate_proof_count' => 0,
                'blocked_hash_mismatch_count' => 0,
                'failed_count' => 0,
            ],
            'candidates' => [[
                'kind' => 'report_json',
                'local_path' => 'artifacts/reports/MBTI/legacy-plan-attempt/report.json',
                'target_disk' => 's3',
                'target_object_key' => 'report_artifacts_archive/reports/MBTI/legacy-plan-attempt/report.json',
                'source_sha256' => hash('sha256', '{"attempt":"legacy-plan-attempt"}'),
                'source_bytes' => strlen('{"attempt":"legacy-plan-attempt"}'),
                'archive_audit_id' => 1,
                'rehydrate_ready' => true,
                'archived_status' => 'copied',
                'scale_code' => 'MBTI',
                'attempt_id' => 'legacy-plan-attempt',
            ]],
            'blocked' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('selection_scope=legacy_unscoped_plan', $output);
        $this->assertStringContainsString('requested_attempt_ids=', $output);
        $this->assertStringContainsString('requested_limit=', $output);
    }

    public function test_command_execute_surfaces_scoped_plan_metadata_for_auditability(): void
    {
        $this->seedCanonicalArchivedArtifact('scoped-plan-attempt');

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--dry-run' => true,
            '--disk' => 's3',
            '--attempt-id' => ['scoped-plan-attempt'],
        ]));
        preg_match('/^plan=(.+)$/m', Artisan::output(), $matches);
        $planPath = trim((string) ($matches[1] ?? ''));
        $this->assertFileExists($planPath);

        $this->assertSame(0, Artisan::call('storage:shrink-archived-report-artifacts', [
            '--execute' => true,
            '--disk' => 's3',
            '--plan' => $planPath,
        ]));

        $output = Artisan::output();
        $this->assertStringContainsString('selection_scope=attempt_scoped', $output);
        $this->assertStringContainsString('requested_attempt_ids=scoped-plan-attempt', $output);
        $this->assertStringContainsString('requested_limit=', $output);

        $audit = DB::table('audit_logs')
            ->where('action', 'storage_shrink_archived_report_artifacts')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $meta = json_decode((string) $audit->meta_json, true);
        $this->assertIsArray($meta);
        $this->assertSame('attempt_scoped', $meta['selection_scope'] ?? null);
        $this->assertSame(['scoped-plan-attempt'], $meta['requested_attempt_ids'] ?? null);
        $this->assertNull($meta['requested_limit'] ?? null);
    }

    private function seedCanonicalArchivedArtifact(string $attemptId): void
    {
        $reportBytes = json_encode(['attempt' => $attemptId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($reportBytes);
        $reportPath = 'artifacts/reports/MBTI/'.$attemptId.'/report.json';
        Storage::disk('local')->put($reportPath, $reportBytes);
        Storage::disk('s3')->put('report_artifacts_archive/reports/MBTI/'.$attemptId.'/report.json', $reportBytes);

        $this->seedArchiveAudit([
            [
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
            ],
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $results
     */
    private function seedArchiveAudit(array $results): void
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
                'results' => $results,
                'summary' => [
                    'candidate_count' => count($results),
                    'copied_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'copied')),
                    'verified_count' => count(array_filter($results, static fn (array $result): bool => in_array((string) ($result['status'] ?? ''), ['copied', 'already_archived'], true))),
                    'already_archived_count' => count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'already_archived')),
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
