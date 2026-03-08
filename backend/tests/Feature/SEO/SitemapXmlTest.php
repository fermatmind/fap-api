<?php

namespace Tests\Feature\SEO;

use App\Models\PersonalityProfile;
use App\Models\TopicProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapXmlTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_xml_is_cached_and_filtered(): void
    {
        config(['services.seo.tests_url_prefix' => 'https://fermatmind.com/tests/']);
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $nowA = Carbon::create(2026, 1, 30, 10, 0, 0);
        $nowB = Carbon::create(2026, 1, 31, 12, 0, 0);

        DB::table('scales_registry')->insert([
            [
                'code' => 'PR24_ALPHA',
                'org_id' => 0,
                'primary_slug' => 'alpha',
                'slugs_json' => json_encode(['beta', 'alpha']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $nowA,
                'updated_at' => $nowA,
            ],
            [
                'code' => 'PR24_GAMMA',
                'org_id' => 0,
                'primary_slug' => 'gamma',
                'slugs_json' => json_encode(['delta']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $nowB,
                'updated_at' => $nowB,
            ],
            [
                'code' => 'PR24_HIDDEN',
                'org_id' => 0,
                'primary_slug' => 'hidden',
                'slugs_json' => json_encode(['hidden-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 1,
                'is_active' => 0,
                'created_at' => $nowB,
                'updated_at' => $nowB,
            ],
        ]);

        PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'subtitle' => 'Strategic and future-oriented.',
            'excerpt' => 'Explore INTJ traits, strengths, and growth.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 11, 0, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'title' => 'INTJ 人格类型',
            'subtitle' => '理性、战略、面向未来。',
            'excerpt' => '探索 INTJ 的特质、优势与成长方向。',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 0, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI',
            'subtitle' => 'Understand personality preferences and type dynamics.',
            'excerpt' => 'Explore MBTI concepts, type profiles, guides, and tests.',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 11, 30, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 10,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'zh-CN',
            'title' => 'MBTI 主题',
            'subtitle' => '理解人格偏好与类型框架。',
            'excerpt' => '探索 MBTI 概念、类型档案与测试入口。',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 30, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 10,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'title' => 'Big Five',
            'subtitle' => 'Trait dimensions for personality description.',
            'excerpt' => 'Explore the Big Five model.',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 20,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $contentType = (string) $response->headers->get('Content-Type');
        $this->assertStringContainsString('application/xml', $contentType);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('s-maxage=86400', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cacheControl);

        $etag = (string) $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $response->assertHeaderMissing('Set-Cookie');

        $body = (string) $response->getContent();
        $prefix = rtrim((string) config('services.seo.tests_url_prefix'), '/').'/';
        $this->assertStringContainsString('<loc>'.$prefix.'alpha</loc>', $body);
        $this->assertStringContainsString('<loc>'.$prefix.'beta</loc>', $body);
        $this->assertStringContainsString('<loc>'.$prefix.'gamma</loc>', $body);
        $this->assertStringContainsString('<loc>'.$prefix.'delta</loc>', $body);
        $this->assertStringNotContainsString('<loc>'.$prefix.'hidden</loc>', $body);
        $this->assertStringNotContainsString('<loc>'.$prefix.'hidden-alt</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/personality</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/personality</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/personality/intj</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/personality/intj</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/topics</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/topics</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/topics/mbti</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/topics/mbti</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/topics/big-five</loc>', $body);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $body);
        $this->assertStringContainsString('<priority>0.7</priority>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-30</lastmod>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-31</lastmod>', $body);
        $this->assertSame(1, substr_count($body, '<loc>'.$prefix.'alpha</loc>'));

        $second = $this->withHeaders(['If-None-Match' => $etag])->get('/sitemap.xml');
        $second->assertStatus(304);
        $cacheControl304 = (string) $second->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl304);
        $this->assertStringContainsString('max-age=3600', $cacheControl304);
        $this->assertStringContainsString('s-maxage=86400', $cacheControl304);
        $this->assertStringContainsString('stale-while-revalidate=604800', $cacheControl304);
        $second->assertHeader('ETag', $etag);
        $second->assertHeaderMissing('Set-Cookie');
        $this->assertSame('', (string) $second->getContent());
    }
}
