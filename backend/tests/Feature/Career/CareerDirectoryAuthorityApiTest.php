<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerDirectoryAuthorityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_returns_paginated_lightweight_directory_authority(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actors', 'Actors', '演员', 'arts-media', 'Arts and Media');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries', 'actors']);

        $response = $this->getJson('/api/v0.5/career/directory?locale=en&page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('authority_version', 'career.directory_authority.v1')
            ->assertJsonPath('bundle_kind', 'career_directory')
            ->assertJsonPath('public_truth.public_detail_indexable_count', 3)
            ->assertJsonPath('public_truth.directory_member_count', 3)
            ->assertJsonPath('public_truth.future_scale_ready', true)
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.total_pages', 2)
            ->assertJsonPath('pagination.has_next_page', true)
            ->assertJsonPath('filters.locale', 'en')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.canonical_path', '/en/career/jobs/accountants-and-auditors')
            ->assertJsonPath('items.0.detail_ready', true)
            ->assertJsonPath('items.0.indexable', true)
            ->assertJsonPath('items.0.family.slug', 'business-finance')
            ->assertJsonMissingPath('items.0.truth_summary')
            ->assertJsonMissingPath('items.0.score_summary')
            ->assertJsonMissingPath('items.0.provenance_meta');

        $this->assertSame(
            ['arts-media', 'business-finance'],
            collect($response->json('facets.families'))->pluck('slug')->all(),
        );
    }

    public function test_it_filters_by_family_and_query_without_exposing_full_job_index_fields(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('actors', 'Actors', '演员', 'arts-media', 'Arts and Media');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries', 'actors']);

        $this->getJson('/api/v0.5/career/directory?locale=zh-CN&family=business-finance&q=actuar&page=1&per_page=50')
            ->assertOk()
            ->assertJsonPath('filters.locale', 'zh-CN')
            ->assertJsonPath('filters.family', 'business-finance')
            ->assertJsonPath('filters.q', 'actuar')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.slug', 'actuaries')
            ->assertJsonPath('items.0.title', '精算师')
            ->assertJsonPath('items.0.canonical_path', '/zh/career/jobs/actuaries');
    }

    public function test_it_excludes_held_noindex_and_stub_only_entries(): void
    {
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师', 'business-finance', 'Business and Finance');
        $this->createDirectoryOccupation('software-developers', 'Software Developers', '软件开发人员', 'software', 'Software');
        $this->createDirectoryOccupation('private-investigators', 'Private Investigators', '私家侦探', 'protective-service', 'Protective Service');
        $this->publishRuntimeProjection([
            'actuaries',
            'software-developers',
            'private-investigators' => [
                'robots_indexable' => false,
                'runtime_publish_state' => 'blocked',
                'detail_route_enabled' => false,
                'release_gate_pass' => false,
            ],
        ]);

        $slugs = collect($this->getJson('/api/v0.5/career/directory?locale=en&per_page=100')->assertOk()->json('items'))
            ->pluck('slug')
            ->all();

        $this->assertSame(['actuaries'], $slugs);
    }

    private function createDirectoryOccupation(
        string $slug,
        string $titleEn,
        string $titleZh,
        string $familySlug,
        string $familyTitle
    ): Occupation {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => $familySlug],
            [
                'title_en' => $familyTitle,
                'title_zh' => $familyTitle,
            ],
        );

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $titleEn,
            'canonical_title_zh' => $titleZh,
            'search_h1_zh' => $titleZh,
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 54, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 54, 0),
        ]);

        foreach ([
            ['source_system' => 'us_soc', 'source_code' => '15-1252'],
            ['source_system' => 'onet_soc_2019', 'source_code' => '15-1252.00'],
        ] as $crosswalk) {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => $crosswalk['source_system'],
                'source_code' => $crosswalk['source_code'],
                'source_title' => $titleEn,
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);
        }

        CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => range(1, 24),
            'page_payload_json' => [
                'zh' => ['hero' => ['title' => $titleZh]],
                'en' => ['hero' => ['title' => $titleEn]],
            ],
            'seo_payload_json' => [
                'indexability_state' => 'index',
                'robots_policy' => 'index,follow',
            ],
            'sources_json' => [
                'primary' => [
                    ['label' => 'Fixture source', 'url' => 'https://example.test/career'],
                ],
            ],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
        ]);

        return $occupation;
    }

    /**
     * @param  list<string>|array<string, array<string, mixed>>  $slugs
     */
    private function publishRuntimeProjection(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $key => $value) {
            $slug = is_int($key) ? (string) $value : (string) $key;
            $overrides = is_array($value) ? $value : [];
            $published = ($overrides['runtime_publish_state'] ?? 'published') === 'published';

            $items[$slug.'|en'] = array_merge([
                'slug' => $slug,
                'locale' => 'en',
                'dataset_visible' => $published,
                'search_visible' => $published,
                'detail_route_enabled' => $published,
                'robots_indexable' => $published,
                'release_gate_pass' => $published,
                'runtime_publish_state' => $published ? 'published' : 'blocked',
            ], $overrides);
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                items: $items,
            ),
        );

        Cache::flush();
    }
}
