<?php

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\ContentPage;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Models\PersonalityPublicContentAsset;
use App\Models\TopicProfile;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\TopicProfileSeoService;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SitemapGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_excludes_dataset_urls_and_includes_only_public_global_scales(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $now = now();
        // Isolate this test from migration-seeded default scales.
        DB::table('scales_registry')->delete();

        DB::table('scales_registry')->insert([
            [
                'code' => 'P0_PUBLIC_GLOBAL',
                'org_id' => 0,
                'primary_slug' => 'public-global',
                'slugs_json' => json_encode(['public-global-alt']),
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
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'P0_PRIVATE_GLOBAL',
                'org_id' => 0,
                'primary_slug' => 'private-global',
                'slugs_json' => json_encode(['private-global-alt']),
                'driver_type' => 'MBTI',
                'default_pack_id' => null,
                'default_region' => null,
                'default_locale' => null,
                'default_dir_version' => null,
                'capabilities_json' => null,
                'view_policy_json' => null,
                'commercial_json' => null,
                'seo_schema_json' => null,
                'is_public' => 0,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'P0_PUBLIC_TENANT',
                'org_id' => 9,
                'primary_slug' => 'tenant-public',
                'slugs_json' => json_encode(['tenant-public-alt']),
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
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $payload = $this->generateSitemap();

        $slugList = (array) ($payload['slug_list'] ?? []);
        sort($slugList, SORT_STRING);

        $this->assertContains('tests:en:public-global', $slugList);
        $this->assertContains('tests:zh:public-global', $slugList);
        $this->assertNotContains('career-dataset-hub', $slugList);
        $this->assertNotContains('career-dataset-method', $slugList);

        $xml = (string) ($payload['xml'] ?? '');
        $this->assertStringNotContainsString('https://www.fermatmind.com/datasets/occupations', $xml);
        $this->assertStringNotContainsString('https://www.fermatmind.com/datasets/occupations/method', $xml);
        $this->assertStringContainsString('https://fermatmind.com/en/tests/public-global', $xml);
        $this->assertStringContainsString('https://fermatmind.com/zh/tests/public-global', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/tests/public-global', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/tests/public-global-alt', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/tests/public-global-alt', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/tests/private-global', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/tests/private-global', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/tests/private-global-alt', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/tests/private-global-alt', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/tests/tenant-public', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/tests/tenant-public', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/tests/tenant-public-alt', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/tests/tenant-public-alt', $xml);
    }

    public function test_generate_includes_core_static_index_urls_and_excludes_help_pages(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'help-about',
            'path' => '/help/about',
            'kind' => ContentPage::KIND_HELP,
            'locale' => 'en',
            'title' => 'About help',
            'content_md' => '# About help',
            'content_html' => null,
            'seo_title' => 'About help',
            'meta_description' => 'About help page.',
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 8, 9, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 3, 8, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 8, 9, 0, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');

        foreach ([
            'https://fermatmind.com/',
            'https://fermatmind.com/en',
            'https://fermatmind.com/en/business',
            'https://fermatmind.com/en/career',
            'https://fermatmind.com/en/career/guides',
            'https://fermatmind.com/en/career/recommendations',
            'https://fermatmind.com/en/career/tests',
            'https://fermatmind.com/en/support',
            'https://fermatmind.com/en/tests',
            'https://fermatmind.com/en/tests/category/career',
            'https://fermatmind.com/en/tests/category/personality',
            'https://fermatmind.com/zh/business',
            'https://fermatmind.com/zh/career',
            'https://fermatmind.com/zh/career/guides',
            'https://fermatmind.com/zh/career/recommendations',
            'https://fermatmind.com/zh/career/tests',
            'https://fermatmind.com/zh/support',
            'https://fermatmind.com/zh/tests',
            'https://fermatmind.com/zh/tests/category/career',
            'https://fermatmind.com/zh/tests/category/personality',
        ] as $loc) {
            $this->assertStringContainsString($loc, $xml);
        }

        $this->assertStringNotContainsString('https://fermatmind.com/en/help/about', $xml);
    }

    public function test_generate_includes_released_zh_big_five_public_content_assets_only(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $traitSlugs = [
            'openness',
            'conscientiousness',
            'extraversion',
            'agreeableness',
            'neuroticism',
        ];
        $rangeSlugs = [];
        foreach ($traitSlugs as $traitSlug) {
            foreach (['high', 'mid', 'low'] as $range) {
                $rangeSlugs[] = "{$traitSlug}-{$range}";
            }
        }

        foreach ($traitSlugs as $slug) {
            $this->createBigFivePublicContentAsset($slug, PersonalityPublicContentAsset::ENTITY_DOMAIN);
        }
        foreach ($rangeSlugs as $slug) {
            $this->createBigFivePublicContentAsset($slug, PersonalityPublicContentAsset::ENTITY_POLARITY);
        }

        $this->createBigFivePublicContentAsset('openness-en', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'entity_key' => 'openness',
            'slug' => 'big-five/openness-en',
            'locale' => 'en',
            'canonical_json' => ['path' => '/en/personality/big-five/openness'],
        ]);
        $this->createBigFivePublicContentAsset('hub', PersonalityPublicContentAsset::ENTITY_HUB, [
            'canonical_json' => ['path' => '/zh/personality/big-five'],
        ]);
        $this->createBigFivePublicContentAsset('hub-noindex', PersonalityPublicContentAsset::ENTITY_HUB, [
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five-noindex'],
        ]);
        $this->createBigFivePublicContentAsset('hub-draft', PersonalityPublicContentAsset::ENTITY_HUB, [
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five-draft'],
        ]);
        $this->createBigFivePublicContentAsset('openness-noindex', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness-noindex'],
        ]);
        $this->createBigFivePublicContentAsset('openness-review', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness-review'],
        ]);
        $this->createBigFivePublicContentAsset('openness-sitemap-off', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'sitemap_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness-sitemap-off'],
        ]);
        $this->createBigFivePublicContentAsset('openness-llms-off', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'llms_eligible' => false,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness-llms-off'],
        ]);
        $this->createBigFivePublicContentAsset('openness-short-path', PersonalityPublicContentAsset::ENTITY_DOMAIN, [
            'canonical_json' => ['path' => '/zh/big-five/openness'],
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');
        $slugList = (array) ($payload['slug_list'] ?? []);

        foreach (array_merge($traitSlugs, $rangeSlugs) as $slug) {
            $this->assertStringContainsString("https://fermatmind.com/zh/personality/big-five/{$slug}", $xml);
            $this->assertContains("personality-public-content:big-five:zh:{$slug}", $slugList);
        }

        // Hub should be included when published and eligible
        $this->assertStringContainsString('https://fermatmind.com/zh/personality/big-five', $xml);
        $this->assertContains('personality-public-content:big-five:zh:hub', $slugList);

        $this->assertStringNotContainsString('https://fermatmind.com/en/personality/big-five/openness', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five/openness-noindex', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five/openness-review', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five/openness-sitemap-off', $xml);
        $this->assertStringContainsString('https://fermatmind.com/zh/personality/big-five/openness-llms-off', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/big-five/openness', $xml);

        // Hub should NOT be included when noindex or draft
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five-noindex', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/personality/big-five-draft', $xml);
    }

    public function test_generate_includes_released_enneagram_assets_in_both_supported_locales(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        $paths = [
            'enneagram' => '/personality/enneagram',
            'gut' => '/personality/enneagram/centers/gut',
            'heart' => '/personality/enneagram/centers/heart',
            'head' => '/personality/enneagram/centers/head',
            ...array_combine(array_map(static fn (int $type): string => 'type-'.$type, range(1, 9)), array_map(static fn (int $type): string => '/personality/enneagram/type-'.$type, range(1, 9))),
            ...array_combine(
                ['1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6', '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1'],
                array_map(
                    static fn (string $wing): string => '/personality/enneagram/wings/'.$wing,
                    ['1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6', '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1']
                )
            ),
        ];

        foreach (range(1, 9) as $type) {
            foreach (['self-preservation', 'social', 'one-to-one'] as $subtype) {
                $paths['type-'.$type.'/'.$subtype] = '/personality/enneagram/type-'.$type.'/instincts/'.$subtype;
            }
        }

        foreach (['zh-CN' => 'zh', 'en' => 'en'] as $locale => $segment) {
            foreach ($paths as $entityKey => $path) {
                $this->createEnneagramPublicContentAsset($entityKey, $locale, '/'.$segment.$path);
            }
        }
        $this->createEnneagramPublicContentAsset('type-9-noindex', 'en', '/en/personality/enneagram/type-9-noindex', [
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'index_eligible' => false,
            'sitemap_eligible' => false,
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');
        $slugList = (array) ($payload['slug_list'] ?? []);

        foreach ($paths as $entityKey => $path) {
            $this->assertStringContainsString('https://fermatmind.com/en'.$path, $xml);
            $this->assertStringContainsString('https://fermatmind.com/zh'.$path, $xml);
            $this->assertContains('personality-public-content:enneagram:en:'.$entityKey, $slugList);
            $this->assertContains('personality-public-content:enneagram:zh:'.$entityKey, $slugList);
        }

        $this->assertStringNotContainsString('https://fermatmind.com/en/personality/enneagram/type-9-noindex', $xml);
    }

    public function test_generate_excludes_science_content_pages_until_public_readiness_gate_passes(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);

        ContentPage::withoutEvents(fn (): ContentPage => ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'item-design-notes',
            'path' => '/item-design-notes',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'methodology',
            'locale' => 'en',
            'title' => 'Item design notes',
            'content_md' => '## Item design notes',
            'content_html' => null,
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'publish_allowed' => false,
            'operator_approval_required' => true,
            'operator_approved_at' => null,
            'claim_gate_status' => 'not_reviewed',
            'forbidden_claims' => [],
            'published_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
        ]));

        ContentPage::query()->create([
            'org_id' => 0,
            'slug' => 'method-boundaries',
            'path' => '/method-boundaries',
            'kind' => ContentPage::KIND_POLICY,
            'page_type' => 'boundary',
            'locale' => 'en',
            'title' => 'Method boundaries',
            'content_md' => '## Method boundaries',
            'content_html' => null,
            'seo_title' => 'Method boundaries',
            'meta_description' => 'Method boundaries page.',
            'status' => ContentPage::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'review_state' => 'approved',
            'publish_allowed' => true,
            'operator_approval_required' => true,
            'operator_approved_at' => Carbon::create(2026, 6, 9, 8, 0, 0, 'UTC'),
            'claim_gate_status' => 'passed',
            'forbidden_claims' => [],
            'published_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 6, 8, 9, 0, 0, 'UTC'),
        ]);

        $xml = (string) ($this->generateSitemap()['xml'] ?? '');

        $this->assertStringContainsString('https://fermatmind.com/en/method-boundaries', $xml);
        $this->assertStringNotContainsString('https://fermatmind.com/en/item-design-notes', $xml);
    }

    public function test_generate_includes_only_indexable_global_article_urls_with_locale_aware_paths(): void
    {
        config([
            'app.url' => 'https://api.staging.fermatmind.com',
            'app.frontend_url' => 'https://staging.fermatmind.com',
        ]);

        $eligibleEn = $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'en',
            'title' => 'MBTI Basics',
            'excerpt' => 'Learn the core concepts behind MBTI.',
            'published_at' => Carbon::create(2026, 3, 6, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 6, 10, 0, 0, 'UTC'),
        ]);

        $eligibleZh = $this->createArticle([
            'slug' => 'mbti-basics',
            'locale' => 'zh-CN',
            'title' => 'MBTI 基础',
            'excerpt' => '了解 MBTI 的核心概念。',
            'published_at' => Carbon::create(2026, 3, 6, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 6, 12, 0, 0, 'UTC'),
        ]);

        $this->createArticle([
            'slug' => 'draft-article',
            'locale' => 'en',
            'status' => 'draft',
            'updated_at' => Carbon::create(2026, 3, 6, 12, 30, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'private-article',
            'locale' => 'en',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 6, 12, 45, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'noindex-article',
            'locale' => 'en',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 6, 13, 0, 0, 'UTC'),
        ]);
        $this->createArticle([
            'org_id' => 9,
            'slug' => 'tenant-article',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 6, 13, 15, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'future-article',
            'locale' => 'en',
            'published_at' => Carbon::now('UTC')->addDay(),
            'updated_at' => Carbon::create(2026, 3, 6, 13, 30, 0, 'UTC'),
        ]);
        $this->createArticle([
            'slug' => 'fr-article',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 6, 13, 45, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');

        $this->assertStringContainsString('https://staging.fermatmind.com/en/articles', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/articles', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/en/articles/mbti-basics', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/articles/mbti-basics', $xml);

        $this->assertStringNotContainsString('https://staging.fermatmind.com/articles/mbti-basics', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/articles/draft-article', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/articles/private-article', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/articles/noindex-article', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/articles/tenant-article', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/articles/future-article', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/articles/fr-article', $xml);

        $seoService = app(ArticleSeoService::class);

        $this->assertSame(
            $seoService->buildCanonicalUrl((string) $eligibleEn->slug, (string) $eligibleEn->locale),
            data_get($seoService->buildSeoPayload($eligibleEn), 'canonical')
        );
        $this->assertSame(
            $seoService->buildCanonicalUrl((string) $eligibleZh->slug, (string) $eligibleZh->locale),
            data_get($seoService->buildSeoPayload($eligibleZh), 'canonical')
        );
        $this->assertStringContainsString(data_get($seoService->buildSeoPayload($eligibleEn), 'canonical'), $xml);
        $this->assertStringContainsString(data_get($seoService->buildSeoPayload($eligibleZh), 'canonical'), $xml);
    }

    public function test_generate_includes_only_indexable_global_personality_urls_with_locale_aware_paths(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $eligibleEn = $this->createPersonalityProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 10, 0, 0, 'UTC'),
        ]);

        $eligibleZh = $this->createPersonalityProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 12, 0, 0, 'UTC'),
        ]);
        $this->createPersonalitySeoMeta($eligibleEn, [
            'canonical_url' => 'https://staging.fermatmind.com/en/personality/intj-a',
            'jsonld_overrides_json' => ['mainEntityOfPage' => 'https://staging.fermatmind.com/en/personality/intj-a'],
        ]);
        $this->createPersonalitySeoMeta($eligibleZh, [
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/intj-a',
            'jsonld_overrides_json' => ['mainEntityOfPage' => 'https://staging.fermatmind.com/zh/personality/intj-a'],
        ]);
        $eligibleEnVariant = $this->createPersonalityVariant($eligibleEn, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => Carbon::create(2026, 3, 7, 9, 15, 0, 'UTC'),
        ]);
        $this->createPersonalityVariantSeoMeta($eligibleEnVariant, [
            'canonical_url' => 'https://staging.fermatmind.com/en/personality/intj-a',
        ]);
        $eligibleZhVariant = $this->createPersonalityVariant($eligibleZh, [
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => Carbon::create(2026, 3, 7, 11, 15, 0, 'UTC'),
        ]);
        $this->createPersonalityVariantSeoMeta($eligibleZhVariant, [
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/intj-t',
        ]);

        $this->createPersonalityProfile([
            'type_code' => 'ENTJ',
            'slug' => 'entj',
            'locale' => 'en',
            'status' => 'draft',
            'updated_at' => Carbon::create(2026, 3, 7, 12, 30, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'locale' => 'en',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 12, 45, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'INFJ',
            'slug' => 'infj',
            'locale' => 'en',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 13, 0, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'org_id' => 9,
            'type_code' => 'INFP',
            'slug' => 'infp',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 15, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'scale_code' => 'DISC',
            'type_code' => 'DISC-I',
            'slug' => 'disc-i',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 30, 0, 'UTC'),
        ]);
        $this->createPersonalityProfile([
            'type_code' => 'ISFJ',
            'slug' => 'isfj',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 45, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');
        $this->assertSame(2, DB::table('personality_profiles')
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('status', 'published')
            ->where('is_public', 1)
            ->where('is_indexable', 1)
            ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES)
            ->where(function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->count());

        $this->assertStringContainsString('https://staging.fermatmind.com/en/personality', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/personality', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/en/personality/intj-a', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/personality/intj-t', $xml);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/en/personality/intj</loc>', $xml);
        $this->assertStringNotContainsString('<loc>https://staging.fermatmind.com/zh/personality/intj</loc>', $xml);

        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/entj', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/entp', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/infj', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/infp', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/personality/disc-i', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/personality/isfj', $xml);

        $seoService = app(PersonalityProfileSeoService::class);

        $this->assertSame(
            data_get($seoService->buildMeta($eligibleEn, $eligibleEnVariant), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleEn, $eligibleEnVariant), 'mainEntityOfPage')
        );
        $this->assertSame(
            data_get($seoService->buildMeta($eligibleZh, $eligibleZhVariant), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleZh, $eligibleZhVariant), 'mainEntityOfPage')
        );
        $this->assertSame('AboutPage', data_get($seoService->buildJsonLd($eligibleEn, $eligibleEnVariant), '@type'));
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleEn, $eligibleEnVariant), 'canonical'), $xml);
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleZh, $eligibleZhVariant), 'canonical'), $xml);
    }

    public function test_generate_excludes_held_personality_variants_and_at_comparisons(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $profile = $this->createPersonalityProfile([
            'type_code' => 'ISTJ',
            'slug' => 'istj',
            'locale' => 'zh-CN',
            'is_indexable' => true,
        ]);
        $assertive = $this->createPersonalityVariant($profile, [
            'canonical_type_code' => 'ISTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'ISTJ-A',
        ]);
        $turbulent = $this->createPersonalityVariant($profile, [
            'canonical_type_code' => 'ISTJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'ISTJ-T',
        ]);
        $this->createPersonalityVariantSeoMeta($assertive, [
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/istj-a',
            'robots' => 'noindex,follow',
        ]);
        $this->createPersonalityVariantSeoMeta($turbulent, [
            'canonical_url' => 'https://staging.fermatmind.com/zh/personality/istj-t',
            'robots' => 'index,follow',
        ]);

        PersonalityProfileSection::query()->create([
            'org_id' => 0,
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'ISTJ-A vs ISTJ-T',
            'render_variant' => 'rich_text',
            'payload_json' => ['indexability_held' => true],
            'sort_order' => 920,
            'is_enabled' => true,
        ]);

        $seoService = app(PersonalityProfileSeoService::class);
        $this->assertSame('noindex,follow', data_get($seoService->buildMeta($profile, $assertive), 'robots'));
        $this->assertSame('index,follow', data_get($seoService->buildMeta($profile, $turbulent), 'robots'));

        $xml = (string) ($this->generateSitemap()['xml'] ?? '');

        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh/personality/istj-a</loc>', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/personality/istj-t</loc>', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh/personality/istj-a-vs-istj-t</loc>', $xml);
    }

    public function test_generate_includes_only_indexable_global_topic_urls_with_locale_aware_paths(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $eligibleEn = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 10, 0, 0, 'UTC'),
        ]);

        $eligibleZh = $this->createTopicProfile([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'zh-CN',
            'title' => 'MBTI 主题',
            'subtitle' => '理解人格偏好与类型框架。',
            'excerpt' => '探索 MBTI 概念、类型档案与测试入口。',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 7, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 12, 0, 0, 'UTC'),
        ]);

        $this->createTopicProfile([
            'topic_code' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'en',
            'status' => 'draft',
            'updated_at' => Carbon::create(2026, 3, 7, 12, 30, 0, 'UTC'),
        ]);
        $this->createTopicProfile([
            'topic_code' => 'enneagram',
            'slug' => 'enneagram',
            'locale' => 'en',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 12, 45, 0, 'UTC'),
        ]);
        $this->createTopicProfile([
            'topic_code' => 'self-awareness',
            'slug' => 'self-awareness',
            'locale' => 'en',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 7, 13, 0, 0, 'UTC'),
        ]);
        $this->createTopicProfile([
            'org_id' => 9,
            'topic_code' => 'eq',
            'slug' => 'eq',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 15, 0, 'UTC'),
        ]);
        $this->createTopicProfile([
            'topic_code' => 'disc',
            'slug' => 'disc',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 7, 13, 30, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');

        $this->assertStringContainsString('https://staging.fermatmind.com/en/topics', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/topics', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/en/topics/mbti', $xml);
        $this->assertStringContainsString('https://staging.fermatmind.com/zh/topics/mbti', $xml);

        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/topics/big-five', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/topics/enneagram', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/topics/self-awareness', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/topics/eq', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/topics/disc', $xml);

        $seoService = app(TopicProfileSeoService::class);

        $this->assertSame(
            data_get($seoService->buildMeta($eligibleEn, 'en'), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleEn, 'en'), 'mainEntityOfPage')
        );
        $this->assertSame(
            data_get($seoService->buildMeta($eligibleZh, 'zh-CN'), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleZh, 'zh-CN'), 'mainEntityOfPage')
        );
        $this->assertSame('CollectionPage', data_get($seoService->buildJsonLd($eligibleEn, 'en'), '@type'));
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleEn, 'en'), 'canonical'), $xml);
        $this->assertStringContainsString(data_get($seoService->buildMeta($eligibleZh, 'zh-CN'), 'canonical'), $xml);
    }

    public function test_generate_includes_career_job_list_urls_without_cms_only_detail_urls(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $frontendUnavailableEn = $this->createCareerJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'excerpt' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 8, 9, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 8, 10, 0, 0, 'UTC'),
        ]);
        $this->createCareerJobSeoMeta($frontendUnavailableEn, [
            'robots' => 'index,follow',
        ]);

        $eligibleZh = $this->createCareerJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'zh-CN',
            'title' => '产品经理',
            'excerpt' => '了解产品经理的职责、薪资水平、发展路径和人格匹配。',
            'market_demand_json' => [
                'source_refs' => [
                    ['url' => 'https://www.bls.gov/ooh/management/product-manager.htm'],
                ],
            ],
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 8, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 8, 12, 0, 0, 'UTC'),
        ]);
        $this->createCareerJobSeoMeta($eligibleZh, [
            'robots' => 'index,follow',
            'jsonld_overrides_json' => [
                'source_docx' => '01_产品经理_product-manager.docx',
            ],
        ]);

        $robotsNoindexZh = $this->createCareerJob([
            'job_code' => 'robots-noindex',
            'slug' => 'robots-noindex',
            'locale' => 'zh-CN',
            'title' => '禁索引岗位',
            'market_demand_json' => [
                'source_refs' => [
                    ['url' => 'https://www.bls.gov/ooh/management/robots-noindex.htm'],
                ],
            ],
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 8, 12, 5, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 8, 12, 10, 0, 'UTC'),
        ]);
        $this->createCareerJobSeoMeta($robotsNoindexZh, [
            'robots' => 'noindex,follow',
            'jsonld_overrides_json' => [
                'source_docx' => '02_禁索引岗位_robots-noindex.docx',
            ],
        ]);

        $this->createCareerJob([
            'job_code' => 'draft-role',
            'slug' => 'draft-role',
            'locale' => 'en',
            'status' => CareerJob::STATUS_DRAFT,
            'updated_at' => Carbon::create(2026, 3, 8, 12, 30, 0, 'UTC'),
        ]);
        $this->createCareerJob([
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'locale' => 'en',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 8, 12, 45, 0, 'UTC'),
        ]);
        $this->createCareerJob([
            'job_code' => 'noindex-role',
            'slug' => 'noindex-role',
            'locale' => 'en',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 8, 13, 0, 0, 'UTC'),
        ]);
        $this->createCareerJob([
            'org_id' => 9,
            'job_code' => 'tenant-role',
            'slug' => 'tenant-role',
            'locale' => 'en',
            'updated_at' => Carbon::create(2026, 3, 8, 13, 15, 0, 'UTC'),
        ]);
        $this->createCareerJob([
            'job_code' => 'future-role',
            'slug' => 'future-role',
            'locale' => 'en',
            'published_at' => Carbon::now('UTC')->addDay(),
            'updated_at' => Carbon::create(2026, 3, 8, 13, 30, 0, 'UTC'),
        ]);
        $this->createCareerJob([
            'job_code' => 'fr-role',
            'slug' => 'fr-role',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 8, 13, 45, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');

        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh/career/jobs', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/product-manager', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh/career/jobs/product-manager', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh/career/jobs/robots-noindex', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/draft-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/private-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/noindex-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/tenant-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/jobs/future-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/career/jobs/fr-role', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/zh-CN/career/jobs/product-manager', $xml);

        $seoService = app(CareerJobSeoService::class);

        $this->assertSame('noindex,follow', data_get($seoService->buildMeta($frontendUnavailableEn, 'en'), 'robots'));
        $this->assertStringNotContainsString(data_get($seoService->buildMeta($frontendUnavailableEn, 'en'), 'canonical'), $xml);
        $this->assertSame(
            data_get($seoService->buildMeta($eligibleZh, 'zh-CN'), 'canonical'),
            data_get($seoService->buildJsonLd($eligibleZh, 'zh-CN'), 'mainEntityOfPage')
        );
        $this->assertSame('Occupation', data_get($seoService->buildJsonLd($eligibleZh, 'zh-CN'), '@type'));
    }

    public function test_generate_includes_only_indexable_global_career_guide_urls_with_locale_aware_paths_and_lastmod(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $eligibleEn = $this->createCareerGuide([
            'guide_code' => 'career-planning-101',
            'slug' => 'career-planning-101',
            'locale' => 'en',
            'title' => 'Career Planning 101',
            'published_at' => Carbon::create(2026, 3, 9, 9, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 10, 0, 0, 'UTC'),
        ]);

        $latestEn = $this->createCareerGuide([
            'guide_code' => 'job-search-playbook',
            'slug' => 'job-search-playbook',
            'locale' => 'en',
            'title' => 'Job Search Playbook',
            'published_at' => Carbon::create(2026, 3, 9, 12, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 3, 9, 11, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 14, 0, 0, 'UTC'),
        ]);

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
            'published_at' => Carbon::create(2026, 3, 9, 16, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 3, 9, 15, 0, 0, 'UTC'),
            'updated_at' => null,
        ]);

        $eligibleZh = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('guide_code', 'job-fit-guide')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->createCareerGuide([
            'guide_code' => 'draft-guide',
            'slug' => 'draft-guide',
            'status' => CareerGuide::STATUS_DRAFT,
            'updated_at' => Carbon::create(2026, 3, 9, 17, 0, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'private-guide',
            'slug' => 'private-guide',
            'is_public' => false,
            'updated_at' => Carbon::create(2026, 3, 9, 17, 15, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'noindex-guide',
            'slug' => 'noindex-guide',
            'is_indexable' => false,
            'updated_at' => Carbon::create(2026, 3, 9, 17, 30, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'org_id' => 9,
            'guide_code' => 'tenant-guide',
            'slug' => 'tenant-guide',
            'updated_at' => Carbon::create(2026, 3, 9, 17, 45, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'future-guide',
            'slug' => 'future-guide',
            'published_at' => Carbon::now('UTC')->addDay(),
            'updated_at' => Carbon::create(2026, 3, 9, 18, 0, 0, 'UTC'),
        ]);
        $this->createCareerGuide([
            'guide_code' => 'fr-guide',
            'slug' => 'fr-guide',
            'locale' => 'fr',
            'updated_at' => Carbon::create(2026, 3, 9, 18, 15, 0, 'UTC'),
        ]);

        $payload = $this->generateSitemap();
        $xml = (string) ($payload['xml'] ?? '');
        $entries = $this->sitemapEntries($xml);

        $enListUrl = 'https://staging.fermatmind.com/en/career/guides';
        $zhListUrl = 'https://staging.fermatmind.com/zh/career/guides';
        $seoService = app(CareerGuideSeoService::class);
        $enDetailUrl = $seoService->buildCanonicalUrl($eligibleEn);
        $zhDetailUrl = $seoService->buildCanonicalUrl($eligibleZh);

        $this->assertArrayHasKey($enListUrl, $entries);
        $this->assertArrayHasKey($zhListUrl, $entries);
        $this->assertNotNull($enDetailUrl);
        $this->assertNotNull($zhDetailUrl);
        $this->assertArrayHasKey($enDetailUrl, $entries);
        $this->assertArrayHasKey($zhDetailUrl, $entries);

        $this->assertStringNotContainsString('https://staging.fermatmind.com/career/guides', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/career/guides/career-planning-101', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/guides/draft-guide', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/guides/private-guide', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/guides/noindex-guide', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/guides/tenant-guide', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/en/career/guides/future-guide', $xml);
        $this->assertStringNotContainsString('https://staging.fermatmind.com/fr/career/guides/fr-guide', $xml);

        $this->assertSame($latestEn->updated_at?->toAtomString(), $entries[$enListUrl]);
        $this->assertSame($eligibleZh->published_at?->toAtomString(), $entries[$zhListUrl]);
        $this->assertSame($eligibleEn->updated_at?->toAtomString(), $entries[$enDetailUrl]);
        $this->assertSame($eligibleZh->published_at?->toAtomString(), $entries[$zhDetailUrl]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPersonalityProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
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
            'published_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPersonalitySeoMeta(PersonalityProfile $profile, array $overrides = []): PersonalityProfileSeoMeta
    {
        /** @var PersonalityProfileSeoMeta */
        return PersonalityProfileSeoMeta::query()->create(array_merge([
            'profile_id' => (int) $profile->id,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_url' => null,
            'robots' => null,
            'jsonld_overrides_json' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPersonalityVariant(PersonalityProfile $profile, array $overrides = []): PersonalityProfileVariant
    {
        /** @var PersonalityProfileVariant */
        return PersonalityProfileVariant::query()->create(array_merge([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $profile->type_code,
            'variant_code' => 'A',
            'runtime_type_code' => ((string) $profile->type_code).'-A',
            'type_name' => 'Variant type',
            'nickname' => 'Variant nickname',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['variant'],
            'hero_summary_md' => 'Variant summary',
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => Carbon::create(2026, 3, 7, 8, 15, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPersonalityVariantSeoMeta(PersonalityProfileVariant $variant, array $overrides = []): PersonalityProfileVariantSeoMeta
    {
        /** @var PersonalityProfileVariantSeoMeta */
        return PersonalityProfileVariantSeoMeta::query()->create(array_merge([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_url' => null,
            'robots' => null,
            'jsonld_overrides_json' => null,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBigFivePublicContentAsset(
        string $entityKey,
        string $entityType,
        array $overrides = [],
    ): PersonalityPublicContentAsset {
        $canonicalPath = "/zh/personality/big-five/{$entityKey}";

        /** @var PersonalityPublicContentAsset */
        return PersonalityPublicContentAsset::query()->create(array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => 'big-five/'.$entityKey,
            'locale' => 'zh-CN',
            'title' => 'Big Five '.$entityKey,
            'summary' => 'Big Five public content asset.',
            'content_sections_json' => [
                ['id' => 'overview', 'heading' => 'Overview', 'body' => 'Visible reviewed body.'],
            ],
            'seo_json' => [
                'title' => 'Big Five '.$entityKey,
                'description' => 'Big Five SEO description.',
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => $canonicalPath],
            'hreflang_json' => [],
            'faq_json' => [
                ['question' => 'What is this page?', 'answer' => 'A reviewed Big Five content asset.'],
            ],
            'media_json' => [],
            'schema_json' => ['runtime_jsonld_enabled' => true],
            'method_boundary_json' => ['non_diagnostic' => true],
            'evidence_notes_json' => ['status' => 'reviewed'],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'seo_discoverability_released',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-package',
            'source_hash' => str_repeat('a', 64),
            'published_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'last_reviewed_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 7, 2, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEnneagramPublicContentAsset(string $entityKey, string $locale, string $canonicalPath, array $overrides = []): PersonalityPublicContentAsset
    {
        $entityType = match (true) {
            $entityKey === 'enneagram' => PersonalityPublicContentAsset::ENTITY_HUB,
            in_array($entityKey, ['gut', 'heart', 'head'], true) => PersonalityPublicContentAsset::ENTITY_CENTER,
            preg_match('/^[1-9]w[1-9]$/', $entityKey) === 1 => PersonalityPublicContentAsset::ENTITY_WING,
            preg_match('#^type-[1-9]/(?:self-preservation|social|one-to-one)$#', $entityKey) === 1 => PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE,
            default => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
        };

        return PersonalityPublicContentAsset::query()->create(array_merge([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => $entityType,
            'entity_key' => $entityKey,
            'slug' => 'enneagram/'.$entityKey,
            'locale' => $locale,
            'title' => 'Enneagram '.$entityKey,
            'summary' => 'Enneagram public content asset.',
            'content_sections_json' => [['id' => 'overview', 'heading' => 'Overview', 'body' => 'Visible reviewed body.']],
            'seo_json' => ['title' => 'Enneagram '.$entityKey, 'description' => 'Enneagram SEO description.'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => $canonicalPath],
            'hreflang_json' => [],
            'faq_json' => [['question' => 'What is this page?', 'answer' => 'A reviewed Enneagram content asset.']],
            'media_json' => [],
            'schema_json' => ['runtime_jsonld_enabled' => true],
            'method_boundary_json' => ['non_diagnostic' => true],
            'evidence_notes_json' => ['status' => 'reviewed'],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'seo_discoverability_released',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'test-package',
            'source_hash' => str_repeat('b', 64),
            'published_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'last_reviewed_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'created_at' => Carbon::create(2026, 7, 1, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 7, 2, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        /** @var Article */
        $article = Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'article-slug',
            'locale' => 'en',
            'title' => 'Article Title',
            'excerpt' => 'Article excerpt.',
            'content_md' => '# Article body',
            'content_html' => null,
            'cover_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'published_at' => Carbon::create(2026, 3, 6, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 6, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 6, 8, 0, 0, 'UTC'),
        ], $overrides));

        /** @var ArticleTranslationRevision */
        $revision = ArticleTranslationRevision::query()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => (int) ($article->source_article_id ?: $article->id),
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => (string) $article->locale,
            'source_locale' => (string) ($article->source_locale ?: $article->locale),
            'revision_number' => 1,
            'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'source_version_hash' => $article->source_version_hash,
            'translated_from_version_hash' => $article->translated_from_version_hash ?: $article->source_version_hash,
            'supersedes_revision_id' => null,
            'title' => (string) $article->title,
            'excerpt' => $article->excerpt,
            'content_md' => (string) $article->content_md,
            'seo_title' => null,
            'seo_description' => null,
            'published_at' => $article->published_at,
        ]);

        $article->forceFill(['published_revision_id' => $revision->id])->save();

        return $article->fresh(['publishedRevision']) ?? $article;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTopicProfile(array $overrides = []): TopicProfile
    {
        /** @var TopicProfile */
        return TopicProfile::query()->create(array_merge([
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
            'published_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 7, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCareerJob(array $overrides = []): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'subtitle' => null,
            'excerpt' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 8, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCareerJobSeoMeta(CareerJob $job, array $overrides = []): CareerJobSeoMeta
    {
        /** @var CareerJobSeoMeta */
        return CareerJobSeoMeta::query()->create(array_merge([
            'job_id' => (int) $job->id,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_url' => null,
            'robots' => null,
            'jsonld_overrides_json' => null,
        ], $overrides));
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
            'published_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 9, 8, 0, 0, 'UTC'),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function generateSitemap(): array
    {
        app(PublicCareerAuthorityResponseCache::class)->warm();

        return app(SitemapGenerator::class)->generate();
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
