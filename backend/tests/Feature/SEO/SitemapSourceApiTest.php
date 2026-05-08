<?php

namespace Tests\Feature\SEO;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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
        $this->writeProjectionArtifact([
            $this->projectionItem('agricultural-inspectors', 'en'),
            $this->projectionItem('agricultural-inspectors', 'zh'),
            $this->projectionItem(
                'software-developers',
                'en',
                CareerRuntimePublishProjectionService::STATE_QUARANTINED,
                [
                    'public_resolution_type' => CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
                    'detail_route_enabled' => false,
                    'sitemap_live' => false,
                    'robots_indexable' => false,
                    'release_gate_pass' => false,
                    'canonical_self' => false,
                    'canonical_url' => null,
                ],
            ),
        ]);

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

    public function test_sitemap_source_only_exports_runtime_published_career_job_detail_urls(): void
    {
        config(['app.frontend_url' => 'https://www.fermatmind.com']);

        $this->createCareerJob('backend-engineer', 'Backend Engineer', 'en');
        $this->createCareerJob('backend-engineer', 'Backend Engineer', 'zh-CN');
        $this->createCareerJob('software-engineer', 'Software Engineer', 'en');
        $this->createCareerJob('software-engineer', 'Software Engineer', 'zh-CN');

        $this->createDisplayAsset(
            $this->createOccupation('data-scientists', 'Data Scientists'),
            ['updated_at' => Carbon::create(2026, 1, 31, 12, 57, 0)]
        );
        $this->writeProjectionArtifact([
            $this->projectionItem('data-scientists', 'en'),
            $this->projectionItem(
                'data-scientists',
                'zh',
                CareerRuntimePublishProjectionService::STATE_PUBLISHED_CANDIDATE,
                [
                    'detail_route_enabled' => false,
                    'dataset_visible' => false,
                    'search_visible' => false,
                    'sitemap_live' => false,
                    'llms_live' => false,
                    'llms_full_live' => false,
                ],
            ),
        ]);

        $response = $this->getJson('/api/v0.5/seo/sitemap-source');

        $response->assertOk();
        $locs = collect($response->json('items'))->pluck('loc')->all();

        $this->assertContains('https://fermatmind.com/en/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://www.fermatmind.com/en/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://www.fermatmind.com/zh/career/jobs/data-scientists', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/backend-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/backend-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/en/career/jobs/software-engineer', $locs);
        $this->assertNotContains('https://fermatmind.com/zh/career/jobs/software-engineer', $locs);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function writeProjectionArtifact(array $items): void
    {
        $timestamp = str_replace('.', '', sprintf('%.6F', microtime(true)));
        $directory = storage_path('app/private/career_runtime_publish_projection/zzzzzzzz-sitemap-source-test-'.$timestamp.'-'.strtolower(str()->random(8)));

        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME, json_encode([
            'projection_kind' => CareerRuntimePublishProjectionService::PROJECTION_KIND,
            'projection_version' => CareerRuntimePublishProjectionService::PROJECTION_VERSION,
            'source_authority' => 'CareerFullReleaseLedger',
            'items' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectionItem(
        string $slug,
        string $locale,
        string $state = CareerRuntimePublishProjectionService::STATE_PUBLISHED,
        array $overrides = [],
    ): array {
        $published = $state === CareerRuntimePublishProjectionService::STATE_PUBLISHED;

        return array_merge([
            'slug' => $slug,
            'locale' => $locale,
            'public_resolution_type' => CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB,
            'runtime_publish_state' => $state,
            'detail_route_enabled' => $published,
            'dataset_visible' => $published,
            'search_visible' => $published,
            'sitemap_live' => $published,
            'llms_live' => $published,
            'llms_full_live' => $published,
            'canonical_url' => $published ? 'https://fermatmind.com/'.$locale.'/career/jobs/'.$slug : null,
            'canonical_self' => $published,
            'robots_indexable' => $published,
            'release_gate_pass' => $published,
            'blockers' => [],
        ], $overrides);
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

    private function createCareerJob(string $slug, string $title, string $locale): CareerJob
    {
        return CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => $locale,
            'title' => $title,
            'excerpt' => $title,
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => Carbon::create(2026, 1, 31, 12, 45, 0),
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'created_at' => Carbon::create(2026, 1, 31, 12, 44, 0),
            'updated_at' => Carbon::create(2026, 1, 31, 12, 45, 0),
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
