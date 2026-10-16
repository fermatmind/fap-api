<?php

declare(strict_types=1);

use App\Services\Scale\ScaleRegistryWriter;
use App\Support\CanonicalTranslationPayloadHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Own the bilingual transaction so cache invalidation follows its commit.
    public $withinTransaction = false;

    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_enneagram_context_links_20261016.json'), true, 512, JSON_THROW_ON_ERROR);

        $matched = DB::transaction(function () use ($package): bool {
            $matched = false;
            foreach (['scales_registry', 'scales_registry_v2'] as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'content_i18n_json')) {
                    continue;
                }

                $row = DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->lockForUpdate()->first();
                if ($row === null) {
                    continue;
                }

                $matched = true;
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
                        if (CanonicalTranslationPayloadHash::sameValue($current, $update['value'])) {
                            continue;
                        }
                        if (! CanonicalTranslationPayloadHash::sameValue($current, $update['expected'])) {
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

            return $matched;
        });

        if ($matched) {
            // Also close caches on idempotent re-entry after a prior cache failure.
            try {
                app(ScaleRegistryWriter::class)->invalidatePublicContentProjection(
                    0,
                    'ENNEAGRAM',
                    ['enneagram-personality-test-nine-types'],
                );
            } catch (Throwable $exception) {
                throw new RuntimeException('ENNEAGRAM context links committed; PARTIAL_CACHE_CLOSEOUT.', 0, $exception);
            }
        }
    }

    public function down(): void
    {
        // Retain compatible published links on application rollback; revise forward.
    }
};
