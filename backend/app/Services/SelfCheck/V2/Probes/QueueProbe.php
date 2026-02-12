<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\Probes;

use App\Services\SelfCheck\V2\Contracts\ProbeInterface;
use App\Services\SelfCheck\V2\DTO\ProbeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

final class QueueProbe implements ProbeInterface
{
    public function name(): string
    {
        return 'queue';
    }

    public function probe(bool $verbose = false): array
    {
        $driver = (string) config('queue.default', 'sync');
        $connection = config("queue.connections.{$driver}");

        try {
            if (!is_array($connection)) {
                return (new ProbeResult(false, 'QUEUE_CONFIG_MISSING', 'queue connection config missing'))
                    ->toArray($verbose);
            }

            if ($driver === 'database') {
                $hasJobs = Schema::hasTable('jobs');
                if (!$hasJobs) {
                    return (new ProbeResult(false, 'QUEUE_TABLE_MISSING', 'jobs table not found'))
                        ->toArray($verbose);
                }
                DB::select('select 1 as ok');
                return (new ProbeResult(true, '', '', [
                    'driver' => $driver,
                    'connection' => (string) ($connection['connection'] ?? ''),
                    'failed_jobs' => Schema::hasTable('failed_jobs'),
                ]))->toArray($verbose);
            }

            if ($driver === 'redis') {
                $redisConnection = (string) ($connection['connection'] ?? 'default');
                $pong = Redis::connection($redisConnection)->ping();
                $ok = ((string) $pong === 'PONG' || $pong === true);

                return (new ProbeResult($ok, $ok ? '' : 'QUEUE_UNAVAILABLE', $ok ? '' : 'redis queue ping failed', [
                    'driver' => $driver,
                    'connection' => $redisConnection,
                ]))->toArray($verbose);
            }

            return (new ProbeResult(true, '', '', [
                'driver' => $driver,
                'connection' => (string) ($connection['connection'] ?? ''),
            ]))->toArray($verbose);
        } catch (\Throwable $e) {
            return (new ProbeResult(false, 'QUEUE_UNAVAILABLE', (string) $e->getMessage()))->toArray($verbose);
        }
    }
}
