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
     * @var array<string, array{reason:string,exception_class:?string}>
     */
    private static array $tableMetaCache = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private static array $tableColumnsCache = [];

    /**
     * @var array<string, ?string>
     */
    private static array $tableColumnsIntrospectionExceptionCache = [];

    /**
     * @var array<string, array{reason:string,exception_class:?string}>
     */
    private static array $columnMetaCache = [];

    public static function hasTable(string $table): bool
    {
        return self::hasTableWithMeta($table);
    }

    public static function hasTableWithMeta(string $table, ?string &$reason = null, ?string &$exceptionClass = null): bool
    {
        $table = strtolower(trim($table));
        if ($table === '') {
            $reason = 'invalid_input';
            $exceptionClass = null;
            return false;
        }

        $feature = self::featureForTable($table);
        if ($feature !== null && (bool) config("fap.features.{$feature}", false) !== true) {
            $cacheKey = self::cacheKey($table);
            self::$tableExistsCache[$cacheKey] = false;
            self::$tableMetaCache[$cacheKey] = [
                'reason' => 'feature_disabled',
                'exception_class' => null,
            ];
            $reason = 'feature_disabled';
            $exceptionClass = null;
            return false;
        }

        $cacheKey = self::cacheKey($table);
        if (array_key_exists($cacheKey, self::$tableExistsCache)) {
            $meta = self::$tableMetaCache[$cacheKey] ?? [
                'reason' => self::$tableExistsCache[$cacheKey] ? 'exists' : 'unknown_false',
                'exception_class' => null,
            ];
            $reason = $meta['reason'];
            $exceptionClass = $meta['exception_class'];
            return self::$tableExistsCache[$cacheKey];
        }

        $meta = [
            'reason' => 'table_missing',
            'exception_class' => null,
        ];

        try {
            $exists = Schema::hasTable($table);
            $meta['reason'] = $exists ? 'exists' : 'table_missing';
        } catch (\Throwable $e) {
            Log::warning('[schema_baseline] has_table_failed', [
                'table' => $table,
                'connection' => self::connectionName(),
                'exception' => $e::class,
            ]);
            $exists = false;
            $meta['reason'] = 'schema_query_exception';
            $meta['exception_class'] = $e::class;
        }

        self::$tableExistsCache[$cacheKey] = $exists;
        self::$tableMetaCache[$cacheKey] = $meta;
        if (! $exists) {
            unset(self::$tableColumnsCache[$cacheKey]);
            unset(self::$tableColumnsIntrospectionExceptionCache[$cacheKey]);
        }

        $reason = $meta['reason'];
        $exceptionClass = $meta['exception_class'];

        return $exists;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return self::hasColumnWithMeta($table, $column);
    }

    public static function hasColumnWithMeta(
        string $table,
        string $column,
        ?string &$reason = null,
        ?string &$exceptionClass = null
    ): bool {
        $table = strtolower(trim($table));
        $column = strtolower(trim($column));
        if ($table === '' || $column === '') {
            $reason = 'invalid_input';
            $exceptionClass = null;
            return false;
        }

        $columnCacheKey = self::columnCacheKey($table, $column);
        if (array_key_exists($columnCacheKey, self::$columnMetaCache)) {
            $meta = self::$columnMetaCache[$columnCacheKey];
            $reason = $meta['reason'];
            $exceptionClass = $meta['exception_class'];
            if ($meta['reason'] === 'exists') {
                return true;
            }
            if ($meta['reason'] === 'column_missing') {
                return false;
            }
        }

        $tableReason = null;
        $tableExceptionClass = null;
        if (! self::hasTableWithMeta($table, $tableReason, $tableExceptionClass)) {
            $reason = $tableReason === 'schema_query_exception'
                ? 'table_check_exception'
                : 'table_missing';
            $exceptionClass = $tableExceptionClass;
            self::$columnMetaCache[$columnCacheKey] = [
                'reason' => $reason,
                'exception_class' => $exceptionClass,
            ];
            return false;
        }

        $cacheKey = self::cacheKey($table);
        if (! array_key_exists($cacheKey, self::$tableColumnsCache)) {
            self::$tableColumnsCache[$cacheKey] = self::introspectColumns($table);
        }

        $introspectionExceptionClass = self::$tableColumnsIntrospectionExceptionCache[$cacheKey] ?? null;
        if ($introspectionExceptionClass !== null) {
            $reason = 'column_listing_exception';
            $exceptionClass = $introspectionExceptionClass;
            self::$columnMetaCache[$columnCacheKey] = [
                'reason' => $reason,
                'exception_class' => $exceptionClass,
            ];

            return false;
        }

        $exists = self::$tableColumnsCache[$cacheKey][$column] ?? false;
        $reason = $exists ? 'exists' : 'column_missing';
        $exceptionClass = null;
        self::$columnMetaCache[$columnCacheKey] = [
            'reason' => $reason,
            'exception_class' => null,
        ];

        return $exists;
    }

    public static function clearCache(): void
    {
        self::$tableExistsCache = [];
        self::$tableMetaCache = [];
        self::$tableColumnsCache = [];
        self::$tableColumnsIntrospectionExceptionCache = [];
        self::$columnMetaCache = [];
    }

    /**
     * @return array<string, bool>
     */
    private static function introspectColumns(string $table): array
    {
        $cacheKey = self::cacheKey($table);

        try {
            $columns = Schema::getColumnListing($table);
            self::$tableColumnsIntrospectionExceptionCache[$cacheKey] = null;
        } catch (\Throwable $e) {
            Log::warning('[schema_baseline] get_column_listing_failed', [
                'table' => $table,
                'connection' => self::connectionName(),
                'exception' => $e::class,
            ]);
            self::$tableColumnsIntrospectionExceptionCache[$cacheKey] = $e::class;

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

    private static function columnCacheKey(string $table, string $column): string
    {
        return self::cacheKey($table).'.'.$column;
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
        if (! is_array($tableToFeature)) {
            return null;
        }

        $feature = $tableToFeature[$table] ?? null;
        if (! is_string($feature)) {
            return null;
        }

        $feature = strtolower(trim($feature));

        return $feature !== '' ? $feature : null;
    }
}
