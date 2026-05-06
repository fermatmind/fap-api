<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_it_returns_ledger_backed_duplicate_alias_with_non_index_policy(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $occupation = Occupation::query()->where('canonical_slug', 'data-scientists')->firstOrFail();

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Duplicate Api Approved Alias',
            'normalized' => 'duplicate-api-approved-alias',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $this->getJson('/api/v0.5/career/resolve?q=duplicate-api-approved-alias&locale=en-US')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'occupation')
            ->assertJsonPath('resolution.occupation.canonical_slug', 'data-scientists')
            ->assertJsonPath('resolution.occupation.seo_contract.canonical_path', '/career/jobs/data-scientists')
            ->assertJsonMissingPath('resolution.occupation.alias_url');
    }

    public function test_it_returns_display_asset_backed_duplicate_alias_without_recommendation_snapshot(): void
    {
        $occupation = $this->createDisplayAssetBackedOccupation(
            'food-scientists-and-technologists',
            'Food Scientists and Technologists',
        );
        $this->createDisplayAsset($occupation);
        $this->createReleaseEligibleCareerJobs((string) $occupation->canonical_slug);

        $this->assertSame(0, RecommendationSnapshot::query()->where('occupation_id', $occupation->id)->count());

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Agricultural and Food Scientists',
            'normalized' => 'agricultural-and-food-scientists',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $this->getJson('/api/v0.5/career/resolve?q=agricultural-and-food-scientists&locale=en-US')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'occupation')
            ->assertJsonPath('resolution.occupation.canonical_slug', 'food-scientists-and-technologists')
            ->assertJsonPath('resolution.occupation.seo_contract.canonical_path', '/career/jobs/food-scientists-and-technologists')
            ->assertJsonPath('resolution.occupation.seo_contract.index_eligible', true)
            ->assertJsonPath('resolution.occupation.trust_summary.reviewer_status', 'approved_display_asset')
            ->assertJsonMissingPath('resolution.occupation.alias_url');
    }

    public function test_it_rejects_blocked_duplicate_rows_without_materialized_alias(): void
    {
        $this->createDisplayAsset($this->createDisplayAssetBackedOccupation(
            'farmworkers-and-laborers-crop-nursery-and-greenhouse',
            'Farmworkers and Laborers Crop Nursery and Greenhouse',
        ));

        $this->getJson('/api/v0.5/career/resolve?q=agricultural-workers&locale=en-US')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'none')
            ->assertJsonMissingPath('resolution.occupation')
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

    public function test_it_returns_family_for_a_curated_family_target_alias(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $this->getJson('/api/v0.5/career/resolve?q=information%20technology%20careers&locale=en')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'family')
            ->assertJsonPath('resolution.family.canonical_slug', 'computer-and-information-technology')
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

    public function test_it_does_not_return_family_for_explicit_family_aliases_when_the_family_has_no_visible_children(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'empty-family-api-resolution',
            'title_en' => 'Empty Family Api Resolution',
            'title_zh' => '空家族接口解析',
        ]);

        OccupationAlias::query()->create([
            'occupation_id' => null,
            'family_id' => $family->id,
            'alias' => 'Empty Family Api Careers',
            'normalized' => 'empty family api careers',
            'lang' => 'en',
            'register' => 'family_market_title',
            'intent_scope' => 'exact',
            'target_kind' => 'family',
            'precision_score' => 0.95,
            'confidence_score' => 0.95,
        ]);

        $this->getJson('/api/v0.5/career/resolve?q=empty%20family%20api%20careers&locale=en')
            ->assertOk()
            ->assertJsonPath('resolution.resolved_kind', 'none')
            ->assertJsonMissingPath('resolution.occupation')
            ->assertJsonMissingPath('resolution.family')
            ->assertJsonMissingPath('resolution.candidates');
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

    private function createDisplayAssetBackedOccupation(string $slug, string $title): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'display-backed-api-alias-resolution',
            'title_en' => 'Display Backed Api Alias Resolution',
            'title_zh' => '展示资产接口别名解析',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'US',
            'display_market' => 'zh-CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $title,
            'search_h1_zh' => $title,
            'structural_stability' => null,
            'task_prototype_signature' => [],
            'market_semantics_gap' => null,
            'regulatory_divergence' => null,
            'toolchain_divergence' => null,
            'skill_gap_threshold' => null,
            'trust_inheritance_scope' => [],
        ]);
    }

    private function createDisplayAsset(Occupation $occupation): CareerJobDisplayAsset
    {
        return CareerJobDisplayAsset::query()->create([
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
                    'zh' => ['hero' => ['title' => $occupation->canonical_title_zh]],
                    'en' => ['hero' => ['title' => $occupation->canonical_title_en]],
                ],
            ],
            'seo_payload_json' => [],
            'sources_json' => [],
            'structured_data_json' => [],
            'implementation_contract_json' => [],
            'metadata_json' => [],
        ]);
    }

    private function createReleaseEligibleCareerJobs(string $slug): void
    {
        foreach (CareerJob::SUPPORTED_LOCALES as $locale) {
            $job = CareerJob::query()->create([
                'org_id' => 0,
                'job_code' => $slug,
                'slug' => $slug,
                'locale' => $locale,
                'title' => 'Display Asset Target',
                'excerpt' => 'Display asset target excerpt',
                'status' => CareerJob::STATUS_PUBLISHED,
                'is_public' => true,
                'is_indexable' => true,
                'schema_version' => 'v1',
                'sort_order' => 0,
                'published_at' => Carbon::now()->subDay(),
            ]);

            CareerJobSeoMeta::query()->create([
                'job_id' => (int) $job->id,
                'seo_title' => 'Display Asset Target',
                'seo_description' => 'Display asset target description',
                'canonical_url' => 'https://example.test/'.($locale === 'zh-CN' ? 'zh' : $locale).'/career/jobs/'.$slug,
                'og_title' => 'Display Asset Target',
                'og_description' => 'Display asset target description',
                'og_image_url' => 'https://example.test/images/career.png',
                'twitter_title' => 'Display Asset Target',
                'twitter_description' => 'Display asset target description',
                'twitter_image_url' => 'https://example.test/images/career.png',
                'robots' => 'index,follow',
            ]);
        }
    }
}
