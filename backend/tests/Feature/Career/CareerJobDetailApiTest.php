<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
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

        $this->getJson('/api/v0.5/career/jobs/backend-architect')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_job_detail')
            ->assertJsonPath('identity.canonical_slug', 'backend-architect')
            ->assertJsonPath('trust_manifest.content_version', 'v4.1')
            ->assertJsonPath('seo_contract.canonical_path', '/career/jobs/backend-architect')
            ->assertJsonStructure([
                'identity',
                'locale_policy',
                'titles',
                'alias_index',
                'ontology',
                'truth_layer',
                'trust_manifest',
                'score_bundle' => ['fit_score'],
                'warnings',
                'claim_permissions',
                'integrity_summary',
                'seo_contract',
                'provenance_meta' => ['compiler_version', 'compile_refs'],
            ]);
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
}
