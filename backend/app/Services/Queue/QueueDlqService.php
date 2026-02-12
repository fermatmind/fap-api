<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QueueDlqService
{
    public function __construct(
        private readonly QueueFactory $queueFactory,
    ) {
    }

    public function metrics(): array
    {
        $failedTableExists = Schema::hasTable('failed_jobs');
        $replayTableExists = Schema::hasTable('queue_dlq_replays');

        $failedTotal = 0;
        $failedByQueue = [];
        if ($failedTableExists) {
            $failedTotal = (int) DB::table('failed_jobs')->count();
            $failedRows = DB::table('failed_jobs')
                ->select('queue', DB::raw('count(*) as total'))
                ->groupBy('queue')
                ->orderBy('queue')
                ->get();

            foreach ($failedRows as $row) {
                $failedByQueue[] = [
                    'queue' => (string) ($row->queue ?? ''),
                    'total' => (int) ($row->total ?? 0),
                ];
            }
        }

        $replayTotal = 0;
        $replayStatus = [];
        $lastReplayedAt = null;
        if ($replayTableExists) {
            $replayTotal = (int) DB::table('queue_dlq_replays')->count();

            $statusRows = DB::table('queue_dlq_replays')
                ->select('replay_status', DB::raw('count(*) as total'))
                ->groupBy('replay_status')
                ->orderBy('replay_status')
                ->get();

            foreach ($statusRows as $row) {
                $replayStatus[] = [
                    'status' => (string) ($row->replay_status ?? ''),
                    'total' => (int) ($row->total ?? 0),
                ];
            }

            $lastReplayedAt = DB::table('queue_dlq_replays')
                ->where('replay_status', 'replayed')
                ->max('replayed_at');
        }

        $replayedCount = $this->statusCount($replayStatus, 'replayed');
        $failedReplayCount = $this->statusCount($replayStatus, 'push_failed');
        $denominator = $replayedCount + $failedReplayCount;
        $successRate = $denominator === 0 ? 1.0 : round($replayedCount / $denominator, 4);

        return [
            'tables' => [
                'failed_jobs' => $failedTableExists,
                'queue_dlq_replays' => $replayTableExists,
            ],
            'failed' => [
                'total' => $failedTotal,
                'by_queue' => $failedByQueue,
            ],
            'replay' => [
                'total' => $replayTotal,
                'by_status' => $replayStatus,
                'last_replayed_at' => $lastReplayedAt,
                'success_rate' => $successRate,
            ],
        ];
    }

    public function replayFailedJob(int $failedJobId, string $requestedBy, bool $force = false): array
    {
        if ($failedJobId <= 0) {
            return [
                'ok' => false,
                'status' => 'not_found',
                'error' => 'FAILED_JOB_NOT_FOUND',
                'message' => 'failed job not found.',
            ];
        }

        if (!$force && Schema::hasTable('queue_dlq_replays')) {
            $already = DB::table('queue_dlq_replays')
                ->where('failed_job_id', $failedJobId)
                ->where('replay_status', 'replayed')
                ->orderByDesc('id')
                ->first();

            if ($already) {
                return [
                    'ok' => true,
                    'status' => 'already_replayed',
                    'failed_job_id' => $failedJobId,
                    'replay_log_id' => (int) ($already->id ?? 0),
                    'replayed_job_id' => (string) ($already->replayed_job_id ?? ''),
                    'message' => 'failed job already replayed.',
                ];
            }
        }

        $failedJob = $this->findFailedJob($failedJobId);
        if ($failedJob === null) {
            $this->insertReplayLog($failedJobId, null, '', '', 'not_found', null, $requestedBy, 'failed job not found');

            return [
                'ok' => false,
                'status' => 'not_found',
                'error' => 'FAILED_JOB_NOT_FOUND',
                'message' => 'failed job not found.',
            ];
        }

        $payload = (string) ($failedJob->payload ?? '');
        if ($payload === '' || !$this->isJsonObject($payload)) {
            $this->insertReplayLog(
                $failedJobId,
                $this->failedJobUuid($failedJob),
                (string) ($failedJob->connection ?? ''),
                (string) ($failedJob->queue ?? ''),
                'invalid_payload',
                null,
                $requestedBy,
                'payload invalid json object'
            );

            return [
                'ok' => false,
                'status' => 'invalid_payload',
                'error' => 'FAILED_JOB_PAYLOAD_INVALID',
                'message' => 'failed job payload invalid.',
            ];
        }

        $connection = trim((string) ($failedJob->connection ?? ''));
        if ($connection === '') {
            $connection = (string) config('queue.default', 'sync');
        }
        $queue = trim((string) ($failedJob->queue ?? ''));
        if ($queue === '') {
            $queue = 'default';
        }

        try {
            $replayedJobId = $this->queueFactory
                ->connection($connection)
                ->pushRaw($payload, $queue);

            $this->forgetFailedJob($failedJobId, $failedJob);
            $replayLogId = $this->insertReplayLog(
                $failedJobId,
                $this->failedJobUuid($failedJob),
                $connection,
                $queue,
                'replayed',
                $this->normalizeJobId($replayedJobId),
                $requestedBy,
                null
            );

            return [
                'ok' => true,
                'status' => 'replayed',
                'failed_job_id' => $failedJobId,
                'replayed_job_id' => $this->normalizeJobId($replayedJobId),
                'replay_log_id' => $replayLogId,
            ];
        } catch (\Throwable $e) {
            Log::warning('[queue_dlq] replay failed', [
                'failed_job_id' => $failedJobId,
                'error' => $e->getMessage(),
            ]);

            $this->insertReplayLog(
                $failedJobId,
                $this->failedJobUuid($failedJob),
                $connection,
                $queue,
                'push_failed',
                null,
                $requestedBy,
                $e->getMessage()
            );

            return [
                'ok' => false,
                'status' => 'push_failed',
                'error' => 'REPLAY_FAILED',
                'message' => 'failed job replay failed.',
            ];
        }
    }

    private function findFailedJob(int $failedJobId): ?object
    {
        $failer = app('queue.failer');
        if (is_object($failer) && method_exists($failer, 'find')) {
            $row = $failer->find((string) $failedJobId);
            if (is_object($row)) {
                return $row;
            }

            $row = $failer->find($failedJobId);
            if (is_object($row)) {
                return $row;
            }
        }

        if (!Schema::hasTable('failed_jobs')) {
            return null;
        }

        $row = DB::table('failed_jobs')->where('id', $failedJobId)->first();
        return is_object($row) ? $row : null;
    }

    private function forgetFailedJob(int $failedJobId, ?object $failedJob = null): void
    {
        $failer = app('queue.failer');
        if (is_object($failer) && method_exists($failer, 'forget')) {
            $candidates = [];
            $uuid = $failedJob !== null ? $this->failedJobUuid($failedJob) : null;
            if ($uuid !== null) {
                $candidates[] = $uuid;
            }
            $candidates[] = (string) $failedJobId;

            foreach (array_unique($candidates) as $candidate) {
                try {
                    $failer->forget($candidate);
                } catch (\Throwable $e) {
                    Log::warning('QUEUE_DLQ_FORGET_FAILED', [
                        'candidate_id' => (string) $candidate,
                        'failed_job_id' => $failedJobId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->where('id', $failedJobId)->delete();
        }
    }

    private function insertReplayLog(
        int $failedJobId,
        ?string $failedJobUuid,
        string $connection,
        string $queue,
        string $status,
        ?string $replayedJobId,
        string $requestedBy,
        ?string $notes
    ): int {
        if (!Schema::hasTable('queue_dlq_replays')) {
            return 0;
        }

        $id = DB::table('queue_dlq_replays')->insertGetId([
            'failed_job_id' => $failedJobId,
            'failed_job_uuid' => $failedJobUuid !== null && $failedJobUuid !== '' ? $failedJobUuid : null,
            'connection_name' => $connection,
            'queue_name' => $queue,
            'replay_status' => $status,
            'replayed_job_id' => $replayedJobId !== null && $replayedJobId !== '' ? $replayedJobId : null,
            'requested_by' => $requestedBy !== '' ? $requestedBy : null,
            'request_source' => 'api',
            'notes' => $notes,
            'replayed_at' => $status === 'replayed' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }

    private function failedJobUuid(object $failedJob): ?string
    {
        $value = trim((string) ($failedJob->uuid ?? ''));
        return $value !== '' ? $value : null;
    }

    private function isJsonObject(string $payload): bool
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded);
    }

    private function normalizeJobId(mixed $value): string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @param array<int, array{status: string, total: int}> $replayStatus
     */
    private function statusCount(array $replayStatus, string $status): int
    {
        foreach ($replayStatus as $item) {
            if (($item['status'] ?? '') === $status) {
                return (int) ($item['total'] ?? 0);
            }
        }

        return 0;
    }
}
