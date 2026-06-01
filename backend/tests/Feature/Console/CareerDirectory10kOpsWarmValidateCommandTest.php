<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerDirectory10kOpsWarmValidateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['app.frontend_url' => 'https://fermatmind.com']);
        config(['app.url' => 'https://fermatmind.com']);
    }

    public function test_command_validates_directory_sitemap_and_10k_scale_budget(): void
    {
        $this->createDirectoryOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师');
        $this->createDirectoryOccupation('actors', 'Actors', '演员');
        $this->createDirectoryOccupation('software-developers', 'Software Developers', '软件开发人员');
        $this->publishRuntimeProjection(['accountants-and-auditors', 'actors', 'software-developers']);

        $exitCode = Artisan::call('career:validate-directory-10k-scale-readiness', [
            '--expected-public-count' => 2,
            '--expected-sitemap-career-urls' => 4,
            '--synthetic-count' => 10000,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $report = json_decode((string) Artisan::output(), true);

        $this->assertSame('CAREER-DIRECTORY-10K-OPS-WARM-VALIDATE-01', $report['task']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame(2, $report['public_detail_indexable_count_en']);
        $this->assertSame(2, $report['public_detail_indexable_count_zh_cn']);
        $this->assertSame(4, $report['sitemap_career_detail_url_count']);
        $this->assertSame([], $report['leaked_excluded_slugs']);
        $this->assertSame([], $report['forbidden_item_fields']);
        $this->assertSame(10000, $report['synthetic_scale_budget']['target_directory_count']);
        $this->assertSame(20000, $report['synthetic_scale_budget']['target_bilingual_detail_url_count']);
        $this->assertFalse($report['synthetic_scale_budget']['full_directory_ssr_rendering_allowed']);
        $this->assertFalse($report['production_write_performed']);
        $this->assertFalse($report['runtime_promotion_performed']);
        $this->assertFalse($report['search_channel_action_performed']);
    }

    public function test_command_fails_when_expected_counts_do_not_match(): void
    {
        $this->createDirectoryOccupation('actuaries', 'Actuaries', '精算师');
        $this->publishRuntimeProjection(['actuaries']);

        $exitCode = Artisan::call('career:validate-directory-10k-scale-readiness', [
            '--expected-public-count' => 1046,
            '--expected-sitemap-career-urls' => 2092,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $report = json_decode((string) Artisan::output(), true);

        $this->assertSame('failed', $report['status']);
        $this->assertContains('expected_public_count_mismatch', $report['errors']);
        $this->assertContains('expected_sitemap_career_url_count_mismatch', $report['errors']);
        $this->assertFalse($report['production_write_performed']);
    }

    private function createDirectoryOccupation(string $slug, string $titleEn, string $titleZh): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'business-finance'],
            [
                'title_en' => 'Business and Finance',
                'title_zh' => '商业与金融',
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
     * @param  list<string>  $slugs
     */
    private function publishRuntimeProjection(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            $published = $slug !== 'software-developers';
            foreach (['en', 'zh'] as $locale) {
                $items[$slug.'|'.$locale] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'dataset_visible' => $published,
                    'search_visible' => $published,
                    'detail_route_enabled' => $published,
                    'robots_indexable' => $published,
                    'release_gate_pass' => $published,
                    'runtime_publish_state' => $published ? 'published' : 'quarantined',
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
                items: $items,
            ),
        );

        Cache::flush();
    }
}
