<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Jobs\GenerateReportSnapshotJob;
use App\Services\Report\ReportSnapshotStore;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsEnneagramAttempts;
use Tests\TestCase;

final class EnneagramSnapshotOnlyReadTest extends TestCase
{
    use BuildsEnneagramAttempts;
    use RefreshDatabase;

    public function test_pending_report_reads_return_202_without_composing_or_duplicating_jobs(): void
    {
        [$attemptId, $anonId, $token] = $this->createPendingAttempt('pending');

        $first = $this->withHeaders($this->readHeaders($anonId, $token))
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $second = $this->withHeaders($this->readHeaders($anonId, $token))
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        foreach ([$first, $second] as $response) {
            $response->assertStatus(202);
            $response->assertJson([
                'ok' => true,
                'generating' => true,
                'snapshot_error' => false,
                'retry_after_seconds' => 3,
                'report' => [],
                'meta' => [
                    'snapshot_status' => 'pending',
                ],
            ]);
        }

        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
        $this->assertDatabaseCount('report_snapshots', 1);
    }

    public function test_failed_snapshot_returns_explicit_503_without_live_build_fallback(): void
    {
        [$attemptId, $anonId, $token] = $this->createPendingAttempt('failed');

        DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->update([
                'status' => 'failed',
                'last_error' => 'test failure',
                'updated_at' => now(),
            ]);
        $response = $this->withHeaders($this->readHeaders($anonId, $token))
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(503);
        $response->assertJsonPath('error_code', 'REPORT_SNAPSHOT_FAILED');
        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
    }

    public function test_running_snapshot_returns_202_without_queuing_another_job(): void
    {
        [$attemptId, $anonId, $token] = $this->createPendingAttempt('running');

        DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->update([
                'status' => 'running',
                'updated_at' => now(),
            ]);

        $response = $this->withHeaders($this->readHeaders($anonId, $token))
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(202);
        $response->assertJsonPath('meta.snapshot_status', 'running');
        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
    }

    public function test_refresh_requeues_ready_snapshot_without_request_time_composition(): void
    {
        [$attemptId, $anonId, $token] = $this->createPendingAttempt('refresh');

        DB::table('report_snapshots')
            ->where('attempt_id', $attemptId)
            ->update([
                'status' => 'ready',
                'updated_at' => now(),
            ]);
        app(UniqueLock::class)->release(
            new GenerateReportSnapshotJob(0, $attemptId, 'submit', null)
        );
        Queue::fake();

        $response = $this->withHeaders($this->readHeaders($anonId, $token))
            ->getJson("/api/v0.3/attempts/{$attemptId}/report?refresh=1");

        $response->assertStatus(202);
        $response->assertJsonPath('meta.snapshot_status', 'pending');
        $this->assertDatabaseHas('report_snapshots', [
            'attempt_id' => $attemptId,
            'status' => 'pending',
        ]);
        Queue::assertPushed(GenerateReportSnapshotJob::class, 1);
        Queue::assertPushed(GenerateReportSnapshotJob::class, function (GenerateReportSnapshotJob $job): bool {
            return $job->triggerSource === 'report_api';
        });
    }

    public function test_duplicate_jobs_build_the_same_attempt_snapshot_only_once(): void
    {
        [$attemptId] = $this->createPendingAttempt('job_claim');
        $store = $this->mock(ReportSnapshotStore::class);
        $store->shouldReceive('createSnapshotForAttempt')
            ->once()
            ->andReturn(['ok' => true]);

        (new GenerateReportSnapshotJob(0, $attemptId, 'submit', null))->handle($store);
        (new GenerateReportSnapshotJob(0, $attemptId, 'report_api', null))->handle($store);

        $this->assertDatabaseHas('report_snapshots', [
            'attempt_id' => $attemptId,
            'status' => 'ready',
        ]);
    }

    /**
     * @return array{string,string,string}
     */
    private function createPendingAttempt(string $suffix): array
    {
        (new ScaleRegistrySeeder)->run();
        Queue::fake();

        $anonId = "enneagram_snapshot_only_{$suffix}";
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token);

        $this->assertDatabaseHas('report_snapshots', [
            'attempt_id' => $attemptId,
            'status' => 'pending',
        ]);

        return [$attemptId, $anonId, $token];
    }

    /**
     * @return array<string,string>
     */
    private function readHeaders(string $anonId, string $token): array
    {
        return [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
