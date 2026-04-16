<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\DTO\Career\CareerFamilyHubBundle;
use App\DTO\Career\CareerJobDetailBundle;
use App\Services\Career\StructuredData\CareerBreadcrumbBuilder;
use Tests\TestCase;

final class CareerBreadcrumbBuilderTest extends TestCase
{
    public function test_it_builds_job_detail_breadcrumb_nodes_and_fragment(): void
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
            truthLayer: [],
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

        $builder = app(CareerBreadcrumbBuilder::class);
        $nodes = $builder->buildForJobDetail($bundle);
        $fragment = $builder->buildBreadcrumbList($nodes);

        $this->assertSame([
            ['name' => 'Career', 'path' => '/career'],
            ['name' => 'Backend Architect', 'path' => '/career/jobs/backend-architect'],
        ], $nodes);
        $this->assertSame('BreadcrumbList', data_get($fragment, '@type'));
        $this->assertSame('Career', data_get($fragment, 'itemListElement.0.name'));
        $this->assertSame('/career/jobs/backend-architect', data_get($fragment, 'itemListElement.1.item'));
    }

    public function test_it_builds_family_hub_breadcrumb_nodes_and_fragment(): void
    {
        $bundle = new CareerFamilyHubBundle(
            family: [
                'canonical_slug' => 'computer-and-information-technology',
                'title_en' => 'Computer and Information Technology',
            ],
            visibleChildren: [],
            counts: [],
            seoContract: [],
        );

        $builder = app(CareerBreadcrumbBuilder::class);
        $nodes = $builder->buildForFamilyHub($bundle);
        $fragment = $builder->buildBreadcrumbList($nodes);

        $this->assertSame([
            ['name' => 'Career', 'path' => '/career'],
            ['name' => 'Computer and Information Technology', 'path' => '/career/family/computer-and-information-technology'],
        ], $nodes);
        $this->assertSame('BreadcrumbList', data_get($fragment, '@type'));
        $this->assertSame('/career/family/computer-and-information-technology', data_get($fragment, 'itemListElement.1.item'));
    }
}
