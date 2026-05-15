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

    /**
     * @var list<string>
     */
    private array $projectionDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory(storage_path('app/private/career_runtime_publish_projection'));
    }

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
        $this->assertTrue($lookup->robotsIndexable('actors'));
        $this->assertTrue($lookup->releaseGatePass('actors'));
        $this->assertFalse($lookup->detailRouteEnabled('software-developers'));
        $this->assertFalse($lookup->datasetVisible('software-developers'));
        $this->assertFalse($lookup->searchVisible('software-developers'));
        $this->assertFalse($lookup->robotsIndexable('software-developers'));
        $this->assertFalse($lookup->releaseGatePass('software-developers'));
        $this->assertFalse($lookup->familyHubLive('computer-and-information-technology'));
    }

    public function test_it_uses_newest_valid_projection_artifact_instead_of_lexical_directory_order(): void
    {
        $this->writeProjection('career_post_rollout_runtime_projection_authority_20260515_65e4fdbd', [
            [
                'slug' => 'architectural-and-civil-drafters',
                'locale' => 'en',
                'runtime_publish_state' => 'quarantined',
                'detail_route_enabled' => false,
                'dataset_visible' => false,
                'search_visible' => false,
                'robots_indexable' => false,
                'release_gate_pass' => false,
            ],
        ], 1_000);
        $this->writeInvalidProjection('zzzz-invalid-newer-directory', 3_000);
        $this->writeProjection('20260515T064326Z', [
            [
                'slug' => 'architectural-and-civil-drafters',
                'locale' => 'en',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'search_visible' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ],
        ], 2_000);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        $this->assertTrue($lookup->detailRouteEnabled('architectural-and-civil-drafters'));
        $this->assertTrue($lookup->datasetVisible('architectural-and-civil-drafters'));
        $this->assertTrue($lookup->searchVisible('architectural-and-civil-drafters'));
        $this->assertTrue($lookup->robotsIndexable('architectural-and-civil-drafters'));
        $this->assertTrue($lookup->releaseGatePass('architectural-and-civil-drafters'));
    }

    protected function tearDown(): void
    {
        foreach ($this->projectionDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeProjection(string $timestamp, array $items, ?int $mtime = null): void
    {
        $dir = storage_path('app/private/career_runtime_publish_projection/'.$timestamp);
        File::ensureDirectoryExists($dir);
        $this->projectionDirectories[] = $dir;
        $path = $dir.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        File::put(
            $path,
            (string) json_encode([
                'projection_kind' => 'career_runtime_publish_projection',
                'items' => $items,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        if ($mtime !== null) {
            touch($path, $mtime);
            clearstatcache(true, $path);
        }
    }

    private function writeInvalidProjection(string $timestamp, ?int $mtime = null): void
    {
        $dir = storage_path('app/private/career_runtime_publish_projection/'.$timestamp);
        File::ensureDirectoryExists($dir);
        $this->projectionDirectories[] = $dir;
        $path = $dir.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME;
        File::put($path, '{invalid');

        if ($mtime !== null) {
            touch($path, $mtime);
            clearstatcache(true, $path);
        }
    }
}
