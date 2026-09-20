<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(
            file_get_contents(__DIR__.'/../data/assessment_mbti_method_consolidation_en_20260920.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (($package['schema_version'] ?? null) !== 'assessment.exact-field-copy.v2'
            || ($package['scale_code'] ?? null) !== 'MBTI'
            || ($package['locale'] ?? null) !== 'en'
            || count($package['updates'] ?? []) !== 7) {
            throw new RuntimeException('MBTI English method consolidation package identity is invalid.');
        }
        $expectedTables = ['scales_registry', 'scales_registry_v2'];

        DB::transaction(function () use ($package, $expectedTables): void {
            $availableTables = array_values(array_filter(
                $expectedTables,
                static fn (string $table): bool => Schema::hasTable($table) && Schema::hasColumn($table, 'content_i18n_json'),
            ));
            if ($availableTables === []) {
                return;
            }
            if ($availableTables !== $expectedTables) {
                throw new RuntimeException('MBTI registry table set is incomplete; refusing partial update.');
            }

            $rows = [];
            foreach ($expectedTables as $table) {
                $rows[$table] = DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $package['scale_code'])
                    ->lockForUpdate()
                    ->first();
            }

            $presentRows = array_filter($rows, static fn ($row): bool => $row !== null);
            if ($presentRows === []) {
                return; // Fresh databases receive the same package through the seeder.
            }
            if (count($presentRows) !== count($expectedTables)) {
                throw new RuntimeException('MBTI registry row set is incomplete; refusing partial update.');
            }

            $contents = [];
            $states = [];
            foreach ($rows as $table => $row) {
                $contents[$table] = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $localized = $contents[$table][$package['locale']] ?? null;
                if (! is_array($localized)) {
                    throw new RuntimeException($table.' MBTI English content is not an object.');
                }
                $states[$table] = $this->classifyState($localized, $package['updates'], $table);
            }

            if (count(array_unique($states)) !== 1) {
                throw new RuntimeException('MBTI registry targets are in a mixed state; refusing partial update.');
            }
            if (reset($states) === 'new') {
                return;
            }

            foreach ($expectedTables as $table) {
                $localized = $contents[$table][$package['locale']];
                foreach ($package['updates'] as $update) {
                    $this->applyUpdate($localized, $update);
                }
                $contents[$table][$package['locale']] = $localized;
                DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $package['scale_code'])
                    ->update([
                        'content_i18n_json' => json_encode($contents[$table], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $localized
     * @param  array<int, array<string, mixed>>  $updates
     */
    private function classifyState(array $localized, array $updates, string $table): string
    {
        $allExpected = true;
        $allNew = true;

        foreach ($updates as $update) {
            [$exists, $current] = $this->readTarget($localized, $update, $table);
            $expectedExists = $update['expected_exists'] ?? true;
            $matchesExpected = $expectedExists
                ? $exists && $this->valuesEqual($current, $update['expected'] ?? null)
                : ! $exists;
            $matchesNew = $exists && $this->valuesEqual($current, $update['value']);
            $allExpected = $allExpected && $matchesExpected;
            $allNew = $allNew && $matchesNew;
        }

        if ($allExpected) {
            return 'old';
        }
        if ($allNew) {
            return 'new';
        }

        throw new RuntimeException($table.' MBTI target fields are mixed or changed since review; refusing to overwrite.');
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return $this->normalizeValue($left) === $this->normalizeValue($right);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
    }

    /**
     * @param  array<string, mixed>  $localized
     * @param  array<string, mixed>  $update
     * @return array{bool, mixed}
     */
    private function readTarget(array $localized, array $update, string $table): array
    {
        $collection = data_get($localized, $update['collection']);
        if (! is_array($collection)) {
            throw new RuntimeException($table.' '.$update['collection'].' is not an array.');
        }
        $index = array_search($update['id'], array_column($collection, 'id'), true);
        if ($index === false) {
            throw new RuntimeException($table.' '.$update['collection'].'.'.$update['id'].' is missing.');
        }

        return [
            array_key_exists($update['field'], $collection[$index]),
            $collection[$index][$update['field']] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $localized
     * @param  array<string, mixed>  $update
     */
    private function applyUpdate(array &$localized, array $update): void
    {
        $collection = data_get($localized, $update['collection']);
        $index = array_search($update['id'], array_column($collection, 'id'), true);
        $collection[$index][$update['field']] = $update['value'];
        data_set($localized, $update['collection'], $collection);
    }

    public function down(): void
    {
        // Published copy survives code rollback. A later forward migration must re-check live field values.
    }
};
