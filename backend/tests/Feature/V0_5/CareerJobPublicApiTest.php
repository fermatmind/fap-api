<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\CareerJob;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerJobPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_published_public_only_and_keeps_noindex_items_readable(): void
    {
        $visible = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Product Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'sort_order' => 10,
            'salary_json' => [
                'currency' => 'USD',
                'region' => 'US',
                'low' => 80000,
                'median' => 120000,
                'high' => 180000,
            ],
        ]);
        $this->createSeoMeta($visible, [
            'seo_title' => 'Product Manager Career Guide | FermatMind',
            'seo_description' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
        ]);

        $noindex = $this->createJob([
            'job_code' => 'ux-designer',
            'slug' => 'ux-designer',
            'title' => 'UX Designer',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
            'sort_order' => 20,
        ]);
        $this->createSeoMeta($noindex, [
            'seo_title' => 'UX Designer Career Guide | FermatMind',
            'robots' => 'noindex,follow',
        ]);

        $this->createJob([
            'job_code' => 'data-scientist',
            'slug' => 'data-scientist',
            'title' => 'Data Scientist Draft',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
            'sort_order' => 30,
        ]);
        $this->createJob([
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'title' => 'Private Role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinute(),
            'sort_order' => 40,
        ]);
        $this->createJob([
            'job_code' => 'scheduled-role',
            'slug' => 'scheduled-role',
            'title' => 'Scheduled Role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->addHour(),
            'sort_order' => 50,
        ]);

        $response = $this->getJson('/api/v0.5/career-jobs?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.slug', 'product-manager')
            ->assertJsonPath('items.0.salary.currency', 'USD')
            ->assertJsonPath('items.0.seo_meta.seo_title', 'Product Manager Career Guide | FermatMind')
            ->assertJsonPath('items.1.slug', 'ux-designer')
            ->assertJsonPath('items.1.is_indexable', false)
            ->assertJsonMissingPath('items.0.salary_json');
    }

    public function test_list_respects_locale_and_requested_org_scope(): void
    {
        $this->createJob([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager EN',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createJob([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'zh-CN',
            'title' => '产品经理',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createJob([
            'org_id' => 7,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager Org 7',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career-jobs?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.org_id', 0)
            ->assertJsonPath('items.0.title', 'Product Manager EN');

        $this->getJson('/api/v0.5/career-jobs?locale=zh-CN')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.locale', 'zh-CN')
            ->assertJsonPath('items.0.title', '产品经理');

        $this->getJson('/api/v0.5/career-jobs?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.org_id', 7)
            ->assertJsonPath('items.0.title', 'Product Manager Org 7');
    }

    public function test_detail_returns_job_sections_seo_meta_and_normalized_structured_fields(): void
    {
        $job = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Product Manager',
            'subtitle' => 'Lead product direction across user, business, and engineering goals.',
            'excerpt' => 'Understand the responsibilities, salary, growth path, and personality fit for Product Managers.',
            'hero_kicker' => 'Career profile',
            'hero_quote' => 'Translate uncertainty into direction.',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'body_md' => '# Product Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'salary_json' => [
                'currency' => 'USD',
                'region' => 'US',
                'low' => 80000,
                'median' => 120000,
                'high' => 180000,
                'notes' => 'Ranges vary by city and seniority.',
            ],
            'outlook_json' => [
                'summary' => 'Growing',
                'horizon_years' => 5,
                'notes' => 'Strong demand in AI-enabled product roles.',
            ],
            'skills_json' => [
                'core' => ['roadmapping', 'prioritization'],
                'supporting' => ['stakeholder management'],
            ],
            'work_contents_json' => [
                'items' => ['Define product strategy', 'Prioritize roadmap'],
            ],
            'growth_path_json' => [
                'entry' => 'Associate Product Manager',
                'mid' => 'Product Manager',
                'senior' => 'Senior PM / Group PM',
            ],
            'fit_personality_codes_json' => ['INTJ', 'ENTJ'],
            'mbti_primary_codes_json' => ['INTJ', 'ENTJ'],
            'mbti_secondary_codes_json' => ['INFJ', 'ENFP'],
            'riasec_profile_json' => [
                'R' => 10,
                'I' => 72,
                'A' => 40,
                'S' => 38,
                'E' => 67,
                'C' => 45,
            ],
            'big5_targets_json' => [
                'openness' => 'high',
                'conscientiousness' => 'high',
                'extraversion' => 'balanced',
                'agreeableness' => 'balanced',
                'neuroticism' => 'low',
            ],
            'iq_eq_notes_json' => [
                'iq' => 'Requires strong analytical reasoning.',
                'eq' => 'Requires stakeholder empathy and communication.',
            ],
            'market_demand_json' => [
                'signal' => 'high',
                'notes' => 'Demand remains strong across SaaS and AI startups.',
            ],
        ]);

        CareerJobSection::query()->create([
            'job_id' => (int) $job->id,
            'section_key' => 'day_to_day',
            'title' => 'A typical day',
            'render_variant' => 'rich_text',
            'body_md' => 'A typical day section body.',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        CareerJobSection::query()->create([
            'job_id' => (int) $job->id,
            'section_key' => 'faq',
            'title' => 'FAQ',
            'render_variant' => 'faq',
            'payload_json' => ['items' => [['q' => 'What is PM?', 'a' => 'A product role']]],
            'sort_order' => 20,
            'is_enabled' => false,
        ]);
        $this->createSeoMeta($job, [
            'seo_title' => 'Product Manager Career Guide | FermatMind',
            'seo_description' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
            'canonical_url' => 'https://staging.fermatmind.com/en/career/jobs/product-manager',
            'og_title' => 'Product Manager Career Guide',
            'robots' => 'index,follow',
        ]);

        $response = $this->getJson('/api/v0.5/career-jobs/product-manager?locale=en');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.job_code', 'product-manager')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'career_job_public_detail')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'career_job_detail')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.answer_scope', 'public_indexable_detail')
            ->assertJsonPath('answer_surface_v1.surface_type', 'career_job_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'job_summary')
            ->assertJsonPath('job.industry_label', 'Technology')
            ->assertJsonPath('job.salary.currency', 'USD')
            ->assertJsonPath('job.outlook.summary', 'Growing')
            ->assertJsonPath('job.skills.core.0', 'roadmapping')
            ->assertJsonPath('job.work_contents.items.0', 'Define product strategy')
            ->assertJsonPath('job.riasec_profile.I', 72)
            ->assertJsonPath('job.fit_personality_codes.0', 'INTJ')
            ->assertJsonCount(1, 'sections')
            ->assertJsonPath('sections.0.section_key', 'day_to_day')
            ->assertJsonPath('seo_meta.seo_title', 'Product Manager Career Guide | FermatMind')
            ->assertJsonMissingPath('job.salary_json')
            ->assertJsonMissingPath('revisions');
    }

    public function test_detail_returns_not_found_for_missing_hidden_or_locale_mismatch_jobs(): void
    {
        $draft = $this->createJob([
            'job_code' => 'draft-role',
            'slug' => 'draft-role',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createJob([
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);
        $this->createJob([
            'job_code' => 'zh-role',
            'slug' => 'zh-role',
            'locale' => 'zh-CN',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career-jobs/missing?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-jobs/'.$draft->slug.'?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-jobs/private-role?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-jobs/zh-role?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_detail_and_seo_null_blocked_media_urls(): void
    {
        $job = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Product Manager',
            'cover_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/job.png',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($job, [
            'og_image_url' => 'https://fermatmind-1316873116.cos.ap-shanghai.myqcloud.com/job-og.png',
            'twitter_image_url' => 'https://ci.example.test/job.png?ci-process=thumb',
        ]);

        $this->getJson('/api/v0.5/career-jobs/product-manager?locale=en')
            ->assertOk()
            ->assertJsonPath('job.cover_image_url', null)
            ->assertJsonPath('seo_meta.og_image_url', null)
            ->assertJsonPath('seo_meta.twitter_image_url', null);

        $this->getJson('/api/v0.5/career-jobs/product-manager/seo?locale=en')
            ->assertOk()
            ->assertJsonPath('meta.og.image', null)
            ->assertJsonPath('meta.twitter.image', null);
    }

    public function test_imported_local_baseline_publishes_family_jobs_for_en_and_zh_cn(): void
    {
        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--job' => [
                'innovation-consultant',
                'event-experience-producer',
            ],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->getJson('/api/v0.5/career-jobs/innovation-consultant?locale=en')
            ->assertOk()
            ->assertJsonPath('job.job_code', 'innovation-consultant')
            ->assertJsonPath('job.locale', 'en')
            ->assertJsonPath('job.fit_personality_codes.0', 'ENTJ')
            ->assertJsonPath('job.mbti_primary_codes.0', 'ENTP')
            ->assertJsonPath('job.mbti_secondary_codes.0', 'INTJ');

        $this->getJson('/api/v0.5/career-jobs/event-experience-producer?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('job.job_code', 'event-experience-producer')
            ->assertJsonPath('job.locale', 'zh-CN')
            ->assertJsonPath('job.mbti_primary_codes.0', 'ESFP')
            ->assertJsonPath('job.mbti_secondary_codes.0', 'ENFJ');
    }

    public function test_detail_defaults_to_global_content_and_only_uses_requested_org_scope(): void
    {
        $this->createJob([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Global Product Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createJob([
            'org_id' => 7,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'title' => 'Tenant Product Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career-jobs/product-manager?locale=en')
            ->assertOk()
            ->assertJsonPath('job.org_id', 0)
            ->assertJsonPath('job.title', 'Global Product Manager');

        $this->getJson('/api/v0.5/career-jobs/product-manager?locale=en&org_id=7')
            ->assertOk()
            ->assertJsonPath('job.org_id', 7)
            ->assertJsonPath('job.title', 'Tenant Product Manager');
    }

    public function test_seo_endpoint_returns_locale_aware_meta_jsonld_and_robots_fallback(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $enJob = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'excerpt' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'skills_json' => [
                'core' => ['roadmapping', 'prioritization'],
            ],
        ]);
        $this->createSeoMeta($enJob, [
            'seo_title' => 'Product Manager Career Guide | FermatMind',
            'seo_description' => 'Responsibilities, salary, growth path, and personality fit for Product Managers.',
        ]);

        $zhJob = $this->createJob([
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'zh-CN',
            'title' => '产品经理',
            'excerpt' => '了解产品经理的职责、薪资水平、发展路径和人格匹配。',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subMinute(),
        ]);

        $enResponse = $this->getJson('/api/v0.5/career-jobs/product-manager/seo?locale=en');
        $enResponse->assertOk()
            ->assertJsonPath('meta.title', 'Product Manager Career Guide | FermatMind')
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/en/career/jobs/product-manager')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'career_job_public_detail')
            ->assertJsonPath('meta.alternates.en', 'https://staging.fermatmind.com/en/career/jobs/product-manager')
            ->assertJsonPath('meta.alternates.zh-CN', 'https://staging.fermatmind.com/zh/career/jobs/product-manager')
            ->assertJsonPath('meta.robots', 'index,follow')
            ->assertJsonPath('jsonld.@type', 'Occupation')
            ->assertJsonPath('jsonld.mainEntityOfPage', 'https://staging.fermatmind.com/en/career/jobs/product-manager')
            ->assertJsonPath('jsonld.skills.0', 'roadmapping');

        $zhResponse = $this->getJson('/api/v0.5/career-jobs/product-manager/seo?locale=zh-CN');
        $zhResponse->assertOk()
            ->assertJsonPath('meta.canonical', 'https://staging.fermatmind.com/zh/career/jobs/product-manager')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('meta.robots', 'noindex,follow')
            ->assertJsonPath('jsonld.name', (string) $zhJob->title)
            ->assertJsonPath('jsonld.mainEntityOfPage', 'https://staging.fermatmind.com/zh/career/jobs/product-manager');
    }

    public function test_seo_endpoint_returns_not_found_for_missing_or_hidden_jobs(): void
    {
        $this->createJob([
            'job_code' => 'draft-role',
            'slug' => 'draft-role',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $this->createJob([
            'job_code' => 'private-role',
            'slug' => 'private-role',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => false,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career-jobs/missing/seo?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');

        $this->getJson('/api/v0.5/career-jobs/draft-role/seo?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');

        $this->getJson('/api/v0.5/career-jobs/private-role/seo?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createJob(array $overrides = []): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Career job',
            'subtitle' => 'Structured role profile',
            'excerpt' => 'A structured career job profile.',
            'hero_kicker' => 'Career profile',
            'hero_quote' => 'Build direction with evidence.',
            'cover_image_url' => 'https://cdn.example.test/jobs/product-manager.png',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'body_md' => '# Career job',
            'body_html' => '<h1>Career job</h1>',
            'salary_json' => [
                'currency' => 'USD',
                'region' => 'US',
                'low' => 70000,
                'median' => 110000,
                'high' => 160000,
                'notes' => 'Varies by region and seniority.',
            ],
            'outlook_json' => [
                'summary' => 'Stable',
                'horizon_years' => 5,
                'notes' => 'Demand remains stable.',
            ],
            'skills_json' => [
                'core' => ['problem solving'],
                'supporting' => ['communication'],
            ],
            'work_contents_json' => [
                'items' => ['Define responsibilities'],
            ],
            'growth_path_json' => [
                'entry' => 'Entry',
                'mid' => 'Mid',
                'senior' => 'Senior',
            ],
            'fit_personality_codes_json' => ['INTJ'],
            'mbti_primary_codes_json' => ['INTJ'],
            'mbti_secondary_codes_json' => ['ENTJ'],
            'riasec_profile_json' => [
                'R' => 30,
                'I' => 70,
                'A' => 50,
                'S' => 40,
                'E' => 80,
                'C' => 60,
            ],
            'big5_targets_json' => [
                'openness' => 'high',
                'conscientiousness' => 'high',
                'extraversion' => 'balanced',
                'agreeableness' => 'balanced',
                'neuroticism' => 'low',
            ],
            'iq_eq_notes_json' => [
                'iq' => 'Strong analysis required.',
                'eq' => 'Strong communication required.',
            ],
            'market_demand_json' => [
                'signal' => 'high',
                'notes' => 'Demand remains strong.',
            ],
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
    private function createSeoMeta(CareerJob $job, array $overrides = []): CareerJobSeoMeta
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
}
