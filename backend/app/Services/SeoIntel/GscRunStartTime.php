<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final class GscRunStartTime
{
    public static function expanded(ConnectionInterface $connection): bool
    {
        return SchemaBaseline::columnExists('seo_gsc_sync_runs', 'started_at_utc', $connection->getName());
    }

    public static function expression(ConnectionInterface $connection): string
    {
        // The legacy writer explicitly stored a UTC string in created_at and
        // never updated it. Read it using the existing writer connection's
        // timezone contract; started_at may have been changed by MySQL itself.
        if (! SchemaBaseline::columnExists('seo_gsc_sync_runs', 'created_at', $connection->getName())) {
            throw new RuntimeException('GSC_RUN_START_UNAVAILABLE');
        }

        return self::expanded($connection) ? 'COALESCE(started_at_utc, created_at)' : 'created_at';
    }

    /** @param list<string> $columns */
    public static function latest(Builder $query, array $columns, ?CarbonImmutable $now = null): ?object
    {
        $start = self::expression($query->getConnection());
        $rows = $query->select($columns)->selectRaw($start.' AS run_started_at_utc')
            // A run without an immutable start cannot safely be ordered behind
            // a success. UUIDs and completion times are not attempt order.
            ->orderByRaw($start.' IS NULL DESC')->orderByRaw($start.' DESC')->limit(2)->get();
        $latest = $rows->first();
        if ($latest !== null && ($latest->run_started_at_utc === null
            || ($rows->count() > 1 && $latest->run_started_at_utc === $rows[1]->run_started_at_utc))) {
            throw new RuntimeException('GSC_RUN_START_AMBIGUOUS');
        }
        if ($latest !== null && CarbonImmutable::parse($latest->run_started_at_utc, 'UTC')->gt($now ?? CarbonImmutable::now('UTC'))) {
            throw new RuntimeException('GSC_RUN_START_INVALID');
        }

        return $latest;
    }
}
