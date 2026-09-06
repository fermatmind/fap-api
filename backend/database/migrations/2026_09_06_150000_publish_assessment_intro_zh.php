<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_intro_zh_20260906.json'), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }
                foreach ($package['scales'] as $code => $fields) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)->lockForUpdate()->first();
                    if ($row === null) {
                        continue; // New installations use the same reviewed package in the seeder.
                    }
                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    foreach ($fields as $key => $value) {
                        if (array_key_exists($key, $content['zh'] ?? []) && $content['zh'][$key] != $value) {
                            throw new RuntimeException($code.' Chinese introduction changed since review; refusing to overwrite.');
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
        // Keep published content during application rollback; revisions use forward migrations.
    }
};
