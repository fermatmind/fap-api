<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\UrlTruth;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Process-local capability for existing derived Truth operations only. */
final class ScopedUrlTruthWriter
{
    public const AGENT_INVOCABLE = false;

    private const DATABASE_KEYS = ['driver', 'host', 'port', 'unix_socket', 'database', 'prefix', 'charset', 'collation', 'url', 'read', 'write'];

    public function connectionName(): string
    {
        $reader = (string) config('seo_intel.connection', 'seo_intel');
        $writer = (string) config('seo_council.connection', $reader);
        $read = config('database.connections.'.$reader);
        $write = config('database.connections.'.$writer);
        if (! is_array($read) || ! is_array($write)) {
            throw new RuntimeException('URL_TRUTH_WRITER_UNAVAILABLE');
        }
        foreach (self::DATABASE_KEYS as $key) {
            if (($read[$key] ?? null) !== ($write[$key] ?? null)) {
                throw new RuntimeException('URL_TRUTH_WRITER_DATABASE_MISMATCH');
            }
        }

        return $writer;
    }

    public function run(callable $operation): mixed
    {
        if (PHP_SAPI !== 'cli' || ! app()->runningInConsole() || ! config('seo_intel.enabled', false)) {
            throw new RuntimeException('SCOPED_URL_TRUTH_CLI_REQUIRED');
        }
        $originalWrite = config('seo_intel.write_enabled', false);
        $reader = (string) config('seo_intel.connection', 'seo_intel');
        $writer = $this->connectionName();
        // Resolve by name; never rewrite credentials or mutate a cached connection.
        // A long-lived worker may have cached a connection before config changed.
        foreach (array_unique([$reader, $writer]) as $name) {
            $connection = DB::connection($name);
            foreach (self::DATABASE_KEYS as $key) {
                if ($connection->getConfig($key) !== config('database.connections.'.$name.'.'.$key)) {
                    throw new RuntimeException('URL_TRUTH_WRITER_DATABASE_MISMATCH');
                }
            }
        }
        if (DB::connection($reader)->getDriverName() === 'mysql') {
            $readDatabase = DB::connection($reader)->selectOne('SELECT DATABASE() AS db')->db;
            $writeDatabase = DB::connection($writer)->selectOne('SELECT DATABASE() AS db')->db;
            if (! is_string($readDatabase) || $readDatabase === '' || $readDatabase !== $writeDatabase) {
                throw new RuntimeException('URL_TRUTH_WRITER_DATABASE_MISMATCH');
            }
        }
        try {
            config(['seo_intel.write_enabled' => true, 'seo_intel.connection' => $writer]);

            return $operation();
        } finally {
            config(['seo_intel.write_enabled' => $originalWrite, 'seo_intel.connection' => $reader]);
        }
    }
}
