<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        'web-developers' => ['soc' => '15-1254', 'onet' => '15-1254.00', 'title' => 'Web Developers'],
        'marketing-managers' => ['soc' => '11-2021', 'onet' => '11-2021.00', 'title' => 'Marketing Managers'],
        'lawyers' => ['soc' => '23-1011', 'onet' => '23-1011.00', 'title' => 'Lawyers'],
        'pharmacists' => ['soc' => '29-1051', 'onet' => '29-1051.00', 'title' => 'Pharmacists'],
        'acupuncturists' => ['soc' => '29-1291', 'onet' => '29-1291.00', 'title' => 'Acupuncturists'],
        'business-intelligence-analysts' => ['soc' => '15-2051', 'onet' => '15-2051.01', 'title' => 'Business Intelligence Analysts'],
        'clinical-data-managers' => ['soc' => '15-2051', 'onet' => '15-2051.02', 'title' => 'Clinical Data Managers'],
        'budget-analysts' => ['soc' => '13-2031', 'onet' => '13-2031.00', 'title' => 'Budget Analysts'],
        'human-resources-managers' => ['soc' => '11-3121', 'onet' => '11-3121.00', 'title' => 'Human Resources Managers'],
        'administrative-services-managers' => ['soc' => '11-3012', 'onet' => '11-3012.00', 'title' => 'Administrative Services Managers'],
        'advertising-and-promotions-managers' => ['soc' => '11-2011', 'onet' => '11-2011.00', 'title' => 'Advertising and Promotions Managers'],
        'architects' => ['soc' => '17-1011', 'onet' => '17-1011.00', 'title' => 'Architects'],
        'air-traffic-controllers' => ['soc' => '53-2021', 'onet' => '53-2021.00', 'title' => 'Air Traffic Controllers'],
        'airline-and-commercial-pilots' => ['soc' => '53-2011', 'onet' => '53-2011.00', 'title' => 'Airline and Commercial Pilots'],
        'chemists-and-materials-scientists' => ['soc' => '19-2031', 'onet' => '19-2031.00', 'title' => 'Chemists and Materials Scientists'],
        'clinical-laboratory-technologists-and-technicians' => ['soc' => '29-2011', 'onet' => '29-2011.00', 'title' => 'Clinical Laboratory Technologists and Technicians'],
        'community-health-workers' => ['soc' => '21-1094', 'onet' => '21-1094.00', 'title' => 'Community Health Workers'],
        'compensation-and-benefits-managers' => ['soc' => '11-3111', 'onet' => '11-3111.00', 'title' => 'Compensation and Benefits Managers'],
        'career-and-technical-education-teachers' => ['soc' => '25-2032', 'onet' => '25-2032.00', 'title' => 'Career and Technical Education Teachers'],
        'software-developers' => ['soc' => '15-1252', 'onet' => '15-1252.00', 'title' => 'Software Developers'],
    ];

    private const COMPONENT_ORDER = CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;

    public function test_it_returns_surface_for_actors_ready_asset(): void
    {
        $occupation = $this->createOccupation('actors');
        $asset = $this->createDisplayAsset($occupation);

        $this->assertSame(
            CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
            array_keys($asset->page_payload_json['en']),
        );
        $this->assertTrue(CareerDisplayAssetComponentContract::hasExactPagesForVersion($asset->page_payload_json, 'v4.2'));

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertIsArray($surface);
        $this->assertSame('display.surface.v1', $surface['surface_version']);
        $this->assertSame('v4.2', $surface['template_version']);
        $this->assertSame('actors', $surface['subject']['canonical_slug']);
        $this->assertSame('27-2011', $surface['subject']['soc_code']);
        $this->assertSame('27-2011.00', $surface['subject']['onet_code']);
        $this->assertSame('full', $surface['claim_permissions']['integrity_state']);
        $this->assertTrue($surface['claim_permissions']['allow_strong_claim']);
        $this->assertTrue($surface['claim_permissions']['allow_ai_strategy']);
        $this->assertTrue($surface['claim_permissions']['allow_salary_comparison']);
        $this->assertTrue($surface['claim_permissions']['allow_market_signal']);
        $this->assertFalse($surface['claim_permissions']['allow_local_proxy_wage']);
        $this->assertSame('official', $surface['claim_permissions']['evidence_basis']['salary']);
        $this->assertSame('central_score', $surface['claim_permissions']['evidence_basis']['ai_exposure']);
        $this->assertSame('direct', $surface['claim_permissions']['evidence_basis']['crosswalk']);
        $this->assertCount(26, $surface['component_order']);
        $this->assertContains('fermat_decision_card', $surface['component_order']);
    }

    public function test_it_returns_the_v43_structured_components_from_current_authority(): void
    {
        ini_set('memory_limit', '1024M');
        $occupation = $this->createOccupation('accountants-and-auditors');
        $authority = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $row = $authority['rows']['accountants-and-auditors'];
        $this->createDisplayAsset($occupation, app(CareerCurrentAuthorityPackage::class)->databaseAttributes($row));

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('v4.3', $surface['asset_version']);
        $this->assertSame(
            CareerCurrentAuthorityPackage::hashValue(app(CareerCurrentAuthorityPackage::class)->publicProjection($row, 'zh-CN')),
            CareerCurrentAuthorityPackage::hashValue(app(CareerCurrentAuthorityPackage::class)->displayOwnedProjection($surface)),
        );
        $this->assertSame(CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER, $surface['component_order']);
        $this->assertSame(
            ['qa3', 'qa2', 'qa1'],
            array_column($surface['page']['content']['career_quick_answers_block']['items'], 'key'),
        );
        $this->assertSame('published', $surface['page']['content']['onet_structured_fields_block']['availability']);

        $bundle = app(CareerJobDetailBundleBuilder::class)->buildBySlug(
            'accountants-and-auditors',
            'zh-CN',
            [
                'slug' => 'accountants-and-auditors',
                'locale' => 'zh-CN',
                'runtime_publish_state' => 'published',
                'detail_route_enabled' => true,
                'robots_indexable' => true,
                'release_gate_pass' => true,
            ],
        );
        $this->assertNotNull($bundle);
        $payload = (new CareerJobDetailResource($bundle))->toArray(
            Request::create('/api/v0.5/career/jobs/accountants-and-auditors', 'GET', ['locale' => 'zh-CN']),
        );
        $this->assertSame(
            CareerCurrentAuthorityPackage::hashValue(app(CareerCurrentAuthorityPackage::class)->publicProjection($row, 'zh-CN')),
            CareerCurrentAuthorityPackage::hashValue(app(CareerCurrentAuthorityPackage::class)->displayOwnedProjection($payload['display_surface_v1'])),
        );
    }

    public function test_it_exposes_presentation_v1_only_for_the_zh_surface(): void
    {
        ini_set('memory_limit', '1024M');
        $occupation = $this->createOccupation('actuaries');
        $authority = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $presentation = $authority['rows']['actuaries']['metadata_json']['presentation_v1']['zh'];
        $this->createDisplayAsset($occupation, [
            'metadata_json' => ['presentation_v1' => ['zh' => $presentation]],
        ]);

        $zh = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');
        $en = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'en');

        $this->assertSame($presentation, $zh['presentation_v1']);
        $this->assertArrayNotHasKey('presentation_v1', $en);
        $this->assertSame(8, data_get($zh, 'presentation_v1.hero.ai_exposure.value'));
    }

    public function test_it_returns_the_complete_26_component_surface_with_both_workbuddy_blocks(): void
    {
        $occupation = $this->createOccupation('actors');
        $asset = $this->createDisplayAsset($occupation, [
            'component_order_json' => CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER,
        ]);
        $pages = $asset->page_payload_json;
        foreach (['zh', 'en'] as $locale) {
            $pages[$locale]['career_ai_description_block'] = [
                'component' => 'CareerAiDescriptionBlock',
                'heading' => $locale === 'zh' ? 'AI 职业解读' : 'AI Career Analysis',
                'body' => [$locale === 'zh' ? '这是完整职业正文。' : 'This is the complete career body.'],
            ];
            $pages[$locale]['career_path_block'] = [
                'component' => 'CareerPathBlock',
                'heading' => $locale === 'zh' ? '职业发展路径' : 'Career Path',
                'rows' => [['Entry', '0-2 years', 'Skills', 'Reference']],
                'caveat' => 'Reference only.',
                'source_key' => 'career_path_baseline_v1',
            ];
        }
        $asset->forceFill(['page_payload_json' => $pages])->save();

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertIsArray($surface);
        $this->assertSame(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER, $surface['component_order']);
        $this->assertSame('演员职业判断', $surface['page']['content']['hero']['title']);
        $this->assertSame('AI 职业解读', $surface['page']['content']['career_ai_description_block']['heading']);
        $this->assertSame('职业发展路径', $surface['page']['content']['career_path_block']['heading']);
        $this->assertArrayHasKey('market_signal_card', $surface['page']['content']);
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
            $this->assertCount(26, $surface['component_order']);
        }
    }

    public function test_it_returns_surface_for_d8_validator_eligible_slugs(): void
    {
        foreach ([
            'web-developers',
            'marketing-managers',
            'lawyers',
            'pharmacists',
            'acupuncturists',
            'business-intelligence-analysts',
            'clinical-data-managers',
            'budget-analysts',
            'human-resources-managers',
            'administrative-services-managers',
            'advertising-and-promotions-managers',
            'architects',
            'air-traffic-controllers',
            'airline-and-commercial-pilots',
            'chemists-and-materials-scientists',
            'clinical-laboratory-technologists-and-technicians',
            'community-health-workers',
            'compensation-and-benefits-managers',
            'career-and-technical-education-teachers',
        ] as $slug) {
            $occupation = $this->createOccupation($slug);
            $this->createDisplayAsset($occupation);

            $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

            $this->assertIsArray($surface);
            $this->assertSame($slug, $surface['subject']['canonical_slug']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['soc'], $surface['subject']['soc_code']);
            $this->assertSame(self::PILOT_SLUGS[$slug]['onet'], $surface['subject']['onet_code']);
            $this->assertSame('display.surface.v1', $surface['surface_version']);
            $this->assertSame('v4.2', $surface['asset_version']);
            $this->assertCount(26, $surface['component_order']);
        }
    }

    public function test_it_returns_null_for_manual_hold_slug_even_with_display_asset(): void
    {
        $occupation = $this->createOccupation('software-developers');
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_null_for_missing_display_asset(): void
    {
        $occupation = $this->createOccupation('veterinary-technologists-and-technicians');

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

    public function test_it_returns_null_if_asset_versions_are_not_v42(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'asset_version' => 'v4.3',
            'template_version' => 'v4.3',
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_fails_closed_for_two_formal_rows_instead_of_selecting_by_asset_version(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation);
        $this->createDisplayAsset($occupation, [
            'asset_version' => 'v4.1',
            'template_version' => 'v4.1',
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_null_if_component_order_is_not_complete(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'component_order_json' => array_slice(self::COMPONENT_ORDER, 0, 23),
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
    }

    public function test_it_returns_null_if_product_schema_is_present(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'structured_data_json' => [
                '@type' => 'Product',
                'name' => 'Unsafe product schema',
            ],
        ]);

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

    public function test_it_localizes_internal_hrefs_to_the_requested_locale(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'primary_cta' => [
                        'label' => '开始职业兴趣测试',
                        'href' => '/en/tests/holland-career-interest-test-riasec | /zh/tests/holland-career-interest-test-riasec',
                    ],
                    'final_cta' => [
                        'label' => '继续探索职业',
                        'href' => '/en/career',
                    ],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                    'primary_cta' => [
                        'label' => 'Start the career interest test',
                        'href' => '/zh/tests/holland-career-interest-test-riasec | /en/tests/holland-career-interest-test-riasec',
                    ],
                    'final_cta' => [
                        'label' => 'Explore careers',
                        'href' => '/zh/career',
                    ],
                ],
            ],
        ]);

        $zhSurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');
        $enSurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'en');

        $this->assertSame('/zh/tests/holland-career-interest-test-riasec', $zhSurface['page']['content']['primary_cta']['href']);
        $this->assertSame('/zh/career', $zhSurface['page']['content']['final_cta']['href']);
        $this->assertSame('/en/tests/holland-career-interest-test-riasec', $enSurface['page']['content']['primary_cta']['href']);
        $this->assertSame('/en/career', $enSurface['page']['content']['final_cta']['href']);
    }

    public function test_it_holds_zh_surface_when_core_hero_is_english_without_blocking_en(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => 'Data Scientists'],
                    'primary_cta' => ['label' => 'Start career fit test'],
                    'faq_block' => [
                        'items' => [
                            ['q' => 'What do data scientists do?', 'a' => 'They build models from data.'],
                        ],
                    ],
                ],
                'en' => [
                    'hero' => ['title' => 'Data scientist career fit'],
                    'primary_cta' => ['label' => 'Start career fit test'],
                ],
            ],
        ]);

        $zhSurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');
        $enSurface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'en');

        $this->assertNull($zhSurface);
        $this->assertIsArray($enSurface);
        $this->assertSame('en', $enSurface['page']['locale']);
        $this->assertSame('Data scientist career fit', $enSurface['page']['content']['hero']['title']);
    }

    public function test_it_holds_zh_surface_when_locale_readiness_marks_it_not_ready(): void
    {
        $occupation = $this->createOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'metadata_json' => [
                'locale_readiness' => [
                    'zh-CN' => ['status' => 'not_ready'],
                ],
            ],
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertNull($surface);
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

    public function test_it_blocks_ai_strategy_when_ai_exposure_evidence_is_missing(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'market_signal_card' => [
                        'salary_data_type' => 'BLS official wage evidence',
                        'body' => 'Official wage signal from BLS.',
                    ],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                    'market_signal_card' => [
                        'salary_data_type' => 'BLS official wage evidence',
                        'body' => 'Official wage signal from BLS.',
                    ],
                ],
            ],
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('provisional', $surface['claim_permissions']['integrity_state']);
        $this->assertFalse($surface['claim_permissions']['allow_ai_strategy']);
        $this->assertSame('missing', $surface['claim_permissions']['evidence_basis']['ai_exposure']);
        $this->assertContains('ai_strategy_missing_ai_exposure', $surface['claim_permissions']['blocked_claims']);
    }

    public function test_it_blocks_salary_comparison_for_proxy_wage_sources(): void
    {
        $occupation = $this->createOccupation('actors');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => '演员职业判断'],
                    'market_signal_card' => [
                        'salary_data_type' => 'CN industry proxy wage',
                        'body' => 'Local proxy wage signal.',
                    ],
                    'ai_impact_table' => [
                        'score_normalized' => '82',
                        'label' => 'medium',
                        'source' => 'FermatMind central score',
                    ],
                ],
                'en' => [
                    'hero' => ['title' => 'Actor career fit'],
                    'market_signal_card' => [
                        'salary_data_type' => 'CN industry proxy wage',
                        'body' => 'Local proxy wage signal.',
                    ],
                    'ai_impact_table' => [
                        'score_normalized' => '82',
                        'label' => 'medium',
                        'source' => 'FermatMind central score',
                    ],
                ],
            ],
        ]);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('provisional', $surface['claim_permissions']['integrity_state']);
        $this->assertFalse($surface['claim_permissions']['allow_salary_comparison']);
        $this->assertFalse($surface['claim_permissions']['allow_local_proxy_wage']);
        $this->assertSame('proxy', $surface['claim_permissions']['evidence_basis']['salary']);
        $this->assertContains('salary_comparison_proxy_wage_not_direct_fact', $surface['claim_permissions']['blocked_claims']);
    }

    public function test_it_blocks_strong_claims_for_proxy_crosswalks(): void
    {
        $occupation = $this->createOccupation('actors');
        $occupation->update(['crosswalk_mode' => 'local_heavy_interpretation']);
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('provisional', $surface['claim_permissions']['integrity_state']);
        $this->assertFalse($surface['claim_permissions']['allow_strong_claim']);
        $this->assertSame('proxy', $surface['claim_permissions']['evidence_basis']['crosswalk']);
        $this->assertContains('strong_claim_crosswalk_not_direct', $surface['claim_permissions']['blocked_claims']);
    }

    public function test_it_blocks_strong_claims_for_trust_inheritance_crosswalks(): void
    {
        $occupation = $this->createOccupation('actors');
        $occupation->update(['crosswalk_mode' => 'trust_inheritance']);
        $this->createDisplayAsset($occupation);

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('provisional', $surface['claim_permissions']['integrity_state']);
        $this->assertFalse($surface['claim_permissions']['allow_strong_claim']);
        $this->assertSame('trust_inheritance', $surface['claim_permissions']['evidence_basis']['crosswalk']);
        $this->assertContains('strong_claim_crosswalk_not_direct', $surface['claim_permissions']['blocked_claims']);
    }

    public function test_it_blocks_strong_claims_when_direct_mode_lacks_trusted_dual_crosswalks(): void
    {
        $occupation = $this->createOccupation('actors');
        $occupation->crosswalks()
            ->where('source_system', 'onet_soc_2019')
            ->update(['confidence_score' => 0.5]);
        $this->createDisplayAsset($occupation->refresh());

        $surface = app(CareerJobDisplaySurfaceBuilder::class)->buildForOccupation($occupation, 'zh-CN');

        $this->assertSame('provisional', $surface['claim_permissions']['integrity_state']);
        $this->assertFalse($surface['claim_permissions']['allow_strong_claim']);
        $this->assertSame('missing', $surface['claim_permissions']['evidence_basis']['crosswalk']);
        $this->assertContains('strong_claim_crosswalk_not_direct', $surface['claim_permissions']['blocked_claims']);
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
        $attributes = array_replace([
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
                    'market_signal_card' => [
                        'salary_data_type' => 'BLS official wage evidence',
                        'body' => 'Official wage signal from BLS.',
                    ],
                    'ai_impact_table' => [
                        'score_normalized' => '82',
                        'label' => 'medium',
                        'source' => 'FermatMind central score',
                    ],
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
                    'market_signal_card' => [
                        'salary_data_type' => 'BLS official wage evidence',
                        'body' => 'Official wage signal from BLS.',
                    ],
                    'ai_impact_table' => [
                        'score_normalized' => '82',
                        'label' => 'medium',
                        'source' => 'FermatMind central score',
                    ],
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
        ], $overrides);

        $payload = is_array($attributes['page_payload_json'] ?? null) ? $attributes['page_payload_json'] : [];
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $componentOrder = ($attributes['asset_version'] ?? null) === 'v4.3'
            ? CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER
            : CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER;
        foreach (['en', 'zh'] as $locale) {
            if (is_array($pages[$locale] ?? null)) {
                $pages[$locale] = array_replace(
                    array_fill_keys($componentOrder, []),
                    $pages[$locale],
                );
            }
        }
        $attributes['page_payload_json'] = is_array($payload['page'] ?? null)
            ? ['page' => $pages]
            : $pages;

        return CareerJobDisplayAsset::query()->create($attributes);
    }
}
