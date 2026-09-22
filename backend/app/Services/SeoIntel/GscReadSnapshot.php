<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use WeakMap;

/** One consistent GSC read across SQL aggregates and lazy database pages. */
final class GscReadSnapshot
{
    /** @var WeakMap<ConnectionInterface, bool>|null */
    private static ?WeakMap $active = null;

    /** @param Closure(): array $read */
    public static function read(ConnectionInterface $connection, Closure $read): array
    {
        if ($connection->getDriverName() !== 'mysql') {
            return $connection->transaction($read);
        }

        self::$active ??= new WeakMap;
        if (isset(self::$active[$connection])) {
            return $read();
        }
        if ($connection->transactionLevel() !== 0) {
            // A savepoint cannot upgrade an existing READ COMMITTED snapshot.
            // Do not commit or change a transaction owned by the caller.
            throw new RuntimeException('GSC_READ_SNAPSHOT_REQUIRES_OWN_TRANSACTION');
        }

        // Applies to this next transaction only; leave the session default and
        // the scheduled writer's later transactions unchanged.
        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        try {
            return $connection->transaction(function () use ($connection, $read): array {
                self::$active[$connection] = true;

                return $read();
            });
        } finally {
            unset(self::$active[$connection]);
        }
    }
}
