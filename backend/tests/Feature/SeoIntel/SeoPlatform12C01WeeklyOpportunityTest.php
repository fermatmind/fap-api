<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12WeeklyOpportunityEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12C01WeeklyOpportunityTest extends TestCase
{
    public function test_weekly_evaluator_outputs_all_candidate_kinds_with_required_evidence_fields(): void
    {
        $kinds = ['opportunity', 'decay', 'review_due', 'query_owner_conflict', 'cannibalization', 'internal_link', 'orphan'];
        $evidence = ['evaluated_at' => '2026-09-07T03:00:00Z', 'candidates' => []];
        foreach ($kinds as $index => $kind) {
            $evidence['candidates'][] = $this->candidate($kind, 'zh-CN', $index + 1, true);
        }

        $artifact = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate($evidence, 'zh-CN');

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame($kinds, array_column($artifact['candidates'], 'kind'));
        foreach ($artifact['candidates'] as $candidate) {
            $this->assertNotSame([], $candidate['evidence_refs']);
            $this->assertIsInt($candidate['confidence_ppm']);
            $this->assertArrayHasKey('unknowns', $candidate);
            $this->assertArrayHasKey('family', $candidate);
            $this->assertSame('zh-CN', $candidate['locale']);
        }
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);
    }

    public function test_unresolved_query_owner_conflict_holds_without_writes(): void
    {
        $artifact = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate([
            'evaluated_at' => '2026-09-07T03:00:00Z',
            'candidates' => [$this->candidate('query_owner_conflict', 'zh-CN', 1, false)],
        ], 'zh-CN');

        $this->assertSame('HOLD', $artifact['state']);
        $this->assertSame(['QUERY_OWNER_CONFLICT_UNRESOLVED'], $artifact['reason_codes']);
        $this->assertSame(1, $artifact['unresolved_owner_conflict_count']);
        $this->assertSame(['query_owner' => false, 'url_truth' => false, 'cms' => false], $artifact['writes']);
        $this->assertFalse($artifact['execution_allowed']);
        $this->assertTrue($artifact['artifact_only']);
    }

    public function test_chinese_and_english_are_evaluated_independently(): void
    {
        $zh = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate([
            'evaluated_at' => '2026-09-07T03:00:00Z',
            'candidates' => [$this->candidate('decay', 'zh-CN', 1, true)],
        ], 'zh-CN');
        $en = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate([
            'evaluated_at' => '2026-09-07T03:10:00Z',
            'candidates' => [],
        ], 'en');

        $this->assertSame('READY', $zh['state']);
        $this->assertSame(1, $zh['candidate_count']);
        $this->assertSame('VALID_ZERO', $en['state']);
        $this->assertSame(0, $en['candidate_count']);
        $this->assertNotSame($zh['artifact_hash'], $en['artifact_hash']);

        $mismatched = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate([
            'evaluated_at' => '2026-09-07T03:10:00Z',
            'candidates' => [$this->candidate('decay', 'zh-CN', 1, true)],
        ], 'en');
        $this->assertSame('HOLD', $mismatched['state']);
    }

    public function test_raw_queries_are_not_emitted_in_candidate_artifact(): void
    {
        $candidate = $this->candidate('opportunity', 'en', 1, true);
        $candidate['raw_query'] = 'private search phrase';
        $artifact = app(Platform12WeeklyOpportunityEvaluator::class)->evaluate([
            'evaluated_at' => '2026-09-07T03:10:00Z',
            'candidates' => [$candidate],
        ], 'en');
        $encoded = json_encode($artifact, JSON_THROW_ON_ERROR);

        $this->assertSame('READY', $artifact['state']);
        $this->assertStringNotContainsString('private search phrase', $encoded);
        $this->assertStringNotContainsString('raw_query', $encoded);
    }

    public function test_catalog_declares_two_zero_budget_locale_missions_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $missions = collect($catalog['missions'])->whereIn('mission_id', [
            'seo.platform12.weekly_opportunity_zh_cn',
            'seo.platform12.weekly_opportunity_en',
        ])->values();

        $this->assertCount(2, $missions);
        $this->assertSame(['zh-CN', 'en'], $missions->pluck('locale')->all());
        foreach ($missions as $mission) {
            $this->assertSame(0, array_sum($mission['budgets']));
        }
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $consoleRoutes = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('seo.platform12.weekly_opportunity_zh_cn', $consoleRoutes);
        $this->assertStringNotContainsString('seo.platform12.weekly_opportunity_en', $consoleRoutes);
    }

    /** @return array<string,mixed> */
    private function candidate(string $kind, string $locale, int $seed, bool $resolved): array
    {
        return [
            'candidate_ref' => hash('sha256', $kind.$locale.$seed),
            'kind' => $kind,
            'family' => $seed % 2 === 0 ? 'career' : 'articles_topics',
            'locale' => $locale,
            'evidence_refs' => [hash('sha256', 'evidence'.$seed)],
            'confidence_ppm' => 800000,
            'unknowns' => $seed % 2 === 0 ? ['seasonality'] : [],
            'owner_conflict_resolved' => $resolved,
        ];
    }
}
