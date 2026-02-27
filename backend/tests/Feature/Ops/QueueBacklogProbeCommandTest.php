<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QueueBacklogProbeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_outputs_queue_backlog_failure_retry_and_timeout_metrics(): void
    {
        $this->useDatabaseQueueDriver();

        $now = now();

        DB::table('jobs')->insert([
            [
                'queue' => 'attempts',
                'payload' => '{"job":"attempt.submit"}',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now->copy()->subSeconds(120)->timestamp,
                'created_at' => $now->copy()->subSeconds(120)->timestamp,
            ],
            [
                'queue' => 'attempts',
                'payload' => '{"job":"attempt.submit"}',
                'attempts' => 2,
                'reserved_at' => $now->copy()->subSeconds(30)->timestamp,
                'available_at' => $now->copy()->subSeconds(40)->timestamp,
                'created_at' => $now->copy()->subSeconds(40)->timestamp,
            ],
            [
                'queue' => 'reports',
                'payload' => '{"job":"report.snapshot"}',
                'attempts' => 1,
                'reserved_at' => null,
                'available_at' => $now->copy()->subSeconds(80)->timestamp,
                'created_at' => $now->copy()->subSeconds(80)->timestamp,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'attempts',
                'payload' => '{"job":"attempt.submit"}',
                'exception' => 'TimeoutExceededException: worker timed out.',
                'failed_at' => $now->copy()->subMinutes(5),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database_commerce',
                'queue' => 'commerce',
                'payload' => '{"job":"commerce.refund"}',
                'exception' => 'RuntimeException: provider unavailable.',
                'failed_at' => $now->copy()->subMinutes(4),
            ],
        ]);

        DB::table('report_jobs')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => 'attempt-report-1',
            'status' => 'running',
            'tries' => 1,
            'available_at' => $now->copy()->subMinutes(3),
            'started_at' => $now->copy()->subMinutes(2),
            'finished_at' => null,
            'failed_at' => null,
            'last_error' => null,
            'last_error_trace' => null,
            'report_json' => null,
            'meta' => null,
            'created_at' => $now->copy()->subMinutes(3),
            'updated_at' => $now->copy()->subMinutes(2),
        ]);

        DB::table('attempt_submissions')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => 'attempt-submit-1',
            'actor_user_id' => null,
            'actor_anon_id' => null,
            'dedupe_key' => 'dedupe-attempt-submit-1',
            'mode' => 'async',
            'state' => 'pending',
            'error_code' => null,
            'error_message' => null,
            'request_payload_json' => null,
            'response_payload_json' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now->copy()->subMinutes(3),
            'updated_at' => $now->copy()->subMinutes(3),
        ]);

        $exitCode = Artisan::call('ops:queue-backlog-probe', [
            '--json' => 1,
            '--queues' => 'attempts,reports,commerce',
            '--window-minutes' => 120,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['pass'] ?? false));
        $this->assertSame('database', (string) ($payload['queue_driver'] ?? ''));
        $this->assertIsArray($payload['thresholds']['by_queue'] ?? null);
        $this->assertIsArray($payload['slo'] ?? null);

        $queues = is_array($payload['queues'] ?? null) ? $payload['queues'] : [];

        $attempts = $this->findQueue($queues, 'attempts');
        $this->assertIsArray($attempts);
        $this->assertSame(1, (int) ($attempts['backlog']['pending'] ?? 0));
        $this->assertSame(1, (int) ($attempts['backlog']['reserved'] ?? 0));
        $this->assertSame(2, (int) ($attempts['backlog']['total'] ?? 0));
        $this->assertSame(1, (int) ($attempts['failures']['total'] ?? 0));
        $this->assertSame(1, (int) ($attempts['failures']['timeout_total'] ?? 0));
        $this->assertSame(1, (int) ($attempts['attempt_submissions']['states']['pending'] ?? 0));
        $this->assertSame('ok', (string) ($attempts['slo']['status'] ?? ''));
        $this->assertGreaterThanOrEqual(0.0, (float) ($attempts['slo']['max_utilization'] ?? -1));

        $reports = $this->findQueue($queues, 'reports');
        $this->assertIsArray($reports);
        $this->assertSame(1, (int) ($reports['report_jobs']['states']['running'] ?? 0));
        $this->assertSame('ok', (string) ($reports['slo']['status'] ?? ''));

        $commerce = $this->findQueue($queues, 'commerce');
        $this->assertIsArray($commerce);
        $this->assertSame('ok', (string) ($commerce['slo']['status'] ?? ''));
    }

    public function test_strict_mode_returns_failure_when_queue_threshold_is_exceeded(): void
    {
        $this->useDatabaseQueueDriver();

        $nowTs = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'attempts',
            'payload' => '{"job":"attempt.submit"}',
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => $nowTs - 60,
            'created_at' => $nowTs - 60,
        ]);

        $exitCode = Artisan::call('ops:queue-backlog-probe', [
            '--json' => 1,
            '--strict' => 1,
            '--queues' => 'attempts',
            '--max-pending' => 0,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['pass'] ?? true));

        $violations = is_array($payload['violations'] ?? null) ? $payload['violations'] : [];
        $this->assertNotEmpty($violations);
        $first = is_array($violations[0] ?? null) ? $violations[0] : [];
        $this->assertSame('attempts', (string) ($first['queue'] ?? ''));
        $this->assertSame('pending', (string) ($first['metric'] ?? ''));
    }

    public function test_command_uses_default_three_queues_and_config_driven_strict_mode(): void
    {
        $this->useDatabaseQueueDriver();

        config()->set('ops.queue_backlog_probe.queues', ['attempts', 'reports', 'commerce']);
        config()->set('ops.queue_backlog_probe.strict_default', true);
        config()->set('ops.queue_backlog_probe.thresholds.attempts.max_pending', 0);
        config()->set('ops.queue_backlog_probe.thresholds.reports.max_pending', 999);
        config()->set('ops.queue_backlog_probe.thresholds.commerce.max_pending', 999);

        $nowTs = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'attempts',
            'payload' => '{"job":"attempt.submit"}',
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => $nowTs - 30,
            'created_at' => $nowTs - 30,
        ]);

        $exitCode = Artisan::call('ops:queue-backlog-probe', [
            '--json' => 1,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertFalse((bool) ($payload['pass'] ?? true));
        $this->assertTrue((bool) ($payload['slo']['strict'] ?? false));

        $queues = is_array($payload['queues'] ?? null) ? $payload['queues'] : [];
        $this->assertNotSame([], $this->findQueue($queues, 'attempts'));
        $this->assertNotSame([], $this->findQueue($queues, 'reports'));
        $this->assertNotSame([], $this->findQueue($queues, 'commerce'));
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

    /**
     * @param  list<array<string,mixed>>  $queues
     * @return array<string,mixed>
     */
    private function findQueue(array $queues, string $name): array
    {
        foreach ($queues as $queue) {
            if ((string) ($queue['queue'] ?? '') === $name) {
                return $queue;
            }
        }

        return [];
    }
}
