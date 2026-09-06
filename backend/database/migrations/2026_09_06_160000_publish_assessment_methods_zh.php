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
        $package = $read('assessment_methods_zh_20260906.json')['scales'];
        $faq = $read('assessment_faq_zh_20260906.json')['scales'];
        $intro = $read('assessment_intro_zh_20260906.json')['scales'];
        $mbti = $read('mbti_landing_zh_20260905.json')['content'];
        DB::transaction(function () use ($package, $faq, $intro, $mbti): void {
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
                    $baseline = $code === 'MBTI' ? $mbti : array_merge($intro[$code] ?? [], ['faq' => $faq[$code]['faq']]);
                    foreach ($fields as $key => $value) {
                        $current = $content['zh'][$key] ?? null;
                        if ($current != $value && $current != ($baseline[$key] ?? null)) {
                            throw new RuntimeException($code.' Chinese methods changed since review; refusing to overwrite.');
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
        // Published methods remain readable on application rollback. Revise through a forward migration.
    }
};
