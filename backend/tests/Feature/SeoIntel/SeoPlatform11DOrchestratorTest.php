<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoCouncil\Entrypoints\ApiMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\ScheduledMissionAdapter;
use App\Services\SeoCouncil\Entrypoints\SeoOperationsUiMissionAdapter;
use App\Services\SeoCouncil\SeoCouncilOrchestrator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SeoPlatform11DOrchestratorTest extends TestCase
{
    public function test_all_five_entrypoints_have_the_same_request_binding_and_route_with_only_caller_provenance_different(): void
    {
        $input = $this->request('weekly_opportunity', 'tests', ['search_measurement', 'content_claim']);
        $receipts = [
            app(LocalSkillMissionAdapter::class)->submit($input),
            app(CliMissionAdapter::class)->submit($input),
            app(ScheduledMissionAdapter::class)->submit($input),
            app(ApiMissionAdapter::class)->submit($input),
            app(SeoOperationsUiMissionAdapter::class)->submit($input),
        ];

        $this->assertCount(1, array_unique(array_column($receipts, 'request_hash')));
        $this->assertCount(1, array_unique(array_map(static fn (array $receipt): string => $receipt['binding_ref']['hash'], $receipts)));
        $this->assertCount(1, array_unique(array_map(static fn (array $receipt): string => hash('sha256', json_encode($receipt['route_plan'], JSON_THROW_ON_ERROR)), $receipts)));
        $this->assertSame(['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'], array_column(array_column($receipts, 'caller_provenance'), 'caller_type'));
        foreach ($receipts as $receipt) {
            $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $receipt['status']);
            $this->assertFalse($receipt['execution_allowed']);
            $this->assertSame(0, $receipt['negative_guarantees']['model_calls']);
            foreach ($receipt['route_plan'] as $handoff) {
                $this->assertSame($receipt['run_id'], $handoff['run_id']);
                $this->assertFalse($handoff['budget']['model_calls'] > 0);
            }
        }
    }

    public function test_requested_role_is_non_authoritative_and_cannot_expand_the_binding(): void
    {
        $input = $this->request('bounded_review', 'tests', ['search_measurement'], 'analytics');
        $input['requested_role'] = 'seo.cms_writer';
        $receipt = app(ApiMissionAdapter::class)->submit($input);

        $this->assertSame(['seo.expert.search_analytics_measurement'], array_column($receipt['route_plan'], 'target_role_id'));
        $this->assertStringNotContainsString('seo.cms_writer', json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->assertFalse($receipt['execution_allowed']);
    }

    #[DataProvider('invalidScopeProvider')]
    public function test_privacy_scope_budget_and_contract_expansion_are_rejected(callable $mutate, string $expectedCode): void
    {
        $input = $mutate($this->request('weekly_opportunity', 'tests', ['search_measurement']));

        try {
            app(ApiMissionAdapter::class)->submit($input);
            $this->fail('Expected mission validation to reject the expansion.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($expectedCode, $exception->getMessage());
        }
    }

    public static function invalidScopeProvider(): array
    {
        return [
            'private identifier' => [static function (array $input): array {
                $input['mission_id'] = 'owner@example.com';

                return $input;
            }, 'PRIVATE_DATA_DENIED'],
            'model budget' => [static function (array $input): array {
                $input['budget']['model_calls'] = 1;

                return $input;
            }, 'BUDGET_EXPANSION_DENIED'],
            'tool budget' => [static function (array $input): array {
                $input['tool_scope'] = ['browser'];

                return $input;
            }, 'MISSION_SCOPE_EXPANSION_DENIED'],
            'egress budget' => [static function (array $input): array {
                $input['egress_scope'] = ['public'];

                return $input;
            }, 'MISSION_SCOPE_EXPANSION_DENIED'],
            'autonomy expansion' => [static function (array $input): array {
                $input['autonomy'] = 'L4';

                return $input;
            }, 'MISSION_SCOPE_EXPANSION_DENIED'],
            'hidden deadline' => [static function (array $input): array {
                $input['deadline'] = 'now';

                return $input;
            }, 'MISSION_REQUEST_FIELDS_INVALID'],
        ];
    }

    public function test_stale_resume_and_mutually_exclusive_authority_revisions_fail_closed(): void
    {
        $stale = $this->request('weekly_opportunity', 'tests', ['search_measurement']);
        $stale['resume_from'] = ['receipt_hash' => str_repeat('a', 64), 'step_hash' => str_repeat('b', 64)];
        $staleReceipt = app(ApiMissionAdapter::class)->submit($stale);

        $conflict = $this->request('weekly_opportunity', 'tests', ['search_measurement', 'content_claim']);
        $conflict['evidence_bundle_refs'][1]['authority_revision'] = str_repeat('b', 64);
        $conflictReceipt = app(ApiMissionAdapter::class)->submit($conflict);

        $this->assertSame('STALE_RESUME_HOLD', $staleReceipt['status']);
        $this->assertFalse($staleReceipt['execution_allowed']);
        $this->assertSame('unresolved_conflict', $conflictReceipt['status']);
        $this->assertTrue($conflictReceipt['human_decision_required']);
        $this->assertFalse($conflictReceipt['execution_allowed']);
        $this->assertSame('unresolved_conflict', $conflictReceipt['conflicts'][0]['status']);
    }

    public function test_malicious_mode_output_cannot_delegate_expand_scope_or_claim_execution(): void
    {
        $orchestrator = app(SeoCouncilOrchestrator::class);
        $safe = [
            'output_id' => str_repeat('b', 64),
            'handoff_hash' => str_repeat('a', 64),
            'role_id' => 'seo.independent_reviewer',
            'status' => 'PASS',
            'summary_code' => 'evidence_review_pass',
            'execution_allowed' => false,
            'model_calls' => 0,
            'tool_calls' => 0,
            'external_calls' => 0,
            'write_count' => 0,
            'output_hash' => str_repeat('c', 64),
        ];

        $this->assertTrue($orchestrator->acceptModeOutput($safe, str_repeat('a', 64), 'seo.independent_reviewer'));
        foreach (['peer_handoff', 'delegate_to', 'flow_override', 'additional_roles', 'tool_scope', 'egress_scope'] as $field) {
            $malicious = $safe;
            $malicious[$field] = ['seo.cms_writer'];
            $this->assertFalse($orchestrator->acceptModeOutput($malicious, str_repeat('a', 64), 'seo.independent_reviewer'), $field);
        }
        $safe['execution_allowed'] = true;
        $this->assertFalse($orchestrator->acceptModeOutput($safe, str_repeat('a', 64), 'seo.independent_reviewer'));
    }

    public function test_career_chain_is_fixed_hash_bound_and_stops_before_dry_compile_or_writes(): void
    {
        $receipt = app(ApiMissionAdapter::class)->submit($this->request('career_candidate_generation', 'career', ['career_candidate', 'content_claim']));
        $handoffs = array_values(array_filter($receipt['route_plan'], static fn (array $item): bool => $item['kind'] === 'role_handoff'));
        $compile = $receipt['route_plan'][3];

        $this->assertSame(['career.content_agent', 'seo.expert.content_entity_quality', 'seo.independent_reviewer'], array_column($handoffs, 'target_role_id'));
        $this->assertSame([1, 2, 3], array_column($handoffs, 'sequence'));
        $this->assertCount(1, array_unique(array_column($handoffs, 'evidence_context_hash')));
        $this->assertNull($handoffs[0]['previous_handoff_hash']);
        $this->assertFalse($handoffs[0]['previous_output_required']);
        $this->assertSame($handoffs[0]['handoff_hash'], $handoffs[1]['previous_handoff_hash']);
        $this->assertSame($handoffs[1]['handoff_hash'], $handoffs[2]['previous_handoff_hash']);
        $this->assertTrue($handoffs[1]['previous_output_required']);
        $this->assertTrue($handoffs[2]['previous_output_required']);
        $this->assertSame('deterministic_dry_compile', $compile['kind']);
        $this->assertSame($handoffs[2]['handoff_hash'], $compile['previous_handoff_hash']);
        $this->assertTrue($compile['consumes_previous_output_hash']);
        $this->assertFalse($compile['write_current']);
        $this->assertSame(['claim', 'locale', 'seo', 'duplicate', 'material'], $compile['gates']);
        $this->assertSame('SOURCE_CAPABILITY_UNAVAILABLE', $receipt['status']);
        $this->assertSame('career_manifest_validator_risk_open', $receipt['stop_reason']);
        foreach (['--write-current', 'current_merger', 'page_assembly_import', 'publisher', 'search_submission'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower(json_encode($receipt['route_plan'], JSON_THROW_ON_ERROR)));
        }
    }

    /** @param list<string> $evidenceTypes @return array<string, mixed> */
    private function request(string $mission, string $family, array $evidenceTypes, ?string $reviewDomain = null): array
    {
        $refs = [];
        foreach ($evidenceTypes as $index => $type) {
            $refs[] = [
                'bundle_id' => 'bundle:test:'.$index,
                'bundle_version' => 1,
                'bundle_hash' => hash('sha256', 'bundle:'.$index),
                'evidence_type' => $type,
                'status' => 'READY',
                'authority_revision' => str_repeat('a', 64),
            ];
        }

        return [
            'mission_id' => 'mission:test:'.$mission,
            'idempotency_key' => 'idempotency:test:'.$mission,
            'mission_type' => $mission,
            'family' => $family,
            'locale' => 'zh-CN',
            'review_domain' => $reviewDomain,
            'requested_role' => null,
            'evidence_bundle_refs' => $refs,
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'external_calls' => 0, 'execution_seconds' => 0, 'retry_count' => 0, 'context_bytes' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'tool_scope' => [],
            'egress_scope' => [],
            'resume_from' => null,
        ];
    }
}
