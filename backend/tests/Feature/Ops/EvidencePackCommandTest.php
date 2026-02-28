<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidencePackCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_evidence_pack_with_required_artifacts(): void
    {
        $this->useDatabaseQueueDriver();

        $revision = 'fer2_25_revision_test';
        $timestamp = '20260227T160000Z';
        $packDir = storage_path('app/evidence/'.$revision.'/'.$timestamp);
        File::deleteDirectory($packDir);

        $now = now();

        DB::table('jobs')->insert([
            [
                'queue' => 'attempts',
                'payload' => '{"job":"attempt.submit"}',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now->copy()->subSeconds(90)->timestamp,
                'created_at' => $now->copy()->subSeconds(90)->timestamp,
            ],
            [
                'queue' => 'reports',
                'payload' => '{"job":"report.snapshot"}',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now->copy()->subSeconds(60)->timestamp,
                'created_at' => $now->copy()->subSeconds(60)->timestamp,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'attempts',
            'payload' => '{"job":"attempt.submit"}',
            'exception' => 'TimeoutExceededException: worker timed out.',
            'failed_at' => $now->copy()->subMinutes(3),
        ]);

        $logPath = storage_path('logs/laravel.log');
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, '');
        File::append($logPath, json_encode([
            'message' => 'SLOW_QUERY_DETECTED',
            'datetime' => $now->copy()->subMinutes(2)->toIso8601String(),
            'context' => [
                'route' => 'api/v0.3/attempts/submit',
                'sql_ms' => 812.45,
                'request_id' => 'req_fer2_25',
                'connection' => 'sqlite',
                'org_id' => 1,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = Artisan::call('ops:evidence-pack', [
            '--revision' => $revision,
            '--ts' => $timestamp,
            '--window-minutes' => 120,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryExists($packDir);

        $manifestPath = $packDir.'/manifest.json';
        $revisionPath = $packDir.'/revision.json';
        $healthzPath = $packDir.'/healthz_snapshot.json';
        $queuePath = $packDir.'/queue_backlog_probe.json';
        $slowPath = $packDir.'/slow_query_telemetry.json';
        $apiSloPath = $packDir.'/api_slo.json';

        $this->assertFileExists($manifestPath);
        $this->assertFileExists($revisionPath);
        $this->assertFileExists($healthzPath);
        $this->assertFileExists($queuePath);
        $this->assertFileExists($slowPath);
        $this->assertFileExists($apiSloPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($manifest);
        $this->assertSame($revision, (string) ($manifest['revision'] ?? ''));
        $this->assertSame($timestamp, (string) ($manifest['timestamp'] ?? ''));

        $queuePayload = json_decode((string) file_get_contents($queuePath), true);
        $this->assertIsArray($queuePayload);
        $this->assertSame('ops:queue-backlog-probe', (string) ($queuePayload['command'] ?? ''));
        $this->assertSame(0, (int) ($queuePayload['exit_code'] ?? -1));

        $probePayload = is_array($queuePayload['payload'] ?? null) ? $queuePayload['payload'] : [];
        $queues = is_array($probePayload['queues'] ?? null) ? $probePayload['queues'] : [];
        $queueNames = array_values(array_map(
            static fn (array $queue): string => (string) ($queue['queue'] ?? ''),
            array_filter($queues, 'is_array')
        ));
        $this->assertContains('attempts', $queueNames);
        $this->assertContains('reports', $queueNames);
        $this->assertContains('commerce', $queueNames);

        $slowPayload = json_decode((string) file_get_contents($slowPath), true);
        $this->assertIsArray($slowPayload);
        $this->assertGreaterThanOrEqual(1, (int) ($slowPayload['window_total'] ?? 0));
        $this->assertSame(1, (int) (($slowPayload['by_route']['api/v0.3/attempts/submit'] ?? 0)));

        $apiSloPayload = json_decode((string) file_get_contents($apiSloPath), true);
        $this->assertIsArray($apiSloPayload);
        $this->assertSame('UNKNOWN', (string) ($apiSloPayload['status'] ?? ''));
        $this->assertSame('UNKNOWN', (string) data_get($apiSloPayload, 'metrics.p95_ms.status', ''));
        $this->assertSame('UNKNOWN', (string) data_get($apiSloPayload, 'metrics.error_rate.status', ''));
        $this->assertIsArray($apiSloPayload['request_id_coverage'] ?? null);
    }

    private function useDatabaseQueueDriver(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
    }
}
