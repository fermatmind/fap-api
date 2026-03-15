<?php

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
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

        Article::query()->create([
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
            'content_md' => '# MBTI Basics',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 11, 35, 0),
            'scheduled_at' => null,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        Article::query()->create([
            'org_id' => 0,
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础',
            'excerpt' => '了解 MBTI 的核心概念。',
            'content_md' => '# MBTI 基础',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 35, 0),
            'scheduled_at' => null,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        Article::query()->create([
            'org_id' => 0,
            'slug' => 'article-draft',
            'locale' => 'en',
            'title' => 'Draft Article',
            'excerpt' => 'Draft article should stay out of sitemap.',
            'content_md' => '# Draft Article',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'excerpt' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 11, 45, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'zh-CN',
            'title' => '产品经理',
            'excerpt' => '了解产品经理的职责、薪资水平、发展路径和人格匹配。',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 45, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'locale' => 'en',
            'title' => 'Private Role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 50, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => $nowB,
            'updated_at' => $nowB,
        ]);

        $guideEn = $this->createCareerGuide([
            'guide_code' => 'career-planning-101',
            'slug' => 'career-planning-101',
            'locale' => 'en',
            'title' => 'Career Planning 101',
            'published_at' => Carbon::create(2026, 1, 31, 13, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 1, 31, 12, 50, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 1, 31, 13, 30, 0, 'UTC'),
        ]);

        $guideZhPublishedAt = Carbon::create(2026, 1, 31, 14, 0, 0, 'UTC');

        DB::table('career_guides')->insert([
            'org_id' => 0,
            'guide_code' => 'job-fit-guide',
            'slug' => 'job-fit-guide',
            'locale' => 'zh-CN',
            'title' => '岗位匹配指南',
            'excerpt' => '理解岗位匹配与职业选择。',
            'category_slug' => 'job-fit',
            'body_md' => '# 岗位匹配指南',
            'body_html' => '<h1>岗位匹配指南</h1>',
            'related_industry_slugs_json' => json_encode(['technology']),
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'published_at' => $guideZhPublishedAt,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 1, 31, 13, 45, 0, 'UTC'),
            'updated_at' => null,
        ]);

        $this->createCareerGuide([
            'guide_code' => 'guide-draft',
            'slug' => 'guide-draft',
            'status' => CareerGuide::STATUS_DRAFT,
            'updated_at' => Carbon::create(2026, 1, 31, 14, 10, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'guide-private',
            'slug' => 'guide-private',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 1, 31, 14, 20, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'guide-noindex',
            'slug' => 'guide-noindex',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 1, 31, 14, 30, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'guide-future',
            'slug' => 'guide-future',
            'published_at' => Carbon::now('UTC')->addDay(),
            'updated_at' => Carbon::create(2026, 1, 31, 14, 40, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'org_id' => 9,
            'guide_code' => 'guide-tenant',
            'slug' => 'guide-tenant',
            'updated_at' => Carbon::create(2026, 1, 31, 14, 50, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'guide-fr',
            'slug' => 'guide-fr',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 1, 31, 15, 0, 0, 'UTC'),
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
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/articles</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/articles</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/articles/mbti-basics</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/articles/mbti-basics</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/articles/mbti-basics</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/articles/article-draft</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/career/jobs</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/career/jobs</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/career/jobs/product-manager</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/career/jobs/product-manager</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/jobs/private-role</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/career/guides</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/career/guides</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/en/career/guides/career-planning-101</loc>', $body);
        $this->assertStringContainsString('<loc>https://staging.fermatmind.com/zh/career/guides/job-fit-guide</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/career/guides/career-planning-101</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/guides/guide-draft</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/guides/guide-private</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/guides/guide-noindex</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/guides/guide-future</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/career/guides/guide-tenant</loc>', $body);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/fr/career/guides/guide-fr</loc>', $body);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $body);
        $this->assertStringContainsString('<priority>0.7</priority>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-30</lastmod>', $body);
        $this->assertStringContainsString('<lastmod>2026-01-31</lastmod>', $body);
        $this->assertSame(1, substr_count($body, '<loc>'.$prefix.'alpha</loc>'));

        $entries = $this->sitemapEntries($body);
        $this->assertSame(
            $guideEn->updated_at?->toAtomString(),
            $entries['https://staging.fermatmind.com/en/career/guides/career-planning-101'] ?? null
        );
        $this->assertSame(
            $guideZhPublishedAt->toAtomString(),
            $entries['https://staging.fermatmind.com/zh/career/guides'] ?? null
        );
        $this->assertSame(
            $guideZhPublishedAt->toAtomString(),
            $entries['https://staging.fermatmind.com/zh/career/guides/job-fit-guide'] ?? null
        );

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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCareerGuide(array $overrides = []): CareerGuide
    {
        /** @var CareerGuide */
        return CareerGuide::query()->create(array_merge([
            'org_id' => 0,
            'guide_code' => 'career-guide',
            'slug' => 'career-guide',
            'locale' => 'en',
            'title' => 'Career guide',
            'excerpt' => 'Career guide excerpt.',
            'category_slug' => 'career-planning',
            'body_md' => '# Career guide',
            'body_html' => '<h1>Career guide</h1>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'published_at' => Carbon::create(2026, 1, 31, 12, 40, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 1, 31, 12, 30, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 40, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @return array<string, string>
     */
    private function sitemapEntries(string $xml): array
    {
        $document = simplexml_load_string($xml);
        if ($document === false) {
            $this->fail('Failed to parse sitemap XML.');
        }

        $document->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $nodes = $document->xpath('/s:urlset/s:url');
        if ($nodes === false) {
            return [];
        }

        $entries = [];
        foreach ($nodes as $node) {
            $children = $node->children('http://www.sitemaps.org/schemas/sitemap/0.9');
            $loc = trim((string) $children->loc);
            if ($loc === '') {
                continue;
            }

            $entries[$loc] = trim((string) $children->lastmod);
        }

        return $entries;
    }
}
