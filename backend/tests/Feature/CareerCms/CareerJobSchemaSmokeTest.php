<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\CareerJob;
use App\Models\CareerJobRevision;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerJobSchemaSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_relations_scopes_and_casts_work(): void
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Product Manager',
            'subtitle' => 'Own the why and the roadmap',
            'excerpt' => 'A structured role profile for product work.',
            'hero_kicker' => 'Career path',
            'hero_quote' => 'Translate uncertainty into strategy.',
            'cover_image_url' => 'https://cdn.example.test/jobs/product-manager.png',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'body_md' => 'Primary narrative body',
            'body_html' => '<p>Primary narrative body</p>',
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
                'notes' => 'Demand remains strong in AI-enabled product roles.',
            ],
            'skills_json' => [
                'core' => ['user research', 'roadmapping', 'prioritization'],
                'supporting' => ['data literacy', 'stakeholder management'],
            ],
            'work_contents_json' => [
                'items' => [
                    'Define product strategy',
                    'Prioritize roadmap',
                    'Collaborate with design and engineering',
                ],
            ],
            'growth_path_json' => [
                'entry' => 'Associate Product Manager',
                'mid' => 'Product Manager',
                'senior' => 'Senior PM / Group PM',
                'notes' => 'Track can branch into leadership or strategy.',
            ],
            'fit_personality_codes_json' => ['INTJ', 'ENTJ'],
            'mbti_primary_codes_json' => ['INTJ', 'ENTJ'],
            'mbti_secondary_codes_json' => ['INFJ', 'ENFJ'],
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
                'notes' => 'Demand remains strong across SaaS, fintech, and AI startups.',
            ],
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
            'sort_order' => 10,
        ]);

        CareerJobSection::query()->create([
            'job_id' => $job->id,
            'section_key' => 'growth_story',
            'title' => 'Growth story',
            'render_variant' => 'cards',
            'body_md' => 'How the role evolves.',
            'payload_json' => ['milestones' => ['APM', 'PM', 'Group PM']],
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        CareerJobSection::query()->create([
            'job_id' => $job->id,
            'section_key' => 'day_to_day',
            'title' => 'Day to day',
            'render_variant' => 'bullets',
            'body_md' => '- Prioritize roadmap',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        CareerJobSeoMeta::query()->create([
            'job_id' => $job->id,
            'seo_title' => 'Product manager career path',
            'seo_description' => 'Understand the product manager role, pay, and fit.',
            'jsonld_overrides_json' => ['@type' => 'WebPage'],
        ]);

        CareerJobRevision::query()->create([
            'job_id' => $job->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Product Manager'],
            'note' => 'Initial draft',
            'created_at' => now()->subMinute(),
        ]);

        CareerJobRevision::query()->create([
            'job_id' => $job->id,
            'revision_no' => 2,
            'snapshot_json' => ['title' => 'Product Manager v2'],
            'note' => 'Published update',
            'created_at' => now(),
        ]);

        $freshJob = CareerJob::query()->findOrFail($job->id);

        $this->assertSame(['day_to_day', 'growth_story'], $freshJob->sections()->pluck('section_key')->all());
        $this->assertSame('Product manager career path', $freshJob->seoMeta?->seo_title);
        $this->assertSame([2, 1], $freshJob->revisions()->pluck('revision_no')->all());
        $this->assertSame(
            [$job->id],
            CareerJob::query()
                ->publishedPublic()
                ->indexable()
                ->forLocale('en')
                ->forSlug('PRODUCT-MANAGER')
                ->forJobCode('PRODUCT-MANAGER')
                ->pluck('id')
                ->all()
        );
        $this->assertSame('USD', $freshJob->salary_json['currency']);
        $this->assertSame(['INTJ', 'ENTJ'], $freshJob->mbti_primary_codes_json);
        $this->assertSame(72, $freshJob->riasec_profile_json['I']);
        $this->assertSame(
            ['milestones' => ['APM', 'PM', 'Group PM']],
            $freshJob->sections()->where('section_key', 'growth_story')->firstOrFail()->payload_json
        );
        $this->assertSame(['@type' => 'WebPage'], $freshJob->seoMeta?->jsonld_overrides_json);
        $this->assertSame(['title' => 'Product Manager v2'], $freshJob->revisions()->firstOrFail()->snapshot_json);
    }

    public function test_unique_org_job_code_locale_constraint_is_enforced(): void
    {
        CareerJob::query()->create($this->jobPayload([
            'job_code' => 'data-analyst',
            'slug' => 'data-analyst',
        ]));

        $this->expectException(QueryException::class);

        CareerJob::query()->create($this->jobPayload([
            'job_code' => 'data-analyst',
            'slug' => 'data-analyst-2',
        ]));
    }

    public function test_unique_org_slug_locale_constraint_is_enforced(): void
    {
        CareerJob::query()->create($this->jobPayload([
            'job_code' => 'backend-engineer',
            'slug' => 'engineering-manager',
        ]));

        $this->expectException(QueryException::class);

        CareerJob::query()->create($this->jobPayload([
            'job_code' => 'engineering-manager',
            'slug' => 'engineering-manager',
        ]));
    }

    public function test_deleting_job_cascades_sections_seo_meta_and_revisions(): void
    {
        $job = CareerJob::query()->create($this->jobPayload([
            'job_code' => 'ux-designer',
            'slug' => 'ux-designer',
        ]));

        $section = CareerJobSection::query()->create([
            'job_id' => $job->id,
            'section_key' => 'faq',
            'render_variant' => 'faq',
        ]);

        $seoMeta = CareerJobSeoMeta::query()->create([
            'job_id' => $job->id,
            'seo_title' => 'UX designer career',
        ]);

        $revision = CareerJobRevision::query()->create([
            'job_id' => $job->id,
            'revision_no' => 1,
            'snapshot_json' => ['slug' => 'ux-designer'],
            'created_at' => now(),
        ]);

        $job->delete();

        $this->assertDatabaseMissing('career_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('career_job_sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('career_job_seo_meta', ['id' => $seoMeta->id]);
        $this->assertDatabaseMissing('career_job_revisions', ['id' => $revision->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function jobPayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'job_code' => 'product-manager',
            'slug' => 'product-manager',
            'locale' => 'en',
            'title' => 'Career job',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides);
    }
}
