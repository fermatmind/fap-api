<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SchemaBaseline
{
    /**
     * @var array<string, bool>
     */
    private static array $tableExistsCache = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private static array $tableColumnsCache = [];

    public static function hasTable(string $table): bool
    {
        $table = strtolower(trim($table));
        if ($table === '') {
            return false;
        }

        $feature = self::featureForTable($table);
        if ($feature !== null && (bool) config("fap.features.{$feature}", false) !== true) {
            return false;
        }

        $cacheKey = self::cacheKey($table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            return self::$tableExistsCache[$cacheKey];
        }

        try {
            $exists = Schema::hasTable($table);
        } catch (\Throwable $e) {
            Log::warning('[schema_baseline] has_table_failed', [
                'table' => $table,
                'connection' => self::connectionName(),
                'exception' => $e::class,
            ]);
            $exists = false;
        }

        self::$tableExistsCache[$cacheKey] = $exists;
        if (! $exists) {
            unset(self::$tableColumnsCache[$cacheKey]);
        }

        return $exists;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $table = strtolower(trim($table));
        $column = strtolower(trim($column));
        if ($table === '' || $column === '') {
            return false;
        }

        if (!self::hasTable($table)) {
            return false;
        }

        $cacheKey = self::cacheKey($table);
        if (!array_key_exists($cacheKey, self::$tableColumnsCache)) {
            self::$tableColumnsCache[$cacheKey] = self::introspectColumns($table);
        }

        return self::$tableColumnsCache[$cacheKey][$column] ?? false;
    }

    public static function clearCache(): void
    {
        self::$tableExistsCache = [];
        self::$tableColumnsCache = [];
    }

    /**
     * @return array<string, bool>
     */
    private static function introspectColumns(string $table): array
    {
        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable $e) {
            Log::warning('[schema_baseline] get_column_listing_failed', [
                'table' => $table,
                'connection' => self::connectionName(),
                'exception' => $e::class,
            ]);

            return [];
        }

        $normalized = [];
        foreach ($columns as $name) {
            $key = strtolower(trim((string) $name));
            if ($key === '') {
                continue;
            }
            $normalized[$key] = true;
        }

        return $normalized;
    }

    private static function cacheKey(string $table): string
    {
        return self::connectionName().':'.$table;
    }

    private static function connectionName(): string
    {
        try {
            $name = DB::connection()->getName();
            $name = strtolower(trim((string) $name));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            Log::warning('[schema_baseline] connection_name_resolve_failed', [
                'exception' => $e::class,
            ]);
        }

        $default = strtolower(trim((string) config('database.default', 'default')));

        return $default !== '' ? $default : 'default';
    }

    private static function featureForTable(string $table): ?string
    {
        $tableToFeature = config('fap.schema_baseline.feature_tables', []);
        if (!is_array($tableToFeature)) {
            return null;
        }

        $feature = $tableToFeature[$table] ?? null;
        if (!is_string($feature)) {
            return null;
        }

        $feature = strtolower(trim($feature));

        return $feature !== '' ? $feature : null;
    }
}
