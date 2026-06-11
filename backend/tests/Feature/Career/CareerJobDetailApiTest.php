<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerJobDetailApiTest extends TestCase
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
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture,
        );
    }

    public function test_it_returns_a_resource_backed_job_detail_bundle_with_explicit_sections(): void
    {
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-job-api',
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

        $response = $this->getJson('/api/v0.5/career/jobs/backend-architect')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_detail')
            ->assertJsonPath('identity.canonical_slug', 'backend-architect')
            ->assertJsonPath('trust_manifest.content_version', 'v4.1')
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/backend-architect')
            ->assertJsonPath('structured_data.occupation.@type', 'Occupation')
            ->assertJsonPath('structured_data.breadcrumb_list.@type', 'BreadcrumbList')
            ->assertJsonMissingPath('structured_data.occupation.description')
            ->assertJsonMissingPath('structured_data.occupation.occupationalExperienceRequirements')
            ->assertJsonMissingPath('structured_data.dataset')
            ->assertJsonMissingPath('structured_data.article')
            ->assertJsonMissingPath('structured_data.route_kind')
            ->assertJsonMissingPath('structured_data.canonical_path')
            ->assertJsonMissingPath('structured_data.canonical_title')
            ->assertJsonMissingPath('structured_data.breadcrumb_nodes')
            ->assertJsonStructure([
                'identity',
                'locale_policy',
                'titles',
                'alias_index',
                'ontology',
                'truth_layer',
                'trust_manifest',
                'score_bundle' => ['fit_score'],
                'white_box_scores' => [
                    'fit_score' => [
                        'score',
                        'integrity_state',
                        'degradation_factor',
                        'formula_breakdown',
                        'component_weights',
                        'penalties',
                        'warnings',
                    ],
                ],
                'warnings',
                'claim_permissions',
                'integrity_summary',
                'seo_contract',
                'structured_data' => [
                    'occupation',
                    'breadcrumb_list',
                ],
                'provenance_meta' => ['compiler_version', 'compile_refs'],
                'lifecycle_companion',
            ])
            ->assertJsonMissingPath('white_box_scores.fit_score.formula_ref')
            ->assertJsonMissingPath('white_box_scores.fit_score.critical_missing_fields');

        $this->assertIsNumeric($response->json('white_box_scores.fit_score.score'));
        $this->assertIsString((string) $response->json('white_box_scores.fit_score.integrity_state'));
        $this->assertIsNumeric($response->json('white_box_scores.fit_score.degradation_factor'));
    }

    public function test_it_serves_cached_public_job_detail_payload_without_rebuilding_the_bundle(): void
    {
        Cache::put(
            PublicCareerAuthorityResponseCache::JOB_DETAIL_CACHE_KEY_PREFIX.':cached-career-detail:en',
            [
                'bundle_kind' => 'career_job_detail',
                'identity' => ['canonical_slug' => 'cached-career-detail'],
                'titles' => ['canonical_en' => 'Cached Career Detail'],
                'seo_contract' => [
                    'canonical_path' => '/en/career/jobs/cached-career-detail',
                    'canonical_target' => '/en/career/jobs/cached-career-detail',
                    'robots_policy' => 'index,follow',
                    'index_eligible' => true,
                    'index_state' => 'indexable',
                    'reason_codes' => [],
                ],
                'structured_data' => [],
            ]
        );

        $this->getJson('/api/v0.5/career/jobs/cached-career-detail?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'cached-career-detail')
            ->assertJsonPath('seo_contract.canonical_path', '/en/career/jobs/cached-career-detail');
    }

    public function test_it_remains_conservative_and_does_not_fall_back_to_legacy_cms_jobs(): void
    {
        CareerFoundationFixture::seedHighTrustCompleteChain(['slug' => 'authority-only']);

        CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'legacy-backend-architect',
            'slug' => 'legacy-backend-architect',
            'locale' => 'en',
            'title' => 'Legacy Backend Architect',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career/jobs/legacy-backend-architect')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_it_builds_docx_baseline_cms_jobs_as_authority_detail_bundles(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'accountants-and-auditors', 'status' => 'already_imported_validated'],
        ]);

        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'accountants-and-auditors',
            'slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'title' => '会计师和审计师',
            'subtitle' => 'Accountants and Auditors',
            'excerpt' => 'Prepare and examine financial records.',
            'body_md' => "# 会计师和审计师\n\n会计师和审计师不是单纯处理数字的岗位。",
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'salary_json' => [
                'annual_median_usd' => 81350,
            ],
            'outlook_json' => [
                'jobs_2024' => 1562000,
                'projected_jobs_2034' => 1657000,
                'employment_change' => 95000,
                'outlook_pct_2024_2034' => 6,
                'outlook_raw' => 'Employment is projected to grow 6 percent from 2024 to 2034.',
            ],
            'growth_path_json' => [
                'raw' => ['Bachelor degree is a common entry path.'],
            ],
            'market_demand_json' => [
                'ai_exposure_score_10' => 6,
                'ai_exposure_raw' => 'AI can automate routine accounting tasks while preserving advisory work.',
                'source_refs' => [
                    [
                        'label' => 'BLS Occupational Outlook Handbook',
                        'url' => 'https://www.bls.gov/ooh/business-and-financial/accountants-and-auditors.htm',
                    ],
                    [
                        'label' => 'O*NET OnLine',
                        'url' => 'https://www.onetonline.org/link/summary/13-2011.00',
                    ],
                ],
            ],
        ]);

        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'jsonld_overrides_json' => [
                'source_docx' => '01_会计师和审计师_accountants-and-auditors.docx',
            ],
        ]);
        CareerJobSection::query()->create([
            'job_id' => (int) $job->id,
            'section_key' => 'day_to_day',
            'title' => '01 你通常会在这些工作场景里接触这份职业',
            'render_variant' => 'rich_text',
            'body_md' => '• 处理需要准确记录、核对或解释的财务与经营信息。',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_detail')
            ->assertJsonPath('identity.canonical_slug', 'accountants-and-auditors')
            ->assertJsonPath('identity.occupation_uuid', 'career_job:accountants-and-auditors')
            ->assertJsonPath('locale_policy.crosswalk_mode', 'docx_baseline')
            ->assertJsonPath('titles.canonical_zh', '会计师和审计师')
            ->assertJsonPath('trust_manifest.logic_version', 'career.protocol.job_detail.docx_baseline.v1')
            ->assertJsonPath('truth_layer.median_pay_usd_annual', 81350)
            ->assertJsonPath('content_sections.0.title', '01 你通常会在这些工作场景里接触这份职业')
            ->assertJsonPath('content_sections.0.body_md', '• 处理需要准确记录、核对或解释的财务与经营信息。')
            ->assertJsonPath('content_body_md', "# 会计师和审计师\n\n会计师和审计师不是单纯处理数字的岗位。")
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/accountants-and-auditors')
            ->assertJsonPath('claim_permissions.allow_strong_claim', true)
            ->assertJsonPath('provenance_meta.compile_refs.source_docx', '01_会计师和审计师_accountants-and-auditors.docx');
    }

    public function test_docx_baseline_canonical_uses_requested_public_locale_instead_of_job_locale(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'canonical-locale-regression', 'status' => 'already_imported_validated'],
        ]);

        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => 'canonical-locale-regression',
            'slug' => 'canonical-locale-regression',
            'locale' => 'zh-CN',
            'title' => '规范链接回归职业',
            'subtitle' => 'Canonical Locale Regression',
            'excerpt' => 'Verify public locale canonical path.',
            'body_md' => "# 规范链接回归职业\n\n这是用于验证公开语言路径的职业内容。",
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'salary_json' => ['annual_median_usd' => 81350],
            'outlook_json' => ['jobs_2024' => 1000],
            'growth_path_json' => ['raw' => ['Fixture growth path.']],
            'market_demand_json' => [
                'ai_exposure_score_10' => 6,
                'source_refs' => [
                    [
                        'label' => 'BLS Occupational Outlook Handbook',
                        'url' => 'https://www.bls.gov/ooh/fixture.htm',
                    ],
                ],
            ],
        ]);
        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'jsonld_overrides_json' => [
                'source_docx' => 'canonical-locale-regression.docx',
            ],
        ]);

        $this->getJson('/api/v0.5/career/jobs/canonical-locale-regression?locale=en')
            ->assertOk()
            ->assertJsonPath('seo_contract.canonical_path', '/en/career/jobs/canonical-locale-regression')
            ->assertJsonPath('seo_contract.canonical_target', '/en/career/jobs/canonical-locale-regression')
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow');

        $this->getJson('/api/v0.5/career/jobs/canonical-locale-regression?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/canonical-locale-regression')
            ->assertJsonPath('seo_contract.canonical_target', '/zh/career/jobs/canonical-locale-regression')
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow');
    }

    public function test_public_resolution_guard_blocks_governed_docx_fallback_rows(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'duplicate-hold-job', 'status' => 'duplicate_identity_hold'],
            ['slug' => 'cn-proxy-0001', 'status' => 'CN_proxy_hold'],
            ['slug' => 'broad-workers-all-other', 'status' => 'broad_group_hold'],
            ['slug' => 'software-developers', 'status' => 'manual_hold'],
            ['slug' => 'blocked-governance-job', 'status' => 'requires_governance_review'],
        ]);

        foreach ([
            'duplicate-hold-job',
            'cn-proxy-0001',
            'broad-workers-all-other',
            'software-developers',
            'blocked-governance-job',
        ] as $slug) {
            $this->createPublishedDocxCareerJob($slug);

            $this->getJson('/api/v0.5/career/jobs/'.$slug)
                ->assertStatus(404)
                ->assertJsonPath('ok', false)
                ->assertJsonPath('error_code', 'NOT_FOUND');
        }
    }

    public function test_display_asset_existence_alone_does_not_bypass_public_resolution_guard(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'display-backed-duplicate-hold', 'status' => 'duplicate_identity_hold'],
        ]);

        $occupation = $this->createDisplayAssetBackedOccupation('display-backed-duplicate-hold');
        $this->createDisplayAsset($occupation);

        $this->getJson('/api/v0.5/career/jobs/display-backed-duplicate-hold')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_display_asset_backed_public_canonical_job_remains_available(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'display-backed-public-canonical', 'status' => 'already_imported_validated'],
        ]);

        $occupation = $this->createDisplayAssetBackedOccupation('display-backed-public-canonical');
        $this->createDisplayAsset($occupation);

        $this->getJson('/api/v0.5/career/jobs/display-backed-public-canonical')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_detail')
            ->assertJsonPath('identity.canonical_slug', 'display-backed-public-canonical')
            ->assertJsonPath('seo_contract.canonical_path', '/zh/career/jobs/display-backed-public-canonical');
    }

    public function test_zh_display_asset_backed_bundle_uses_runtime_published_shell_when_locale_surface_is_english(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'data-scientists', 'status' => 'already_imported_validated'],
        ]);

        $occupation = $this->createDisplayAssetBackedOccupation('data-scientists');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'page' => [
                    'zh' => [
                        'hero' => ['title' => 'Data Scientists'],
                        'primary_cta' => ['label' => 'Start career fit test'],
                    ],
                    'en' => [
                        'hero' => ['title' => 'Data scientist career fit'],
                        'primary_cta' => ['label' => 'Start career fit test'],
                    ],
                ],
            ],
        ]);

        $this->getJson('/api/v0.5/career/jobs/data-scientists?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('titles.canonical_zh', '展示资产职业')
            ->assertJsonPath('seo_contract.index_state', 'indexable')
            ->assertJsonPath('seo_contract.index_eligible', true)
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow')
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.implementation_contract.authority', 'runtime_publish_projection')
            ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'restricted');

        $this->getJson('/api/v0.5/career/jobs/data-scientists?locale=en')
            ->assertOk()
            ->assertJsonPath('seo_contract.index_state', 'indexable')
            ->assertJsonPath('display_surface_v1.page.locale', 'en')
            ->assertJsonPath('display_surface_v1.page.content.hero.title', 'Data scientist career fit');
    }

    public function test_zh_display_asset_backed_bundle_allows_shared_english_cta_when_reviewed_zh_content_is_ready(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'reviewed-zh-display-asset', 'status' => 'already_imported_validated'],
        ]);

        $occupation = $this->createDisplayAssetBackedOccupation('reviewed-zh-display-asset');
        $this->createDisplayAsset($occupation, [
            'page_payload_json' => [
                'page' => [
                    'zh' => [
                        'hero' => ['title' => '展示资产职业'],
                        'primary_cta' => ['label' => 'Start career fit test'],
                        'definition_block' => [
                            'heading' => '职业定义',
                            'body' => '这是一份已经通过中文审阅的职业展示内容。',
                        ],
                        'market_signal_card' => [
                            'salary_data_type' => 'BLS official wage evidence',
                            'body' => '市场信号仅基于公开来源解释，不替代个人判断。',
                        ],
                        'ai_impact_table' => [
                            'score_normalized' => '82',
                            'source' => 'FermatMind central score',
                        ],
                    ],
                    'en' => [
                        'hero' => ['title' => 'Display backed career'],
                        'primary_cta' => ['label' => 'Start career fit test'],
                        'market_signal_card' => [
                            'salary_data_type' => 'BLS official wage evidence',
                            'body' => 'Official market signal from BLS.',
                        ],
                        'ai_impact_table' => [
                            'score_normalized' => '82',
                            'source' => 'FermatMind central score',
                        ],
                    ],
                ],
            ],
        ]);

        $this->getJson('/api/v0.5/career/jobs/reviewed-zh-display-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('trust_manifest.logic_version', 'career.protocol.job_detail.display_asset_backed.v1')
            ->assertJsonPath('integrity_summary.integrity_state', 'display_asset_backed')
            ->assertJsonPath('claim_permissions.allow_strong_claim', false)
            ->assertJsonPath('claim_permissions.allow_salary_comparison', false)
            ->assertJsonPath('seo_contract.reason_codes.0', 'validated_display_asset_backed_release')
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN')
            ->assertJsonPath('display_surface_v1.page.content.hero.title', '展示资产职业')
            ->assertJsonPath('display_surface_v1.page.content.primary_cta.label', 'Start career fit test')
            ->assertJsonPath('display_surface_v1.page.content.definition_block.heading', '职业定义')
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_strong_claim', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.allow_salary_comparison', true)
            ->assertJsonPath('display_surface_v1.claim_permissions.integrity_state', 'full');
    }

    public function test_display_asset_surface_aligns_english_module_subset_to_component_order_without_copying_zh_content(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'english-module-subset-display-asset', 'status' => 'already_imported_validated'],
        ]);

        $occupation = $this->createDisplayAssetBackedOccupation('english-module-subset-display-asset');
        $zhPage = $this->pageContentForDisplayComponentOrder('中文已审内容');
        $zhPage['hero'] = ['title' => '中文完整展示资产职业'];
        $enPage = [
            'hero' => ['title' => 'English display asset career'],
            'primary_cta' => ['label' => 'Start career fit test'],
            'market_signal_card' => [
                'salary_data_type' => 'BLS official wage evidence',
                'body' => 'Official market signal from BLS.',
            ],
            'ai_impact_table' => [
                'score_normalized' => '82',
                'source' => 'FermatMind central score',
            ],
        ];

        $this->createDisplayAsset($occupation, [
            'component_order_json' => self::DISPLAY_COMPONENT_ORDER,
            'page_payload_json' => [
                'page' => [
                    'zh' => $zhPage,
                    'en' => $enPage,
                ],
            ],
        ]);

        $zhResponse = $this->getJson('/api/v0.5/career/jobs/english-module-subset-display-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('display_surface_v1.page.locale', 'zh-CN');
        $enResponse = $this->getJson('/api/v0.5/career/jobs/english-module-subset-display-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('display_surface_v1.page.locale', 'en')
            ->assertJsonPath('display_surface_v1.page.content.responsibilities_block.module_state', 'pending_reviewed_locale_content')
            ->assertJsonPath('display_surface_v1.page.content.responsibilities_block.content_available', false)
            ->assertJsonPath('display_surface_v1.page.content.responsibilities_block.placeholder_policy', 'no_cross_locale_editorial_copy_generated');

        $zhContentKeys = array_keys((array) $zhResponse->json('display_surface_v1.page.content'));
        $enContentKeys = array_keys((array) $enResponse->json('display_surface_v1.page.content'));
        sort($zhContentKeys);
        sort($enContentKeys);

        $expected = self::DISPLAY_COMPONENT_ORDER;
        sort($expected);
        $this->assertSame($expected, $zhContentKeys);
        $this->assertSame($expected, $enContentKeys);

        $enDisplayContent = json_encode($enResponse->json('display_surface_v1.page.content'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('中文完整展示资产职业', $enDisplayContent);
        $this->assertStringNotContainsString('中文已审内容', $enDisplayContent);
    }

    public function test_runtime_published_occupation_without_display_or_docx_asset_returns_restricted_public_shell(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'agricultural-workers-all-other', 'status' => 'already_imported_validated'],
        ]);

        $this->createDisplayAssetBackedOccupation('agricultural-workers-all-other');

        $this->getJson('/api/v0.5/career/jobs/agricultural-workers-all-other?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'agricultural-workers-all-other')
            ->assertJsonPath('trust_manifest.logic_version', 'career.protocol.job_detail.runtime_published_shell.v1')
            ->assertJsonPath('seo_contract.canonical_path', '/en/career/jobs/agricultural-workers-all-other')
            ->assertJsonPath('seo_contract.index_state', 'indexable')
            ->assertJsonPath('seo_contract.robots_policy', 'index,follow')
            ->assertJsonPath('display_surface_v1.page.locale', 'en')
            ->assertJsonPath('display_surface_v1.implementation_contract.authority', 'runtime_publish_projection')
            ->assertJsonPath('display_surface_v1.page.content.primary_cta.test_slug', 'holland-career-interest-test-riasec')
            ->assertJsonPath('display_surface_v1.page.content.primary_cta.target_action', 'start_riasec_test')
            ->assertJsonPath('display_surface_v1.page.content.primary_cta.subject_key', 'agricultural-workers-all-other');
    }

    public function test_runtime_shell_does_not_bypass_projection_release_gate(): void
    {
        $this->configurePublicResolutionPlan([
            ['slug' => 'blocked-runtime-shell', 'status' => 'requires_governance_review'],
        ]);

        $this->createDisplayAssetBackedOccupation('blocked-runtime-shell');

        $this->getJson('/api/v0.5/career/jobs/blocked-runtime-shell?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    /**
     * @param  list<array{slug: string, status: string}>  $rows
     */
    private function configurePublicResolutionPlan(array $rows): void
    {
        $path = storage_path('framework/testing/career-public-resolution-plan-'.str_replace('.', '', uniqid('', true)).'.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode(['rows' => $rows], JSON_THROW_ON_ERROR));

        config(['fap.career.public_resolution_plan_path' => $path]);

        $detailRouteEnabled = [];
        $robotsIndexable = [];
        $releaseGatePass = [];
        $items = [];
        foreach ($rows as $row) {
            $slug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }

            $enabled = in_array((string) ($row['status'] ?? ''), ['already_imported_validated', 'upload_candidate'], true)
                && $slug !== 'software-developers';
            $detailRouteEnabled[$slug] = $enabled;
            $robotsIndexable[$slug] = $enabled;
            $releaseGatePass[$slug] = $enabled;
            foreach (['en', 'zh'] as $locale) {
                $items[$slug.'|'.$locale] = [
                    'slug' => $slug,
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

    private function createPublishedDocxCareerJob(string $slug): CareerJob
    {
        $job = CareerJob::query()->create([
            'org_id' => 0,
            'job_code' => $slug,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'title' => str($slug)->replace('-', ' ')->title()->toString(),
            'subtitle' => str($slug)->replace('-', ' ')->title()->toString(),
            'excerpt' => 'Published DOCX fallback fixture.',
            'body_md' => '# Published DOCX fallback fixture',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'salary_json' => ['annual_median_usd' => 81350],
            'outlook_json' => ['jobs_2024' => 1000],
            'growth_path_json' => ['raw' => ['Fixture growth path.']],
            'market_demand_json' => [
                'ai_exposure_score_10' => 6,
                'source_refs' => [
                    [
                        'label' => 'BLS Occupational Outlook Handbook',
                        'url' => 'https://www.bls.gov/ooh/fixture.htm',
                    ],
                ],
            ],
        ]);

        CareerJobSeoMeta::query()->create([
            'job_id' => (int) $job->id,
            'jsonld_overrides_json' => [
                'source_docx' => $slug.'.docx',
            ],
        ]);

        return $job;
    }

    private function createDisplayAssetBackedOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => $slug.'-family',
            'title_en' => 'Display Backed Family',
            'title_zh' => '展示资产职业族',
        ]);

        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => str($slug)->replace('-', ' ')->title()->toString(),
            'canonical_title_zh' => '展示资产职业',
            'search_h1_zh' => '展示资产职业',
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
        ]);

        foreach ([
            ['source_system' => 'us_soc', 'source_code' => '15-1252'],
            ['source_system' => 'onet_soc_2019', 'source_code' => '15-1252.00'],
        ] as $crosswalk) {
            OccupationCrosswalk::query()->create([
                'occupation_id' => $occupation->id,
                'source_system' => $crosswalk['source_system'],
                'source_code' => $crosswalk['source_code'],
                'source_title' => 'Display Backed Occupation',
                'mapping_type' => 'direct_match',
                'confidence_score' => 1.0,
            ]);
        }

        return $occupation;
    }

    private function createDisplayAsset(Occupation $occupation, array $overrides = []): CareerJobDisplayAsset
    {
        $zhPage = [
            'hero' => ['title' => '展示资产职业'],
            'market_signal_card' => [
                'salary_data_type' => 'BLS official wage evidence',
                'body' => 'Official market signal from BLS.',
            ],
            'ai_impact_table' => [
                'score_normalized' => '82',
                'source' => 'FermatMind central score',
            ],
        ];
        $enPage = [
            'hero' => ['title' => 'Display backed career'],
            'market_signal_card' => [
                'salary_data_type' => 'BLS official wage evidence',
                'body' => 'Official market signal from BLS.',
            ],
            'ai_impact_table' => [
                'score_normalized' => '82',
                'source' => 'FermatMind central score',
            ],
        ];

        return CareerJobDisplayAsset::query()->create(array_replace([
            'occupation_id' => $occupation->id,
            'canonical_slug' => (string) $occupation->canonical_slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'component_order_json' => array_map(static fn (int $index): string => 'component_'.$index, range(1, 24)),
            'page_payload_json' => [
                'page' => [
                    'zh' => $zhPage,
                    'en' => $enPage,
                ],
            ],
            'seo_payload_json' => [],
            'sources_json' => [
                'primary' => [
                    ['label' => 'BLS Occupational Outlook Handbook', 'url' => 'https://www.bls.gov/ooh/fixture.htm'],
                ],
            ],
            'structured_data_json' => [
                '@type' => 'Occupation',
                'name' => (string) $occupation->canonical_title_en,
            ],
            'implementation_contract_json' => [],
            'metadata_json' => [],
        ], $overrides));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pageContentForDisplayComponentOrder(string $bodyPrefix): array
    {
        $content = [];
        foreach (self::DISPLAY_COMPONENT_ORDER as $moduleKey) {
            $content[$moduleKey] = ['body' => $bodyPrefix.': '.$moduleKey];
        }

        $content['market_signal_card'] = [
            'salary_data_type' => 'BLS official wage evidence',
            'body' => $bodyPrefix.': market_signal_card',
        ];
        $content['ai_impact_table'] = [
            'score_normalized' => '82',
            'source' => 'FermatMind central score',
        ];

        return $content;
    }
}
