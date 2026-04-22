<?php

namespace Tests\Feature\V0_3;

use App\Services\Scale\ScaleRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'scale_code' => 'MBTI',
            'scale_code_legacy' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => '11111111-1111-4111-8111-111111111111',
            'pack_id_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3',
            'dir_version_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3',
            'primary_slug' => 'mbti-personality-test-16-personality-types',
            'requested_slug' => 'mbti-personality-test-16-personality-types',
            'resolved_from_alias' => false,
        ]);
        $response->assertJsonStructure([
            'seo_schema_json',
            'seo_schema',
        ]);
        $response->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1');
        $response->assertJsonPath('landing_surface_v1.entry_surface', 'test_detail');
        $response->assertJsonPath('landing_surface_v1.entry_type', 'test_landing');
        $response->assertJsonPath('forms.0.form_code', 'mbti_144');
        $response->assertJsonPath('forms.0.is_default', true);
        $response->assertJsonPath('forms.0.question_count', 144);
        $response->assertJsonPath('forms.1.form_code', 'mbti_93');
    }

    public function test_lookup_aliases_resolve_to_canonical_for_all_public_models(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $cases = [
            ['slug' => 'mbti-personality-test-16-personality-types', 'scale_code' => 'MBTI', 'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES', 'primary_slug' => 'mbti-personality-test-16-personality-types', 'resolved_from_alias' => false],
            ['slug' => 'mbti-test', 'scale_code' => 'MBTI', 'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES', 'primary_slug' => 'mbti-personality-test-16-personality-types', 'resolved_from_alias' => true],
            ['slug' => 'big-five-personality-test-ocean-model', 'scale_code' => 'BIG5_OCEAN', 'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL', 'primary_slug' => 'big-five-personality-test-ocean-model', 'resolved_from_alias' => false],
            ['slug' => 'big5-ocean', 'scale_code' => 'BIG5_OCEAN', 'scale_code_v2' => 'BIG_FIVE_OCEAN_MODEL', 'primary_slug' => 'big-five-personality-test-ocean-model', 'resolved_from_alias' => true],
            ['slug' => 'clinical-depression-anxiety-assessment-professional-edition', 'scale_code' => 'CLINICAL_COMBO_68', 'scale_code_v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO', 'primary_slug' => 'clinical-depression-anxiety-assessment-professional-edition', 'resolved_from_alias' => false],
            ['slug' => 'clinical-combo-68', 'scale_code' => 'CLINICAL_COMBO_68', 'scale_code_v2' => 'CLINICAL_DEPRESSION_ANXIETY_PRO', 'primary_slug' => 'clinical-depression-anxiety-assessment-professional-edition', 'resolved_from_alias' => true],
            ['slug' => 'depression-screening-test-standard-edition', 'scale_code' => 'SDS_20', 'scale_code_v2' => 'DEPRESSION_SCREENING_STANDARD', 'primary_slug' => 'depression-screening-test-standard-edition', 'resolved_from_alias' => false],
            ['slug' => 'sds-20', 'scale_code' => 'SDS_20', 'scale_code_v2' => 'DEPRESSION_SCREENING_STANDARD', 'primary_slug' => 'depression-screening-test-standard-edition', 'resolved_from_alias' => true],
            ['slug' => 'iq-test-intelligence-quotient-assessment', 'scale_code' => 'IQ_RAVEN', 'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT', 'primary_slug' => 'iq-test-intelligence-quotient-assessment', 'resolved_from_alias' => false],
            ['slug' => 'iq-test', 'scale_code' => 'IQ_RAVEN', 'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT', 'primary_slug' => 'iq-test-intelligence-quotient-assessment', 'resolved_from_alias' => true],
            ['slug' => 'eq-test-emotional-intelligence-assessment', 'scale_code' => 'EQ_60', 'scale_code_v2' => 'EQ_EMOTIONAL_INTELLIGENCE', 'primary_slug' => 'eq-test-emotional-intelligence-assessment', 'resolved_from_alias' => false],
            ['slug' => 'eq-test', 'scale_code' => 'EQ_60', 'scale_code_v2' => 'EQ_EMOTIONAL_INTELLIGENCE', 'primary_slug' => 'eq-test-emotional-intelligence-assessment', 'resolved_from_alias' => true],
            ['slug' => 'enneagram-personality-test-nine-types', 'scale_code' => 'ENNEAGRAM', 'scale_code_v2' => 'ENNEAGRAM_PERSONALITY_TEST', 'primary_slug' => 'enneagram-personality-test-nine-types', 'resolved_from_alias' => false],
            ['slug' => 'enneagram-test', 'scale_code' => 'ENNEAGRAM', 'scale_code_v2' => 'ENNEAGRAM_PERSONALITY_TEST', 'primary_slug' => 'enneagram-personality-test-nine-types', 'resolved_from_alias' => true],
            ['slug' => 'holland-career-interest-test-riasec', 'scale_code' => 'RIASEC', 'scale_code_v2' => 'HOLLAND_RIASEC_CAREER_INTEREST', 'primary_slug' => 'holland-career-interest-test-riasec', 'resolved_from_alias' => false],
            ['slug' => 'riasec-test', 'scale_code' => 'RIASEC', 'scale_code_v2' => 'HOLLAND_RIASEC_CAREER_INTEREST', 'primary_slug' => 'holland-career-interest-test-riasec', 'resolved_from_alias' => true],
        ];

        foreach ($cases as $case) {
            $response = $this->getJson('/api/v0.3/scales/lookup?slug='.$case['slug']);
            $response->assertStatus(200);
            $response->assertJson([
                'ok' => true,
                'scale_code' => $case['scale_code'],
                'scale_code_legacy' => $case['scale_code'],
                'scale_code_v2' => $case['scale_code_v2'],
                'primary_slug' => $case['primary_slug'],
                'requested_slug' => $case['slug'],
                'resolved_from_alias' => $case['resolved_from_alias'],
            ]);
            $response->assertJsonPath('slug', $case['primary_slug']);
            $this->assertIsString($response->json('pack_id_v2'));
            $this->assertIsString($response->json('dir_version_v2'));
        }
    }

    public function test_catalog_returns_backend_owned_test_landing_metadata(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        $response = $this->getJson('/api/v0.3/scales/catalog?locale=zh');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locale', 'zh-CN');
        $response->assertJsonStructure([
            'items' => [
                [
                    'slug',
                    'scale_code',
                    'scale_code_v2',
                    'title',
                    'title_i18n',
                    'description',
                    'description_i18n',
                    'cover_image',
                    'questions_count',
                    'time_minutes',
                    'forms',
                    'card_visual',
                    'card_tone',
                    'card_seed',
                    'card_density',
                    'card_tagline_i18n',
                    'highlight_priority',
                    'highlight_rating',
                    'highlight_excerpt_i18n',
                    'highlight_seo_copy_i18n',
                    'is_public',
                    'is_active',
                    'is_indexable',
                ],
            ],
        ]);

        $items = collect($response->json('items'));
        $this->assertCount(8, $items);
        $mbti = $items->firstWhere('slug', 'mbti-personality-test-16-personality-types');
        $this->assertIsArray($mbti);
        $this->assertSame('MBTI', $mbti['scale_code']);
        $this->assertSame('MBTI 性格测试（16型人格测试）', $mbti['title']);
        $this->assertSame(144, $mbti['questions_count']);
        $this->assertSame(15, $mbti['time_minutes']);
        $this->assertSame('spark_minimal', $mbti['card_visual']);
        $this->assertSame('类型轴线综合', $mbti['card_tagline_i18n']['zh']);
        $this->assertSame(100, $mbti['highlight_priority']);
        $this->assertSame('mbti_144', data_get($mbti, 'forms.0.form_code'));
        $this->assertTrue((bool) data_get($mbti, 'forms.0.is_default'));
        $this->assertSame(144, data_get($mbti, 'forms.0.question_count'));

        $bigFive = $items->firstWhere('slug', 'big-five-personality-test-ocean-model');
        $this->assertIsArray($bigFive);
        $this->assertSame('big5_120', data_get($bigFive, 'forms.0.form_code'));
        $this->assertSame('big5_90', data_get($bigFive, 'forms.1.form_code'));

        $enneagram = $items->firstWhere('slug', 'enneagram-personality-test-nine-types');
        $this->assertIsArray($enneagram);
        $this->assertSame('enneagram_likert_105', data_get($enneagram, 'forms.0.form_code'));
        $this->assertSame('enneagram_forced_choice_144', data_get($enneagram, 'forms.1.form_code'));

        $riasec = $items->firstWhere('slug', 'holland-career-interest-test-riasec');
        $this->assertIsArray($riasec);
        $this->assertSame('RIASEC', $riasec['scale_code']);
        $this->assertSame(60, $riasec['questions_count']);
        $this->assertSame('riasec_60', data_get($riasec, 'forms.0.form_code'));
        $this->assertTrue((bool) data_get($riasec, 'forms.0.is_default'));
        $this->assertSame(60, data_get($riasec, 'forms.0.question_count'));
        $this->assertSame('riasec_140', data_get($riasec, 'forms.1.form_code'));
        $this->assertSame(140, data_get($riasec, 'forms.1.question_count'));
    }

    public function test_lookup_alias_mode_canonical_only_rejects_alias_but_allows_canonical(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        Config::set('scales_lookup.alias_mode', 'canonical_only');

        $canonical = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types');
        $canonical->assertStatus(200);
        $canonical->assertJson([
            'ok' => true,
            'primary_slug' => 'mbti-personality-test-16-personality-types',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'resolved_from_alias' => false,
        ]);

        $alias = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test');
        $alias->assertStatus(404);
        $alias->assertJson([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
        ]);
    }

    public function test_lookup_uses_v2_primary_scale_code_when_response_mode_is_v2(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');

        Config::set('scale_identity.api_response_scale_code_mode', 'v2');
        Cache::flush();
        Cache::store((string) config('content_packs.mbti_response_cache_store', 'hot_redis'))->flush();

        $response = $this->getJson('/api/v0.3/scales/lookup?slug=mbti-test');
        $response->assertStatus(200);
        $response->assertJsonPath('scale_code', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('scale_code_legacy', 'MBTI');
        $response->assertJsonPath('scale_code_v2', 'MBTI_PERSONALITY_TEST_16_TYPES');
        $response->assertJsonPath('resolved_from_alias', true);
        $response->assertJsonPath('pack_id_v2', 'MBTI_PERSONALITY_TEST_16_TYPES.cn-mainland.zh-CN.v0.3');
        $response->assertJsonPath('dir_version_v2', 'MBTI_PERSONALITY_TEST_16_TYPES-CN-v0.3');
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
            'scale_code_legacy' => 'IQ_RAVEN',
            'scale_code_v2' => 'IQ_INTELLIGENCE_QUOTIENT',
            'scale_uid' => '55555555-5555-4555-8555-555555555555',
            'primary_slug' => 'raven-iq-test',
            'requested_slug' => 'raven-iq-test',
            'resolved_from_alias' => false,
        ]);
        $response->assertJsonStructure([
            'pack_id_v2',
            'dir_version_v2',
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
