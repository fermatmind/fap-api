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

    private const AUDIT_TABLE = 'migration_index_audits';

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
        self::persistAuditRecord($action, $table, $indexName, $driver, $extra);
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

    private static function persistAuditRecord(
        string $action,
        string $table,
        string $indexName,
        ?string $driver,
        array $extra
    ): void {
        try {
            $conn = self::connection(self::safeString($extra['connection'] ?? null, 32));
            if (!$conn->getSchemaBuilder()->hasTable(self::AUDIT_TABLE)) {
                return;
            }

            $now = now();
            $auditDriver = $driver;
            if ($auditDriver === null || $auditDriver === '') {
                $auditDriver = $conn->getDriverName();
            }

            $meta = [];
            foreach ($extra as $key => $value) {
                if (in_array($key, self::SAFE_EXTRA_KEYS, true)) {
                    continue;
                }
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }
                $meta[(string) $key] = self::truncate((string) $value);
            }

            $conn->table(self::AUDIT_TABLE)->insert([
                'migration_name' => self::detectMigrationName(),
                'table_name' => self::truncate($table, 128),
                'index_name' => self::truncate($indexName, 128),
                'action' => self::truncate($action, 64),
                'phase' => self::safeString($extra['phase'] ?? null, 32),
                'driver' => self::truncate((string) $auditDriver, 32),
                'status' => self::safeString($extra['status'] ?? null, 32) ?? 'logged',
                'reason' => self::safeString($extra['reason'] ?? null, 191),
                'meta_json' => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::warning('[schema_index] audit_persist_skipped', [
                'table' => $table,
                'index' => $indexName,
                'action' => $action,
                'reason' => self::truncate((string) $e->getMessage(), 128),
            ]);
        }
    }

    private static function connection(?string $connection = null): ConnectionInterface
    {
        if ($connection !== null && $connection !== '') {
            return DB::connection($connection);
        }

        return DB::connection();
    }

    private static function detectMigrationName(): ?string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($trace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file === '') {
                continue;
            }
            if (strpos($file, DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR) === false) {
                continue;
            }

            $name = pathinfo($file, PATHINFO_FILENAME);
            if ($name === '') {
                continue;
            }

            return self::truncate($name, 191);
        }

        return null;
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

    private static function safeString(mixed $value, int $maxLength): ?string
    {
        if (!is_scalar($value) && $value !== null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return self::truncate($text, $maxLength);
    }
}
