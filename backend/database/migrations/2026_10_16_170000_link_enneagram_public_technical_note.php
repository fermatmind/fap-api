<?php

declare(strict_types=1);

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Own the bilingual transaction so cache invalidation follows its commit.
    public $withinTransaction = false;

    public function up(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/../data/assessment_enneagram_technical_note_links_20261016.json'), true, 512, JSON_THROW_ON_ERROR);

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
                $content = json_decode($row->content_i18n_json ?: '{}', false, 512, JSON_THROW_ON_ERROR);
                $changed = false;
                foreach ($package['locales'] as $locale => $updates) {
                    if (! is_object($content->{$locale} ?? null)) {
                        throw new RuntimeException('ENNEAGRAM.'.$locale.' content is missing.');
                    }
                    foreach ($updates as $update) {
                        $collection = $content->{$locale}->{$update['collection']} ?? null;
                        if (! is_array($collection)) {
                            throw new RuntimeException('ENNEAGRAM.'.$locale.' FAQ is malformed.');
                        }
                        $indices = [];
                        foreach ($collection as $index => $item) {
                            if (is_object($item) && ($item->id ?? null) === $update['id']) {
                                $indices[] = $index;
                            }
                        }
                        if (count($indices) !== 1) {
                            throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' is missing or duplicated; refusing to overwrite.');
                        }
                        $item = $collection[$indices[0]];
                        $current = $item->{$update['field']} ?? [];
                        if (! is_array($current)) {
                            throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' links are malformed; refusing to overwrite.');
                        }
                        $found = false;
                        foreach ($current as $link) {
                            if (! is_object($link) || ! is_string($link->href ?? null) || ! is_string($link->label ?? null) || trim($link->href) === '' || trim($link->label) === '') {
                                throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' link is malformed; refusing to overwrite.');
                            }
                            if ($link->href === $update['link']['href']) {
                                if ($link->label !== $update['link']['label']) {
                                    throw new RuntimeException('ENNEAGRAM.'.$locale.'.'.$update['id'].' target link changed; refusing to overwrite.');
                                }
                                $found = true;
                            }
                        }
                        if (! $found) {
                            $current[] = (object) $update['link'];
                            $item->{$update['field']} = $current;
                            $changed = true;
                        }
                    }
                }
                if ($changed) {
                    DB::table($table)->where('org_id', 0)->where('code', 'ENNEAGRAM')->update([
                        'content_i18n_json' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                }
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
                throw new RuntimeException('ENNEAGRAM technical note links committed; PARTIAL_CACHE_CLOSEOUT.', 0, $exception);
            }
        }
    }

    public function down(): void
    {
        // Retain compatible published links on application rollback; revise forward.
    }
};
