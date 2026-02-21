<?php

namespace Tests\Feature\V0_3;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScalesLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_by_slug_hits_mbti(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI',
            'primary_slug' => 'mbti-test',
        ]);
        $response->assertJsonStructure([
            'seo_schema_json',
            'seo_schema',
        ]);
    }

    public function test_lookup_unknown_slug_returns_not_found(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $response = $this->getJson('/api/v0.3/scales/lookup?slug=unknown-slug');
        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_lookup_after_inserting_scale(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'IQ_RAVEN',
            'org_id' => 0,
            'primary_slug' => 'raven-iq-test',
            'slugs_json' => [
                'raven-iq-test',
                'raven-matrices',
            ],
            'driver_type' => 'iq',
            'default_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'default_region' => 'CN_MAINLAND',
            'default_locale' => 'zh-CN',
            'default_dir_version' => 'IQ-RAVEN-v0.1.0',
            'capabilities_json' => [
                'content_graph' => true,
            ],
            'view_policy_json' => [
                'report' => 'public',
            ],
            'commercial_json' => [
                'price_tier' => 'FREE',
            ],
            'seo_schema_json' => [
                '@context' => 'https://schema.org',
                '@type' => 'Quiz',
                'name' => 'Raven IQ Test',
            ],
            'is_public' => true,
            'is_active' => true,
        ]);
        $writer->syncSlugsForScale($scale);

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=raven-iq-test');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'IQ_RAVEN',
            'primary_slug' => 'raven-iq-test',
        ]);
        $response->assertJsonPath('seo_schema_json.@type', 'Quiz');
        $response->assertJsonPath('seo_schema.@type', 'Quiz');
    }

    public function test_slug_unique_constraint_enforced(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        if (! Schema::hasTable('scale_slugs')) {
            $this->markTestSkipped('scale_slugs missing');
        }

        DB::table('scale_slugs')->insert([
            'org_id' => 0,
            'slug' => 'unique-slug',
            'scale_code' => 'MBTI',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('scale_slugs')->insert([
            'org_id' => 0,
            'slug' => 'unique-slug',
            'scale_code' => 'IQ_RAVEN',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_lookup_returns_locale_specific_seo_fields(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $writer = app(ScaleRegistryWriter::class);

        $scale = $writer->upsertScale([
            'code' => 'I18N_SAMPLE',
            'org_id' => 0,
            'primary_slug' => 'i18n-sample',
            'slugs_json' => ['i18n-sample'],
            'driver_type' => 'mbti',
            'default_locale' => 'en',
            'seo_i18n_json' => [
                'en' => [
                    'title' => 'English SEO Title',
                    'description' => 'English SEO Description',
                    'og_image_url' => 'https://cdn.example.com/en-og.png',
                ],
                'zh' => [
                    'title' => '中文 SEO 标题',
                    'description' => '中文 SEO 描述',
                    'og_image_url' => 'https://cdn.example.com/zh-og.png',
                ],
            ],
            'is_public' => true,
            'is_active' => true,
            'is_indexable' => false,
        ]);
        $writer->syncSlugsForScale($scale);

        $zh = $this->getJson('/api/v0.3/scales/lookup?slug=i18n-sample&locale=zh');
        $zh->assertStatus(200);
        $zh->assertJsonPath('locale', 'zh-CN');
        $zh->assertJsonPath('seo_title', '中文 SEO 标题');
        $zh->assertJsonPath('seo_description', '中文 SEO 描述');
        $zh->assertJsonPath('og_image_url', 'https://cdn.example.com/zh-og.png');
        $zh->assertJsonPath('is_indexable', false);

        $en = $this->withHeaders(['X-FAP-Locale' => 'en'])
            ->getJson('/api/v0.3/scales/lookup?slug=i18n-sample');
        $en->assertStatus(200);
        $en->assertJsonPath('locale', 'en');
        $en->assertJsonPath('seo_title', 'English SEO Title');
        $en->assertJsonPath('seo_description', 'English SEO Description');
        $en->assertJsonPath('og_image_url', 'https://cdn.example.com/en-og.png');
    }

    public function test_sitemap_source_returns_indexable_and_lastmod(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        DB::table('scales_registry')->insert([
            [
                'code' => 'SITEMAP_A',
                'org_id' => 0,
                'primary_slug' => 'sitemap-a',
                'slugs_json' => json_encode(['sitemap-a', 'sitemap-a-alt']),
                'driver_type' => 'mbti',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => 'en',
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'seo_i18n_json' => null,
                'content_i18n_json' => null,
                'report_summary_i18n_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'is_indexable' => 1,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'code' => 'SITEMAP_B',
                'org_id' => 0,
                'primary_slug' => 'sitemap-b',
                'slugs_json' => json_encode(['sitemap-b']),
                'driver_type' => 'mbti',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => 'en',
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => json_encode(['indexable' => false]),
                'commercial_json' => null,
                'seo_schema_json' => null,
                'seo_i18n_json' => null,
                'content_i18n_json' => null,
                'report_summary_i18n_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'is_indexable' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/v0.3/scales/sitemap-source?locale=zh');
        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locale', 'zh');
        $response->assertJsonStructure([
            'items' => [
                ['slug', 'lastmod', 'is_indexable'],
            ],
        ]);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);

        $bySlug = collect($items)->keyBy('slug');
        $this->assertTrue($bySlug->has('sitemap-a'));
        $this->assertTrue((bool) $bySlug->get('sitemap-a')['is_indexable']);
        $this->assertTrue($bySlug->has('sitemap-b'));
        $this->assertFalse((bool) $bySlug->get('sitemap-b')['is_indexable']);
    }
}
