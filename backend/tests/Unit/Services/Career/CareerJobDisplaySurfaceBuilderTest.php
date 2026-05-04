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

    private const PILOT_SLUGS = [
        'actors' => ['soc' => '27-2011', 'onet' => '27-2011.00', 'title' => 'Actors'],
        'data-scientists' => ['soc' => '15-2051', 'onet' => '15-2051.00', 'title' => 'Data Scientists'],
        'registered-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.00', 'title' => 'Registered Nurses'],
        'accountants-and-auditors' => ['soc' => '13-2011', 'onet' => '13-2011.00', 'title' => 'Accountants and Auditors'],
        'actuaries' => ['soc' => '15-2011', 'onet' => '15-2011.00', 'title' => 'Actuaries'],
        'financial-analysts' => ['soc' => '13-2051', 'onet' => '13-2051.00', 'title' => 'Financial Analysts'],
        'high-school-teachers' => ['soc' => '25-2031', 'onet' => '25-2031.00', 'title' => 'High School Teachers'],
        'market-research-analysts' => ['soc' => '13-1161', 'onet' => '13-1161.00', 'title' => 'Market Research Analysts'],
        'architectural-and-engineering-managers' => ['soc' => '11-9041', 'onet' => '11-9041.00', 'title' => 'Architectural and Engineering Managers'],
        'civil-engineers' => ['soc' => '17-2051', 'onet' => '17-2051.00', 'title' => 'Civil Engineers'],
        'biomedical-engineers' => ['soc' => '17-2031', 'onet' => '17-2031.00', 'title' => 'Biomedical Engineers'],
        'dentists' => ['soc' => '29-1021', 'onet' => '29-1021.00', 'title' => 'Dentists'],
    ];

    private const COMPONENT_ORDER = [
        'breadcrumb',
        'hero',
        'fermat_decision_card',
        'primary_cta',
        'career_snapshot_primary_locale',
        'career_snapshot_secondary_locale',
        'fit_decision_checklist',
        'riasec_fit_block',
        'personality_fit_block',
        'definition_block',
        'responsibilities_block',
        'work_context_block',
        'market_signal_card',
        'adjacent_career_comparison_table',
        'ai_impact_table',
        'career_risk_cards',
        'contract_project_risk_block',
        'next_steps_block',
        'faq_block',
        'related_next_pages',
        'source_card',
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

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
        $this->assertCount(24, $surface['component_order']);
        $this->assertContains('fermat_decision_card', $surface['component_order']);
    }

    public function test_it_returns_surface_for_selected_second_pilot_slugs(): void
    {
        foreach (['data-scientists', 'registered-nurses', 'accountants-and-auditors'] as $slug) {
            $occupation = $this->createOccupation($slug);
            $this->createDisplayAsset($occupation);

            $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

            $this->assertIsArray($surface);
            $this->assertSame($slug, $surface['subject']['canonical_slug']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['soc'], $surface['subject']['soc_code']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['onet'], $surface['subject']['onet_code']);
        }
    }

    public function test_it_returns_surface_for_selected_d5_slugs(): void
    {
        foreach ([
            'actuaries',
            'financial-analysts',
            'high-school-teachers',
            'market-research-analysts',
            'architectural-and-engineering-managers',
            'civil-engineers',
            'biomedical-engineers',
            'dentists',
        ] as $slug) {
            $occupation = $this->createOccupation($slug);
            $this->createDisplayAsset($occupation);

            $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

            $this->assertIsArray($surface);
            $this->assertSame($slug, $surface['subject']['canonical_slug']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['soc'], $surface['subject']['soc_code']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['onet'], $surface['subject']['onet_code']);
            $this->assertSame('display.surface.v1', $surface['surface_version']);
            $this->assertCount(24, $surface['component_order']);
        }
    }

    public function test_it_returns_null_for_non_selected_slug(): void
    {
        $occupation = $this->createOccupation('veterinary-technologists-and-technicians');
        $this->createDisplayAsset($occupation);

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

    public function test_it_returns_null_if_asset_type_is_not_public_display(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, ['asset_type' => 'career_job_internal_review']);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_null_if_asset_slug_does_not_match_occupation(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, ['canonical_slug' => 'actors']);

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
        $this->assertStringNotContainsString('release_gates', $encoded);
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
        $meta = self::PILOT_SLUGS[$slug] ?? [
            'soc' => '29-2056',
            'onet' => '29-2056.00',
            'title' => 'Veterinary Technologists and Technicians',
        ];

        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'performing-arts-'.$slug,
            'title_en' => 'Career Family',
            'title_zh' => '职业族群',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => $meta['title'],
            'canonical_title_zh' => $meta['title'],
            'search_h1_zh' => $meta['title'],
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => $meta['soc'],
            'source_title' => $meta['title'],
            'mapping_type' => 'direct_match',
            'confidence_score' => 1.0,
        ]);
        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => $meta['onet'],
            'source_title' => $meta['title'],
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
            'component_order_json' => self::COMPONENT_ORDER,
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'boundary_notice' => [
                        'release_gate' => ['do_not_show' => true],
                        'release_gates' => [
                            'sitemap' => false,
                            'llms' => false,
                        ],
                    ],
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
