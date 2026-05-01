<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerJobListApiTest extends TestCase
{
    use RefreshDatabase;

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

        $this->getJson('/api/v0.5/career/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'cn-digital-compliance-specialist')
            ->assertJsonPath('items.0.trust_summary.status', 'unavailable')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonPath('items.0.seo_contract.reason_codes.0', 'detail_page_unavailable')
            ->assertJsonMissingPath('items.0.trust_summary.reviewer_status')
            ->assertJsonMissingPath('items.0.trust_summary.content_version')
            ->assertJsonMissingPath('items.0.trust_summary.data_version')
            ->assertJsonMissingPath('items.0.trust_summary.logic_version')
            ->assertJsonMissingPath('items.0.provenance_meta.content_version')
            ->assertJsonMissingPath('items.0.provenance_meta.data_version')
            ->assertJsonMissingPath('items.0.provenance_meta.logic_version')
            ->assertJsonMissingPath('items.0.provenance_meta.import_run_id');

        $this->getJson('/api/v0.5/career/jobs/cn-digital-compliance-specialist')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    /**
     * @param  array<string, mixed>  $chain
     */
    private function compileJobChain(array $chain): void
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-job-api-'.$chain['occupation']->canonical_slug,
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
}
