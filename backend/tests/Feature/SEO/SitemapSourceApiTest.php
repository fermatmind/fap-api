<?php

namespace Tests\Feature\SEO;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SitemapSourceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_source_api_returns_backend_sitemap_generator_urls(): void
    {
        config(['app.frontend_url' => 'https://staging.fermatmind.com']);

        $this->createDisplayAsset(
            $this->createOccupation('agricultural-inspectors', 'Agricultural Inspectors'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0)]
        );
        $this->createDisplayAsset(
            $this->createOccupation('software-developers', 'Software Developers'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 56, 0)]
        );

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('source', 'backend_sitemap_generator');

        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://staging.fermatmind.com/en/career/jobs/agricultural-inspectors', $locs);
        $this->assertContains('https://staging.fermatmind.com/zh/career/jobs/agricultural-inspectors', $locs);
        $this->assertNotContains('https://staging.fermatmind.com/en/career/jobs/software-developers', $locs);
        $this->assertNotContains('https://staging.fermatmind.com/zh/career/jobs/software-developers', $locs);
        $this->assertSame(count($locs), $response->json('count'));
    }

    private function createOccupation(string $slug, string $title): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'family-'.$slug,
            'title_en' => $title,
            'title_zh' => $title,
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
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
    }

    private function createDisplayAsset(Occupation $occupation, array $overrides = []): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create(array_merge([
            'occupation_id' => $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => range(1, 24),
            'page_payload_json' => [
                'zh' => ['hero' => ['title' => $occupation->canonical_title_zh]],
                'en' => ['hero' => ['title' => $occupation->canonical_title_en]],
            ],
            'seo_payload_json' => [
                'indexability_state' => 'index',
                'robots_policy' => 'index,follow',
            ],
            'sources_json' => [],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
            'created_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 55, 0),
        ], $overrides));
    }
}
