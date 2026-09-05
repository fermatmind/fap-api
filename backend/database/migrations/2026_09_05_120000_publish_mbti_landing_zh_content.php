<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/mbti_landing_zh_20260905.json'), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }
                $row = DB::table($table)->where('org_id', 0)->where('code', 'MBTI')->lockForUpdate()->first();
                if ($row === null) {
                    continue; // Fresh databases receive catalog records separately.
                }
                $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                $zh = $content['zh'] ?? [];
                if (! is_array($zh)) {
                    throw new RuntimeException('MBTI Chinese content is not an object.');
                }
                $candidate = $package['content'];
                if (array_intersect_key($zh, $candidate) == $candidate) {
                    continue;
                }
                if (($zh['faq'] ?? []) != $package['expected_faq'] || isset($zh['why_choose']) || isset($zh['version_comparison'])) {
                    throw new RuntimeException('MBTI Chinese landing content changed since review; refusing to overwrite.');
                }
                $content['zh'] = array_replace($zh, $candidate);
                DB::table($table)->where('org_id', 0)->where('code', 'MBTI')->update([
                    'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Content survives code rollback. Revisions use a forward, baseline-checked migration.
    }
};
