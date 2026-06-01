<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerSitemapExposureDirectoryAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['app.frontend_url' => 'https://fermatmind.com']);
    }

    public function test_career_detail_sitemap_urls_are_enumerated_from_directory_authority(): void
    {
        $this->createOccupation('accountants-and-auditors', 'Accountants and Auditors', '会计师与审计师');
        $this->createOccupation('actuaries', 'Actuaries', '精算师');
        $this->createDisplayAsset($this->createOccupation('display-asset-only', 'Display Asset Only', '仅展示资产'));
        $this->createOccupation('software-developers', 'Software Developers', '软件开发人员');

        $this->publishRuntimeProjection(['accountants-and-auditors', 'actuaries', 'software-developers']);

        $urls = app(SitemapGenerator::class)->generateApprovedCareerJobDetailUrls();
        $locs = array_values(array_map(static fn (array $url): string => (string) ($url['loc'] ?? ''), $urls));
        sort($locs, SORT_STRING);

        $this->assertSame([
            'https://fermatmind.com/en/career/jobs/accountants-and-auditors',
            'https://fermatmind.com/en/career/jobs/actuaries',
            'https://fermatmind.com/zh/career/jobs/accountants-and-auditors',
            'https://fermatmind.com/zh/career/jobs/actuaries',
        ], $locs);

        $xml = (string) app(SitemapGenerator::class)->generate()['xml'];
        $this->assertStringContainsString('https://fermatmind.com/en/career/jobs/accountants-and-auditors', $xml);
        $this->assertStringContainsString('https://fermatmind.com/zh/career/jobs/actuaries', $xml);
        $this->assertStringNotContainsString('display-asset-only', $xml);
        $this->assertStringNotContainsString('software-developers', $xml);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function publishRuntimeProjection(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            $items[$slug.'|en'] = [
                'slug' => $slug,
                'locale' => 'en',
                'dataset_visible' => true,
                'search_visible' => true,
                'detail_route_enabled' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
                'runtime_publish_state' => 'published',
            ];
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

    private function createOccupation(string $slug, string $titleEn, string $titleZh): Occupation
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'business-finance'],
            [
                'title_en' => 'Business and Finance',
                'title_zh' => '商业与金融',
            ],
        );

        return Occupation::query()->create([
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
    }

    private function createDisplayAsset(Occupation $occupation): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create([
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
        ]);
    }
}
