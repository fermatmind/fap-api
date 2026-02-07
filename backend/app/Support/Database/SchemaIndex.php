<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SchemaIndex
{
    private const SAFE_EXTRA_KEYS = [
        'connection',
        'phase',
        'reason',
        'status',
    ];

    public static function indexExists(string $table, string $indexName, ?string $connection = null): bool
    {
        if (!self::isSafeIdentifier($table) || !self::isSafeIdentifier($indexName)) {
            return false;
        }

        $conn = self::connection($connection);
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            return self::indexExistsSqlite($conn, $table, $indexName);
        }

        if ($driver === 'mysql') {
            return self::indexExistsMySql($conn, $table, $indexName);
        }

        if ($driver === 'pgsql') {
            return self::indexExistsPgSql($conn, $table, $indexName);
        }

        return false;
    }

    public static function isDuplicateIndexException(Throwable $e, string $indexName): bool
    {
        return self::matchesException($e, $indexName, [
            'already exists',
            'already an index named',
            'duplicate key name',
            'duplicate',
        ]);
    }

    public static function isMissingIndexException(Throwable $e, string $indexName): bool
    {
        return self::matchesException($e, $indexName, [
            'no such index',
            'does not exist',
            'unknown key name',
            'cannot drop index',
        ]);
    }

    public static function logIndexAction(
        string $action,
        string $table,
        string $indexName,
        ?string $driver = null,
        array $extra = []
    ): void {
        $context = [
            'action' => $action,
            'table' => $table,
            'index' => $indexName,
            'driver' => $driver ?? '',
        ];

        foreach (self::SAFE_EXTRA_KEYS as $key) {
            if (!array_key_exists($key, $extra)) {
                continue;
            }

            $value = $extra[$key];
            if (is_scalar($value) || $value === null) {
                $context[$key] = self::truncate((string) $value);
            }
        }

        Log::info('[schema_index] action', $context);
    }

    private static function indexExistsSqlite(ConnectionInterface $conn, string $table, string $indexName): bool
    {
        $tableName = str_replace("'", "''", $table);
        $rows = $conn->select("PRAGMA index_list('{$tableName}')");

        foreach ($rows as $row) {
            $name = (string) ($row->name ?? '');
            if ($name !== '' && strcasecmp($name, $indexName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function indexExistsMySql(ConnectionInterface $conn, string $table, string $indexName): bool
    {
        $database = (string) $conn->getDatabaseName();
        if ($database === '') {
            return false;
        }

        $rows = $conn->select(
            'SELECT index_name FROM information_schema.statistics WHERE table_schema = ? AND table_name = ?',
            [$database, $table]
        );

        foreach ($rows as $row) {
            $name = (string) ($row->index_name ?? $row->INDEX_NAME ?? '');
            if ($name !== '' && strcasecmp($name, $indexName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function indexExistsPgSql(ConnectionInterface $conn, string $table, string $indexName): bool
    {
        $schema = (string) ($conn->getConfig('schema') ?? 'public');
        $schema = trim(explode(',', $schema)[0]);
        if ($schema === '') {
            $schema = 'public';
        }

        $rows = $conn->select(
            'SELECT indexname FROM pg_indexes WHERE schemaname = ? AND tablename = ?',
            [$schema, $table]
        );

        foreach ($rows as $row) {
            $name = (string) ($row->indexname ?? '');
            if ($name !== '' && strcasecmp($name, $indexName) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function matchesException(Throwable $e, string $indexName, array $needles): bool
    {
        $message = mb_strtolower((string) $e->getMessage(), 'UTF-8');
        $indexNeedle = mb_strtolower($indexName, 'UTF-8');

        if ($indexNeedle === '' || mb_strpos($message, $indexNeedle) === false) {
            return false;
        }

        foreach ($needles as $needle) {
            if (mb_strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function connection(?string $connection = null): ConnectionInterface
    {
        if ($connection !== null && $connection !== '') {
            return DB::connection($connection);
        }

        return DB::connection();
    }

    private static function isSafeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]+$/', $value);
    }

    private static function truncate(string $value, int $maxLength = 128): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }
}
