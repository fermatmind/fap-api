<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_landing_content_seo_20260917.json'), true, 512, JSON_THROW_ON_ERROR);

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
                    foreach ($scale['locales'] as $locale => $localizedPackage) {
                        $localized = $content[$locale] ?? null;
                        if (! is_array($localized)) {
                            throw new RuntimeException($scaleCode.'.'.$locale.' content is not an object.');
                        }

                        $items = data_get($localized, 'why_choose.items');
                        if (! is_array($items)) {
                            throw new RuntimeException($scaleCode.'.'.$locale.'.why_choose.items is missing.');
                        }

                        $versionItem = $localizedPackage['version_item'];
                        $index = array_search($versionItem['id'], array_column($items, 'id'), true);
                        if ($index === false) {
                            $items[] = $versionItem;
                        } elseif ($items[$index] !== $versionItem) {
                            throw new RuntimeException($scaleCode.'.'.$locale.'.why_choose.items.versions changed since review; refusing to overwrite.');
                        }
                        data_set($localized, 'why_choose.items', $items);

                        $currentComparison = $localized['version_comparison'] ?? null;
                        if ($currentComparison !== null && $currentComparison !== $localizedPackage['version_comparison']) {
                            throw new RuntimeException($scaleCode.'.'.$locale.'.version_comparison changed since review; refusing to overwrite.');
                        }
                        $localized['version_comparison'] = $localizedPackage['version_comparison'];
                        $content[$locale] = $localized;
                    }

                    DB::table($table)->where('org_id', 0)->where('code', $scaleCode)->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Published content stays readable on rollback. Revisions are forward-only.
    }
};
