<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Services\Career\Bundles\CareerAliasResolutionBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerAliasResolutionBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_unambiguous_public_safe_occupation_resolution(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $occupation = Occupation::query()
            ->where('canonical_slug', 'data-scientists')
            ->firstOrFail();

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

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('applied data scientist', 'en-US')
            ->toArray();

        $this->assertSame('career_alias_resolution', $payload['bundle_kind']);
        $this->assertSame('occupation', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame($occupation->id, data_get($payload, 'resolution.occupation.occupation_uuid'));
        $this->assertSame('data-scientists', data_get($payload, 'resolution.occupation.canonical_slug'));
        $this->assertSame(true, data_get($payload, 'resolution.occupation.seo_contract.index_eligible'));
        $this->assertSame('approved', data_get($payload, 'resolution.occupation.trust_summary.reviewer_status'));
    }

    public function test_it_returns_family_when_family_identity_matches_and_no_safe_leaf_is_selected(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('computer-and-information-technology', 'en')
            ->toArray();

        $this->assertSame('family', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame(
            'computer-and-information-technology',
            data_get($payload, 'resolution.family.canonical_slug')
        );
        $family = OccupationFamily::query()->where('canonical_slug', 'computer-and-information-technology')->firstOrFail();
        $this->assertSame($family->title_en, data_get($payload, 'resolution.family.title_en'));
    }

    public function test_it_returns_family_for_explicit_family_target_aliases_when_the_family_has_visible_children(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('information technology careers', 'en')
            ->toArray();

        $this->assertSame('family', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame(
            'computer-and-information-technology',
            data_get($payload, 'resolution.family.canonical_slug')
        );
    }

    public function test_it_returns_ambiguous_when_multiple_public_safe_leaf_candidates_share_the_same_alias(): void
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

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('applied analytics architect', 'en-US')
            ->toArray();

        $this->assertSame('ambiguous', data_get($payload, 'resolution.resolved_kind'));
        $this->assertCount(2, data_get($payload, 'resolution.candidates'));
        $this->assertSame(
            ['data-scientists', 'management-analysts'],
            collect(data_get($payload, 'resolution.candidates'))
                ->pluck('canonical_slug')
                ->sort()
                ->values()
                ->all()
        );
        $this->assertSame(
            ['occupation', 'occupation'],
            collect(data_get($payload, 'resolution.candidates'))
                ->pluck('candidate_kind')
                ->all()
        );
    }

    public function test_it_omits_blocked_leaf_matches_and_returns_none_when_no_other_public_safe_resolution_exists(): void
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
            'alias' => 'Blocked Resolve Target',
            'normalized' => 'blocked resolve target',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.9,
            'confidence_score' => 0.9,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('blocked resolve target', 'en-US')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
        $this->assertNull(data_get($payload, 'resolution.occupation'));
        $this->assertNull(data_get($payload, 'resolution.family'));
        $this->assertNull(data_get($payload, 'resolution.candidates'));
    }

    public function test_it_returns_none_for_queries_without_any_safe_resolution(): void
    {
        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('query-with-no-career-match', 'en')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
    }

    public function test_it_omits_family_target_aliases_when_the_family_has_no_visible_public_safe_children(): void
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'empty-family-resolution',
            'title_en' => 'Empty Family Resolution',
            'title_zh' => '空家族解析',
        ]);

        OccupationAlias::query()->create([
            'occupation_id' => null,
            'family_id' => $family->id,
            'alias' => 'Empty Family Careers',
            'normalized' => 'empty family careers',
            'lang' => 'en',
            'register' => 'family_market_title',
            'intent_scope' => 'exact',
            'target_kind' => 'family',
            'precision_score' => 0.95,
            'confidence_score' => 0.95,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('empty family careers', 'en')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
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
            'dataset_checksum' => 'checksum-resolve-'.$chain['occupation']->canonical_slug,
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
