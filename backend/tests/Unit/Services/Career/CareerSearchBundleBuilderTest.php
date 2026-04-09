<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\OccupationAlias;
use App\Services\Career\Bundles\CareerSearchBundleBuilder;
use App\Services\Career\CareerRecommendationCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerSearchBundleBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_supports_canonical_slug_exact_and_prefix_matching(): void
    {
        $exact = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect',
            'crosswalk_mode' => 'exact',
        ]));
        $prefix = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-lead',
            'crosswalk_mode' => 'exact',
        ]));

        $results = app(CareerSearchBundleBuilder::class)->build('backend-architect');

        $this->assertCount(2, $results);
        $this->assertSame('canonical_slug_exact', $results[0]->matchKind);
        $this->assertSame('backend-architect', $results[0]->identity['canonical_slug']);
        $this->assertSame('canonical_slug_prefix', $results[1]->matchKind);
        $this->assertSame('backend-architect-lead', $results[1]->identity['canonical_slug']);
        $this->assertSame($exact['compileRun']->id, $results[0]->provenanceMeta['compile_run_id']);
        $this->assertSame($prefix['compileRun']->id, $results[1]->provenanceMeta['compile_run_id']);
    }

    public function test_it_supports_canonical_title_exact_and_prefix_matching(): void
    {
        $exact = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'distributed-platform-architect',
            'crosswalk_mode' => 'exact',
        ]));
        $prefix = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'distributed-platform-lead',
            'crosswalk_mode' => 'exact',
        ]));

        $exact['occupation']->update([
            'canonical_title_en' => 'Distributed Platform Architect',
            'canonical_title_zh' => '分布式平台架构师',
        ]);
        $prefix['occupation']->update([
            'canonical_title_en' => 'Distributed Platform Lead',
            'canonical_title_zh' => '分布式平台负责人',
        ]);

        $exactResults = app(CareerSearchBundleBuilder::class)->build('Distributed Platform Architect');
        $prefixResults = app(CareerSearchBundleBuilder::class)->build('Distributed Platform');

        $this->assertSame('canonical_title_exact', $exactResults[0]->matchKind);
        $this->assertSame('Distributed Platform Architect', $exactResults[0]->matchedText);
        $this->assertSame('canonical_title_prefix', $prefixResults[0]->matchKind);
        $this->assertSame('Distributed Platform Architect', $prefixResults[0]->matchedText);
    }

    public function test_it_supports_alias_exact_and_prefix_matching(): void
    {
        $exact = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'platform-ops-architect',
            'crosswalk_mode' => 'exact',
        ]));
        $prefix = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'platform-ops-specialist',
            'crosswalk_mode' => 'exact',
        ]));

        OccupationAlias::query()->create([
            'occupation_id' => $exact['occupation']->id,
            'alias' => 'Cloud Platform Architect',
            'normalized' => 'cloud platform architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.94,
        ]);
        OccupationAlias::query()->create([
            'occupation_id' => $prefix['occupation']->id,
            'alias' => 'Cloud Platform Specialist',
            'normalized' => 'cloud platform specialist',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.92,
            'confidence_score' => 0.9,
        ]);

        $exactResults = app(CareerSearchBundleBuilder::class)->build('cloud platform architect');
        $prefixResults = app(CareerSearchBundleBuilder::class)->build('cloud platform');

        $this->assertSame('alias_exact', $exactResults[0]->matchKind);
        $this->assertSame('Cloud Platform Architect', $exactResults[0]->matchedText);
        $this->assertSame('alias_prefix', $prefixResults[0]->matchKind);
        $this->assertSame('Cloud Platform Architect', $prefixResults[0]->matchedText);
    }

    public function test_exact_beats_prefix_and_canonical_matches_beat_alias_matches(): void
    {
        $canonical = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-canonical',
            'crosswalk_mode' => 'exact',
        ]));
        $canonical['occupation']->update(['canonical_title_en' => 'Backend Architect']);

        $aliasOnly = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'platform-architecture-specialist',
            'crosswalk_mode' => 'exact',
        ]));
        $aliasOnly['occupation']->update(['canonical_title_en' => 'Platform Architecture Specialist']);
        OccupationAlias::query()->create([
            'occupation_id' => $aliasOnly['occupation']->id,
            'alias' => 'Backend Architect',
            'normalized' => 'backend architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.9,
            'confidence_score' => 0.88,
        ]);

        $results = app(CareerSearchBundleBuilder::class)->build('backend architect');

        $this->assertCount(2, $results);
        $this->assertSame('canonical_title_exact', $results[0]->matchKind);
        $this->assertSame('backend-architect-canonical', $results[0]->identity['canonical_slug']);
        $this->assertSame('alias_exact', $results[1]->matchKind);
    }

    public function test_it_excludes_non_indexable_rows_ignores_crosswalk_codes_and_omits_score_summary(): void
    {
        $visible = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'backend-architect-visible-search',
            'crosswalk_mode' => 'exact',
        ]));
        OccupationAlias::query()->create([
            'occupation_id' => $visible['occupation']->id,
            'alias' => 'Search Safe Architect',
            'normalized' => 'search safe architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);

        $hidden = $this->compileJobChain(CareerFoundationFixture::seedTrustLimitedCrossMarketChain());
        OccupationAlias::query()->create([
            'occupation_id' => $hidden['occupation']->id,
            'alias' => 'Search Safe Architect',
            'normalized' => 'search safe architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.91,
            'confidence_score' => 0.89,
        ]);

        $results = app(CareerSearchBundleBuilder::class)->build('search safe architect');
        $payload = $results[0]->toArray();

        $this->assertCount(1, $results);
        $this->assertSame('backend-architect-visible-search', $results[0]->identity['canonical_slug']);
        $this->assertArrayNotHasKey('score_summary', $payload);
        $this->assertSame([], app(CareerSearchBundleBuilder::class)->build('15-1252'));
    }

    public function test_it_scopes_alias_matching_by_locale_when_locale_is_supplied(): void
    {
        $en = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'cloud-architect-en',
            'crosswalk_mode' => 'exact',
        ]));
        $zh = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'cloud-architect-zh',
            'crosswalk_mode' => 'exact',
        ]));

        OccupationAlias::query()->create([
            'occupation_id' => $en['occupation']->id,
            'alias' => 'Cloud Reliability Architect',
            'normalized' => 'cloud reliability architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);
        OccupationAlias::query()->create([
            'occupation_id' => $zh['occupation']->id,
            'alias' => '云可靠性架构师',
            'normalized' => '云可靠性架构师',
            'lang' => 'zh-CN',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.96,
        ]);

        $enResults = app(CareerSearchBundleBuilder::class)->build('cloud reliability architect', locale: 'en-US');
        $zhResults = app(CareerSearchBundleBuilder::class)->build('云可靠性架构师', locale: 'zh-CN');

        $this->assertCount(1, $enResults);
        $this->assertSame('cloud-architect-en', $enResults[0]->identity['canonical_slug']);
        $this->assertSame('alias_exact', $enResults[0]->matchKind);

        $this->assertCount(1, $zhResults);
        $this->assertSame('cloud-architect-zh', $zhResults[0]->identity['canonical_slug']);
        $this->assertSame('alias_exact', $zhResults[0]->matchKind);
    }

    public function test_it_excludes_direct_match_rows_from_public_safe_search_scope(): void
    {
        $direct = $this->compileJobChain(CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'direct-match-architect',
            'crosswalk_mode' => 'direct_match',
        ]));

        OccupationAlias::query()->create([
            'occupation_id' => $direct['occupation']->id,
            'alias' => 'Direct Match Architect',
            'normalized' => 'direct match architect',
            'lang' => 'en-US',
            'register' => 'alias',
            'intent_scope' => 'specialized',
            'target_kind' => 'leaf_or_child',
            'precision_score' => 0.95,
            'confidence_score' => 0.95,
        ]);

        $this->assertSame([], app(CareerSearchBundleBuilder::class)->build('direct match architect'));
    }

    /**
     * @param  array<string, mixed>  $chain
     * @return array<string, mixed>
     */
    private function compileJobChain(array $chain, ?Carbon $compiledAt = null): array
    {
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-search-'.$chain['occupation']->canonical_slug,
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

        if ($compiledAt !== null) {
            $snapshot->forceFill(['compiled_at' => $compiledAt])->save();
        }

        return [
            'importRun' => $importRun,
            'compileRun' => $compileRun,
            'snapshot' => $snapshot,
        ] + $chain;
    }
}
