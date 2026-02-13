<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class VerifySchemaCommand extends Command
{
    protected $signature = 'fap:schema:verify';

    protected $description = 'Verify runtime schema baseline before deployment';

    public function handle(): int
    {
        $missingTables = [];
        $missingColumns = [];

        $requiredTables = config('fap.schema_baseline.required_tables', []);
        if (is_array($requiredTables)) {
            foreach ($requiredTables as $table) {
                $table = strtolower(trim((string) $table));
                if ($table === '') {
                    continue;
                }

                $feature = $this->featureForTable($table);
                if ($feature !== null && !$this->featureEnabled($feature)) {
                    continue;
                }

                if (!Schema::hasTable($table)) {
                    $missingTables[] = $table;
                }
            }
        }

        $requiredColumns = config('fap.schema_baseline.required_columns', []);
        if (is_array($requiredColumns)) {
            foreach ($requiredColumns as $table => $columns) {
                $table = strtolower(trim((string) $table));
                if ($table === '' || !is_array($columns)) {
                    continue;
                }

                $feature = $this->featureForTable($table);
                if ($feature !== null && !$this->featureEnabled($feature)) {
                    continue;
                }

                if (!Schema::hasTable($table)) {
                    $missingTables[] = $table;
                    continue;
                }

                foreach ($columns as $column) {
                    $column = strtolower(trim((string) $column));
                    if ($column === '') {
                        continue;
                    }

                    if (!Schema::hasColumn($table, $column)) {
                        $missingColumns[] = "{$table}.{$column}";
                    }
                }
            }
        }

        $missingTables = array_values(array_unique($missingTables));
        sort($missingTables);
        $missingColumns = array_values(array_unique($missingColumns));
        sort($missingColumns);

        if ($missingTables !== [] || $missingColumns !== []) {
            $this->error('schema baseline check failed');

            if ($missingTables !== []) {
                $this->line('missing tables:');
                foreach ($missingTables as $table) {
                    $this->line("- {$table}");
                }
            }

            if ($missingColumns !== []) {
                $this->line('missing columns:');
                foreach ($missingColumns as $column) {
                    $this->line("- {$column}");
                }
            }

            return self::FAILURE;
        }

        $this->info('OK: schema baseline verified');

        return self::SUCCESS;
    }

    private function featureForTable(string $table): ?string
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

    private function featureEnabled(string $feature): bool
    {
        return (bool) config("fap.features.{$feature}", false) === true;
    }
}
