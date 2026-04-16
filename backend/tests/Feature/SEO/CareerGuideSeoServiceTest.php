<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Services\Cms\CareerGuideSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CareerGuideSeoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_seo_meta_persists_localized_frontend_canonical_and_robots_defaults(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $guide = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'zh-CN',
            'title' => '从 MBTI 到职业匹配',
            'excerpt' => '把人格洞察转成职业决策。',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);

        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'seo_title' => '旧标题',
            'seo_description' => '旧描述',
            'canonical_url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            'robots' => 'index,follow',
        ]);

        $seoMeta = app(CareerGuideSeoService::class)->generateSeoMeta((int) $guide->id);

        $this->assertSame(
            'https://staging.fermatmind.com/zh/career/guides/from-mbti-to-job-fit',
            $seoMeta->canonical_url
        );
        $this->assertSame('noindex,follow', $seoMeta->robots);
        $this->assertDatabaseHas('career_guide_seo_meta', [
            'career_guide_id' => (int) $guide->id,
            'canonical_url' => 'https://staging.fermatmind.com/zh/career/guides/from-mbti-to-job-fit',
            'robots' => 'noindex,follow',
        ]);
    }

    public function test_build_seo_payload_only_emits_real_sibling_locale_alternates(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $service = app(CareerGuideSeoService::class);

        $enGuide = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'en',
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'Translate personality insights into practical career decisions.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'zh-CN',
            'title' => '从 MBTI 到职业匹配',
            'excerpt' => '把人格洞察转成职业决策。',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 15, 0, 'UTC'),
        ]);

        $payload = $service->buildSeoPayload($enGuide);
        $this->assertSame(
            'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            data_get($payload, 'alternates.en')
        );
        $this->assertSame(
            'https://staging.fermatmind.com/zh/career/guides/from-mbti-to-job-fit',
            data_get($payload, 'alternates.zh-CN')
        );

        $soloGuide = $this->createGuide([
            'guide_code' => 'solo-guide',
            'slug' => 'solo-guide',
            'locale' => 'en',
            'title' => 'Solo Guide',
            'excerpt' => 'Only one locale exists.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 9, 0, 0, 'UTC'),
        ]);

        $soloPayload = $service->buildSeoPayload($soloGuide);
        $this->assertSame(
            'https://staging.fermatmind.com/en/career/guides/solo-guide',
            data_get($soloPayload, 'alternates.en')
        );
        $this->assertNull(data_get($soloPayload, 'alternates.zh-CN'));
    }

    public function test_build_jsonld_normalizes_legacy_override_urls(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $guide = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'en',
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'Translate personality insights into practical career decisions.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        CareerGuideSeoMeta::query()->create([
            'career_guide_id' => (int) $guide->id,
            'canonical_url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            'jsonld_overrides_json' => [
                '@id' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit#webpage',
                'url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
                'mainEntityOfPage' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            ],
        ]);

        $jsonLd = app(CareerGuideSeoService::class)->buildJsonLd($guide);

        $this->assertSame('Article', data_get($jsonLd, '@type'));
        $this->assertSame(
            'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit#webpage',
            data_get($jsonLd, '@id')
        );
        $this->assertSame(
            'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            data_get($jsonLd, 'url')
        );
        $this->assertSame(
            'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            data_get($jsonLd, 'mainEntityOfPage')
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGuide(array $overrides = []): CareerGuide
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
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 0,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'created_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 5, 9, 0, 0, 'UTC'),
        ], $overrides));
    }
}
