<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CareerRuntimePublishProjectionLookupTest extends TestCase
{
    private string $projectionTimestamp = '99999999T999999Z';

    public function test_it_reads_materialized_projection_visibility_by_slug_and_locale(): void
    {
        $this->writeProjection($this->projectionTimestamp, [
            [
                'slug' => 'actors',
                'locale' => 'en',
                'public_resolution_type' => 'public_canonical_job',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'search_visible' => true,
                'sitemap_live' => true,
                'llms_live' => true,
                'llms_full_live' => true,
                'canonical_self' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ],
            [
                'slug' => 'software-developers',
                'locale' => 'en',
                'public_resolution_type' => 'keep_non_public_with_policy',
                'runtime_publish_state' => 'quarantined',
                'detail_route_enabled' => false,
                'dataset_visible' => false,
                'search_visible' => false,
                'sitemap_live' => false,
                'llms_live' => false,
                'llms_full_live' => false,
                'canonical_self' => false,
                'robots_indexable' => false,
                'release_gate_pass' => false,
            ],
        ]);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        $this->assertTrue($lookup->detailRouteEnabled('actors'));
        $this->assertTrue($lookup->datasetVisible('actors'));
        $this->assertTrue($lookup->searchVisible('actors'));
        $this->assertFalse($lookup->detailRouteEnabled('software-developers'));
        $this->assertFalse($lookup->datasetVisible('software-developers'));
        $this->assertFalse($lookup->searchVisible('software-developers'));
        $this->assertFalse($lookup->familyHubLive('computer-and-information-technology'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/private/career_runtime_publish_projection/'.$this->projectionTimestamp));

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeProjection(string $timestamp, array $items): void
    {
        $dir = storage_path('app/private/career_runtime_publish_projection/'.$timestamp);
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME,
            (string) json_encode([
                'projection_kind' => 'career_runtime_publish_projection',
                'items' => $items,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
