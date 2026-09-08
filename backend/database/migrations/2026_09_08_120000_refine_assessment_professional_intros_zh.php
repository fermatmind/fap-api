<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_professional_intros_zh_20260908.json'), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($package): void {
            foreach ($package['scales'] as $scaleCode => $scale) {
                foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                        continue;
                    }

                    $row = DB::table($table)->where('org_id', 0)->where('code', $scaleCode)->lockForUpdate()->first();
                    if ($row === null) {
                        continue;
                    }

                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    $localized = $content[$package['locale']] ?? null;
                    if (! is_array($localized)) {
                        throw new RuntimeException($scaleCode.' Chinese content is not an object.');
                    }

                    foreach ($scale['updates'] as $update) {
                        $this->applyUpdate($localized, $update, $scaleCode);
                    }

                    $content[$package['locale']] = $localized;
                    DB::table($table)->where('org_id', 0)->where('code', $scaleCode)->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
    }

    private function applyUpdate(array &$localized, array $update, string $scaleCode): void
    {
        if (isset($update['path'])) {
            $current = data_get($localized, $update['path']);
            if ($current === $update['value']) {
                return;
            }
            if ($current !== $update['expected']) {
                throw new RuntimeException($scaleCode.'.'.$update['path'].' changed since review; refusing to overwrite.');
            }
            data_set($localized, $update['path'], $update['value']);

            return;
        }

        $collection = data_get($localized, $update['collection']);
        $index = is_array($collection) ? array_search($update['id'], array_column($collection, 'id'), true) : false;
        if ($index === false) {
            throw new RuntimeException($scaleCode.'.'.$update['collection'].'.'.$update['id'].' is missing.');
        }
        $current = $collection[$index][$update['field']] ?? null;
        if ($current === $update['value']) {
            return;
        }
        if ($current !== $update['expected']) {
            throw new RuntimeException($scaleCode.'.'.$update['collection'].'.'.$update['id'].'.'.$update['field'].' changed since review; refusing to overwrite.');
        }
        $collection[$index][$update['field']] = $update['value'];
        data_set($localized, $update['collection'], $collection);
    }

    public function down(): void
    {
        // Published copy remains readable on rollback. Revise through a forward migration.
    }
};
