<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\StructuredData\CareerDatasetStructuredDataBuilder;
use Tests\TestCase;

final class CareerDatasetStructuredDataBuilderTest extends TestCase
{
    public function test_it_builds_dataset_json_ld_for_dataset_hub_route_only(): void
    {
        $payload = app(CareerDatasetStructuredDataBuilder::class)->build('career_dataset_hub', [
            'dataset_name' => 'FermatMind Career Occupations Dataset (First Wave)',
            'description' => 'Public dataset hub',
            'url' => 'https://www.fermatmind.com/datasets/occupations',
            'license' => [
                'url' => 'https://www.fermatmind.com/datasets/occupations/license',
            ],
            'publisher' => [
                'name' => 'FermatMind',
                'url' => 'https://www.fermatmind.com',
            ],
            'distribution' => [
                'download_url' => 'https://www.fermatmind.com/datasets/occupations/download',
                'format' => ['json', 'csv'],
            ],
            'keywords' => ['career', 'dataset'],
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('Dataset', data_get($payload, 'fragments.dataset.@type'));
        $this->assertSame(
            'https://www.fermatmind.com/datasets/occupations/download',
            data_get($payload, 'fragments.dataset.distribution.0.contentUrl')
        );
        $this->assertSame('BreadcrumbList', data_get($payload, 'fragments.breadcrumb_list.@type'));
    }

    public function test_it_builds_method_article_json_ld_only_for_dataset_method_route(): void
    {
        $payload = app(CareerDatasetStructuredDataBuilder::class)->build('career_dataset_method', [
            'title' => 'Occupations dataset method',
            'summary' => 'Method contract.',
            'url' => 'https://www.fermatmind.com/datasets/occupations/method',
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('Article', data_get($payload, 'fragments.article.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'fragments.breadcrumb_list.@type'));
    }

    public function test_it_denies_dataset_schema_for_non_dataset_routes(): void
    {
        $this->assertNull(app(CareerDatasetStructuredDataBuilder::class)->build('career_job_detail', [
            'dataset_name' => 'Should not render',
            'url' => 'https://www.fermatmind.com/datasets/occupations',
        ]));
    }
}
