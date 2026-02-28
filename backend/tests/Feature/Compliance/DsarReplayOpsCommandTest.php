<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Jobs\ExecuteDsarRequestJob;
use App\Services\Attempts\UserDataLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DsarReplayOpsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_requeue_command_replays_failed_request_and_dispatches_job(): void
    {
        Queue::fake();

        $orgId = 901;
        $actorUserId = 90101;
        $subjectUserId = 90102;
        $requestId = (string) Str::uuid();
        $failedTaskId = (string) Str::uuid();

        $this->seedUser($actorUserId, 'owner901@example.test');
        $this->seedUser($subjectUserId, 'subject901@example.test');
        $this->seedFailedRequest($requestId, $orgId, $subjectUserId, $actorUserId, $failedTaskId);

        $exitCode = Artisan::call('ops:dsar-requeue', [
            '--org-id' => (string) $orgId,
            '--request-id' => $requestId,
            '--actor-user-id' => (string) $actorUserId,
            '--reason' => 'manual replay for exhausted request',
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $this->assertSame('dispatch', (string) ($payload['state'] ?? ''));
        $this->assertSame('running', (string) ($payload['status'] ?? ''));
        $newTaskId = (string) ($payload['task_id'] ?? '');
        $newReferenceId = (string) ($payload['job_reference'] ?? '');
        $this->assertNotSame('', $newTaskId);
        $this->assertNotSame($failedTaskId, $newTaskId);
        $this->assertNotSame('', $newReferenceId);

        $requestRow = DB::table('dsar_requests')->where('id', $requestId)->first();
        $this->assertNotNull($requestRow);
        $this->assertSame('running', (string) ($requestRow->status ?? ''));
        $this->assertSame($actorUserId, (int) ($requestRow->executed_by_user_id ?? 0));
        $this->assertNull($requestRow->result_json);

        $requestPayload = $this->decodeJson($requestRow->payload_json ?? null);
        $execution = is_array($requestPayload['execution'] ?? null) ? $requestPayload['execution'] : [];
        $this->assertSame($newTaskId, (string) ($execution['task_id'] ?? ''));
        $this->assertSame($newReferenceId, (string) ($execution['reference_id'] ?? ''));
        $this->assertSame(1, (int) ($execution['requeue_count'] ?? 0));
        $this->assertSame('manual replay for exhausted request', (string) ($execution['requeue_reason'] ?? ''));

        $pendingTask = DB::table('dsar_request_tasks')->where('id', $newTaskId)->first();
        $this->assertNotNull($pendingTask);
        $this->assertSame('pending', (string) ($pendingTask->status ?? ''));
        $this->assertSame('orchestration', (string) ($pendingTask->domain ?? ''));
        $this->assertSame('execute', (string) ($pendingTask->action ?? ''));

        Queue::assertPushed(ExecuteDsarRequestJob::class, function (ExecuteDsarRequestJob $job) use (
            $requestId,
            $orgId,
            $actorUserId,
            $newTaskId,
            $newReferenceId
        ): bool {
            return $job->requestId === $requestId
                && $job->orgId === $orgId
                && $job->actorUserId === $actorUserId
                && $job->taskId === $newTaskId
                && $job->referenceId === $newReferenceId;
        });

        $this->assertDatabaseHas('dsar_audit_logs', [
            'request_id' => $requestId,
            'event_type' => 'requeue_requested',
        ]);
        $this->assertDatabaseHas('dsar_audit_logs', [
            'request_id' => $requestId,
            'event_type' => 'requeue_started',
        ]);
        $this->assertDatabaseHas('dsar_audit_logs', [
            'request_id' => $requestId,
            'event_type' => 'dsar_status_transition',
        ]);
    }

    public function test_requeue_command_is_noop_for_running_request(): void
    {
        Queue::fake();

        $orgId = 902;
        $actorUserId = 90201;
        $subjectUserId = 90202;
        $requestId = (string) Str::uuid();
        $runningTaskId = (string) Str::uuid();
        $runningReferenceId = (string) Str::uuid();

        $this->seedUser($actorUserId, 'owner902@example.test');
        $this->seedUser($subjectUserId, 'subject902@example.test');

        DB::table('dsar_requests')->insert([
            'id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'requested_by_user_id' => $actorUserId,
            'executed_by_user_id' => $actorUserId,
            'mode' => 'hybrid_anonymize',
            'status' => 'running',
            'reason' => 'already running',
            'payload_json' => json_encode([
                'execution' => [
                    'task_id' => $runningTaskId,
                    'reference_id' => $runningReferenceId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => null,
            'requested_at' => now()->subMinute(),
            'executed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        DB::table('dsar_request_tasks')->insert([
            'id' => $runningTaskId,
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'running',
            'error_code' => null,
            'stats_json' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $taskCountBefore = DB::table('dsar_request_tasks')->where('request_id', $requestId)->count();

        $exitCode = Artisan::call('ops:dsar-requeue', [
            '--org-id' => (string) $orgId,
            '--request-id' => $requestId,
            '--actor-user-id' => (string) $actorUserId,
            '--reason' => 'try replay while running',
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('noop', (string) ($payload['state'] ?? ''));
        $this->assertSame('running', (string) ($payload['status'] ?? ''));
        $this->assertSame($runningTaskId, (string) ($payload['task_id'] ?? ''));
        $this->assertSame($runningReferenceId, (string) ($payload['job_reference'] ?? ''));

        $taskCountAfter = DB::table('dsar_request_tasks')->where('request_id', $requestId)->count();
        $this->assertSame($taskCountBefore, $taskCountAfter);

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('dsar_audit_logs', [
            'request_id' => $requestId,
            'event_type' => 'requeue_requested',
        ]);
        $this->assertDatabaseMissing('dsar_audit_logs', [
            'request_id' => $requestId,
            'event_type' => 'requeue_started',
        ]);
    }

    public function test_requeue_done_and_failed_audits_are_written_by_job(): void
    {
        $orgId = 903;
        $actorUserId = 90301;
        $subjectUserId = 90302;

        $this->seedUser($actorUserId, 'owner903@example.test');
        $this->seedUser($subjectUserId, 'subject903@example.test');

        $doneRequestId = (string) Str::uuid();
        $doneTaskId = (string) Str::uuid();
        $doneReferenceId = (string) Str::uuid();
        $this->seedRunningReplayRequest($doneRequestId, $orgId, $subjectUserId, $actorUserId, $doneTaskId, $doneReferenceId);

        DB::table('dsar_request_tasks')->insert([
            'id' => $doneTaskId,
            'request_id' => $doneRequestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'pending',
            'error_code' => null,
            'stats_json' => null,
            'started_at' => null,
            'finished_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        /** @var UserDataLifecycleService $service */
        $service = app(UserDataLifecycleService::class);

        $doneJob = new ExecuteDsarRequestJob($doneRequestId, $orgId, $actorUserId, $doneTaskId, $doneReferenceId);
        $doneJob->handle($service);

        $this->assertDatabaseHas('dsar_audit_logs', [
            'request_id' => $doneRequestId,
            'event_type' => 'requeue_done',
        ]);

        $failedRequestId = (string) Str::uuid();
        $failedTaskId = (string) Str::uuid();
        $failedReferenceId = (string) Str::uuid();
        $this->seedRunningReplayRequest($failedRequestId, $orgId, $subjectUserId, $actorUserId, $failedTaskId, $failedReferenceId);

        DB::table('dsar_request_tasks')->insert([
            'id' => $failedTaskId,
            'request_id' => $failedRequestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'running',
            'error_code' => null,
            'stats_json' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => null,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $failedJob = new ExecuteDsarRequestJob($failedRequestId, $orgId, $actorUserId, $failedTaskId, $failedReferenceId);
        $failedJob->failed(new \RuntimeException('terminal fail for replay'));

        $failedAudit = DB::table('dsar_audit_logs')
            ->where('request_id', $failedRequestId)
            ->where('event_type', 'requeue_failed')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($failedAudit);
        $failedContext = $this->decodeJson($failedAudit->context_json ?? null);
        $this->assertSame('USER_DSAR_RETRY_EXHAUSTED', (string) ($failedContext['error_code'] ?? ''));
        $this->assertSame($failedTaskId, (string) ($failedContext['task_id'] ?? ''));
        $this->assertSame($failedReferenceId, (string) ($failedContext['reference_id'] ?? ''));
    }

    public function test_sla_stats_command_outputs_timeout_failed_and_replay_counts(): void
    {
        $orgId = 904;
        $windowHours = 24;
        $staleMinutes = 60;

        $now = now();

        DB::table('dsar_requests')->insert([
            [
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90401,
                'requested_by_user_id' => 90411,
                'executed_by_user_id' => null,
                'mode' => 'hybrid_anonymize',
                'status' => 'pending',
                'reason' => 'stale pending',
                'payload_json' => null,
                'result_json' => null,
                'requested_at' => $now->copy()->subHours(3),
                'executed_at' => null,
                'created_at' => $now->copy()->subHours(3),
                'updated_at' => $now->copy()->subHours(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90402,
                'requested_by_user_id' => 90411,
                'executed_by_user_id' => null,
                'mode' => 'hybrid_anonymize',
                'status' => 'running',
                'reason' => 'stale running',
                'payload_json' => null,
                'result_json' => null,
                'requested_at' => $now->copy()->subHours(4),
                'executed_at' => null,
                'created_at' => $now->copy()->subHours(4),
                'updated_at' => $now->copy()->subHours(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'requested_by_user_id' => 90411,
                'executed_by_user_id' => 90411,
                'mode' => 'hybrid_anonymize',
                'status' => 'failed',
                'reason' => 'recent failed',
                'payload_json' => null,
                'result_json' => null,
                'requested_at' => $now->copy()->subHours(2),
                'executed_at' => $now->copy()->subHours(2),
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subHours(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90404,
                'requested_by_user_id' => 90411,
                'executed_by_user_id' => 90411,
                'mode' => 'hybrid_anonymize',
                'status' => 'failed',
                'reason' => 'old failed',
                'payload_json' => null,
                'result_json' => null,
                'requested_at' => $now->copy()->subDays(3),
                'executed_at' => $now->copy()->subDays(3),
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDays(3),
            ],
        ]);

        DB::table('dsar_request_tasks')->insert([
            [
                'id' => (string) Str::uuid(),
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'domain' => 'orchestration',
                'action' => 'execute',
                'status' => 'failed',
                'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
                'stats_json' => null,
                'started_at' => $now->copy()->subHours(2),
                'finished_at' => $now->copy()->subHours(2),
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subHours(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90404,
                'domain' => 'orchestration',
                'action' => 'execute',
                'status' => 'failed',
                'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
                'stats_json' => null,
                'started_at' => $now->copy()->subDays(3),
                'finished_at' => $now->copy()->subDays(3),
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDays(3),
            ],
        ]);

        DB::table('dsar_audit_logs')->insert([
            [
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'event_type' => 'requeue_requested',
                'level' => 'info',
                'message' => 'requested',
                'context_json' => '{}',
                'occurred_at' => $now->copy()->subHours(1),
                'created_at' => $now->copy()->subHours(1),
                'updated_at' => $now->copy()->subHours(1),
            ],
            [
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'event_type' => 'requeue_started',
                'level' => 'info',
                'message' => 'started',
                'context_json' => '{}',
                'occurred_at' => $now->copy()->subHours(1),
                'created_at' => $now->copy()->subHours(1),
                'updated_at' => $now->copy()->subHours(1),
            ],
            [
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'event_type' => 'requeue_done',
                'level' => 'info',
                'message' => 'done',
                'context_json' => '{}',
                'occurred_at' => $now->copy()->subHours(1),
                'created_at' => $now->copy()->subHours(1),
                'updated_at' => $now->copy()->subHours(1),
            ],
            [
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90403,
                'event_type' => 'requeue_failed',
                'level' => 'error',
                'message' => 'failed',
                'context_json' => '{}',
                'occurred_at' => $now->copy()->subHours(1),
                'created_at' => $now->copy()->subHours(1),
                'updated_at' => $now->copy()->subHours(1),
            ],
            [
                'request_id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'subject_user_id' => 90404,
                'event_type' => 'requeue_failed',
                'level' => 'error',
                'message' => 'old failed',
                'context_json' => '{}',
                'occurred_at' => $now->copy()->subDays(3),
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDays(3),
            ],
        ]);

        $exitCode = Artisan::call('ops:dsar-sla-stats', [
            '--org-id' => (string) $orgId,
            '--window-hours' => (string) $windowHours,
            '--stale-minutes' => (string) $staleMinutes,
            '--json' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim((string) Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));

        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];
        $this->assertSame(2, (int) ($stats['timeout_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['failed_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['retry_exhausted_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['replay_requested_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['replay_started_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['replay_done_count'] ?? -1));
        $this->assertSame(1, (int) ($stats['replay_failed_count'] ?? -1));

        $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
        $this->assertArrayHasKey('timeout_count', $sources);
        $this->assertArrayHasKey('failed_count', $sources);
        $this->assertArrayHasKey('retry_exhausted_count', $sources);
        $this->assertArrayHasKey('replay_failed_count', $sources);
    }

    private function seedUser(int $id, string $email): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFailedRequest(
        string $requestId,
        int $orgId,
        int $subjectUserId,
        int $actorUserId,
        string $taskId
    ): void {
        DB::table('dsar_requests')->insert([
            'id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'requested_by_user_id' => $actorUserId,
            'executed_by_user_id' => $actorUserId,
            'mode' => 'hybrid_anonymize',
            'status' => 'failed',
            'reason' => 'retry exhausted',
            'payload_json' => json_encode([
                'execution' => [
                    'task_id' => $taskId,
                    'reference_id' => (string) Str::uuid(),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => json_encode([
                'ok' => false,
                'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'requested_at' => now()->subMinutes(20),
            'executed_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);

        DB::table('dsar_request_tasks')->insert([
            'id' => $taskId,
            'request_id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'domain' => 'orchestration',
            'action' => 'execute',
            'status' => 'failed',
            'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
            'stats_json' => null,
            'started_at' => now()->subMinutes(15),
            'finished_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);
    }

    private function seedRunningReplayRequest(
        string $requestId,
        int $orgId,
        int $subjectUserId,
        int $actorUserId,
        string $taskId,
        string $referenceId
    ): void {
        DB::table('dsar_requests')->insert([
            'id' => $requestId,
            'org_id' => $orgId,
            'subject_user_id' => $subjectUserId,
            'requested_by_user_id' => $actorUserId,
            'executed_by_user_id' => $actorUserId,
            'mode' => 'hybrid_anonymize',
            'status' => 'running',
            'reason' => 'replay in progress',
            'payload_json' => json_encode([
                'execution' => [
                    'task_id' => $taskId,
                    'reference_id' => $referenceId,
                    'requeue_count' => 1,
                    'requeue_reason' => 'manual_requeue',
                    'requeue_requested_by_user_id' => $actorUserId,
                    'requeue_requested_at' => now()->toIso8601String(),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result_json' => null,
            'requested_at' => now()->subMinutes(5),
            'executed_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
