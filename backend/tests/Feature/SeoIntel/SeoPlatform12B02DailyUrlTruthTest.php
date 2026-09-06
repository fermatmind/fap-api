<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12DailyUrlTruthEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12B02DailyUrlTruthTest extends TestCase
{
    public function test_offline_fixtures_are_deterministic_and_read_only(): void
    {
        $fixtures = json_decode(
            (string) file_get_contents(resource_path('seo-agent/council/platform12/fixtures/seo.platform12_daily_url_truth.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures['cases'] as $case) {
            $receipt = app(Platform12DailyUrlTruthEvaluator::class)->evaluate($case['evidence']);
            $this->assertSame($case['expected_state'], $receipt['state'], $case['id']);
            $this->assertTrue($receipt['read_only']);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertSame([], $receipt['candidate_actions']);
            $this->assertSame(['url_truth' => false, 'canonical' => false, 'robots' => false, 'authority' => false], $receipt['writes']);
            $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($receipt, 'receipt_hash'), $receipt['receipt_hash']);
        }
    }

    public function test_reconciliation_uses_fixed_denominators_and_classifies_priority_candidates(): void
    {
        $receipt = app(Platform12DailyUrlTruthEvaluator::class)->evaluate($this->readyEvidence());

        $this->assertSame(100, $receipt['authority_reconciliation']['fixed_denominator']);
        $this->assertSame(['priority' => 'P0', 'candidate_count' => 2], $receipt['authority_reconciliation']['wrong_canonical']);
        $this->assertSame(['priority' => 'P1', 'candidate_count' => 1], $receipt['authority_reconciliation']['false_noindex']);
        $this->assertSame(3, $receipt['clustering_dedupe']['issue_denominator']);
        $this->assertSame(3, $receipt['clustering_dedupe']['dedupe_denominator']);
        $this->assertTrue($receipt['d1_observation']['result_only']);
        $this->assertFalse($receipt['d1_observation']['action_execution_allowed']);
    }

    public function test_runtime_and_sitemap_observations_cannot_create_authority(): void
    {
        $receipt = app(Platform12DailyUrlTruthEvaluator::class)->evaluate($this->readyEvidence());

        $this->assertFalse($receipt['observation_boundaries']['runtime_can_create_authority']);
        $this->assertFalse($receipt['observation_boundaries']['sitemap_can_create_authority']);
        $this->assertFalse($receipt['authority_reconciliation']['mutation_allowed']);

        $invalid = $this->readyEvidence();
        $invalid['url_truth']['wrong_canonical_count'] = 101;
        $this->assertSame('INPUT_HOLD', app(Platform12DailyUrlTruthEvaluator::class)->evaluate($invalid)['state']);
    }

    public function test_fault_priority_and_incomplete_reconciliation_never_report_ready(): void
    {
        $evidence = $this->readyEvidence();
        $evaluator = app(Platform12DailyUrlTruthEvaluator::class);
        $this->assertSame('WRONG_CANONICAL_HOLD', $evaluator->evaluate($evidence)['state']);
        $evidence['url_truth']['wrong_canonical_count'] = 0;
        $this->assertSame('FALSE_NOINDEX_HOLD', $evaluator->evaluate($evidence)['state']);
        $evidence['url_truth']['false_noindex_count'] = 0;
        $this->assertSame('RECONCILIATION_INCOMPLETE_HOLD', $evaluator->evaluate($evidence)['state']);
        $evidence['url_truth']['current_url_truth_count'] = 100;
        $this->assertSame('READY', $evaluator->evaluate($evidence)['state']);
        $evidence['url_truth']['wrong_canonical_count'] = 100;
        $evidence['url_truth']['false_noindex_count'] = 100;
        $this->assertSame('WRONG_CANONICAL_HOLD', $evaluator->evaluate($evidence)['state'], 'Fault populations may overlap.');
    }

    public function test_catalog_declares_zero_budget_daily_evaluator_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.daily_url_truth_reconciliation');

        $this->assertIsArray($mission);
        $this->assertSame('daily:ALL:06:25', $mission['natural_slot']);
        $this->assertSame(2, $mission['max_attempts']);
        $this->assertSame('none', $mission['failure_policy']['retry_strategy']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.daily_url_truth_reconciliation',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function readyEvidence(): array
    {
        return [
            'evaluated_at' => '2026-09-04T02:10:00Z',
            'authority' => ['availability' => 'AVAILABLE', 'revision_hash' => str_repeat('a', 64), 'current_public_count' => 100],
            'url_truth' => ['availability' => 'AVAILABLE', 'revision_hash' => str_repeat('b', 64), 'current_url_truth_count' => 98, 'wrong_canonical_count' => 2, 'false_noindex_count' => 1],
            'clustering' => ['availability' => 'AVAILABLE', 'issue_count' => 3, 'clustered_issue_count' => 3, 'dedupe_candidate_count' => 3, 'dedupe_unique_count' => 2],
            'd1_observation' => ['availability' => 'AVAILABLE', 'candidate_count' => 3, 'observed_count' => 3],
            'runtime_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 100],
            'sitemap_observation' => ['availability' => 'AVAILABLE', 'observation_count' => 100],
        ];
    }
}
