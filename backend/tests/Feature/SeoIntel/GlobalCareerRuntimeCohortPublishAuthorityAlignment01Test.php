<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class GlobalCareerRuntimeCohortPublishAuthorityAlignment01Test extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_detail_and_seo_authority_are_aligned_for_public_en_and_zh_routes(): void
    {
        $this->configureRuntimeProjection(['runtime-aligned-job' => true]);
        $this->createRuntimeOccupation('runtime-aligned-job');

        $this->getJson('/api/v0.5/career/jobs/runtime-aligned-job?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'runtime-aligned-job')
            ->assertJsonPath('seo_contract.canonical_path', '/en/career/jobs/runtime-aligned-job')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow');

        $this->getJson('/api/v0.5/career/jobs/runtime-aligned-job?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'runtime-aligned-job')
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/runtime-aligned-job')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow');

        foreach ([
            'en' => '/en/career/jobs/runtime-aligned-job',
            'zh-CN' => '/zh/career/jobs/runtime-aligned-job',
        ] as $locale => $canonicalPath) {
            $response = $this->getJson('/api/v0.5/career-jobs/runtime-aligned-job/seo?locale='.$locale.'&org_id=0')
                ->assertOk()
                ->assertJsonPath('meta.robots', 'index,follow')
                ->assertJsonPath('meta.canonical', $canonicalPath)
                ->assertJsonPath('seo_surface_v1.index_eligible', true)
                ->assertJsonPath('seo_surface_v1.index_state', 'indexable')
                ->assertJsonPath('seo_surface_v1.indexability_state', 'indexable')
                ->assertJsonPath('seo_surface_v1.sitemap_state', 'included')
                ->assertJsonPath('seo_surface_v1.llms_exposure_state', 'allow');

            $this->assertContains('Occupation', $response->json('seo_surface_v1.structured_data_keys'));
        }
    }

    public function test_seo_authority_fails_closed_when_runtime_detail_route_is_blocked_even_if_cms_row_exists(): void
    {
        $this->configureRuntimeProjection(['software-developers' => false]);
        $this->createPublishedDocxCareerJob('software-developers');

        $this->getJson('/api/v0.5/career/jobs/software-developers?locale=zh-CN')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->getJson('/api/v0.5/career-jobs/software-developers/seo?locale=zh-CN&org_id=0')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not found');
    }

    public function test_alignment_report_artifact_records_no_publish_or_search_mutation(): void
    {
        $path = base_path('docs/seo/generated/global-career-runtime-cohort-publish-authority-alignment-01.v1.json');

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('GLOBAL-CAREER-RUNTIME-COHORT-PUBLISH-AUTHORITY-ALIGNMENT-01', $payload['task']);
        $this->assertSame('career_publish_authority_alignment_completed_ready_for_1048_dry_run', $payload['final_decision']);
        $this->assertTrue($payload['no_cms_mutation']);
        $this->assertTrue($payload['no_deploy']);
        $this->assertTrue($payload['no_search_channel_action']);
        $this->assertTrue($payload['no_url_submission']);
        $this->assertFalse($payload['cohort_publish_performed']);
        $this->assertSame('DETAIL_READY_1048_ROLLOUT_DRY_RUN-01', $payload['next_task']);
    }

    /**
     * @param  array<string,bool>  $visibilityBySlug
     */
    private function configureRuntimeProjection(array $visibilityBySlug): void
    {
        $detailRouteEnabled = [];
        $robotsIndexable = [];
        $releaseGatePass = [];
        $items = [];

        foreach ($visibilityBySlug as $slug => $enabled) {
            $normalizedSlug = strtolower(trim($slug));
            $detailRouteEnabled[$normalizedSlug] = $enabled;
            $robotsIndexable[$normalizedSlug] = $enabled;
            $releaseGatePass[$normalizedSlug] = $enabled;

            foreach (['en', 'zh'] as $locale) {
                $items[$normalizedSlug.'|'.$locale] = [
                    'slug' => $normalizedSlug,
                    'locale' => $locale,
                    'dataset_visible' => $enabled,
                    'search_visible' => $enabled,
                    'detail_route_enabled' => $enabled,
                    'robots_indexable' => $enabled,
                    'release_gate_pass' => $enabled,
                    'runtime_publish_state' => $enabled ? 'published' : 'blocked',
                ];
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                detailRouteEnabled: $detailRouteEnabled,
                robotsIndexable: $robotsIndexable,
                releaseGatePass: $releaseGatePass,
                items: $items,
            ),
        );
    }

    private function createRuntimeOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => $slug.'-family',
            'title_en' => 'Runtime Family',
            'title_zh' => '运行时职业族',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Runtime Aligned Job',
            'canonical_title_zh' => '运行时对齐职业',
            'search_h1_zh' => '运行时对齐职业',
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
        ]);
    }

    private function createPublishedDocxCareerJob(string $slug): CareerJob
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => '软件开发、质量保证分析与测试人员',
            'subtitle' => 'Software Developers',
            'excerpt' => 'Published DOCX fallback fixture.',
            'body_md' => '# Published DOCX fallback fixture',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'salary_json' => ['annual_median_usd' => 132270],
            'outlook_json' => ['jobs_2024' => 2000000],
            'growth_path_json' => ['raw' => ['Fixture growth path.']],
            'market_demand_json' => [
                'ai_exposure_score_10' => 6,
                'source_refs' => [
                    [
                        'label' => 'BLS Occupational Outlook Handbook',
                        'url' => 'https://www.bls.gov/ooh/computer-and-information-technology/software-developers.htm',
                    ],
                ],
            ],
        ]);

        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'robots' => 'index,follow',
            'jsonld_overrides_json' => [
                'source_docx' => $slug.'.docx',
            ],
        ]);

        return $job;
    }
}
