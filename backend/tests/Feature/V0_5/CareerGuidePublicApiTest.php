<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CareerGuidePublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_validates_locale_and_filters_visibility_locale_category_and_org_scope(): void
    {
        $primary = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'title' => 'From MBTI to Job Fit',
            'category_slug' => 'assessment-usage',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 5, 9, 0, 0, 'UTC'),
            'sort_order' => 10,
        ]);
        $secondary = $this->createGuide([
            'guide_code' => 'annual-career-review-system',
            'slug' => 'annual-career-review-system',
            'title' => 'Annual Career Review System',
            'category_slug' => 'career-planning',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 6, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 6, 9, 0, 0, 'UTC'),
            'sort_order' => 20,
        ]);

        $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'zh-CN',
            'title' => '从 MBTI 到职业匹配',
            'category_slug' => 'assessment-usage',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 10, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'org_id' => 7,
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'title' => 'Tenant Guide',
            'category_slug' => 'assessment-usage',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 11, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'guide_code' => 'draft-guide',
            'slug' => 'draft-guide',
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createGuide([
            'guide_code' => 'private-guide',
            'slug' => 'private-guide',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 12, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'guide_code' => 'future-guide',
            'slug' => 'future-guide',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v0.5/career-guides')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'INVALID_ARGUMENT');

        $this->getJson('/api/v0.5/career-guides?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.slug', (string) $primary->slug)
            ->assertJsonPath('items.0.is_indexable', false)
            ->assertJsonPath('items.1.slug', (string) $secondary->slug)
            ->assertJsonMissingPath('items.0.body_md')
            ->assertJsonMissingPath('items.0.seo_meta');

        $this->getJson('/api/v0.5/career-guides?locale=en&category=assessment-usage')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.slug', (string) $primary->slug)
            ->assertJsonPath('items.0.category_slug', 'assessment-usage');

        $this->getJson('/api/v0.5/career-guides?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.locale', 'zh-CN')
            ->assertJsonPath('items.0.title', '从 MBTI 到职业匹配');

        $this->getJson('/api/v0.5/career-guides?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('items.0.org_id', 7)
            ->assertJsonPath('items.0.title', 'Tenant Guide');
    }

    public function test_detail_returns_guide_related_summaries_industry_slugs_and_resolved_seo_meta(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $guide = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'How to translate MBTI insights into career decisions.',
            'category_slug' => 'assessment-usage',
            'body_md' => '# Guide body',
            'body_html' => '<h1>Guide body</h1>',
            'related_industry_slugs_json' => ['consulting', 'manufacturing'],
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        $this->createSeoMeta($guide, [
            'seo_title' => 'From MBTI to Job Fit | FermatMind',
            'seo_description' => 'Translate personality insights into practical career decisions.',
            'canonical_url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            'og_title' => 'MBTI to Job Fit',
            'og_description' => 'Practical MBTI career guide.',
            'robots' => 'index,follow',
            'jsonld_overrides_json' => ['@id' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit#webpage'],
        ]);

        $job = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Product Manager',
            'excerpt' => 'Translate ambiguity into roadmap decisions.',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 30, 0, 'UTC'),
        ]);
        $hiddenJob = $this->createJob([
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'title' => 'Private Role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 8, 45, 0, 'UTC'),
        ]);

        $article = $this->createArticle([
            'slug' => 'how-to-read-mbti-results',
            'title' => 'How to Read MBTI Results',
            'excerpt' => 'A practical guide to understanding type results.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 9, 0, 0, 'UTC'),
        ]);
        $hiddenArticle = $this->createArticle([
            'slug' => 'hidden-article',
            'title' => 'Hidden Article',
            'status' => 'published',
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 9, 15, 0, 'UTC'),
        ]);

        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'title' => 'INTJ Personality Type',
            'excerpt' => 'Strategic and future-oriented.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 9, 30, 0, 'UTC'),
        ]);
        $hiddenProfile = $this->createProfile([
            'type_code' => 'ENFP',
            'slug' => 'enfp',
            'title' => 'ENFP Personality Type',
            'status' => 'published',
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 9, 45, 0, 'UTC'),
        ]);

        $guide->relatedJobs()->attach($job->id, ['sort_order' => 10]);
        $guide->relatedJobs()->attach($hiddenJob->id, ['sort_order' => 20]);
        $guide->relatedArticles()->attach($article->id, ['sort_order' => 10]);
        $guide->relatedArticles()->attach($hiddenArticle->id, ['sort_order' => 20]);
        $guide->relatedPersonalityProfiles()->attach($profile->id, ['sort_order' => 10]);
        $guide->relatedPersonalityProfiles()->attach($hiddenProfile->id, ['sort_order' => 20]);

        $response = $this->getJson('/api/v0.5/career-guides/from-mbti-to-job-fit?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guide.guide_code', 'from-mbti-to-job-fit')
            ->assertJsonPath('guide.category_slug', 'assessment-usage')
            ->assertJsonPath('guide.body_md', '# Guide body')
            ->assertJsonPath('related_jobs.0.job_code', 'product-manager')
            ->assertJsonPath('related_jobs.0.industry_slug', 'technology')
            ->assertJsonCount(2, 'related_industries')
            ->assertJsonPath('related_industries.0', 'consulting')
            ->assertJsonPath('related_articles.0.slug', 'how-to-read-mbti-results')
            ->assertJsonPath('related_personality_profiles.0.type_code', 'INTJ')
            ->assertJsonPath('seo_meta.seo_title', 'From MBTI to Job Fit | FermatMind')
            ->assertJsonPath(
                'seo_meta.canonical_url',
                'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit'
            )
            ->assertJsonPath('seo_meta.robots', 'index,follow')
            ->assertJsonMissingPath('guide.related_jobs')
            ->assertJsonMissingPath('revisions')
            ->assertJsonMissingPath('related_jobs.0.pivot');
    }

    public function test_detail_returns_not_found_for_missing_hidden_and_locale_mismatch_guides(): void
    {
        $draft = $this->createGuide([
            'guide_code' => 'draft-guide',
            'slug' => 'draft-guide',
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createGuide([
            'guide_code' => 'private-guide',
            'slug' => 'private-guide',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'guide_code' => 'zh-guide',
            'slug' => 'zh-guide',
            'locale' => 'zh-CN',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 15, 0, 'UTC'),
        ]);

        $this->getJson('/api/v0.5/career-guides/missing?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-guides/'.$draft->slug.'?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-guides/private-guide?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-guides/zh-guide?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_seo_endpoint_returns_localized_meta_real_alternates_and_webpage_jsonld(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $enGuide = $this->createGuide([
            'guide_code' => 'from-mbti-to-job-fit',
            'slug' => 'from-mbti-to-job-fit',
            'locale' => 'en',
            'title' => 'From MBTI to Job Fit',
            'excerpt' => 'How to translate MBTI insights into career decisions.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        $this->createSeoMeta($enGuide, [
            'seo_title' => 'From MBTI to Job Fit | FermatMind',
            'seo_description' => 'How to translate MBTI insights into career decisions.',
            'canonical_url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            'jsonld_overrides_json' => [
                '@id' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit#webpage',
                'url' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
                'mainEntityOfPage' => 'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            ],
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

        $response = $this->getJson('/api/v0.5/career-guides/from-mbti-to-job-fit/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath('meta.title', 'From MBTI to Job Fit | FermatMind')
            ->assertJsonPath(
                'meta.canonical',
                'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit'
            )
            ->assertJsonPath(
                'meta.alternates.en',
                'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit'
            )
            ->assertJsonPath(
                'meta.alternates.zh-CN',
                'https://staging.fermatmind.com/zh/career/guides/from-mbti-to-job-fit'
            )
            ->assertJsonPath('jsonld.@type', 'WebPage')
            ->assertJsonPath(
                'jsonld.@id',
                'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit#webpage'
            )
            ->assertJsonPath(
                'jsonld.mainEntityOfPage',
                'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit'
            );

        $this->assertStringNotContainsString(
            'https://api.staging.fermatmind.com/career-guides/from-mbti-to-job-fit',
            (string) $response->getContent()
        );
    }

    public function test_seo_endpoint_does_not_fake_missing_locale_alternates_and_returns_not_found_for_hidden_guides(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $this->createGuide([
            'guide_code' => 'solo-guide',
            'slug' => 'solo-guide',
            'locale' => 'en',
            'title' => 'Solo Guide',
            'excerpt' => 'Only one locale exists.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
        ]);
        $this->createGuide([
            'guide_code' => 'hidden-guide',
            'slug' => 'hidden-guide',
            'locale' => 'en',
            'title' => 'Hidden Guide',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => Carbon::create(2026, 3, 5, 8, 15, 0, 'UTC'),
        ]);

        $response = $this->getJson('/api/v0.5/career-guides/solo-guide/seo?locale=en');

        $response->assertOk()
            ->assertJsonPath(
                'meta.canonical',
                'https://staging.fermatmind.com/en/career/guides/solo-guide'
            )
            ->assertJsonPath(
                'meta.alternates.en',
                'https://staging.fermatmind.com/en/career/guides/solo-guide'
            )
            ->assertJsonMissingPath('meta.alternates.zh-CN');

        $this->getJson('/api/v0.5/career-guides/missing/seo?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');

        $this->getJson('/api/v0.5/career-guides/hidden-guide/seo?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');
    }

    public function test_imported_local_baseline_publishes_family_guides_with_personality_maps(): void
    {
        $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'status' => 'published',
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 18, 8, 0, 0, 'UTC'),
        ]);
        $this->createProfile([
            'type_code' => 'ESFP',
            'slug' => 'esfp',
            'locale' => 'zh-CN',
            'title' => 'ESFP 人格类型',
            'status' => 'published',
            'is_public' => true,
            'published_at' => Carbon::create(2026, 3, 18, 8, 5, 0, 'UTC'),
        ]);

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['intj-career-playbook'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--guide' => ['esfp-career-playbook'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/career-guides/intj-career-playbook?locale=en')
            ->assertOk()
            ->assertJsonPath('guide.guide_code', 'intj-career-playbook')
            ->assertJsonPath('guide.locale', 'en')
            ->assertJsonPath('related_personality_profiles.0.type_code', 'INTJ');

        $this->getJson('/api/v0.5/career-guides/esfp-career-playbook?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('guide.guide_code', 'esfp-career-playbook')
            ->assertJsonPath('guide.locale', 'zh-CN')
            ->assertJsonPath('related_personality_profiles.0.type_code', 'ESFP');
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSeoMeta(CareerGuide $guide, array $overrides = []): CareerGuideSeoMeta
    {
        /** @var CareerGuideSeoMeta */
        return CareerGuideSeoMeta::query()->create(array_merge([
            'career_guide_id' => (int) $guide->id,
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
    private function createJob(array $overrides = []): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'career-job',
            'slug' => 'career-job',
            'locale' => 'en',
            'title' => 'Career job',
            'excerpt' => 'Career job excerpt.',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticle(array $overrides = []): Article
    {
        /** @var Article */
        return Article::query()->create(array_merge([
            'org_id' => 0,
            'category_id' => null,
            'author_admin_user_id' => null,
            'slug' => 'career-article',
            'locale' => 'en',
            'title' => 'Career article',
            'excerpt' => 'Career article excerpt.',
            'content_md' => '# Career article',
            'content_html' => '<h1>Career article</h1>',
            'cover_image_url' => null,
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'created_at' => Carbon::create(2026, 3, 5, 8, 0, 0, 'UTC'),
            'updated_at' => Carbon::create(2026, 3, 5, 9, 0, 0, 'UTC'),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'excerpt' => 'Strategic and future-oriented.',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
        ], $overrides));
    }
}
