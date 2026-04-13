<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\DTO\Career\CareerJobDetailBundle;
use App\Http\Resources\Career\CareerJobDetailResource;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CareerJobDetailStructuredDataPublicProjectionTest extends TestCase
{
    public function test_it_exposes_only_curated_job_detail_structured_data_fragments(): void
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
            ],
            trustManifest: [],
            scoreBundle: [],
            warnings: [],
            claimPermissions: [],
            integritySummary: [],
            seoContract: [
                'canonical_path' => '/career/jobs/backend-architect',
            ],
            provenanceMeta: [],
        );

        $payload = (new CareerJobDetailResource($bundle))->toArray(Request::create('/api/v0.5/career/jobs/backend-architect', 'GET'));

        $this->assertSame('Occupation', data_get($payload, 'structured_data.occupation.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'structured_data.breadcrumb_list.@type'));
        $this->assertSame([
            '@context',
            '@type',
            'name',
            'url',
            'mainEntityOfPage',
            'educationRequirements',
            'experienceRequirements',
        ], array_keys((array) data_get($payload, 'structured_data.occupation')));
        $this->assertSame([
            '@context',
            '@type',
            'itemListElement',
        ], array_keys((array) data_get($payload, 'structured_data.breadcrumb_list')));
        $this->assertArrayNotHasKey('route_kind', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('canonical_path', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('canonical_title', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('breadcrumb_nodes', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('dataset', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('article', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('description', (array) data_get($payload, 'structured_data.occupation'));
        $this->assertArrayNotHasKey('occupationalExperienceRequirements', (array) data_get($payload, 'structured_data.occupation'));
    }
}
