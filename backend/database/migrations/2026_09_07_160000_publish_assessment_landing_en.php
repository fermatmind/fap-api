<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_landing_en_20260907.json'), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }
                foreach ($package['scales'] as $code => $entry) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)->lockForUpdate()->first();
                    if ($row === null) {
                        continue; // Fresh records receive the package from ScaleRegistrySeeder.
                    }
                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    foreach ($entry['content'] as $key => $value) {
                        if (($content['zh'][$key] ?? null) != $entry['source_zh'][$key]) {
                            throw new RuntimeException($code.' Chinese translation source changed; refusing publication.');
                        }
                        $current = $content['en'][$key] ?? null;
                        if ($current != $value && $current != $entry['expected_en'][$key]) {
                            throw new RuntimeException($code.' English landing content changed; refusing overwrite.');
                        }
                        $content['en'][$key] = $value;
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
        // Additive translations remain available on code rollback; revisions publish forward.
    }
};
