<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\OccupationAlias;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_resource_backed_lightweight_search_response(): void
    {
        $chain = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-search-api',
        ]));
        $chain['occupation']->update([
            'canonical_title_en' => 'Backend Search Architect',
        ]);
        OccupationAlias::query()->create([
            'occupation_id' => $chain['occupation']->id,
            'alias' => 'Search Architecture Lead',
            'normalized' => 'search architecture lead',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);

        $this->getJson('/api/v0.5/career/search?q=backend-architect-search&limit=5&mode=prefix')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_search_results')
            ->assertJsonPath('query.q', 'backend-architect-search')
            ->assertJsonPath('query.limit', 5)
            ->assertJsonPath('items.0.identity.canonical_slug', 'backend-architect-search-api')
            ->assertJsonPath('items.0.match_kind', 'canonical_slug_prefix')
            ->assertJsonMissingPath('items.0.score_summary')
            ->assertJsonStructure([
                'bundle_kind',
                'bundle_version',
                'query' => ['q', 'limit', 'locale', 'mode'],
                'items' => [[
                    'match_kind',
                    'matched_text',
                    'identity' => ['occupation_uuid', 'canonical_slug'],
                    'titles',
                    'seo_contract' => ['canonical_path', 'canonical_target', 'index_state', 'index_eligible', 'reason_codes'],
                    'trust_summary',
                    'provenance_meta' => ['compiler_version', 'compile_run_id'],
                ]],
            ]);
    }

    public function test_it_rejects_empty_query_conservatively(): void
    {
        $this->getJson('/api/v0.5/career/search?q=')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    public function test_it_rejects_whitespace_only_query_conservatively(): void
    {
        $this->getJson('/api/v0.5/career/search?q=%20%20%20')
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
            'dataset_checksum' => 'checksum-search-api-'.$chain['occupation']->canonical_slug,
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

        $snapshot = app(CareerRecommendationCompiler::class)->compile($chain['childProjection'], $chain['occupation'], [
            'compile_run_id' => $compileRun->id,
            'import_run_id' => $importRun->id,
        ]);

        return [
            'importRun' => $importRun,
            'compileRun' => $compileRun,
            'snapshot' => $snapshot,
        ] + $chain;
    }
}
