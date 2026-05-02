<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerJobDisplaySurfaceBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_surface_for_actors_ready_asset(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertIsArray($surface);
        $this->assertSame('display.surface.v1', $surface['surface_version']);
        $this->assertSame('v4.2', $surface['template_version']);
        $this->assertSame('actors', $surface['subject']['canonical_slug']);
        $this->assertSame('27-2011', $surface['subject']['soc_code']);
        $this->assertSame('27-2011.00', $surface['subject']['onet_code']);
        $this->assertContains('fermat_decision_card', $surface['component_order']);
    }

    public function test_it_returns_null_for_non_actors(): void
    {
        $occupation = $this->createOccupation('accountants-and-auditors');
        $this->createDisplayAsset($occupation, ['canonical_slug' => 'accountants-and-auditors']);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_null_if_asset_status_is_not_ready_for_pilot(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation, ['status' => 'needs_source_code']);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_zh_content_for_zh_cn_locale(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('zh-CN', $surface['page']['locale']);
        $this->assertSame('演员职业判断', $surface['page']['content']['hero']['title']);
    }

    public function test_it_returns_en_content_for_en_locale(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'en');

        $this->assertSame('en', $surface['page']['locale']);
        $this->assertSame('Actor career fit', $surface['page']['content']['hero']['title']);
    }

    public function test_it_returns_null_when_requested_locale_is_missing(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                ],
            ],
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'en');

        $this->assertNull($surface);
    }

    public function test_it_strips_forbidden_fields(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $encoded = json_encode($surface, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('release_gate', $encoded);
        $this->assertStringNotContainsString('qa_risk', $encoded);
        $this->assertStringNotContainsString('admin_review_state', $encoded);
        $this->assertStringNotContainsString('tracking_json', $encoded);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
    }

    public function test_it_includes_sources_and_implementation_contract(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('BLS Occupational Outlook Handbook', $surface['sources']['primary'][0]['label']);
        $this->assertSame('visible_content_only', $surface['implementation_contract']['structured_data_policy']);
    }

    private function createOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'performing-arts-'.$slug,
            'title_en' => 'Performing Arts',
            'title_zh' => '表演艺术',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Actors',
            'canonical_title_zh' => '演员',
            'search_h1_zh' => '演员职业诊断',
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => '27-2011',
            'source_title' => 'Actors',
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => '27-2011.00',
            'source_title' => 'Actors',
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);

        return $occupation;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDisplayAsset(Occupation $occupation, array $overrides = []): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create(array_replace([
            'occupation_id' => $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => ['hero', 'fermat_decision_card', 'evidence_container'],
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'release_gate' => ['do_not_show' => true],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                    'tracking_json' => ['do_not_show' => true],
                ],
            ],
            'sources_json' => [
                'primary' => [
                    ['label' => 'BLS Occupational Outlook Handbook', 'url' => 'https://example.test/bls'],
                ],
                'raw_ai_exposure_score' => 8.2,
            ],
            'structured_data_json' => [
                '@type' => 'Occupation',
                'name' => 'Actors',
                'qa_risk' => ['do_not_show' => true],
            ],
            'implementation_contract_json' => [
                'structured_data_policy' => 'visible_content_only',
                'admin_review_state' => 'do_not_show',
            ],
            'metadata_json' => [
                'validator_version' => 'career_asset_import_validator_v0.1',
            ],
        ], $overrides));
    }
}
