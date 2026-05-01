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
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_resource_backed_lightweight_search_response(): void
    {
        $chain = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-search-api',
            'crosswalk_mode' => 'exact',
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

    public function test_it_does_not_expand_wildcard_or_path_like_search_input(): void
    {
        $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'wildcard-safe-search-api',
            'crosswalk_mode' => 'exact',
        ]));
        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'wildcard-safe-directory-draft',
            'canonical_title_en' => 'Wildcard Safe Directory Draft',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v0.5/career/search?q='.urlencode('../%').'&limit=5&mode=prefix')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_search_results')
            ->assertJsonPath('query.q', '../%')
            ->assertJsonCount(0, 'items');

        $queries = collect(DB::getQueryLog())
            ->map(static fn (array $entry): string => strtolower((string) ($entry['query'] ?? '')))
            ->filter(static fn (string $query): bool => str_contains($query, 'recommendation_snapshots')
                || str_contains($query, 'occupations')
                || str_contains($query, 'occupation_aliases'))
            ->values();

        $this->assertTrue($queries->contains(static fn (string $query): bool => str_contains($query, "escape '!'")));
        $this->assertTrue($queries->contains(static fn (string $query): bool => str_contains($query, 'limit')));

        DB::disableQueryLog();
    }

    public function test_it_exposes_directory_draft_search_without_internal_metadata(): void
    {
        $this->createDirectoryDraftOccupation([
            'canonical_slug' => 'us-ai-compliance-analyst',
            'canonical_title_en' => 'AI Compliance Analyst',
            'canonical_title_zh' => 'AI 合规分析师',
            'search_h1_zh' => 'AI 合规分析师',
        ]);

        $response = $this->getJson('/api/v0.5/career/search?q=AI%20Compliance&limit=5&mode=prefix&locale=en-US')
            ->assertOk()
            ->assertJsonPath('bundle_kind', 'career_search_results')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.identity.canonical_slug', 'us-ai-compliance-analyst')
            ->assertJsonPath('items.0.match_kind', 'canonical_title_prefix')
            ->assertJsonPath('items.0.trust_summary.public_stub_kind', 'public_directory_stub')
            ->assertJsonPath('items.0.trust_summary.status', 'unavailable')
            ->assertJsonPath('items.0.trust_summary.availability', 'detail_unavailable')
            ->assertJsonPath('items.0.seo_contract.index_eligible', false)
            ->assertJsonPath('items.0.seo_contract.public_stub_kind', 'public_directory_stub')
            ->assertJsonPath('items.0.seo_contract.reason_codes.0', 'detail_page_unavailable')
            ->assertJsonPath('items.0.seo_contract.robots_policy', 'noindex,follow')
            ->assertJsonMissingPath('items.0.identity.occupation_uuid')
            ->assertJsonMissingPath('items.0.trust_summary.reviewer_status')
            ->assertJsonMissingPath('items.0.trust_summary.review_status')
            ->assertJsonMissingPath('items.0.trust_summary.content_version')
            ->assertJsonMissingPath('items.0.trust_summary.data_version')
            ->assertJsonMissingPath('items.0.trust_summary.logic_version')
            ->assertJsonMissingPath('items.0.trust_summary.cross_market_notice')
            ->assertJsonMissingPath('items.0.provenance_meta.import_run_id')
            ->assertJsonMissingPath('items.0.provenance_meta.source_snapshot_id')
            ->assertJsonMissingPath('items.0.provenance_meta.compile_run_id')
            ->assertJsonMissingPath('items.0.provenance_meta.index_state_id')
            ->assertJsonMissingPath('items.0.governance')
            ->assertJsonMissingPath('items.0.readiness');

        $item = $response->json('items.0');
        $this->assertSame(['canonical_slug'], array_keys($item['identity']));
        $this->assertSame([], $item['provenance_meta']);

        $this->getJson('/api/v0.5/career/jobs/us-ai-compliance-analyst')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDirectoryDraftOccupation(array $overrides = []): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'directory-draft-search-family',
            'title_en' => 'Directory Draft Search Family',
            'title_zh' => '目录草稿搜索职业族',
        ]);
        $occupation = Occupation::query()->create(array_merge([
            'family_id' => $family->id,
            'canonical_slug' => 'directory-draft-search-specialist',
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => 'Directory Draft Search Specialist',
            'canonical_title_zh' => '目录草稿搜索专员',
            'search_h1_zh' => '目录草稿搜索专员',
        ], $overrides));
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'china_us_occupation_directories_2026',
            'dataset_version' => '2026',
            'dataset_checksum' => 'directory-draft-search-checksum',
            'scope_mode' => 'occupation_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'US_ONET',
            'source_code' => 'US-TEST-001',
            'source_title' => (string) $occupation->canonical_title_en,
            'mapping_type' => 'directory_draft',
            'confidence_score' => 0.5,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'directory-draft-search-crosswalk'),
        ]);
        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'alias' => (string) $occupation->canonical_title_en,
            'normalized' => strtolower((string) $occupation->canonical_title_en),
            'lang' => 'en-US',
            'register' => 'canonical',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 1,
            'confidence_score' => 1,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'directory-draft-search-alias'),
        ]);

        return $occupation->fresh();
    }
}
