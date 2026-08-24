<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

final class CareerSitemapDisplayAssetVersionCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_v43_adds_no_url_loss_while_v42_remains_readable(): void
    {
        config(['app.frontend_url' => 'https://fermatmind.com']);
        $this->asset('legacy-career', 'v4.2', CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER);
        $this->asset('current-career', 'v4.3', CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER);

        $method = new ReflectionMethod(SitemapGenerator::class, 'getDisplayAssetCareerJobDetailUrls');
        $urls = $method->invoke(app(SitemapGenerator::class));
        $locations = array_column($urls, 'loc');
        sort($locations, SORT_STRING);

        self::assertSame([
            'https://fermatmind.com/en/career/jobs/current-career',
            'https://fermatmind.com/en/career/jobs/legacy-career',
            'https://fermatmind.com/zh/career/jobs/current-career',
            'https://fermatmind.com/zh/career/jobs/legacy-career',
        ], $locations);
    }

    /** @param list<string> $order */
    private function asset(string $slug, string $version, array $order): void
    {
        $family = OccupationFamily::query()->firstOrCreate(
            ['canonical_slug' => 'version-compatibility'],
            ['title_en' => 'Version compatibility', 'title_zh' => '版本兼容'],
        );
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $slug,
            'canonical_title_zh' => $slug,
            'search_h1_zh' => $slug,
            'task_prototype_signature' => [],
            'trust_inheritance_scope' => [],
        ]);
        CareerJobDisplayAsset::query()->create([
            'occupation_id' => $occupation->id,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => $version,
            'template_version' => $version,
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => $order,
            'page_payload_json' => ['en' => [], 'zh' => []],
            'seo_payload_json' => [],
            'sources_json' => [],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
        ]);
    }
}
