<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerGuideSchemaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_schema_relations_casts_and_scopes_work(): void
    {
        $guide = CareerGuide::query()->create($this->guidePayload([
            'status' => CareerGuide::STATUS_PUBLISHED,
            'published_at' => now(),
            'sort_order' => 10,
        ]));

        $localizedGuide = CareerGuide::query()->create($this->guidePayload([
            'locale' => 'zh-CN',
            'title' => 'Annual career review system zh',
            'excerpt' => 'Localized zh-CN guide content.',
            'body_md' => 'Localized zh-CN body.',
            'body_html' => '<p>Localized zh-CN body.</p>',
        ]));

        $job = CareerJob::query()->create($this->jobPayload());
        $article = Article::query()->create($this->articlePayload());
        $profile = PersonalityProfile::query()->create($this->profilePayload());

        $guide->seoMeta()->create([
            'seo_title' => 'Annual career review system',
            'seo_description' => 'A structured annual career review guide.',
            'jsonld_overrides_json' => ['@type' => 'WebPage'],
        ]);

        $guide->revisions()->create([
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Annual career review system'],
            'note' => 'Initial snapshot',
            'created_by_admin_user_id' => 7,
            'created_at' => now(),
        ]);

        $guide->relatedJobs()->attach($job->id, ['sort_order' => 10]);
        $guide->relatedArticles()->attach($article->id, ['sort_order' => 20]);
        $guide->relatedPersonalityProfiles()->attach($profile->id, ['sort_order' => 30]);

        $freshGuide = CareerGuide::query()->findOrFail($guide->id);

        $this->assertSame(['technology', 'strategy'], $freshGuide->related_industry_slugs_json);
        $this->assertSame('Annual career review system', $freshGuide->seoMeta?->seo_title);
        $this->assertSame([1], $freshGuide->revisions()->pluck('revision_no')->all());
        $this->assertSame([$job->id], $freshGuide->relatedJobs()->pluck('career_jobs.id')->all());
        $this->assertSame([$article->id], $freshGuide->relatedArticles()->pluck('articles.id')->all());
        $this->assertSame(
            [$profile->id],
            $freshGuide->relatedPersonalityProfiles()->pluck('personality_profiles.id')->all()
        );
        $this->assertSame(
            [$guide->id],
            CareerGuide::query()
                ->publishedPublic()
                ->indexable()
                ->forLocale('en')
                ->forSlug('ANNUAL-CAREER-REVIEW-SYSTEM')
                ->forGuideCode('ANNUAL-CAREER-REVIEW-SYSTEM')
                ->pluck('id')
                ->all()
        );
        $this->assertSame('zh-CN', $localizedGuide->locale);

        $this->assertDatabaseHas('career_guide_seo_meta', [
            'career_guide_id' => $guide->id,
            'seo_title' => 'Annual career review system',
        ]);
        $this->assertDatabaseHas('career_guide_revisions', [
            'career_guide_id' => $guide->id,
            'revision_no' => 1,
        ]);
        $this->assertDatabaseHas('career_guide_job_map', [
            'career_guide_id' => $guide->id,
            'career_job_id' => $job->id,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('career_guide_article_map', [
            'career_guide_id' => $guide->id,
            'article_id' => $article->id,
            'sort_order' => 20,
        ]);
        $this->assertDatabaseHas('career_guide_personality_map', [
            'career_guide_id' => $guide->id,
            'personality_profile_id' => $profile->id,
            'sort_order' => 30,
        ]);
    }

    public function test_unique_org_slug_locale_constraint_is_enforced(): void
    {
        CareerGuide::query()->create($this->guidePayload());

        $this->expectException(QueryException::class);

        CareerGuide::query()->create($this->guidePayload([
            'guide_code' => 'annual-career-review-system-v2',
            'title' => 'Duplicate slug in same locale',
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function guidePayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'guide_code' => 'annual-career-review-system',
            'slug' => 'annual-career-review-system',
            'locale' => 'en',
            'title' => 'Annual career review system',
            'excerpt' => 'A repeatable system for reviewing your career once a year.',
            'category_slug' => 'career-planning',
            'body_md' => 'Use this guide to review your career progress.',
            'body_html' => '<p>Use this guide to review your career progress.</p>',
            'related_industry_slugs_json' => ['technology', 'strategy'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'sort_order' => 0,
            'schema_version' => 'v1',
        ], $overrides);
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
            'title' => 'Product Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function articlePayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'slug' => 'career-review-mistakes',
            'locale' => 'en',
            'title' => 'Career review mistakes',
            'excerpt' => 'What to avoid during a yearly career review.',
            'content_md' => 'Article body',
            'content_html' => '<p>Article body</p>',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ',
            'excerpt' => 'Strategic and independent.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => 'v1',
        ], $overrides);
    }
}
