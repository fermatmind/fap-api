<?php

declare(strict_types=1);

namespace App\Support\Database;

final class SchemaCache
{
    /**
     * @var array<string, bool>
     */
    private static array $tableCache = [];

    /**
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    public static function hasTable(string $table): bool
    {
        if (!array_key_exists($table, self::$tableCache)) {
            self::$tableCache[$table] = \App\Support\SchemaBaseline::hasTable($table);
        }

        return self::$tableCache[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, self::$columnCache)) {
            self::$columnCache[$key] = \App\Support\SchemaBaseline::hasColumn($table, $column);
        }

        return self::$columnCache[$key];
    }

    public static function clear(): void
    {
        self::$tableCache = [];
        self::$columnCache = [];
    }
}
