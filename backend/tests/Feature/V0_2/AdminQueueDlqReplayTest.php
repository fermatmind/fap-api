<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminQueueDlqReplayTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'pr54-admin-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.token' => self::ADMIN_TOKEN,
            'queue.default' => 'database',
        ]);
    }

    public function test_metrics_endpoint_returns_failed_and_replay_counts(): void
    {
        $firstFailedJobId = $this->seedFailedJob('reports');
        $this->seedFailedJob('insights');

        DB::table('queue_dlq_replays')->insert([
            'failed_job_id' => $firstFailedJobId,
            'failed_job_uuid' => (string) Str::uuid(),
            'connection_name' => 'database',
            'queue_name' => 'reports',
            'replay_status' => 'replayed',
            'replayed_job_id' => '123',
            'requested_by' => 'admin_token',
            'request_source' => 'api',
            'notes' => null,
            'replayed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v0.2/admin/queue/dlq/metrics');

        $response
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.failed.total', 2)
            ->assertJsonPath('data.replay.total', 1);

        $failedByQueue = $response->json('data.failed.by_queue');
        $this->assertIsArray($failedByQueue);

        $reports = collect($failedByQueue)->firstWhere('queue', 'reports');
        $insights = collect($failedByQueue)->firstWhere('queue', 'insights');

        $this->assertSame(1, (int) ($reports['total'] ?? 0));
        $this->assertSame(1, (int) ($insights['total'] ?? 0));
    }

    public function test_replay_endpoint_requeues_failed_job_and_removes_from_dlq(): void
    {
        $failedJobId = $this->seedFailedJob('reports');

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v0.2/admin/queue/dlq/replay/{$failedJobId}", [
                'force' => false,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'replayed')
            ->assertJsonPath('data.failed_job_id', $failedJobId);

        $this->assertDatabaseMissing('failed_jobs', [
            'id' => $failedJobId,
        ]);

        $this->assertDatabaseCount('jobs', 1);

        $this->assertDatabaseHas('queue_dlq_replays', [
            'failed_job_id' => $failedJobId,
            'replay_status' => 'replayed',
        ]);
    }

    public function test_replay_endpoint_returns_404_when_failed_job_missing(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v0.2/admin/queue/dlq/replay/999999');

        $response
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'NOT_FOUND');
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-FAP-Admin-Token' => self::ADMIN_TOKEN,
            'Accept' => 'application/json',
        ];
    }

    private function seedFailedJob(string $queue): int
    {
        $payload = json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => 'Tests\\Fixtures\\ReplayableJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'Tests\\Fixtures\\ReplayableJob',
                'command' => 'serialized',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => $queue,
            'payload' => $payload === false ? '{}' : $payload,
            'exception' => 'RuntimeException: pr54 replay test',
            'failed_at' => now(),
        ]);
    }
}
