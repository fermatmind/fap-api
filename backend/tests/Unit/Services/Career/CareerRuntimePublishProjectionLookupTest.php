<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

final class CareerRuntimePublishProjectionLookupTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertNull($lookup->itemForSlug('actors', 'zh-CN'));
        $this->assertSame(['actors'], array_column($lookup->publicDatasetItems(), 'slug'));
        $this->assertSame(['actors'], array_column($lookup->publicDetailItems(), 'slug'));
        $this->assertFalse($lookup->detailRouteEnabled('software-developers'));
        $this->assertFalse($lookup->datasetVisible('software-developers'));
        $this->assertFalse($lookup->searchVisible('software-developers'));
        $this->assertFalse($lookup->robotsIndexable('software-developers'));
        $this->assertFalse($lookup->releaseGatePass('software-developers'));
        $this->assertFalse($lookup->familyHubLive('computer-and-information-technology'));
    }

    public function test_it_returns_one_bilingual_snapshot_for_cache_coverage_iteration(): void
    {
        $items = [];
        foreach (['en', 'zh'] as $locale) {
            $items[] = [
                'slug' => 'actors',
                'locale' => $locale,
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'dataset_visible' => true,
                'search_visible' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ];
        }
        $this->writeProjection($this->projectionTimestamp, $items);

        $snapshot = app(CareerRuntimePublishProjectionLookup::class)
            ->jobDetailCoverageItems(['en', 'zh-CN']);

        $this->assertSame(['actors|en', 'actors|zh-CN'], array_keys($snapshot));
        $this->assertSame('en', $snapshot['actors|en']['locale']);
        $this->assertSame('zh', $snapshot['actors|zh-CN']['locale']);
    }

    public function test_it_requires_an_explicit_published_family_hub_projection_row(): void
    {
        $this->writeProjection($this->projectionTimestamp, [
            [
                'slug' => 'data-scientists',
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
        ]);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        $this->assertTrue($lookup->detailRouteEnabled('data-scientists'));
        $this->assertFalse($lookup->familyHubLive('computer-and-information-technology'));
    }

    public function test_it_requires_a_published_family_hub_projection_row_for_all_family_slugs(): void
    {
        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        $this->assertFalse($lookup->familyHubLive('empty-family'));
        $this->assertFalse($lookup->familyHubLive('agricultural-workers-all-other'));
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

    public function test_it_reloads_projection_visibility_when_newer_materialized_authority_appears(): void
    {
        $this->writeProjection('first', [[
            'slug' => 'actors',
            'locale' => 'en',
            'runtime_publish_state' => 'blocked',
            'detail_route_enabled' => false,
            'dataset_visible' => false,
            'search_visible' => false,
            'robots_indexable' => false,
            'release_gate_pass' => false,
        ]], 1_000);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);
        $this->assertFalse($lookup->detailRouteEnabled('actors'));

        $this->writeProjection('second', [[
            'slug' => 'actors',
            'locale' => 'en',
            'runtime_publish_state' => 'published',
            'detail_route_enabled' => true,
            'dataset_visible' => true,
            'search_visible' => true,
            'robots_indexable' => true,
            'release_gate_pass' => true,
        ]], 2_000);

        $this->assertTrue($lookup->detailRouteEnabled('actors'));
    }

    public function test_it_does_not_derive_runtime_visibility_from_cached_dataset_payloads(): void
    {
        Cache::put(PublicCareerAuthorityResponseCache::DATASET_HUB_CACHE_KEY, [
            'members' => [
                [
                    'canonical_slug' => 'actuaries',
                    'release_cohort' => 'public_detail_indexable',
                    'public_index_state' => 'indexable',
                    'strong_index_decision' => 'strong_index_ready',
                    'included_in_public_dataset' => true,
                ],
                [
                    'canonical_slug' => 'accountants-and-auditors',
                    'release_cohort' => 'review_needed',
                    'public_index_state' => 'noindex',
                    'strong_index_decision' => 'review_needed',
                    'included_in_public_dataset' => false,
                ],
            ],
        ]);

        $lookup = app(CareerRuntimePublishProjectionLookup::class);

        $this->assertFalse($lookup->detailRouteEnabled('actuaries'));
        $this->assertFalse($lookup->datasetVisible('actuaries'));
        $this->assertFalse($lookup->searchVisible('actuaries'));
        $this->assertFalse($lookup->robotsIndexable('actuaries'));
        $this->assertFalse($lookup->releaseGatePass('actuaries'));
        $this->assertSame([], $lookup->publicDatasetItems());
        $this->assertSame([], $lookup->publicDetailItems());

        $this->assertFalse($lookup->detailRouteEnabled('accountants-and-auditors'));
        $this->assertFalse($lookup->datasetVisible('accountants-and-auditors'));
        $this->assertFalse($lookup->searchVisible('accountants-and-auditors'));
        $this->assertFalse($lookup->robotsIndexable('accountants-and-auditors'));
        $this->assertFalse($lookup->releaseGatePass('accountants-and-auditors'));
    }

    public function test_it_discards_cached_detail_payloads_when_the_requested_locale_is_not_published(): void
    {
        $visibility = Mockery::mock(CareerRuntimePublishProjectionVisibility::class);
        $visibility->shouldReceive('itemForSlug')
            ->with('actors', 'zh-CN')
            ->once()
            ->andReturn(null);
        $this->app->instance(CareerRuntimePublishProjectionVisibility::class, $visibility);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cacheKey = $cache->jobDetailCacheKey('actors', 'zh-CN');
        Cache::forever($cacheKey, ['identity' => ['canonical_slug' => 'actors']]);

        $this->assertNull($cache->jobDetailPayload('actors', 'zh-CN'));
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_it_filters_job_index_items_before_activating_the_requested_locale_snapshot(): void
    {
        $publishedActor = [
            'runtime_publish_state' => 'published',
            'detail_route_enabled' => true,
            'robots_indexable' => true,
            'release_gate_pass' => true,
            'dataset_visible' => true,
        ];
        $visibility = Mockery::mock(CareerRuntimePublishProjectionVisibility::class);
        $visibility->shouldReceive('itemForSlug')
            ->with('actors', 'zh-CN')
            ->times(3)
            ->andReturn($publishedActor);
        $visibility->shouldReceive('itemForSlug')
            ->with('accountants-and-auditors', 'zh-CN')
            ->twice()
            ->andReturn(null);
        $this->app->instance(CareerRuntimePublishProjectionVisibility::class, $visibility);

        $cache = app(PublicCareerAuthorityResponseCache::class);
        $cache->publishJobDetailReadModel('actors', 'zh-CN', [
            'identity' => ['canonical_slug' => 'actors'],
        ]);
        $cache->publishJobIndexReadModelsAtomically([
            'zh-CN' => [
                'bundle_kind' => 'career_job_index',
                'bundle_version' => 'career.protocol.job_index.v1',
                'items' => [
                    ['identity' => ['canonical_slug' => 'actors']],
                    ['identity' => ['canonical_slug' => 'accountants-and-auditors']],
                ],
            ],
        ]);

        $payload = $cache->jobIndexPayload('zh-CN');

        $this->assertSame(['actors'], collect($payload['items'])->pluck('identity.canonical_slug')->all());
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
