<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\DTO\Career\CareerFamilyHubBundle;
use App\Http\Resources\Career\CareerFamilyHubResource;
use Illuminate\Http\Request;
use Tests\TestCase;

final class CareerFamilyHubStructuredDataPublicProjectionTest extends TestCase
{
    public function test_it_exposes_only_curated_family_hub_structured_data_fragments(): void
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
            ],
            counts: [
                'visible_children_count' => 1,
                'publish_ready_count' => 1,
                'blocked_override_eligible_count' => 0,
                'blocked_not_safely_remediable_count' => 0,
                'blocked_total' => 0,
            ],
        );

        $payload = (new CareerFamilyHubResource($bundle))->toArray(Request::create('/api/v0.5/career/family/computer-and-information-technology', 'GET'));

        $this->assertSame('CollectionPage', data_get($payload, 'structured_data.collection_page.@type'));
        $this->assertSame('ItemList', data_get($payload, 'structured_data.item_list.@type'));
        $this->assertSame('BreadcrumbList', data_get($payload, 'structured_data.breadcrumb_list.@type'));
        $this->assertSame([
            '@context',
            '@type',
            'name',
            'url',
            'mainEntityOfPage',
            'numberOfItems',
        ], array_keys((array) data_get($payload, 'structured_data.collection_page')));
        $this->assertSame([
            '@context',
            '@type',
            'numberOfItems',
            'itemListElement',
        ], array_keys((array) data_get($payload, 'structured_data.item_list')));
        $this->assertSame([
            '@type',
            'position',
            'name',
            'url',
        ], array_keys((array) data_get($payload, 'structured_data.item_list.itemListElement.0')));
        $this->assertSame([
            '@context',
            '@type',
            'itemListElement',
        ], array_keys((array) data_get($payload, 'structured_data.breadcrumb_list')));
        $this->assertSame([
            '@type',
            'position',
            'name',
            'item',
        ], array_keys((array) data_get($payload, 'structured_data.breadcrumb_list.itemListElement.0')));
        $this->assertArrayNotHasKey('route_kind', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('canonical_path', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('canonical_title', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('breadcrumb_nodes', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('dataset', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('article', (array) data_get($payload, 'structured_data'));
        $this->assertArrayNotHasKey('defined_term_set', (array) data_get($payload, 'structured_data'));
    }
}
