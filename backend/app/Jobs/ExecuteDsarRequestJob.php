<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Attempts\UserDataLifecycleService;
use App\Support\SchemaBaseline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExecuteDsarRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function __construct(
        public string $requestId,
        public int $orgId,
        public int $actorUserId,
        public string $taskId,
        public string $referenceId,
    ) {
        $this->onConnection('database');
        $this->onQueue('compliance');
    }

    public function handle(UserDataLifecycleService $service): void
    {
        $now = now();
        $requestRow = DB::table('dsar_requests')
            ->where('id', $this->requestId)
            ->where('org_id', $this->orgId)
            ->first();

        if ($requestRow === null) {
            return;
        }

        $currentStatus = trim((string) ($requestRow->status ?? 'pending'));
        if (in_array($currentStatus, ['done', 'failed'], true)) {
            return;
        }

        if (SchemaBaseline::hasTable('dsar_request_tasks')) {
            DB::table('dsar_request_tasks')
                ->where('id', $this->taskId)
                ->where('request_id', $this->requestId)
                ->update([
                    'status' => 'running',
                    'started_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $result = $service->process(
            $this->orgId,
            (int) ($requestRow->subject_user_id ?? 0),
            (string) ($requestRow->mode ?? 'hybrid_anonymize'),
            [
                'actor_user_id' => $this->actorUserId,
                'request_id' => $this->requestId,
                'reason' => (string) ($requestRow->reason ?? 'user_dsar_request'),
            ]
        );

        $finalStatus = ($result['ok'] ?? false) === true ? 'done' : 'failed';
        $finishedAt = now();

        DB::table('dsar_requests')
            ->where('id', $this->requestId)
            ->where('org_id', $this->orgId)
            ->update([
                'status' => $finalStatus,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'executed_by_user_id' => $this->actorUserId,
                'executed_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);

        if (SchemaBaseline::hasTable('dsar_request_tasks')) {
            DB::table('dsar_request_tasks')
                ->where('id', $this->taskId)
                ->where('request_id', $this->requestId)
                ->update([
                    'status' => $finalStatus,
                    'error_code' => $finalStatus === 'failed' ? 'USER_DSAR_FAILED' : null,
                    'stats_json' => json_encode([
                        'result_ok' => ($result['ok'] ?? false) === true,
                        'counts' => is_array($result['counts'] ?? null) ? $result['counts'] : null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'finished_at' => $finishedAt,
                    'updated_at' => $finishedAt,
                ]);
        }

        $this->appendAuditTransition($currentStatus, $finalStatus, $result);
    }

    public function failed(Throwable $exception): void
    {
        $requestRow = DB::table('dsar_requests')
            ->where('id', $this->requestId)
            ->where('org_id', $this->orgId)
            ->first();

        if ($requestRow === null) {
            return;
        }

        $currentStatus = trim((string) ($requestRow->status ?? 'pending'));
        if (in_array($currentStatus, ['done', 'failed'], true)) {
            return;
        }

        $finishedAt = now();
        $attempts = max(1, (int) $this->attempts());
        $maxTries = max(1, (int) $this->tries);
        $connection = trim((string) ($this->connection ?? ''));
        if ($connection === '') {
            $connection = trim((string) config('queue.default', 'sync'));
        }
        if ($connection === '') {
            $connection = 'sync';
        }

        $queue = trim((string) ($this->queue ?? ''));
        if ($queue === '') {
            $queue = trim((string) config('queue.connections.'.$connection.'.queue', ''));
        }
        if ($queue === '') {
            $queue = 'default';
        }

        $exceptionMessage = trim($exception->getMessage());
        if ($exceptionMessage !== '') {
            $exceptionMessage = mb_substr($exceptionMessage, 0, 300);
        }

        $result = [
            'ok' => false,
            'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
            'message' => 'dsar execution retry exhausted.',
            'terminal' => [
                'attempts' => $attempts,
                'max_tries' => $maxTries,
                'queue' => $queue,
                'connection' => $connection,
                'task_id' => $this->taskId,
                'reference_id' => $this->referenceId,
                'exception_class' => class_basename($exception),
                'exception_message' => $exceptionMessage,
            ],
        ];

        DB::table('dsar_requests')
            ->where('id', $this->requestId)
            ->where('org_id', $this->orgId)
            ->update([
                'status' => 'failed',
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'executed_by_user_id' => $this->actorUserId,
                'executed_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);

        if (SchemaBaseline::hasTable('dsar_request_tasks')) {
            DB::table('dsar_request_tasks')
                ->where('id', $this->taskId)
                ->where('request_id', $this->requestId)
                ->update([
                    'status' => 'failed',
                    'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
                    'stats_json' => json_encode([
                        'dlq_marked' => true,
                        'attempts' => $attempts,
                        'max_tries' => $maxTries,
                        'queue' => $queue,
                        'connection' => $connection,
                        'reference_id' => $this->referenceId,
                        'task_id' => $this->taskId,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'finished_at' => $finishedAt,
                    'updated_at' => $finishedAt,
                ]);
        }

        $this->appendAuditTransition($currentStatus, 'failed', ['ok' => false]);
        $this->appendTerminalFailureAudit($requestRow, $result['terminal'], $finishedAt);
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function appendAuditTransition(string $from, string $to, array $result): void
    {
        if (! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return;
        }

        $now = now();
        DB::table('dsar_audit_logs')->insert([
            'request_id' => $this->requestId,
            'org_id' => $this->orgId,
            'subject_user_id' => null,
            'event_type' => 'dsar_status_transition',
            'level' => $to === 'failed' ? 'error' : 'info',
            'message' => sprintf('dsar request transitioned from %s to %s', $from, $to),
            'context_json' => json_encode([
                'from' => $from,
                'to' => $to,
                'reference_id' => $this->referenceId,
                'task_id' => $this->taskId,
                'result_ok' => ($result['ok'] ?? false) === true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  object  $requestRow
     * @param  array{
     *   attempts:int,
     *   max_tries:int,
     *   queue:string,
     *   connection:string,
     *   task_id:string,
     *   reference_id:string,
     *   exception_class:string,
     *   exception_message:string
     * }  $terminal
     */
    private function appendTerminalFailureAudit(object $requestRow, array $terminal, \Illuminate\Support\Carbon $now): void
    {
        if (! SchemaBaseline::hasTable('dsar_audit_logs')) {
            return;
        }

        DB::table('dsar_audit_logs')->insert([
            'request_id' => $this->requestId,
            'org_id' => $this->orgId,
            'subject_user_id' => (int) ($requestRow->subject_user_id ?? 0) ?: null,
            'event_type' => 'dsar_job_failed_terminal',
            'level' => 'error',
            'message' => 'dsar execution failed after retries exhausted.',
            'context_json' => json_encode([
                'error_code' => 'USER_DSAR_RETRY_EXHAUSTED',
                'attempts' => $terminal['attempts'],
                'max_tries' => $terminal['max_tries'],
                'queue' => $terminal['queue'],
                'connection' => $terminal['connection'],
                'task_id' => $terminal['task_id'],
                'reference_id' => $terminal['reference_id'],
                'exception_class' => $terminal['exception_class'],
                'exception_message' => $terminal['exception_message'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
