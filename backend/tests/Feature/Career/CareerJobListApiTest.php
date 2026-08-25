<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobListApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISPLAY_COMPONENT_ORDER = CareerDisplayAssetComponentContract::CURRENT_ORDER;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: true),
        );
    }

    public function test_it_returns_a_resource_backed_lightweight_job_index(): void
    {
        $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'backend-architect-index']));
        $this->compileJobChain(CareerFoundationFixture::seedMissingTruthChain());
        $this->markDetailReady('backend-architect-index');
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_index')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'backend-architect-index')
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.trust_summary.reviewer', null)
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
                    'provenance_meta' => ['compiler_version'],
                ]],
            ]);
    }

    public function test_docx_backed_job_review_contract_fails_closed_without_human_review_evidence(): void
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'docx-review-contract-list',
            'slug' => 'docx-review-contract-list',
            'locale' => 'zh-CN',
            'title' => 'DOCX 审核契约职业',
            'subtitle' => 'DOCX Review Contract Job',
            'excerpt' => 'DOCX review contract fixture.',
            'body_md' => '# DOCX review contract fixture',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'market_demand_json' => [
                'source_refs' => [[
                    'label' => 'BLS Occupational Outlook Handbook',
                    'url' => 'https://www.bls.gov/ooh/fixture.htm',
                ]],
            ],
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'jsonld_overrides_json' => [
                'source_docx' => 'docx-review-contract-list.docx',
            ],
        ]);
        $this->markDetailReady('docx-review-contract-list');
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'docx-review-contract-list')
            ->assertJsonPath('items.0.trust_summary.reviewer_status', 'docx_baseline_imported')
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.trust_summary.reviewer', null);
    }

    public function test_it_serves_cached_public_job_index_payload_without_rebuilding_the_bundle(): void
    {
        $this->markDetailReady('cached-career-index', 'en');
        app(PublicCareerAuthorityResponseCache::class)->publishJobIndexReadModelsAtomically([
            'en' => [
                'bundle_kind' => 'career_job_index',
                'bundle_version' => 'career.protocol.job_index.v1',
                'items' => [[
                    'identity' => ['canonical_slug' => 'cached-career-index'],
                    'titles' => ['canonical_en' => 'Cached Career Index'],
                    'truth_summary' => [],
                    'trust_summary' => [
                        'reviewer_status' => 'approved',
                        'reviewed_at' => '2026-07-18T00:00:00Z',
                        'reviewer' => ['name' => 'Legacy Cached Career Reviewer'],
                    ],
                    'score_summary' => [],
                    'seo_contract' => ['canonical_path' => '/career/jobs/cached-career-index', 'index_state' => 'indexable', 'index_eligible' => true, 'reason_codes' => []],
                    'provenance_meta' => [],
                ]],
            ],
        ]);
        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'cached-career-index')
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.trust_summary.reviewer', null);
    }

    public function test_it_filters_cached_job_index_when_detail_authority_is_not_ready(): void
    {
        $payload = [
            'bundle_kind' => 'career_job_index',
            'bundle_version' => 'career.protocol.job_index.v1',
            'items' => [[
                'identity' => ['canonical_slug' => 'cached-cold-detail'],
                'seo_contract' => ['canonical_path' => '/career/jobs/cached-cold-detail'],
            ]],
        ];
        app(PublicCareerAuthorityResponseCache::class)->publishJobIndexReadModelsAtomically(['en' => $payload]);

        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $this->markDetailReady('cached-cold-detail', 'en');

        $this->getJson('/api/v0.5/career/jobs?locale=en')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_cold_job_index_fails_closed_until_an_out_of_band_warm_completes(): void
    {
        $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'rebuilt-cold-detail']));

        $this->getJson('/api/v0.5/career/jobs')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'CAREER_JOB_INDEX_NOT_WARM')
            ->assertJsonMissingPath('items');

        $this->markDetailReady('rebuilt-cold-detail');
        $this->getJson('/api/v0.5/career/jobs')
            ->assertStatus(503);

        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'rebuilt-cold-detail');
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
        $this->markDetailReady('latest-career-index');
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'latest-career-index')
            ->assertJsonMissingPath('items.1')
            ->assertJsonMissing(['canonical_slug' => 'older-career-index']);
    }

    public function test_it_exposes_directory_draft_jobs_without_internal_metadata(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture,
        );

        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'cn-digital-compliance-specialist',
            'canonical_title_en' => 'Digital Compliance Specialist',
            'canonical_title_zh' => '数字合规专员',
            'search_h1_zh' => '数字合规专员',
            'truth_market' => 'CN',
            'display_market' => 'CN',
        ]);
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $this->getJson('/api/v0.5/career/jobs/cn-digital-compliance-specialist')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_directory_draft_with_valid_display_asset_is_listed_as_detail_ready(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultItemPublished: true,
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
        $this->markDetailReady('acupuncturists');
        $this->warmPublicJobIndexes();

        $response = $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'acupuncturists')
            ->assertJsonPath('items.0.identity.entity_level', 'dataset_candidate')
            ->assertJsonPath('items.0.trust_summary.reviewer_status', 'pilot_display_asset')
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.trust_summary.reviewer', null)
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

        app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload('acupuncturists', 'zh-CN', true);
        $this->getJson('/api/v0.5/career/jobs/acupuncturists?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'acupuncturists')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1');
    }

    public function test_directory_draft_display_asset_list_work_stays_bounded(): void
    {
        $slugs = [];
        for ($i = 1; $i <= 8; $i++) {
            $slug = "bounded-directory-draft-{$i}";
            $slugs[] = $slug;
            $occupation = $this->createDirectoryDraftOccupation([
                'canonical_slug' => $slug,
                'canonical_title_en' => "Bounded Directory Draft {$i}",
                'canonical_title_zh' => "有界目录草稿 {$i}",
                'search_h1_zh' => "有界目录草稿 {$i}",
                'truth_market' => 'US',
                'display_market' => 'zh-CN',
            ]);
            $this->addDisplayAssetBackedCrosswalks($occupation, sprintf('29-%04d', 1200 + $i), sprintf('29-%04d.00', 1200 + $i));
            $this->createDisplayAsset($occupation->refresh());
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultItemPublished: true,
                detailRouteEnabled: array_fill_keys($slugs, true),
                robotsIndexable: array_fill_keys($slugs, true),
                releaseGatePass: array_fill_keys($slugs, true),
            ),
        );
        foreach ($slugs as $slug) {
            $this->markDetailReady($slug);
        }
        $this->warmPublicJobIndexes();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(8, 'items');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queryCount, "Directory draft list should not query per draft; saw {$queryCount} queries.");
    }

    public function test_directory_draft_with_runtime_published_detail_shell_is_listed_as_detail_ready(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: [
                'agricultural-workers-all-other' => [
                    'slug' => 'agricultural-workers-all-other',
                    'runtime_publish_state' => 'published',
                    'dataset_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'canonical_path' => '/career/jobs/agricultural-workers-all-other',
                    'reason_codes' => ['runtime_published_navigation_shell'],
                ],
            ]),
        );

        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'agricultural-workers-all-other',
            'canonical_title_en' => 'Agricultural Workers, All Other',
            'canonical_title_zh' => '其他农业工人',
            'search_h1_zh' => '其他农业工人',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->markDetailReady('agricultural-workers-all-other');
        $this->warmPublicJobIndexes();

        $response = $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'agricultural-workers-all-other')
            ->assertJsonPath('items.0.identity.entity_level', 'dataset_candidate')
            ->assertJsonPath('items.0.trust_summary.reviewer_status', 'runtime_publish_projection')
            ->assertJsonPath('items.0.trust_summary.review_state', 'unknown')
            ->assertJsonPath('items.0.trust_summary.last_reviewed_at', null)
            ->assertJsonPath('items.0.trust_summary.reviewer', null)
            ->assertJsonPath('items.0.trust_summary.logic_version', 'career.protocol.job_detail.runtime_projection.v1')
            ->assertJsonPath('items.0.seo_contract.index_eligible', true)
            ->assertJsonPath('items.0.seo_contract.index_state', 'indexable')
            ->assertJsonPath('items.0.seo_contract.robots_policy', 'index,follow')
            ->assertJsonMissingPath('items.0.trust_summary.public_stub_kind')
            ->assertJsonMissingPath('items.0.trust_summary.status')
            ->assertJsonMissingPath('items.0.trust_summary.availability')
            ->assertJsonMissingPath('items.0.seo_contract.public_stub_kind');

        $this->assertContains('runtime_publish_projection', $response->json('items.0.trust_summary.reason_codes'));
        $this->assertContains('runtime_published_navigation_shell', $response->json('items.0.trust_summary.reason_codes'));
        $this->assertContains('runtime_publish_projection', $response->json('items.0.seo_contract.reason_codes'));
        $this->assertContains('runtime_published_navigation_shell', $response->json('items.0.seo_contract.reason_codes'));
    }

    public function test_runtime_projection_limits_public_job_index_to_release_gate_slugs(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: [
                'actuaries' => [
                    'slug' => 'actuaries',
                    'runtime_publish_state' => 'published',
                    'dataset_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'canonical_path' => '/career/jobs/actuaries',
                    'reason_codes' => ['cached_dataset_hub_fallback'],
                ],
            ]),
        );

        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'actuaries',
            'canonical_title_en' => 'Actuaries',
            'canonical_title_zh' => '精算师',
            'search_h1_zh' => '精算师',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->markDetailReady('actuaries');
        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'accountants-and-auditors',
            'canonical_title_en' => 'Accountants and Auditors',
            'canonical_title_zh' => '会计师和审计师',
            'search_h1_zh' => '会计师和审计师',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'actuaries')
            ->assertJsonMissing(['canonical_slug' => 'accountants-and-auditors']);
    }

    public function test_directory_draft_runtime_projection_requires_published_state_before_list_upgrade(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(items: [
                'water-resource-specialists' => [
                    'slug' => 'water-resource-specialists',
                    'runtime_publish_state' => 'candidate',
                    'dataset_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'canonical_path' => '/career/jobs/water-resource-specialists',
                ],
            ]),
        );

        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'water-resource-specialists',
            'canonical_title_en' => 'Water Resource Specialists',
            'canonical_title_zh' => '水资源专家',
            'search_h1_zh' => '水资源专家',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
        ]);
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    public function test_directory_draft_display_asset_remains_stub_when_runtime_projection_rejects_indexing(): void
    {
        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultItemPublished: true,
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
        $this->warmPublicJobIndexes();

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'items');
    }

    private function warmPublicJobIndexes(): void
    {
        app(PublicCareerAuthorityResponseCache::class)->warmDirectoryReadModels(
            ['en', 'zh-CN'],
            null,
            null,
            activateJobIndexPayloads: true,
        );
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

    private function markDetailReady(string $slug, string $locale = 'zh-CN'): void
    {
        app(PublicCareerAuthorityResponseCache::class)->publishJobDetailReadModel($slug, $locale, [
            'identity' => ['canonical_slug' => $slug],
            'fixture' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDirectoryDraftOccupation(array $overrides = []): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'directory-draft-family-'.($overrides['canonical_slug'] ?? 'default'),
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
        $attributes = array_replace([
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
        ], $overrides);
        $pages = (array) $attributes['page_payload_json'];
        foreach (['en', 'zh'] as $locale) {
            $pages[$locale] = array_replace(
                array_fill_keys(CareerDisplayAssetComponentContract::CURRENT_ORDER, []),
                (array) ($pages[$locale] ?? []),
            );
        }
        $unavailable = ['availability' => 'unavailable', 'reason_code' => 'source_locale_unavailable'];
        $pages['en']['career_quick_answers_block'] = $unavailable;
        $pages['en']['onet_structured_fields_block'] = $unavailable;
        $row = ['label' => 'label', 'value' => 'value', 'alternate_value' => null, 'secondary_value' => null];
        $pages['zh']['career_quick_answers_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.quick_answers.v1',
            'heading' => '职业速答',
            'items' => array_map(static fn (string $key): array => [
                'key' => $key, 'question' => $key.' question', 'answer' => $key.' answer',
                'table' => ['rows' => [$row]],
            ], ['qa3', 'qa2', 'qa1']),
        ];
        $pages['zh']['onet_structured_fields_block'] = [
            'availability' => 'published',
            'schema_version' => 'career.onet_structured_fields.v1',
            'heading' => 'O*NET 结构化字段',
            'rows' => [$row],
        ];
        $attributes['page_payload_json'] = $pages;

        return CareerJobDisplayAsset::query()->create($attributes);
    }
}
