<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $scales = json_decode(file_get_contents(__DIR__.'/../data/assessment_hero_titles_zh_20260907.json'), true, 512, JSON_THROW_ON_ERROR)['scales'];
        DB::transaction(function () use ($scales): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                foreach ($scales as $code => $entry) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)->lockForUpdate()->first();
                    if ($row === null) {
                        continue; // Fresh registry rows receive the reviewed title from the seeder.
                    }
                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    $current = $content['zh']['landing_entry']['title'] ?? null;
                    if ($current === $entry['title']) {
                        continue;
                    }
                    if ($current !== $entry['expected_title']) {
                        throw new RuntimeException($code.' Chinese entry title changed since review; refusing to overwrite.');
                    }
                    $content['zh']['landing_entry']['title'] = $entry['title'];
                    DB::table($table)->where('org_id', 0)->where('code', $code)->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
            if (! Schema::hasTable('landing_surfaces')) {
                return;
            }
            foreach ($scales as $code => $entry) {
                $query = DB::table('landing_surfaces')->where('org_id', 0)->where('locale', 'zh-CN')->where('surface_key', $entry['surface_key']);
                $row = (clone $query)->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }
                $payload = json_decode($row->payload_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $current = $payload['h1_or_hero_title'] ?? null;
                if ($current === $entry['title']) {
                    continue;
                }
                if ($entry['expected_surface_title'] === null || $current !== $entry['expected_surface_title']) {
                    throw new RuntimeException($code.' Chinese CMS hero title changed since review; refusing to overwrite.');
                }
                $payload['h1_or_hero_title'] = $entry['title'];
                $query->update(['payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function down(): void
    {
        // Text remains compatible with previous releases; revisions use a baseline-checked forward migration.
    }
};
