<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionLookup;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
        $this->assertSame(['actors'], array_column($lookup->publicDatasetItems(), 'slug'));
        $this->assertSame(['actors'], array_column($lookup->publicDetailItems(), 'slug'));
        $this->assertFalse($lookup->detailRouteEnabled('software-developers'));
        $this->assertFalse($lookup->datasetVisible('software-developers'));
        $this->assertFalse($lookup->searchVisible('software-developers'));
        $this->assertFalse($lookup->robotsIndexable('software-developers'));
        $this->assertFalse($lookup->releaseGatePass('software-developers'));
        $this->assertFalse($lookup->familyHubLive('computer-and-information-technology'));
    }

    public function test_it_infers_family_hub_authority_from_published_child_projection_without_explicit_family_row(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'computer-and-information-technology',
            'title_en' => 'Computer and Information Technology',
            'title_zh' => '计算机与信息技术',
        ]);

        Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'data-scientists',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Data Scientists',
            'canonical_title_zh' => '数据科学家',
            'search_h1_zh' => '数据科学家职业',
            'structural_stability' => 0.84,
            'task_prototype_signature' => ['analysis' => 0.9],
            'market_semantics_gap' => 0.1,
            'regulatory_divergence' => 0.1,
            'toolchain_divergence' => 0.1,
            'skill_gap_threshold' => 0.4,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
        ]);

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

        $this->assertSame(1, Occupation::query()->where('family_id', $family->id)->count());
        $this->assertTrue($lookup->detailRouteEnabled('data-scientists'));
        $this->assertTrue($lookup->familyHubLive('computer-and-information-technology'));
    }

    public function test_it_does_not_infer_family_hub_authority_without_published_children_or_for_broad_holds(): void
    {
        OccupationFamily::query()->create([
            'canonical_slug' => 'empty-family',
            'title_en' => 'Empty Family',
            'title_zh' => '空家族',
        ]);
        OccupationFamily::query()->create([
            'canonical_slug' => 'agricultural-workers-all-other',
            'title_en' => 'Agricultural Workers, All Other',
            'title_zh' => '其他农业工作者',
        ]);

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

    public function test_it_falls_back_to_cached_dataset_hub_without_rebuilding_projection(): void
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

        $this->assertTrue($lookup->detailRouteEnabled('actuaries'));
        $this->assertTrue($lookup->datasetVisible('actuaries'));
        $this->assertTrue($lookup->searchVisible('actuaries'));
        $this->assertTrue($lookup->robotsIndexable('actuaries'));
        $this->assertTrue($lookup->releaseGatePass('actuaries'));
        $this->assertSame(['actuaries'], array_column($lookup->publicDatasetItems(), 'slug'));
        $this->assertSame(['actuaries'], array_column($lookup->publicDetailItems(), 'slug'));

        $this->assertFalse($lookup->detailRouteEnabled('accountants-and-auditors'));
        $this->assertFalse($lookup->datasetVisible('accountants-and-auditors'));
        $this->assertFalse($lookup->searchVisible('accountants-and-auditors'));
        $this->assertFalse($lookup->robotsIndexable('accountants-and-auditors'));
        $this->assertFalse($lookup->releaseGatePass('accountants-and-auditors'));
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
