<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoCouncil\Platform12\Evaluation\Platform12MonthlyLifecycleCandidateEvaluator;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12MissionCatalogValidator;
use Tests\TestCase;

final class SeoPlatform12D02LifecycleCandidatesTest extends TestCase
{
    public function test_all_lifecycle_actions_are_artifact_only_candidates_with_explicit_basis(): void
    {
        $artifact = app(Platform12MonthlyLifecycleCandidateEvaluator::class)->evaluate($this->evidence());

        $this->assertSame('READY', $artifact['state']);
        $this->assertSame(['KEEP', 'REFRESH', 'CONSOLIDATE', 'RETIRE'], array_column($artifact['candidates'], 'action'));
        foreach ($artifact['candidates'] as $candidate) {
            $this->assertSame(['material_change', 'traffic', 'authority', 'locale', 'rollback'], array_keys($candidate['basis']));
            $this->assertArrayHasKey('evidence_gaps', $candidate);
            $this->assertArrayHasKey('abstain_reason', $candidate);
            $this->assertFalse($candidate['automatic_execution']);
        }
        $this->assertTrue($artifact['artifact_only']);
        $this->assertTrue($artifact['read_only']);
        $this->assertFalse($artifact['execution_allowed']);
        $this->assertSame(app(SeoRegistryHasher::class)->hashWithout($artifact, 'artifact_hash'), $artifact['artifact_hash']);
    }

    public function test_retire_and_destructive_follow_on_actions_never_execute_automatically(): void
    {
        $artifact = app(Platform12MonthlyLifecycleCandidateEvaluator::class)->evaluate($this->evidence());
        $retire = collect($artifact['candidates'])->firstWhere('action', 'RETIRE');

        $this->assertSame('HUMAN_HOLD', $retire['review_state']);
        $this->assertSame('HIGH', $retire['risk_classification']['destructive']);
        $this->assertFalse($retire['automatic_execution']);
        $this->assertSame(['RETIRE', 'DELETE', 'UNPUBLISH', 'REDIRECT'], $artifact['forbidden_automatic_actions']);
        $this->assertSame(['cms' => false, 'url_truth' => false, 'redirects' => false, 'indexability' => false], $artifact['writes']);
    }

    public function test_new_family_canonical_noindex_and_shared_schema_require_human_hold(): void
    {
        foreach (['new_family', 'canonical_change', 'noindex_change', 'shared_schema_change'] as $flag) {
            $evidence = $this->evidence();
            $evidence['candidates'] = [$this->candidate('KEEP', 'a')];
            $evidence['candidates'][0]['scope_risks'][$flag] = true;

            $candidate = app(Platform12MonthlyLifecycleCandidateEvaluator::class)->evaluate($evidence)['candidates'][0];

            $this->assertSame('HUMAN_HOLD', $candidate['review_state'], $flag);
            $this->assertFalse($candidate['automatic_execution'], $flag);
        }
    }

    public function test_shared_layer_sensitive_claim_and_evidence_gap_are_classified(): void
    {
        $artifact = app(Platform12MonthlyLifecycleCandidateEvaluator::class)->evaluate($this->evidence());
        $refresh = collect($artifact['candidates'])->firstWhere('action', 'REFRESH');
        $consolidate = collect($artifact['candidates'])->firstWhere('action', 'CONSOLIDATE');

        $this->assertSame('EVIDENCE_HOLD', $refresh['review_state']);
        $this->assertSame(['traffic_unavailable'], $refresh['evidence_gaps']);
        $this->assertSame('awaiting_traffic', $refresh['abstain_reason']);
        $this->assertSame('HIGH', $consolidate['risk_classification']['shared_layer']);
        $this->assertSame('HIGH', $consolidate['risk_classification']['sensitive_claim']);
    }

    public function test_unavailable_basis_without_matching_evidence_gap_fails_closed(): void
    {
        $evidence = $this->evidence();
        $evidence['candidates'] = [$this->candidate('REFRESH', 'a')];
        $evidence['candidates'][0]['basis']['traffic'] = ['state' => 'UNAVAILABLE', 'evidence_ref' => null];

        $artifact = app(Platform12MonthlyLifecycleCandidateEvaluator::class)->evaluate($evidence);

        $this->assertSame('HOLD', $artifact['state']);
        $this->assertSame([], $artifact['candidates']);
        $this->assertFalse($artifact['execution_allowed']);
    }

    public function test_catalog_declares_zero_budget_monthly_candidate_evaluator_without_registration(): void
    {
        $contracts = app(Platform12ContractRegistry::class);
        $catalog = $contracts->missionCatalog();
        $mission = collect($catalog['missions'])->firstWhere('mission_id', 'seo.platform12.monthly_lifecycle_candidates');

        $this->assertIsArray($mission);
        $this->assertSame('monthly:01:04:10', $mission['natural_slot']);
        $this->assertSame(0, array_sum($mission['budgets']));
        $this->assertFalse($catalog['runtime_activation_allowed']);
        $this->assertSame($catalog, app(Platform12MissionCatalogValidator::class)->validate($catalog));
        $this->assertTrue($contracts->verifyGenerated());
        $this->assertStringNotContainsString(
            'seo.platform12.monthly_lifecycle_candidates',
            (string) file_get_contents(base_path('routes/console.php')),
        );
    }

    /** @return array<string,mixed> */
    private function evidence(): array
    {
        $keep = $this->candidate('KEEP', 'a');
        $refresh = $this->candidate('REFRESH', 'b');
        $refresh['basis']['traffic'] = ['state' => 'UNAVAILABLE', 'evidence_ref' => null];
        $refresh['evidence_gaps'] = ['traffic_unavailable'];
        $refresh['abstain_reason'] = 'awaiting_traffic';
        $consolidate = $this->candidate('CONSOLIDATE', 'c');
        $consolidate['scope_risks']['shared_layer'] = true;
        $consolidate['scope_risks']['sensitive_claim'] = true;
        $retire = $this->candidate('RETIRE', 'd');

        return [
            'evaluated_at' => '2026-10-01T04:10:00Z',
            'candidates' => [$keep, $refresh, $consolidate, $retire],
        ];
    }

    /** @return array<string,mixed> */
    private function candidate(string $action, string $seed): array
    {
        return [
            'candidate_ref' => str_repeat($seed, 64),
            'action' => $action,
            'locale' => 'zh-CN',
            'basis' => [
                'material_change' => ['state' => 'MATERIAL', 'evidence_ref' => str_repeat('1', 64)],
                'traffic' => ['state' => 'AVAILABLE', 'evidence_ref' => str_repeat('2', 64)],
                'authority' => ['revision' => str_repeat('3', 64), 'evidence_ref' => str_repeat('4', 64)],
                'locale' => ['value' => 'zh-CN', 'evidence_ref' => str_repeat('5', 64)],
                'rollback' => ['ready' => true, 'evidence_ref' => str_repeat('6', 64)],
            ],
            'scope_risks' => [
                'new_family' => false,
                'canonical_change' => false,
                'noindex_change' => false,
                'shared_schema_change' => false,
                'shared_layer' => false,
                'sensitive_claim' => false,
            ],
            'evidence_gaps' => [],
            'abstain_reason' => 'none',
        ];
    }
}
