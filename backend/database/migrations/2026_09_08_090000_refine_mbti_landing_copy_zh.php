<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_mbti_copy_zh_20260908.json'), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }

                $row = DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $package['scale_code'])
                    ->lockForUpdate()
                    ->first();
                if ($row === null) {
                    continue;
                }

                $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $localized = $content[$package['locale']] ?? null;
                if (! is_array($localized)) {
                    throw new RuntimeException('MBTI Chinese content is not an object.');
                }

                foreach ($package['updates'] as $update) {
                    $this->applyUpdate($localized, $update, true);
                }

                $content[$package['locale']] = $localized;
                DB::table($table)
                    ->where('org_id', 0)
                    ->where('code', $package['scale_code'])
                    ->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $localized
     * @param  array<string, mixed>  $update
     */
    private function applyUpdate(array &$localized, array $update, bool $validateBaseline): void
    {
        if (isset($update['path'])) {
            $current = data_get($localized, $update['path']);
            if ($current === $update['value']) {
                return;
            }
            if ($validateBaseline && $current !== $update['expected']) {
                throw new RuntimeException($update['path'].' changed since review; refusing to overwrite.');
            }
            data_set($localized, $update['path'], $update['value']);

            return;
        }

        $collection = data_get($localized, $update['collection']);
        if (! is_array($collection)) {
            throw new RuntimeException($update['collection'].' is not an array.');
        }
        $index = array_search($update['id'], array_column($collection, 'id'), true);
        if ($index === false) {
            throw new RuntimeException($update['collection'].'.'.$update['id'].' is missing.');
        }
        $current = $collection[$index][$update['field']] ?? null;
        if ($current === $update['value']) {
            return;
        }
        if ($validateBaseline && $current !== $update['expected']) {
            throw new RuntimeException($update['collection'].'.'.$update['id'].'.'.$update['field'].' changed since review; refusing to overwrite.');
        }
        $collection[$index][$update['field']] = $update['value'];
        data_set($localized, $update['collection'], $collection);
    }

    public function down(): void
    {
        // Published copy remains readable on rollback. Revise through a forward migration.
    }
};
