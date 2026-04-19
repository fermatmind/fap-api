<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\DTO\Career\CareerFamilyHubBundle;
use App\DTO\Career\CareerJobDetailBundle;
use App\Services\Career\StructuredData\CareerStructuredDataBuilder;
use Tests\TestCase;

final class CareerStructuredDataBuilderTest extends TestCase
{
    public function test_it_builds_internal_job_detail_structured_data_fragments(): void
    {
        $bundle = new CareerJobDetailBundle(
            identity: [
                'canonical_slug' => 'backend-architect',
            ],
            localePolicy: [],
            titles: [
                'canonical_en' => 'Backend Architect',
            ],
            aliasIndex: [],
            ontology: [],
            truthLayer: [
                'entry_education' => 'Bachelor\'s degree',
                'work_experience' => '5 years or more',
                'on_the_job_training' => 'None',
                'outlook_description' => 'Designs and maintains backend systems.',
            ],
            contentSections: [],
            contentBodyMd: null,
            trustManifest: [],
            scoreBundle: [],
            whiteBoxScores: [],
            warnings: [],
            claimPermissions: [],
            integritySummary: [],
            seoContract: [
                'canonical_path' => '/career/jobs/backend-architect',
            ],
            provenanceMeta: [],
            lifecycleCompanion: [],
            lifecycleOperational: [],
            shortlistContract: [],
            conversionClosure: [],
        );

        $payload = app(CareerStructuredDataBuilder::class)->build('career_job_detail', $bundle);

        $this->assertNotNull($payload);
        $this->assertSame('career_job_detail', data_get($payload, 'route_kind'));
        $this->assertSame('/career/jobs/backend-architect', data_get($payload, 'canonical_path'));
        $this->assertSame('Backend Architect', data_get($payload, 'canonical_title'));
        $this->assertSame('Occupation', data_get($payload, 'fragments.occupation.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'fragments.breadcrumb_list.@type'));
        $this->assertSame('Bachelor\'s degree', data_get($payload, 'fragments.occupation.educationRequirements'));
        $this->assertSame('5 years or more', data_get($payload, 'fragments.occupation.experienceRequirements'));
        $this->assertArrayNotHasKey('occupationalExperienceRequirements', (array) data_get($payload, 'fragments.occupation'));
        $this->assertArrayNotHasKey('description', (array) data_get($payload, 'fragments.occupation'));
        $this->assertStringNotContainsString('Dataset', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Article', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_it_builds_internal_family_hub_structured_data_fragments_from_visible_children_only(): void
    {
        $bundle = new CareerFamilyHubBundle(
            family: [
                'canonical_slug' => 'computer-and-information-technology',
                'title_en' => 'Computer and Information Technology',
            ],
            visibleChildren: [
                [
                    'canonical_slug' => 'backend-architect',
                    'canonical_title_en' => 'Backend Architect',
                    'seo_contract' => [
                        'canonical_path' => '/career/jobs/backend-architect',
                    ],
                ],
                [
                    'canonical_slug' => 'data-scientists',
                    'canonical_title_en' => 'Data Scientists',
                    'seo_contract' => [
                        'canonical_path' => '/career/jobs/data-scientists',
                    ],
                ],
                [
                    'canonical_slug' => 'orphaned-role',
                    'canonical_title_en' => 'Orphaned Role',
                    'seo_contract' => [],
                ],
            ],
            counts: [
                'visible_children_count' => 3,
            ],
            seoContract: [],
        );

        $payload = app(CareerStructuredDataBuilder::class)->build('career_family_hub', $bundle);

        $this->assertNotNull($payload);
        $this->assertSame('career_family_hub', data_get($payload, 'route_kind'));
        $this->assertSame('/career/family/computer-and-information-technology', data_get($payload, 'canonical_path'));
        $this->assertSame('CollectionPage', data_get($payload, 'fragments.collection_page.@type'));
        $this->assertSame('ItemList', data_get($payload, 'fragments.item_list.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'fragments.breadcrumb_list.@type'));
        $this->assertSame(2, data_get($payload, 'fragments.item_list.numberOfItems'));
        $this->assertSame(2, data_get($payload, 'fragments.collection_page.numberOfItems'));
        $this->assertSame('Backend Architect', data_get($payload, 'fragments.item_list.itemListElement.0.name'));
        $this->assertSame('/career/jobs/backend-architect', data_get($payload, 'fragments.item_list.itemListElement.0.url'));
        $this->assertSame(1, data_get($payload, 'fragments.item_list.itemListElement.0.position'));
        $this->assertSame(2, data_get($payload, 'fragments.item_list.itemListElement.1.position'));
        $this->assertStringNotContainsString('Orphaned Role', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Dataset', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Article', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_it_excludes_unsupported_route_kinds_from_the_internal_builder(): void
    {
        $builder = app(CareerStructuredDataBuilder::class);

        $this->assertNull($builder->build('career_recommendation_detail', []));
        $this->assertNull($builder->build('career_search', []));
        $this->assertNull($builder->build('career_alias_resolution', []));
        $this->assertNull($builder->build('test_landing', []));
        $this->assertNull($builder->build('topic_detail', []));
        $this->assertNull($builder->build('article_public_detail', []));
    }

    public function test_it_builds_article_structured_data_for_article_and_career_guide_routes_without_dataset_schema(): void
    {
        $builder = app(CareerStructuredDataBuilder::class);

        $articlePayload = $builder->build('article_public_detail', [
            'headline' => 'MBTI Basics',
            'description' => 'Learn the core concepts behind MBTI.',
            'url' => 'https://staging.fermatmind.com/en/articles/mbti-basics',
            'main_entity_of_page' => 'https://staging.fermatmind.com/en/articles/mbti-basics',
            'date_published' => '2026-03-12T08:00:00+00:00',
            'date_modified' => '2026-03-12T09:00:00+00:00',
            'article_section' => 'personality',
            'keywords' => ['mbti', 'article'],
        ]);
        $guidePayload = $builder->build('career_guide_public_detail', [
            'headline' => 'From MBTI to Job Fit',
            'description' => 'Translate personality insights into career decisions.',
            'url' => 'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            'main_entity_of_page' => 'https://staging.fermatmind.com/en/career/guides/from-mbti-to-job-fit',
            'date_published' => '2026-03-05T08:00:00+00:00',
            'date_modified' => '2026-03-05T09:00:00+00:00',
            'article_section' => 'assessment-usage',
            'keywords' => ['career', 'guide'],
        ]);

        $this->assertNotNull($articlePayload);
        $this->assertNotNull($guidePayload);
        $this->assertSame('Article', data_get($articlePayload, 'fragments.article.@type'));
        $this->assertSame('Article', data_get($guidePayload, 'fragments.article.@type'));
        $this->assertSame('BreadcrumbList', data_get($articlePayload, 'fragments.breadcrumb_list.@type'));
        $this->assertSame('BreadcrumbList', data_get($guidePayload, 'fragments.breadcrumb_list.@type'));
        $this->assertArrayNotHasKey('dataset', (array) data_get($articlePayload, 'fragments'));
        $this->assertArrayNotHasKey('dataset', (array) data_get($guidePayload, 'fragments'));
    }

    public function test_it_builds_dataset_and_method_structured_data_only_for_dataset_routes(): void
    {
        $builder = app(CareerStructuredDataBuilder::class);

        $hubPayload = $builder->build('career_dataset_hub', [
            'dataset_name' => 'FermatMind Career Occupations Dataset (First Wave)',
            'description' => 'Public dataset hub',
            'url' => 'https://www.fermatmind.com/datasets/occupations',
            'publisher' => [
                'name' => 'FermatMind',
                'url' => 'https://www.fermatmind.com',
            ],
            'license' => [
                'url' => 'https://www.fermatmind.com/datasets/occupations/license',
            ],
            'distribution' => [
                'download_url' => 'https://www.fermatmind.com/datasets/occupations/download',
                'format' => ['json', 'csv'],
            ],
        ]);
        $methodPayload = $builder->build('career_dataset_method', [
            'title' => 'Occupations dataset method',
            'summary' => 'Method summary',
            'url' => 'https://www.fermatmind.com/datasets/occupations/method',
        ]);

        $this->assertSame('Dataset', data_get($hubPayload, 'fragments.dataset.@type'));
        $this->assertSame('BreadcrumbList', data_get($hubPayload, 'fragments.breadcrumb_list.@type'));
        $this->assertSame('Article', data_get($methodPayload, 'fragments.article.@type'));
        $this->assertSame('BreadcrumbList', data_get($methodPayload, 'fragments.breadcrumb_list.@type'));
        $this->assertNull($builder->build('career_recommendation_detail', [
            'title' => 'Should not render',
            'url' => 'https://www.fermatmind.com/datasets/occupations/method',
        ]));
    }
}
