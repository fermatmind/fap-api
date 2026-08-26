<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Scale\PublicScaleCatalogCache;
use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicScaleResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.public_scale_cache_store', 'array');
        Config::set('content_packs.public_scale_lookup_cache_ttl_seconds', 600);
        Config::set('content_packs.public_scale_catalog_fresh_ttl_seconds', 60);
        Config::set('content_packs.public_scale_catalog_stale_ttl_seconds', 120);
        Config::set('content_packs.public_scale_catalog_ttl_jitter_seconds', 0);
        Cache::store('array')->flush();
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_lookup_cache_covers_all_flagship_public_scales_without_shape_changes(): void
    {
        foreach ([
            ['mbti-personality-test-16-personality-types', 'MBTI'],
            ['big-five-personality-test-ocean-model', 'BIG5_OCEAN'],
            ['enneagram-personality-test-nine-types', 'ENNEAGRAM'],
            ['holland-career-interest-test-riasec', 'RIASEC'],
        ] as [$slug, $scaleCode]) {
            $first = $this->getJson("/api/v0.3/scales/lookup?slug={$slug}&locale=zh");
            $second = $this->getJson("/api/v0.3/scales/lookup?slug={$slug}&locale=zh");

            $first->assertOk()->assertHeader('X-FAP-Cache', 'miss');
            $second->assertOk()->assertHeader('X-FAP-Cache', 'hit');
            $second->assertJsonPath('scale_code_legacy', $scaleCode);
            $second->assertJsonPath('requested_slug', $slug);
            $second->assertJsonPath('resolved_from_alias', false);
            $this->assertSame($first->json(), $second->json());
        }
    }

    public function test_catalog_projection_selects_only_declared_fields(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v0.3/scales/catalog?locale=zh')->assertOk();

        $catalogSelect = collect(DB::getQueryLog())->first(
            static fn (array $query): bool => str_contains((string) ($query['query'] ?? ''), 'from "scales_registry_v2"')
                && str_contains((string) ($query['query'] ?? ''), '"content_i18n_json"')
        );
        $this->assertIsArray($catalogSelect);
        $sql = (string) $catalogSelect['query'];
        $this->assertStringNotContainsString('select *', strtolower($sql));
        foreach ([
            'code',
            'primary_slug',
            'default_pack_id',
            'default_dir_version',
            'default_locale',
            'capabilities_json',
            'view_policy_json',
            'seo_schema_json',
            'seo_i18n_json',
            'content_i18n_json',
            'is_public',
            'is_active',
            'is_indexable',
        ] as $column) {
            $this->assertStringContainsString('"'.$column.'"', $sql);
        }
    }

    public function test_successful_registry_write_bumps_catalog_generation(): void
    {
        $cache = app(PublicScaleCatalogCache::class);
        $before = $cache->generation(0);
        $writer = app(ScaleRegistryWriter::class);
        $writer->upsertScale([
            'code' => 'CACHE_INVALIDATION_SAMPLE',
            'org_id' => 0,
            'primary_slug' => 'cache-invalidation-sample',
            'slugs_json' => ['cache-invalidation-sample'],
            'driver_type' => 'mbti',
            'default_locale' => 'en',
            'content_i18n_json' => [
                'en' => [
                    'title' => 'Cache invalidation sample',
                    'description' => 'Cache invalidation sample description',
                    'catalog' => ['questions_count' => 10, 'time_minutes' => 2],
                ],
            ],
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => true,
        ]);

        $this->assertSame($before + 1, $cache->generation(0));
    }

    public function test_authoritative_unpublish_does_not_reuse_lookup_or_registry_slug_cache(): void
    {
        $slug = 'big-five-personality-test-ocean-model';
        $this->getJson("/api/v0.3/scales/lookup?slug={$slug}&locale=zh")->assertOk();

        $row = (array) DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'BIG5_OCEAN')
            ->first();
        foreach ([
            'slugs_json',
            'capabilities_json',
            'view_policy_json',
            'commercial_json',
            'seo_schema_json',
            'seo_i18n_json',
            'content_i18n_json',
            'report_summary_i18n_json',
        ] as $column) {
            if (is_string($row[$column] ?? null)) {
                $row[$column] = json_decode($row[$column], true);
            }
        }
        $row['is_public'] = false;
        unset($row['id'], $row['created_at'], $row['updated_at']);

        app(ScaleRegistryWriter::class)->upsertScale($row);

        $this->getJson("/api/v0.3/scales/lookup?slug={$slug}&locale=zh")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }
}
