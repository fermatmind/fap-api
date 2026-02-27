<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

final class QueueBacklogProbeService
{
    /**
     * @var list<string>
     */
    private const DEFAULT_QUEUES = ['attempts', 'reports', 'commerce'];

    /**
     * @param  list<string>  $queues
     * @return array{
     *     ok:bool,
     *     timestamp:string,
     *     queue_connection:string,
     *     queue_driver:string,
     *     window_minutes:int,
     *     queues:list<array{
     *         queue:string,
     *         driver:string,
     *         status:string,
     *         notes:list<string>,
     *         backlog:array{
     *             pending:int,
     *             reserved:int,
     *             delayed:int,
     *             total:int,
     *             oldest_pending_seconds:int,
     *             oldest_reserved_seconds:int,
     *             avg_pending_seconds:int
     *         },
     *         retry_histogram:list<array{attempts:int,total:int}>,
     *         failures:array{
     *             total:int,
     *             window_total:int,
     *             timeout_total:int,
     *             window_timeout_total:int,
     *             last_failed_at:?string
     *         },
     *         report_jobs:null|array{
     *             states:array<string,int>,
     *             oldest_running_seconds:int
     *         },
     *         attempt_submissions:null|array{
     *             states:array<string,int>,
     *             oldest_pending_seconds:int
     *         }
     *     }>,
     *     totals:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         failed:int,
     *         timeout_failed:int
     *     },
     *     worker_hints:list<array{
     *         queue:string,
     *         command:string
     *     }>
     * }
     */
    public function probe(array $queues, int $windowMinutes): array
    {
        $windowMinutes = max(1, $windowMinutes);
        $queueNames = $this->normalizeQueues($queues);
        if ($queueNames === []) {
            $queueNames = self::DEFAULT_QUEUES;
        }

        $connectionName = trim((string) config('queue.default', 'sync'));
        if ($connectionName === '') {
            $connectionName = 'sync';
        }

        $connectionConfig = config("queue.connections.{$connectionName}");
        $driver = $connectionName;
        if (is_array($connectionConfig)) {
            $driver = strtolower(trim((string) ($connectionConfig['driver'] ?? $connectionName)));
        } else {
            $driver = strtolower(trim($connectionName));
        }
        if ($driver === '') {
            $driver = 'sync';
        }

        $windowStart = now()->subMinutes($windowMinutes);
        $rows = [];
        foreach ($queueNames as $queueName) {
            $rows[] = $this->probeQueue($queueName, $driver, $connectionName, $connectionConfig, $windowStart);
        }

        $totals = [
            'pending' => 0,
            'reserved' => 0,
            'delayed' => 0,
            'failed' => 0,
            'timeout_failed' => 0,
        ];

        foreach ($rows as $row) {
            $totals['pending'] += (int) ($row['backlog']['pending'] ?? 0);
            $totals['reserved'] += (int) ($row['backlog']['reserved'] ?? 0);
            $totals['delayed'] += (int) ($row['backlog']['delayed'] ?? 0);
            $totals['failed'] += (int) ($row['failures']['total'] ?? 0);
            $totals['timeout_failed'] += (int) ($row['failures']['timeout_total'] ?? 0);
        }

        return [
            'ok' => true,
            'timestamp' => now()->toISOString(),
            'queue_connection' => $connectionName,
            'queue_driver' => $driver,
            'window_minutes' => $windowMinutes,
            'queues' => $rows,
            'totals' => $totals,
            'worker_hints' => $this->workerHints($queueNames),
        ];
    }

    /**
     * @return array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * }
     */
    private function probeQueue(
        string $queueName,
        string $driver,
        string $connectionName,
        mixed $connectionConfig,
        \DateTimeInterface $windowStart
    ): array {
        $row = [
            'queue' => $queueName,
            'driver' => $driver,
            'status' => 'ok',
            'notes' => [],
            'backlog' => [
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'total' => 0,
                'oldest_pending_seconds' => 0,
                'oldest_reserved_seconds' => 0,
                'avg_pending_seconds' => 0,
            ],
            'retry_histogram' => [],
            'failures' => [
                'total' => 0,
                'window_total' => 0,
                'timeout_total' => 0,
                'window_timeout_total' => 0,
                'last_failed_at' => null,
            ],
            'report_jobs' => null,
            'attempt_submissions' => null,
        ];

        try {
            if ($driver === 'database') {
                $row = $this->probeDatabaseBacklog($row, $queueName);
            } elseif ($driver === 'redis') {
                $row = $this->probeRedisBacklog($row, $queueName, $connectionConfig);
            } else {
                $row['status'] = 'degraded';
                $row['notes'][] = sprintf('queue driver "%s" does not expose queue depth metrics.', $connectionName);
            }
        } catch (\Throwable $e) {
            $row['status'] = 'degraded';
            $row['notes'][] = 'backlog probe failed: '.$e->getMessage();
        }

        $row = $this->attachFailureMetrics($row, $queueName, $windowStart);
        $row = $this->attachQueueSpecificState($row, $queueName);

        return $row;
    }

    /**
     * @param  array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * } $row
     * @return array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * }
     */
    private function probeDatabaseBacklog(array $row, string $queueName): array
    {
        if (! Schema::hasTable('jobs')) {
            $row['status'] = 'degraded';
            $row['notes'][] = 'jobs table missing.';

            return $row;
        }

        $nowTs = now()->timestamp;

        $pending = (int) $this->jobsQuery($queueName)->whereNull('reserved_at')->count();
        $reserved = (int) $this->jobsQuery($queueName)->whereNotNull('reserved_at')->count();
        $oldestPendingCreated = $this->jobsQuery($queueName)->whereNull('reserved_at')->min('created_at');
        $oldestReservedAt = $this->jobsQuery($queueName)->whereNotNull('reserved_at')->min('reserved_at');
        $avgPendingCreated = $this->jobsQuery($queueName)->whereNull('reserved_at')->avg('created_at');

        $row['backlog']['pending'] = $pending;
        $row['backlog']['reserved'] = $reserved;
        $row['backlog']['total'] = $pending + $reserved;
        $row['backlog']['oldest_pending_seconds'] = $this->secondsSince($oldestPendingCreated, $nowTs);
        $row['backlog']['oldest_reserved_seconds'] = $this->secondsSince($oldestReservedAt, $nowTs);
        $row['backlog']['avg_pending_seconds'] = $this->secondsSince($avgPendingCreated, $nowTs);

        $retryRows = $this->jobsQuery($queueName)
            ->select('attempts', DB::raw('count(*) as total'))
            ->groupBy('attempts')
            ->orderBy('attempts')
            ->get();

        $histogram = [];
        foreach ($retryRows as $retryRow) {
            $histogram[] = [
                'attempts' => (int) ($retryRow->attempts ?? 0),
                'total' => (int) ($retryRow->total ?? 0),
            ];
        }
        $row['retry_histogram'] = $histogram;

        return $row;
    }

    /**
     * @param  array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * } $row
     * @return array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * }
     */
    private function probeRedisBacklog(array $row, string $queueName, mixed $connectionConfig): array
    {
        $redisConnection = 'default';
        if (is_array($connectionConfig)) {
            $candidate = trim((string) ($connectionConfig['connection'] ?? ''));
            if ($candidate !== '') {
                $redisConnection = $candidate;
            }
        }

        $keyBase = 'queues:'.$queueName;
        $pending = (int) Redis::connection($redisConnection)->llen($keyBase);
        $reserved = (int) Redis::connection($redisConnection)->zcard($keyBase.':reserved');
        $delayed = (int) Redis::connection($redisConnection)->zcard($keyBase.':delayed');

        $row['backlog']['pending'] = $pending;
        $row['backlog']['reserved'] = $reserved;
        $row['backlog']['delayed'] = $delayed;
        $row['backlog']['total'] = $pending + $reserved + $delayed;
        $row['notes'][] = 'redis backlog only exposes depth, not job age histogram.';

        return $row;
    }

    /**
     * @param  array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * } $row
     * @return array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * }
     */
    private function attachFailureMetrics(array $row, string $queueName, \DateTimeInterface $windowStart): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            $row['notes'][] = 'failed_jobs table missing.';

            return $row;
        }

        $base = DB::table('failed_jobs')->where('queue', $queueName);
        $row['failures']['total'] = (int) (clone $base)->count();
        $row['failures']['window_total'] = (int) (clone $base)
            ->where('failed_at', '>=', $windowStart)
            ->count();
        $row['failures']['timeout_total'] = (int) $this->applyTimeoutExceptionFilter(clone $base)->count();
        $row['failures']['window_timeout_total'] = (int) $this->applyTimeoutExceptionFilter(clone $base)
            ->where('failed_at', '>=', $windowStart)
            ->count();

        $lastFailedAt = (clone $base)->max('failed_at');
        $row['failures']['last_failed_at'] = is_string($lastFailedAt) && trim($lastFailedAt) !== ''
            ? trim($lastFailedAt)
            : null;

        return $row;
    }

    /**
     * @param  array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * } $row
     * @return array{
     *     queue:string,
     *     driver:string,
     *     status:string,
     *     notes:list<string>,
     *     backlog:array{
     *         pending:int,
     *         reserved:int,
     *         delayed:int,
     *         total:int,
     *         oldest_pending_seconds:int,
     *         oldest_reserved_seconds:int,
     *         avg_pending_seconds:int
     *     },
     *     retry_histogram:list<array{attempts:int,total:int}>,
     *     failures:array{
     *         total:int,
     *         window_total:int,
     *         timeout_total:int,
     *         window_timeout_total:int,
     *         last_failed_at:?string
     *     },
     *     report_jobs:null|array{
     *         states:array<string,int>,
     *         oldest_running_seconds:int
     *     },
     *     attempt_submissions:null|array{
     *         states:array<string,int>,
     *         oldest_pending_seconds:int
     *     }
     * }
     */
    private function attachQueueSpecificState(array $row, string $queueName): array
    {
        $nowTs = now()->timestamp;

        if ($queueName === 'reports' && Schema::hasTable('report_jobs')) {
            $states = [
                'queued' => 0,
                'running' => 0,
                'failed' => 0,
                'success' => 0,
            ];

            $groupRows = DB::table('report_jobs')
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            foreach ($groupRows as $groupRow) {
                $status = strtolower(trim((string) ($groupRow->status ?? '')));
                if ($status === '') {
                    continue;
                }
                $states[$status] = (int) ($groupRow->total ?? 0);
            }

            $oldestRunning = DB::table('report_jobs')
                ->where('status', 'running')
                ->min('started_at');

            $row['report_jobs'] = [
                'states' => $states,
                'oldest_running_seconds' => $this->secondsSince($oldestRunning, $nowTs),
            ];
        }

        if ($queueName === 'attempts' && Schema::hasTable('attempt_submissions')) {
            $states = [
                'pending' => 0,
                'running' => 0,
                'succeeded' => 0,
                'failed' => 0,
            ];

            $groupRows = DB::table('attempt_submissions')
                ->select('state', DB::raw('count(*) as total'))
                ->groupBy('state')
                ->get();

            foreach ($groupRows as $groupRow) {
                $state = strtolower(trim((string) ($groupRow->state ?? '')));
                if ($state === '') {
                    continue;
                }
                $states[$state] = (int) ($groupRow->total ?? 0);
            }

            $oldestPending = DB::table('attempt_submissions')
                ->where('state', 'pending')
                ->min('created_at');

            $row['attempt_submissions'] = [
                'states' => $states,
                'oldest_pending_seconds' => $this->secondsSince($oldestPending, $nowTs),
            ];
        }

        return $row;
    }

    private function applyTimeoutExceptionFilter(Builder $query): Builder
    {
        return $query->where(function (Builder $nested): void {
            $nested->where('exception', 'like', '%Timeout%')
                ->orWhere('exception', 'like', '%timed out%')
                ->orWhere('exception', 'like', '%Maximum execution time%');
        });
    }

    private function jobsQuery(string $queueName): Builder
    {
        return DB::table('jobs')->where('queue', $queueName);
    }

    /**
     * @param  list<string>  $queues
     * @return list<array{queue:string,command:string}>
     */
    private function workerHints(array $queues): array
    {
        $hints = [];
        foreach ($queues as $queueName) {
            $connection = match ($queueName) {
                'reports' => 'database_reports',
                'commerce' => 'database_commerce',
                default => 'database',
            };

            $timeout = match ($queueName) {
                'reports' => 180,
                'commerce' => 180,
                default => 180,
            };

            $hints[] = [
                'queue' => $queueName,
                'command' => sprintf(
                    'php artisan queue:work %s --queue=%s --tries=3 --timeout=%d --sleep=1',
                    $connection,
                    $queueName,
                    $timeout
                ),
            ];
        }

        return $hints;
    }

    private function secondsSince(mixed $value, int $nowTs): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $ts = null;
        if (is_int($value)) {
            $ts = $value;
        } elseif (is_float($value)) {
            $ts = (int) round($value);
        } elseif (is_numeric($value)) {
            $ts = (int) $value;
        } elseif (is_string($value)) {
            $parsed = strtotime($value);
            if ($parsed !== false) {
                $ts = $parsed;
            }
        }

        if ($ts === null || $ts <= 0) {
            return 0;
        }

        return max(0, $nowTs - $ts);
    }

    /**
     * @param  list<string>  $queues
     * @return list<string>
     */
    private function normalizeQueues(array $queues): array
    {
        $normalized = [];
        foreach ($queues as $queue) {
            $value = strtolower(trim((string) $queue));
            if ($value === '') {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
