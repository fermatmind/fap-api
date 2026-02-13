<?php

declare(strict_types=1);

namespace App\Support;

final class SchemaBaseline
{
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

        $requiredTables = config('fap.schema_baseline.required_tables', []);
        if (!is_array($requiredTables) || $requiredTables === []) {
            return true;
        }

        $requiredTables = array_map(static fn ($item): string => strtolower(trim((string) $item)), $requiredTables);

        return in_array($table, $requiredTables, true);
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

        $requiredColumns = config('fap.schema_baseline.required_columns', []);
        if (!is_array($requiredColumns) || $requiredColumns === []) {
            return true;
        }

        $columns = $requiredColumns[$table] ?? null;
        if (!is_array($columns)) {
            return true;
        }

        $columns = array_map(static fn ($item): string => strtolower(trim((string) $item)), $columns);

        return in_array($column, $columns, true);
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
