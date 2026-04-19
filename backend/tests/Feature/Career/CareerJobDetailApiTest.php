<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobSection;
use App\Models\CareerJobSeoMeta;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerJobDetailApiTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertJsonPath('seo_contract.canonical_path', '/career/jobs/backend-architect')
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
            ->assertJsonPath('seo_contract.canonical_path', '/career/jobs/accountants-and-auditors')
            ->assertJsonPath('claim_permissions.allow_strong_claim', true)
            ->assertJsonPath('provenance_meta.compile_refs.source_docx', '01_会计师和审计师_accountants-and-auditors.docx');
    }
}
