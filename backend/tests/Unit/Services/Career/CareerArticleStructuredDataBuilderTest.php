<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\StructuredData\CareerArticleStructuredDataBuilder;
use Tests\TestCase;

final class CareerArticleStructuredDataBuilderTest extends TestCase
{
    public function test_it_builds_article_structured_data_for_career_guide_surface_with_safe_fields_only(): void
    {
        $payload = app(CareerArticleStructuredDataBuilder::class)->build('career_guide_public_detail', [
            'headline' => 'From MBTI to Job Fit',
            'description' => 'Translate personality insights into practical career decisions.',
            'url' => 'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            'main_entity_of_page' => 'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            'date_published' => '2026-03-05T08:00:00+00:00',
            'date_modified' => '2026-03-05T09:00:00+00:00',
            'article_section' => 'assessment-usage',
            'keywords' => ['mbti', 'career', 'mbti'],
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('career_guide_public_detail', data_get($payload, 'route_kind'));
        $this->assertSame('Article', data_get($payload, 'fragments.article.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'fragments.breadcrumb_list.@type'));
        $this->assertSame('From MBTI to Job Fit', data_get($payload, 'fragments.article.headline'));
        $this->assertSame('assessment-usage', data_get($payload, 'fragments.article.articleSection'));
        $this->assertSame(['mbti', 'career'], data_get($payload, 'fragments.article.keywords'));
        $this->assertArrayNotHasKey('publisher', (array) data_get($payload, 'fragments.article'));
        $this->assertArrayNotHasKey('distribution', (array) data_get($payload, 'fragments.article'));
        $this->assertArrayNotHasKey('downloadUrl', (array) data_get($payload, 'fragments.article'));
        $this->assertArrayNotHasKey('license', (array) data_get($payload, 'fragments.article'));
        $this->assertArrayNotHasKey('usageInfo', (array) data_get($payload, 'fragments.article'));
        $this->assertArrayNotHasKey('includedInDataCatalog', (array) data_get($payload, 'fragments.article'));
    }

    public function test_it_omits_article_for_non_article_route_kinds_and_dataset_only_boundaries(): void
    {
        $builder = app(CareerArticleStructuredDataBuilder::class);

        $this->assertNull($builder->build('career_job_detail', [
            'headline' => 'Backend Architect',
            'url' => 'https://staging.fermatmind.com/en/career/jobs/backend-architect',
        ]));
        $this->assertNull($builder->build('career_family_hub', [
            'headline' => 'Technology Family',
            'url' => 'https://staging.fermatmind.com/en/career/family/technology',
        ]));
        $this->assertNull($builder->build('career_recommendation_detail', [
            'headline' => 'INTJ Recommendations',
            'url' => 'https://staging.fermatmind.com/en/career/recommendations/mbti/intj',
        ]));
        $this->assertNull($builder->build('career_alias_resolution', [
            'headline' => 'Resolve Backend',
            'url' => 'https://staging.fermatmind.com/en/career/resolve?q=backend',
        ]));
    }
}
