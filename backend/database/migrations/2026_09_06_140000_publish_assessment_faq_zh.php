<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_faq_zh_20260906.json'), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($package): void {
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }
                foreach ($package['scales'] as $code => $entry) {
                    $row = DB::table($table)->where('org_id', 0)->where('code', $code)->lockForUpdate()->first();
                    if ($row === null) {
                        continue; // Fresh installs receive the same reviewed content from the seeder.
                    }
                    $content = json_decode($row->content_i18n_json ?: '{}', true, 512, JSON_THROW_ON_ERROR);
                    $current = $content['zh']['faq'] ?? null;
                    if ($current == $entry['faq']) {
                        continue;
                    }
                    if ($current != $entry['expected_faq'] && (! isset($entry['expected_initial_zh']) || ($content['zh'] ?? null) != $entry['expected_initial_zh'])) {
                        throw new RuntimeException($code.' Chinese FAQ changed since review; refusing to overwrite.');
                    }
                    $content['zh']['faq'] = $entry['faq'];
                    DB::table($table)->where('org_id', 0)->where('code', $code)->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Published content survives code rollback; revisions use a baseline-checked forward migration.
    }
};
