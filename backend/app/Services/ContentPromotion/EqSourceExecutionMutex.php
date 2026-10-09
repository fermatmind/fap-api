<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

final class EqSourceExecutionMutex
{
    /** @return resource Held by the driver and explicitly inherited by each child phase. */
    public static function acquire(string $directory, string $execution, float $timeout = 760): mixed
    {
        if (preg_match('/\A[a-f0-9]{40}-[1-9][0-9]{0,19}-1\z/', $execution) !== 1
            || (! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory)) {
            throw new DomainException('eq_source_mutex_identity_invalid');
        }
        $file = $directory.'/'.$execution.'.lock';
        if (is_link($file)) {
            throw new DomainException('eq_source_mutex_invalid');
        }
        $handle = fopen($file, 'c');
        if ($handle === false) {
            throw new DomainException('eq_source_mutex_unavailable');
        }
        $start = microtime(true);
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) - $start >= $timeout) {
                fclose($handle);
                throw new DomainException('eq_source_execution_still_running');
            }
            usleep(10000);
        }

        return $handle;
    }

    /** Called while holding this execution's mutex. Recovery closes admission. */
    public static function claimExecution(string $directory, string $execution, bool $recover): string
    {
        $path = $directory.'/'.$execution;
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new DomainException('eq_source_execution_directory_invalid');
        }
        if (is_dir($path)) {
            if (! $recover) {
                throw new DomainException('eq_source_execution_already_started_or_closed');
            }

            return $path;
        }
        if (! mkdir($path, 0700)) {
            throw new DomainException('eq_source_execution_directory_invalid');
        }

        return $path;
    }
}
