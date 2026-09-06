<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $read = fn (string $name): array => json_decode(file_get_contents(__DIR__.'/../data/'.$name), true, 512, JSON_THROW_ON_ERROR);
        $package = $read('assessment_entry_zh_20260906.json')['scales'];
        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }
                foreach ($package as $code => $fields) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)->lockForUpdate()->first();
                    if ($row === null) {
                        continue; // Fresh rows receive this same package through the seeder.
                    }
                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    foreach ($fields as $key => $value) {
                        $current = $content['zh'][$key] ?? null;
                        if ($current != $value && $current !== null) {
                            throw new RuntimeException($code.' Chinese entry copy already exists; refusing to overwrite.');
                        }
                        $content['zh'][$key] = $value;
                    }
                    DB::table($table)->where('org_id', 0)->where('code', $code)->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Published entry copy remain readable on application rollback. Revise through a forward migration.
    }
};
