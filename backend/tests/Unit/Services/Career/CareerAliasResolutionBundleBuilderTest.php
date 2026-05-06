<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\CareerJobSeoMeta;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Models\RecommendationSnapshot;
use App\Services\Career\Bundles\CareerAliasResolutionBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    public function test_it_resolves_ledger_backed_duplicate_alias_to_approved_canonical_target(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $occupation = Occupation::query()
            ->where('canonical_slug', 'data-scientists')
            ->firstOrFail();

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Duplicate Identity Approved Alias',
            'normalized' => 'duplicate-identity-approved-alias',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('duplicate-identity-approved-alias', 'en-US')
            ->toArray();

        $this->assertSame('occupation', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame('data-scientists', data_get($payload, 'resolution.occupation.canonical_slug'));
        $this->assertSame('/career/jobs/data-scientists', data_get($payload, 'resolution.occupation.seo_contract.canonical_path'));
        $this->assertSame(true, data_get($payload, 'resolution.occupation.seo_contract.index_eligible'));
        $this->assertArrayNotHasKey('alias_url', data_get($payload, 'resolution.occupation'));
    }

    public function test_it_resolves_ledger_duplicate_alias_to_display_asset_backed_target_without_snapshot(): void
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

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('agricultural-and-food-scientists', 'en-US')
            ->toArray();

        $this->assertSame('occupation', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame('food-scientists-and-technologists', data_get($payload, 'resolution.occupation.canonical_slug'));
        $this->assertSame('/career/jobs/food-scientists-and-technologists', data_get($payload, 'resolution.occupation.seo_contract.canonical_path'));
        $this->assertSame(true, data_get($payload, 'resolution.occupation.seo_contract.index_eligible'));
        $this->assertSame('approved_display_asset', data_get($payload, 'resolution.occupation.trust_summary.reviewer_status'));
        $this->assertArrayNotHasKey('alias_url', data_get($payload, 'resolution.occupation'));
    }

    public function test_it_rejects_display_asset_duplicate_alias_when_target_is_noindex(): void
    {
        $occupation = $this->createDisplayAssetBackedOccupation(
            'food-scientists-and-technologists',
            'Food Scientists and Technologists',
        );
        $this->createDisplayAsset($occupation);
        $this->createReleaseEligibleCareerJobs((string) $occupation->canonical_slug, indexable: false);

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

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('agricultural-and-food-scientists', 'en-US')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
        $this->assertNull(data_get($payload, 'resolution.occupation'));
        $this->assertNull(data_get($payload, 'resolution.family'));
        $this->assertNull(data_get($payload, 'resolution.candidates'));
    }

    public function test_it_does_not_resolve_display_asset_alias_when_duplicate_ledger_metadata_is_missing(): void
    {
        $occupation = $this->createDisplayAssetBackedOccupation(
            'broadcast-announcers-and-radio-disc-jockeys',
            'Broadcast Announcers and Radio Disc Jockeys',
        );
        $this->createDisplayAsset($occupation);

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Announcers',
            'normalized' => 'announcers',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.9,
            'confidence_score' => 0.9,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('announcers', 'en-US')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
    }

    public function test_it_rejects_display_asset_backed_duplicate_alias_for_manual_hold_target(): void
    {
        $occupation = $this->createDisplayAssetBackedOccupation('software-developers', 'Software Developers');
        $this->createDisplayAsset($occupation);

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Software Development Workers',
            'normalized' => 'software-development-workers',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('software-development-workers', 'en-US')
            ->toArray();

        $this->assertSame('none', data_get($payload, 'resolution.resolved_kind'));
    }

    public function test_it_bounds_alias_lookup_queries_by_normalized_input(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $occupation = Occupation::query()
            ->where('canonical_slug', 'data-scientists')
            ->firstOrFail();

        OccupationAlias::query()->create([
            'occupation_id' => $occupation->id,
            'family_id' => $occupation->family_id,
            'alias' => 'Bounded Resolution Analyst',
            'normalized' => 'bounded resolution analyst',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);

        foreach (range(1, 70) as $index) {
            OccupationAlias::query()->create([
                'occupation_id' => $occupation->id,
                'family_id' => $occupation->family_id,
                'alias' => 'Unrelated Resolution Alias '.$index,
                'normalized' => 'unrelated resolution alias '.$index,
                'lang' => 'en-US',
                'register' => 'alias',
                'intent_scope' => 'specialized',
                'target_kind' => 'leaf_or_child',
                'precision_score' => 0.5,
                'confidence_score' => 0.5,
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('bounded resolution analyst', 'en-US')
            ->toArray();

        $this->assertSame('occupation', data_get($payload, 'resolution.resolved_kind'));
        $this->assertSame('data-scientists', data_get($payload, 'resolution.occupation.canonical_slug'));

        $aliasQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'occupation_aliases')
        ));

        $this->assertNotEmpty($aliasQueries);
        $this->assertLessThanOrEqual(3, count($aliasQueries), implode("\n", $aliasQueries));

        foreach ($aliasQueries as $sql) {
            $this->assertStringContainsString('normalized', $sql);
            $this->assertStringContainsString('limit', $sql);
        }
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

    public function test_it_rejects_ledger_duplicate_alias_when_target_is_not_publish_ready(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $blocked = $this->compileJobChain(CareerFoundationFixture::seedTrustLimitedCrossMarketChain());
        $blocked['occupation']->update([
            'canonical_slug' => 'blocked-duplicate-target',
            'canonical_title_en' => 'Blocked Duplicate Target',
        ]);

        OccupationAlias::query()->create([
            'occupation_id' => $blocked['occupation']->id,
            'family_id' => $blocked['occupation']->family_id,
            'alias' => 'Blocked Duplicate Approved Alias',
            'normalized' => 'blocked-duplicate-approved-alias',
            'lang' => 'en-US',
            'register' => 'public_resolution_duplicate_alias',
            'intent_scope' => 'duplicate_identity',
            'target_kind' => 'ledger_public_alias_redirect',
            'precision_score' => 1.0,
            'confidence_score' => 1.0,
        ]);

        $payload = app(CareerAliasResolutionBundleBuilder::class)
            ->build('blocked-duplicate-approved-alias', 'en-US')
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

    private function createDisplayAssetBackedOccupation(string $slug, string $title): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'display-backed-alias-resolution',
            'title_en' => 'Display Backed Alias Resolution',
            'title_zh' => '展示资产别名解析',
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

    private function createReleaseEligibleCareerJobs(string $slug, bool $indexable = true): void
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
                'is_indexable' => $indexable,
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
                'robots' => $indexable ? 'index,follow' : 'noindex,follow',
            ]);
        }
    }
}
