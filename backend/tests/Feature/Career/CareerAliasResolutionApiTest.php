<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAliasResolutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_occupation_resolution_for_a_public_safe_leaf_alias(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $occupation = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Applied Data Scientist',
            'normalized' => 'applied data scientist',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);

        $this->getJson('/api/v0.5/career/resolve?q=applied%20data%20scientist&locale=en-US')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_alias_resolution')
            ->assertJsonPath('bundle_version', 'career.protocol.alias_resolution.v1')
            ->assertJsonPath('query.raw', 'applied data scientist')
            ->assertJsonPath('query.normalized', 'applied data scientist')
            ->assertJsonPath('query.locale', 'en-us')
            ->assertJsonPath('resolution.resolved_kind', 'occupation')
            ->assertJsonPath('resolution.occupation.canonical_slug', 'data-scientists')
            ->assertJsonPath('resolution.occupation.seo_contract.index_eligible', true)
            ->assertJsonPath('resolution.occupation.trust_summary.reviewer_status', 'approved')
            ->assertJsonMissingPath('resolution.family')
            ->assertJsonMissingPath('resolution.candidates');
    }

    public function test_it_returns_family_for_an_authority_backed_family_query(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $family = OccupationFamily::query()->where('canonical_slug', 'computer-and-information-technology')->firstOrFail();

        $this->getJson('/api/v0.5/career/resolve?q=computer-and-information-technology&locale=en')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'family')
            ->assertJsonPath('resolution.family.canonical_slug', 'computer-and-information-technology')
            ->assertJsonPath('resolution.family.title_en', $family->title_en)
            ->assertJsonMissingPath('resolution.occupation')
            ->assertJsonMissingPath('resolution.candidates');
    }

    public function test_it_returns_ambiguous_with_public_safe_candidates_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $first = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();
        $second = Occupation::query()->where('canonical_slug', 'management-analysts')->firstOrFail();

        foreach ([$first, $second] as $chain) {
            OccupationAlias::query()->create([
                'occupation_id' => $chain->id,
                'family_id' => $chain->family_id,
                'alias' => 'Applied Analytics Architect',
                'normalized' => 'applied analytics architect',
                'lang' => 'en-US',
                'register' => 'alias',
                'intent_scope' => 'specialized',
                'target_kind' => 'leaf_or_child',
                'precision_score' => 0.92,
                'confidence_score' => 0.93,
            ]);
        }

        $response = $this->getJson('/api/v0.5/career/resolve?q=applied%20analytics%20architect&locale=en-US');

        $response
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'ambiguous')
            ->assertJsonCount(2, 'resolution.candidates')
            ->assertJsonMissingPath('resolution.occupation')
            ->assertJsonMissingPath('resolution.family');

        $candidateKinds = collect($response->json('resolution.candidates'))->pluck('candidate_kind')->all();
        $candidateSlugs = collect($response->json('resolution.candidates'))->pluck('canonical_slug')->sort()->values()->all();

        $this->assertSame(['occupation', 'occupation'], $candidateKinds);
        $this->assertSame(['data-scientists', 'management-analysts'], $candidateSlugs);
    }

    public function test_it_returns_none_for_unknown_or_unsafe_queries(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $blocked = $this->compileJobChain(CareerFoundationFixture::seedTrustLimitedCrossMarketChain());
        $blocked['occupation']->update([
            'canonical_slug' => 'software-developers',
            'canonical_title_en' => 'Software Developers',
        ]);

        OccupationAlias::query()->create([
            'occupation_id' => $blocked['occupation']->id,
            'family_id' => $blocked['occupation']->family_id,
            'alias' => 'Unsafe Resolve Target',
            'normalized' => 'unsafe resolve target',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.9,
            'confidence_score' => 0.9,
        ]);

        $this->getJson('/api/v0.5/career/resolve?q=unsafe%20resolve%20target&locale=en-US')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'none')
            ->assertJsonMissingPath('resolution.occupation')
            ->assertJsonMissingPath('resolution.family')
            ->assertJsonMissingPath('resolution.candidates');
    }

    public function test_it_rejects_empty_query_conservatively(): void
    {
        $this->getJson('/api/v0.5/career/resolve?q=')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    /**
     * @param  array<string, mixed>  $chain
     * @return array<string, mixed>
     */
    private function compileJobChain(array $chain): array
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-resolve-api-'.$chain['occupation']->canonical_slug,
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

        return [
            'importRun' => $importRun,
            'compileRun' => $compileRun,
        ] + $chain;
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
