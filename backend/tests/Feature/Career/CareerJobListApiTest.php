<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobListApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISPLAY_COMPONENT_ORDER = [
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
        'methodology_note',
        'trust_footer',
        'schema_anchor',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture,
        );
    }

    public function test_it_returns_a_resource_backed_lightweight_job_index(): void
    {
        $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-index']));
        $this->compileJobChain(CareerFoundationFixture::seedMissingTruthChain());

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_index')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'backend-architect-index')
            ->assertJsonPath('items.0.seo_contract.index_eligible', true)
            ->assertJsonStructure([
                'bundle_kind',
                'bundle_version',
                'items' => [[
                    'identity',
                    'titles',
                    'truth_summary',
                    'trust_summary',
                    'score_summary',
                    'seo_contract' => ['canonical_path', 'index_state', 'index_eligible', 'reason_codes'],
                    'provenance_meta' => ['compiler_version', 'compile_run_id'],
                ]],
            ]);
    }

    public function test_it_reads_only_the_latest_completed_compile_run_for_public_index(): void
    {
        $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'older-career-index']),
            40
        );
        $this->compileJobChain(
            CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'latest-career-index']),
            5
        );

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'latest-career-index')
            ->assertJsonMissingPath('items.1')
            ->assertJsonMissing(['canonical_slug' => 'older-career-index']);
    }

    public function test_it_exposes_directory_draft_jobs_without_internal_metadata(): void
    {
        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'cn-digital-compliance-specialist',
            'canonical_title_en' => 'Digital Compliance Specialist',
            'canonical_title_zh' => '数字合规专员',
            'search_h1_zh' => '数字合规专员',
            'truth_market' => 'CN',
            'display_market' => 'CN',
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'cn-digital-compliance-specialist')
            ->assertJsonPath('items.0.trust_summary.public_stub_kind', 'public_directory_stub')
            ->assertJsonPath('items.0.trust_summary.status', 'unavailable')
            ->assertJsonPath('items.0.trust_summary.availability', 'detail_unavailable')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonPath('items.0.seo_contract.public_stub_kind', 'public_directory_stub')
            ->assertJsonPath('items.0.seo_contract.reason_codes.0', 'detail_page_unavailable')
            ->assertJsonPath('items.0.seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonMissingPath('items.0.identity.occupation_uuid')
            ->assertJsonMissingPath('items.0.identity.entity_level')
            ->assertJsonMissingPath('items.0.identity.family_uuid')
            ->assertJsonMissingPath('items.0.trust_summary.reviewer_status')
            ->assertJsonMissingPath('items.0.trust_summary.review_status')
            ->assertJsonMissingPath('items.0.trust_summary.content_version')
            ->assertJsonMissingPath('items.0.trust_summary.data_version')
            ->assertJsonMissingPath('items.0.trust_summary.logic_version')
            ->assertJsonMissingPath('items.0.trust_summary.editorial_patch_required')
            ->assertJsonMissingPath('items.0.trust_summary.editorial_patch_status')
            ->assertJsonMissingPath('items.0.provenance_meta.content_version')
            ->assertJsonMissingPath('items.0.provenance_meta.data_version')
            ->assertJsonMissingPath('items.0.provenance_meta.logic_version')
            ->assertJsonMissingPath('items.0.provenance_meta.import_run_id')
            ->assertJsonMissingPath('items.0.provenance_meta.source_snapshot_id')
            ->assertJsonMissingPath('items.0.provenance_meta.compile_run_id')
            ->assertJsonMissingPath('items.0.provenance_meta.index_state_id')
            ->assertJsonMissingPath('items.0.governance')
            ->assertJsonMissingPath('items.0.readiness');

        $item = $response->json('items.0');
        $this->assertSame(['canonical_slug'], array_keys($item['identity']));
        $this->assertSame(['truth_market'], array_keys($item['truth_summary']));
        $this->assertSame([], $item['score_summary']);
        $this->assertSame([], $item['provenance_meta']);

        $this->getJson('/api/v0.5/career/jobs/cn-digital-compliance-specialist')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_directory_draft_with_valid_display_asset_is_listed_as_detail_ready(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                detailRouteEnabled: ['acupuncturists' => true],
                robotsIndexable: ['acupuncturists' => true],
                releaseGatePass: ['acupuncturists' => true],
            ),
        );

        $occupation = $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'acupuncturists',
            'canonical_title_en' => 'Acupuncturists',
            'canonical_title_zh' => '针灸师',
            'search_h1_zh' => '针灸师',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->addDisplayAssetBackedCrosswalks($occupation, '29-1291', '29-1291.00');
        $this->createDisplayAsset($occupation->refresh());

        $response = $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'acupuncturists')
            ->assertJsonPath('items.0.identity.entity_level', 'dataset_candidate')
            ->assertJsonPath('items.0.trust_summary.reviewer_status', 'pilot_display_asset')
            ->assertJsonPath('items.0.trust_summary.logic_version', 'career.protocol.job_list.display_asset_backed.v1')
            ->assertJsonPath('items.0.trust_summary.reason_codes.0', 'validated_display_asset_backed_release')
            ->assertJsonPath('items.0.trust_summary.reason_codes.1', 'runtime_publish_projection')
            ->assertJsonPath('items.0.seo_contract.index_eligible', true)
            ->assertJsonPath('items.0.seo_contract.index_state', 'indexable')
            ->assertJsonPath('items.0.seo_contract.reason_codes.0', 'validated_display_asset_backed_release')
            ->assertJsonPath('items.0.seo_contract.reason_codes.1', 'runtime_publish_projection')
            ->assertJsonPath('items.0.seo_contract.robots_policy', 'index,follow')
            ->assertJsonMissingPath('items.0.trust_summary.public_stub_kind')
            ->assertJsonMissingPath('items.0.trust_summary.status')
            ->assertJsonMissingPath('items.0.trust_summary.availability')
            ->assertJsonMissingPath('items.0.seo_contract.public_stub_kind')
            ->assertJsonMissingPath('items.0.governance')
            ->assertJsonMissingPath('items.0.readiness');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('release_gate', $encoded);
        $this->assertStringNotContainsString('tracking_json', $encoded);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);

        $this->getJson('/api/v0.5/career/jobs/acupuncturists?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'acupuncturists')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1');
    }

    public function test_directory_draft_display_asset_remains_stub_when_runtime_projection_rejects_indexing(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                detailRouteEnabled: ['business-intelligence-analysts' => true],
                robotsIndexable: ['business-intelligence-analysts' => false],
                releaseGatePass: ['business-intelligence-analysts' => true],
            ),
        );

        $occupation = $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'business-intelligence-analysts',
            'canonical_title_en' => 'Business Intelligence Analysts',
            'canonical_title_zh' => '商业智能分析师',
            'search_h1_zh' => '商业智能分析师',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->addDisplayAssetBackedCrosswalks($occupation, '15-2051', '15-2051.01');
        $this->createDisplayAsset($occupation->refresh());

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'business-intelligence-analysts')
            ->assertJsonPath('items.0.trust_summary.public_stub_kind', 'public_directory_stub')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonPath('items.0.seo_contract.reason_codes.0', 'detail_page_unavailable');
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function compileJobChain(array $chain, int $compileFinishedMinutesAgo = 7): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-job-api-'.$chain['occupation']->canonical_slug,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes($compileFinishedMinutesAgo + 3),
            'finished_at' => now()->subMinutes($compileFinishedMinutesAgo + 2),
        ]);
        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => CareerRecommendationCompiler::COMPILER_VERSION,
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes($compileFinishedMinutesAgo + 1),
            'finished_at' => now()->subMinutes($compileFinishedMinutesAgo),
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
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDirectoryDraftOccupation(array $overrides = []): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'directory-draft-family',
            'title_en' => 'Directory Draft Family',
            'title_zh' => '目录草稿职业族',
        ]);
        $occupation = Occupation::query()->create(array_merge([
            'family_id' => $family->id,
            'canonical_slug' => 'directory-draft-specialist',
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => 'Directory Draft Specialist',
            'canonical_title_zh' => '目录草稿专员',
            'search_h1_zh' => '目录草稿专员',
        ], $overrides));
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'china_us_occupation_directories_2026',
            'dataset_version' => '2026',
            'dataset_checksum' => 'directory-draft-index-checksum',
            'scope_mode' => 'occupation_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'CN_2026',
            'source_code' => 'CN-TEST-001',
            'source_title' => (string) $occupation->canonical_title_zh,
            'mapping_type' => 'directory_draft',
            'confidence_score' => 0.5,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'directory-draft-index-crosswalk'),
        ]);
        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'alias' => (string) $occupation->canonical_title_zh,
            'normalized' => (string) $occupation->canonical_title_zh,
            'lang' => 'zh-CN',
            'register' => 'canonical',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 1,
            'confidence_score' => 1,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'directory-draft-index-alias'),
        ]);

        return $occupation->fresh();
    }

    private function addDisplayAssetBackedCrosswalks(Occupation $occupation, string $socCode, string $onetCode): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'display_asset_crosswalks',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'display-asset-crosswalks-'.$occupation->canonical_slug,
            'scope_mode' => 'display_asset_backed_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(8),
            'finished_at' => now()->subMinutes(7),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'us_soc',
            'source_code' => $socCode,
            'source_title' => (string) $occupation->canonical_title_en,
            'mapping_type' => 'direct_match',
            'confidence_score' => 1,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'display-asset-us-soc-'.$occupation->canonical_slug),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'onet_soc_2019',
            'source_code' => $onetCode,
            'source_title' => (string) $occupation->canonical_title_en,
            'mapping_type' => 'directory_candidate',
            'confidence_score' => 0.5,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'display-asset-onet-'.$occupation->canonical_slug),
        ]);
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
            'component_order_json' => self::DISPLAY_COMPONENT_ORDER,
            'page_payload_json' => [
                'zh' => [
                    'hero' => ['title' => (string) $occupation->canonical_title_zh],
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
                'en' => [
                    'hero' => ['title' => (string) $occupation->canonical_title_en],
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
                'name' => (string) $occupation->canonical_title_en,
                'raw_ai_exposure_score' => 8.2,
            ],
            'implementation_contract_json' => [
                'structured_data_policy' => 'visible_content_only',
                'tracking_json' => ['do_not_show' => true],
            ],
            'metadata_json' => [
                'validator_version' => 'career_asset_import_validator_v0.1',
            ],
        ], $overrides));
    }
}
