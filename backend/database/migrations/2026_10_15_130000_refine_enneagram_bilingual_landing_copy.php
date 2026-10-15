<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_enneagram_bilingual_20261015.json'), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }

                $row = DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }

                $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                foreach ($package['locales'] as $locale => $updates) {
                    if (! is_array($content[$locale] ?? null)) {
                        throw new RuntimeException('ENNEAGRAM.'.$locale.' content is missing.');
                    }
                    foreach ($updates as $update) {
                        $collection = data_get($content[$locale], $update['collection']);
                        $index = is_array($collection) ? array_search($update['id'], array_column($collection, 'id'), true) : false;
                        if ($index === false) {
                            throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' is missing.');
                        }
                        $current = $collection[$index][$update['field']] ?? null;
                        if ($current === $update['value']) {
                            continue;
                        }
                        if ($current !== $update['expected']) {
                            throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' changed since review; refusing to overwrite.');
                        }
                        $collection[$index][$update['field']] = $update['value'];
                        data_set($content[$locale], $update['collection'], $collection);
                    }
                }
                DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->update([
                    'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Retain compatible published copy on application rollback; revise forward.
    }
};
