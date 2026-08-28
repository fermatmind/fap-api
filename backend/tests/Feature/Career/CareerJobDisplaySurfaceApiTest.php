<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDisplaySurfaceApiTest extends TestCase
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

    private const COMPONENT_ORDER = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultItemPublished: true,
                detailRouteEnabled: [
                    'software-developers' => false,
                ],
            ),
        );
    }

    public function test_it_adds_display_surface_for_eligible_actors_asset(): void
    {
        $occupation = $this->seedCompiledOccupation('actors');
        $this->addCrosswalks($occupation, 'actors');
        $this->createDisplayAsset($occupation);

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/actors?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'actors')
            ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
            ->assertJsonMissingPath('display_surface_v1.template_version')
            ->assertJsonPath('display_surface_v1.subject.canonical_slug', 'actors')
            ->assertJsonPath('display_surface_v1.subject.soc_code', '27-2011')
            ->assertJsonPath('display_surface_v1.subject.onet_code', '27-2011.00')
            ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'full')
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_ai_strategy', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_salary_comparison', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_market_signal', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_local_proxy_wage', false)
            ->assertJsonPath('display_surface_v1.claim_permissions.evidence_basis.ai_exposure', 'central_score')
            ->assertJsonPath('display_surface_v1.claim_permissions.evidence_basis.salary', 'official')
            ->assertJsonPath('display_surface_v1.claim_permissions.evidence_basis.crosswalk', 'direct')
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.page.content.hero.title', '演员职业判断');

        $this->assertContains('fermat_decision_card', $response->json('display_surface_v1.component_order'));
        $this->assertTrue(CareerDisplayAssetComponentContract::supports($response->json('display_surface_v1.component_order')));
        $this->assertIsArray($response->json('display_surface_v1.page.content.hero'));

        $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('release_gate', $encoded);
        $this->assertStringNotContainsString('release_gates', $encoded);
        $this->assertStringNotContainsString('qa_risk', $encoded);
        $this->assertStringNotContainsString('admin_review_state', $encoded);
        $this->assertStringNotContainsString('tracking_json', $encoded);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
    }

    public function test_v43_api_returns_authoritative_quick_answers_and_onet_rows(): void
    {
        ini_set('memory_limit', '1024M');
        $occupation = $this->seedCompiledOccupation('accountants-and-auditors');
        $this->addCrosswalks($occupation, 'accountants-and-auditors');
        $package = app(CareerCurrentAuthorityPackage::class);
        $row = $package->load(base_path())['rows']['accountants-and-auditors'];
        $this->createDisplayAsset($occupation, $package->databaseAttributes($row));

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=zh-CN')
            ->assertOk()
            ->assertJsonMissingPath('display_surface_v1.asset_version')
            ->assertJsonMissingPath('display_surface_v1.template_version')
            ->assertJsonPath('display_surface_v1.page.content.career_quick_answers_block.availability', 'published')
            ->assertJsonPath('display_surface_v1.page.content.onet_structured_fields_block.availability', 'published')
            ->assertJsonPath('display_surface_v1.presentation_v2.contract_version', 'career.detail.presentation.v2')
            ->assertJsonPath('display_surface_v1.presentation_v2.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.presentation_v2.groups.0.content_state', 'enhanced')
            ->assertJsonPath('display_surface_v1.content_v3.contract_version', 'career.detail.content.v3')
            ->assertJsonPath('display_surface_v1.content_v3.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.content_v3.content_state', 'enhanced');

        $this->assertSame(
            $row['component_order_json'],
            $response->json('display_surface_v1.component_order'),
        );
        $this->assertSame(
            ['qa3', 'qa2', 'qa1'],
            array_column($response->json('display_surface_v1.page.content.career_quick_answers_block.items'), 'key'),
        );
        $this->assertNotEmpty($response->json('display_surface_v1.structured_data_from_visible_content.faq_page.zh.mainEntity'));
        $this->assertFalse(
            $response->json('display_surface_v1.structured_data_from_visible_content.schema_rules.occupation_schema_generated_locally'),
        );
        $this->assertArrayNotHasKey(
            'onet_structured_fields_block',
            $response->json('display_surface_v1.structured_data_from_visible_content'),
        );

        $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=en')
            ->assertOk()
            ->assertJsonPath('display_surface_v1.presentation_v2.contract_version', 'career.detail.presentation.v2')
            ->assertJsonPath('display_surface_v1.presentation_v2.locale', 'en')
            ->assertJsonPath('display_surface_v1.presentation_v2.groups.0.content_state', 'enhanced')
            ->assertJsonPath('display_surface_v1.content_v3.contract_version', 'career.detail.content.v3')
            ->assertJsonPath('display_surface_v1.content_v3.locale', 'en')
            ->assertJsonPath('display_surface_v1.content_v3.content_state', 'enhanced');
    }

    public function test_it_adds_display_surface_for_selected_second_pilot_assets(): void
    {
        foreach (['data-scientists', 'registered-nurses', 'accountants-and-auditors'] as $slug) {
            $occupation = $this->seedCompiledOccupation($slug);
            $this->addCrosswalks($occupation, $slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonMissingPath('display_surface_v1.template_version')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'full')
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', true)
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_ai_strategy', true)
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_salary_comparison', true)
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_job_detail_projection_does_not_expose_internal_source_ids(): void
    {
        $occupation = $this->seedCompiledOccupation('accountants-and-auditors');
        $this->addCrosswalks($occupation, 'accountants-and-auditors');
        $this->createDisplayAsset($occupation);

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('truth_layer.source_refs.0.source_id')
            ->assertJsonMissingPath('truth_layer.source_refs.0.source_trace_id')
            ->assertJsonMissingPath('provenance_meta.source_trace_id')
            ->assertJsonMissingPath('provenance_meta.compile_refs')
            ->assertJsonMissingPath('provenance_meta.import_run_id')
            ->assertJsonMissingPath('provenance_meta.compile_run_id')
            ->assertJsonMissingPath('provenance_meta.index_state_id');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach ([
            'source_id',
            'source_ids',
            'source_trace_id',
            'evidence_id',
            'row_hash',
            'search_projection',
            'audit_fields',
            'compile_refs',
            'crosswalk_ids',
            'import_run_id',
            'compile_run_id',
            'index_state_id',
        ] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $encoded);
        }
    }

    public function test_job_detail_projection_rewrites_internal_raw_enum_values(): void
    {
        $occupation = $this->seedCompiledOccupation('accountants-and-auditors');
        $this->addCrosswalks($occupation, 'accountants-and-auditors');
        $asset = $this->createDisplayAsset($occupation);

        $pagePayload = $asset->page_payload_json;
        $pagePayload['en']['fermat_decision_card'] = [
            'search_intent_type' => ['career_exploration', 'salary_and_outlook'],
            'body' => 'This source_bounded_reference_only signal is not candidate_only_not_runtime_seo.',
        ];
        $pagePayload['en']['market_signal_card'] = [
            'salary_data_type' => 'industry_proxy_or_recruitment_sample_only',
            'body' => 'Use industry_proxy / recruitment_sample, not raw enum text.',
        ];
        $pagePayload['zh']['fermat_decision_card'] = [
            'search_intent_type' => ['career_exploration', 'salary_and_outlook'],
            'body' => '这里是 source_bounded_reference_only，不应暴露 backend projection review。',
        ];
        $pagePayload['zh']['market_signal_card'] = [
            'salary_data_type' => 'industry_proxy / recruitment_sample',
            'body' => '只应展示 industry_proxy 的读者安全文案。',
        ];
        $asset->forceFill(['page_payload_json' => $pagePayload])->save();

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/accountants-and-auditors?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach ([
            'salary_and_outlook',
            'industry_proxy',
            'source_bounded_reference_only',
            'candidate_only_not_runtime_seo',
            'backend projection review',
            'raw enum',
            'staging_preview_only',
        ] as $rawValue) {
            $this->assertStringNotContainsString($rawValue, $encoded);
        }

        $this->assertStringContainsString('salary and outlook context', $encoded);
        $this->assertStringContainsString('recruitment-market reference', $encoded);
        $this->assertStringContainsString('source-bounded reference only', $encoded);
    }

    public function test_it_adds_display_surface_for_selected_d5_assets(): void
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
            $occupation = $this->seedCompiledOccupation($slug);
            $this->addCrosswalks($occupation, $slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonMissingPath('display_surface_v1.template_version')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'full')
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', true)
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_ai_strategy', true)
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_salary_comparison', true)
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $this->assertContains('fermat_decision_card', $response->json('display_surface_v1.component_order'));
            $this->assertTrue(CareerDisplayAssetComponentContract::supports($response->json('display_surface_v1.component_order')));

            $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_it_adds_display_surface_for_d8_active_assets_by_contract(): void
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
            $occupation = $this->seedCompiledOccupation($slug);
            $this->addCrosswalks($occupation, $slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonMissingPath('display_surface_v1.asset_version')
                ->assertJsonMissingPath('display_surface_v1.template_version')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'full')
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $this->assertTrue(CareerDisplayAssetComponentContract::supports($response->json('display_surface_v1.component_order')));

            $encoded = json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Product', $encoded);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_directory_draft_d8_assets_build_display_asset_backed_bundles_for_blocked_slugs(): void
    {
        foreach ([
            'web-developers',
            'marketing-managers',
            'acupuncturists',
            'business-intelligence-analysts',
            'clinical-data-managers',
            'administrative-services-managers',
            'advertising-and-promotions-managers',
        ] as $slug) {
            $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation($slug);
            $this->createDisplayAsset($occupation);

            $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('identity.entity_level', 'dataset_candidate')
                ->assertJsonPath('locale_policy.crosswalk_mode', 'directory_draft')
                ->assertJsonPath('seo_contract.index_eligible', true)
                ->assertJsonPath('seo_contract.index_state', 'indexable')
                ->assertJsonPath('seo_contract.robots_policy', 'index,follow')
                ->assertJsonPath('seo_contract.reason_codes.0', 'validated_display_asset_backed_release')
                ->assertJsonPath('provenance_meta.logic_version', 'career.protocol.job_detail.display_asset_backed.v1')
                ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
                ->assertJsonMissingPath('display_surface_v1.asset_version')
                ->assertJsonMissingPath('display_surface_v1.template_version')
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.soc_code', self::PILOT_SLUGS[$slug]['soc'])
                ->assertJsonPath('display_surface_v1.subject.onet_code', self::PILOT_SLUGS[$slug]['onet'])
                ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', false)
                ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

            $this->assertTrue(CareerDisplayAssetComponentContract::supports($response->json('display_surface_v1.component_order')));

            $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Product', $encoded);
            $this->assertStringNotContainsString('release_gate', $encoded);
            $this->assertStringNotContainsString('release_gates', $encoded);
            $this->assertStringNotContainsString('qa_risk', $encoded);
            $this->assertStringNotContainsString('admin_review_state', $encoded);
            $this->assertStringNotContainsString('tracking_json', $encoded);
            $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        }
    }

    public function test_approved_display_asset_without_compiled_snapshot_builds_public_detail_bundle(): void
    {
        $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation('architects');
        $occupation->forceFill([
            'crosswalk_mode' => 'direct_match',
        ])->save();

        $this->createDisplayAsset($occupation->refresh());

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/architects?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'architects')
            ->assertJsonPath('locale_policy.crosswalk_mode', 'direct_match')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('seo_contract.index_state', 'indexable')
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow')
            ->assertJsonPath('seo_contract.reason_codes.0', 'validated_display_asset_backed_release')
            ->assertJsonPath('provenance_meta.logic_version', 'career.protocol.job_detail.display_asset_backed.v1')
            ->assertJsonPath('display_surface_v1.subject.canonical_slug', 'architects')
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');

        $this->assertTrue(CareerDisplayAssetComponentContract::supports($response->json('display_surface_v1.component_order')));
    }

    public function test_display_asset_backed_bundle_remains_noindex_when_runtime_projection_rejects_indexing(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultItemPublished: true,
                detailRouteEnabled: [
                    'actors' => true,
                    'software-developers' => false,
                ],
                robotsIndexable: [
                    'actors' => false,
                ],
                releaseGatePass: [
                    'actors' => false,
                ],
            ),
        );

        $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation('actors');
        $occupation->forceFill([
            'crosswalk_mode' => 'direct_match',
        ])->save();
        $this->createDisplayAsset($occupation->refresh());

        $this->getJson('/api/v0.5/career/jobs/actors?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_manual_hold_software_developers_is_not_force_enabled_even_with_display_asset(): void
    {
        $occupation = $this->seedCompiledOccupation('software-developers');
        $this->addCrosswalks($occupation, 'software-developers');
        $this->createDisplayAsset($occupation);

        $this->getJson('/api/v0.5/career/jobs/software-developers?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_manual_hold_software_developers_directory_draft_remains_blocked_even_with_display_asset(): void
    {
        $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation('software-developers');
        $this->createDisplayAsset($occupation);

        $this->getJson('/api/v0.5/career/jobs/software-developers?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_it_adds_restricted_runtime_published_surface_for_missing_asset(): void
    {
        $occupation = $this->seedCompiledOccupation('veterinary-technologists-and-technicians');

        $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/veterinary-technologists-and-technicians?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'veterinary-technologists-and-technicians')
            ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'restricted')
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', false)
            ->assertJsonPath('display_surface_v1.implementation_contract.authority', 'runtime_publish_projection');
    }

    public function test_it_rejects_product_schema_asset_and_uses_restricted_runtime_published_surface(): void
    {
        $occupation = $this->seedCompiledOccupation('data-scientists');
        $this->addCrosswalks($occupation, 'data-scientists');
        $this->createDisplayAsset($occupation, [
            'structured_data_json' => [
                '@type' => 'Product',
                'name' => 'Unsafe product schema',
            ],
        ]);

        $response = $this->getWarmedJobDetailJson('/api/v0.5/career/jobs/data-scientists?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'data-scientists')
            ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'restricted')
            ->assertJsonPath('display_surface_v1.implementation_contract.authority', 'runtime_publish_projection');

        $this->assertStringNotContainsString(
            'Product',
            json_encode($response->json('display_surface_v1'), JSON_THROW_ON_ERROR),
        );
    }

    public function test_directory_draft_without_valid_display_asset_remains_blocked(): void
    {
        $this->bindUnpublishedRuntimeProjection();
        $this->seedDisplayAssetBackedDirectoryDraftOccupation('web-developers');

        $this->getJson('/api/v0.5/career/jobs/web-developers?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_directory_draft_with_invalid_display_asset_remains_blocked(): void
    {
        $this->bindUnpublishedRuntimeProjection();
        $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation('marketing-managers');
        $this->createDisplayAsset($occupation, [
            'component_order_json' => ['hero'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/marketing-managers?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_directory_draft_with_product_schema_display_asset_remains_blocked(): void
    {
        $this->bindUnpublishedRuntimeProjection();
        $occupation = $this->seedDisplayAssetBackedDirectoryDraftOccupation('acupuncturists');
        $this->createDisplayAsset($occupation, [
            'structured_data_json' => [
                '@type' => 'Product',
                'name' => 'Unsafe product schema',
            ],
        ]);

        $this->getJson('/api/v0.5/career/jobs/acupuncturists?locale=zh-CN')
            ->assertNotFound();
    }

    private function getWarmedJobDetailJson(string $uri): \Illuminate\Testing\TestResponse
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $slug = (string) basename($path);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $locale = is_string($query['locale'] ?? null) ? $query['locale'] : 'zh-CN';
        app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload($slug, $locale, true);

        return $this->getJson($uri);
    }

    private function bindUnpublishedRuntimeProjection(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture,
        );
    }

    private function seedCompiledOccupation(string $slug): Occupation
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => $slug]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-display-surface-'.$slug,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);
        $chain['contextSnapshot']->update([
            'compile_run_id' => $compileRun->id,
            'context_payload' => ['materialization' => 'career_first_wave'],
        ]);
        $chain['childProjection']->update([
            'compile_run_id' => $compileRun->id,
            'projection_payload' => array_merge(
                is_array($chain['childProjection']->projection_payload) ? $chain['childProjection']->projection_payload : [],
                ['materialization' => 'career_first_wave']
            ),
        ]);
        app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        return $chain['occupation'];
    }

    private function seedDisplayAssetBackedDirectoryDraftOccupation(string $slug): Occupation
    {
        $meta = self::PILOT_SLUGS[$slug];
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'display-asset-backed-'.$slug,
            'title_en' => $meta['title'],
            'title_zh' => $meta['title'],
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => $meta['title'],
            'canonical_title_zh' => $meta['title'],
            'search_h1_zh' => $meta['title'],
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
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
            'mapping_type' => 'directory_candidate',
            'confidence_score' => 0.5,
        ]);

        return $occupation->load(['family', 'aliases', 'crosswalks']);
    }

    private function addCrosswalks(Occupation $occupation, string $slug): void
    {
        $meta = self::PILOT_SLUGS[$slug];

        OccupationCrosswalk::query()
            ->where('occupation_id', $occupation->id)
            ->where('source_system', 'us_soc')
            ->update([
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
            ],
            'structured_data_json' => [
                '@type' => 'Occupation',
                'name' => 'Actors',
                'raw_ai_exposure_score' => 8.2,
            ],
            'implementation_contract_json' => [
                'structured_data_policy' => 'visible_content_only',
                'tracking_json' => ['do_not_show' => true],
            ],
            'metadata_json' => [
                'validator_version' => 'career_asset_import_validator_v0.1',
            ],
        ], $overrides);

        $payload = is_array($attributes['page_payload_json'] ?? null) ? $attributes['page_payload_json'] : [];
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;
        $componentOrder = CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS;
        foreach (['en', 'zh'] as $locale) {
            if (is_array($pages[$locale] ?? null)) {
                $pages[$locale] = array_replace(
                    array_fill_keys($componentOrder, []),
                    $pages[$locale],
                );
                if (($pages[$locale]['career_quick_answers_block'] ?? null) !== []
                    || ($pages[$locale]['onet_structured_fields_block'] ?? null) !== []) {
                    continue;
                }
                if ($locale === 'en') {
                    $unavailable = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
                    $pages[$locale]['career_quick_answers_block'] = $unavailable;
                    $pages[$locale]['onet_structured_fields_block'] = $unavailable;
                } else {
                    $row = ['label' => 'label', 'value' => 'value', 'alternate_value' => null, 'secondary_value' => null];
                    $pages[$locale]['career_quick_answers_block'] = [
                        'availability' => 'published',
                        'schema_version' => 'career.quick_answers.v1',
                        'heading' => '职业速答',
                        'items' => array_map(static fn (string $key): array => [
                            'key' => $key,
                            'question' => $key.' question',
                            'answer' => $key.' answer',
                            'table' => ['rows' => [$row]],
                        ], ['qa3', 'qa2', 'qa1']),
                    ];
                    $pages[$locale]['onet_structured_fields_block'] = [
                        'availability' => 'published',
                        'schema_version' => 'career.onet_structured_fields.v1',
                        'heading' => 'O*NET 结构化字段',
                        'rows' => [$row],
                    ];
                }
            }
        }
        $attributes['page_payload_json'] = is_array($payload['page'] ?? null)
            ? ['page' => $pages]
            : $pages;

        return CareerJobDisplayAsset::query()->create($attributes);
    }
}
