<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use App\Services\SeoAgentPolicyGateway\PolicyGatewayCallerGuard;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class SeoPlatform11CAdmissionPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_all_five_callers_share_one_guard_and_can_only_hold(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        $guard = app(PolicyGatewayCallerGuard::class);
        foreach (['local_skill', 'cli', 'scheduler', 'api', 'seo_operations_ui'] as $caller) {
            $decision = $guard->admission($caller, $this->request($caller));
            $this->assertSame('HOLD', $decision['decision'], $caller);
            $this->assertContains('ROLE_CAPABILITY_BINDING_UNAVAILABLE', $decision['reason_codes'], $caller);
            $this->assertFalse($decision['execution_allowed'], $caller);
            $this->assertFalse($decision['write_allowed'], $caller);
            $this->assertFalse($decision['model_invocation'], $caller);
            $this->assertFalse($decision['tool_invocation'], $caller);
            $this->assertFalse($decision['egress_allowed'], $caller);
        }
    }

    public function test_injection_scope_expansion_l4_private_and_forged_context_are_denied(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        $guard = app(PolicyGatewayCallerGuard::class);
        $probes = [];
        $probes['allow_injection'] = $this->request('api') + ['allowed' => true];
        $probes['tool_scope'] = array_replace($this->request('api'), ['tool_scope' => ['seo.search_submission']]);
        $probes['egress_scope'] = array_replace($this->request('api'), ['egress_scope' => ['https://example.com']]);
        $probes['budget'] = array_replace_recursive($this->request('api'), ['budget' => ['model_calls' => 1]]);
        $probes['deadline'] = array_replace($this->request('api'), ['deadline_seconds' => 1]);
        $probes['autonomy'] = array_replace($this->request('api'), ['autonomy' => 'L1']);
        $probes['l4'] = array_replace($this->request('api'), ['autonomy' => 'L4']);
        $probes['role'] = array_replace($this->request('api'), ['requested_role_id' => 'seo.unknown']);
        $probes['family'] = array_replace($this->request('api'), ['family' => 'private_excluded']);
        $probes['locale'] = array_replace($this->request('api'), ['locale' => 'fr']);
        $probes['private'] = array_replace($this->request('api'), ['request_metadata' => ['source_label' => 'person@example.com', 'correlation_hash' => str_repeat('d', 64)]]);
        $forged = $this->request('api');
        $forged['evidence_context']['payload']['revision_hash'] = str_repeat('e', 64);
        $probes['forged'] = $forged;

        foreach ($probes as $name => $request) {
            $decision = $guard->admission('api', $request);
            $this->assertSame('DENY', $decision['decision'], $name);
            $this->assertFalse($decision['execution_allowed'], $name);
            $this->assertStringNotContainsString('"ALLOW"', json_encode($decision, JSON_THROW_ON_ERROR), $name);
        }
        $this->assertContains('L4_DORMANT_NOT_AUTHORIZED', $guard->admission('api', $probes['l4'])['reason_codes']);
    }

    public function test_expired_context_is_denied_and_measurement_or_source_holds_cannot_execute(): void
    {
        CarbonImmutable::setTestNow('2026-08-29T10:00:00Z');
        $guard = app(PolicyGatewayCallerGuard::class);

        $expired = $this->request('api');
        $expired['evidence_context']['expires_at'] = '2026-08-29T09:59:59Z';
        $expired['evidence_context']['context_hash'] = app(SeoEvidenceCanonicalHasher::class)->hashWithout($expired['evidence_context'], 'context_hash');
        $this->assertSame('DENY', $guard->admission('api', $expired)['decision']);

        foreach (['MEASUREMENT_HOLD', 'SOURCE_CAPABILITY_UNAVAILABLE', 'EVIDENCE_HOLD'] as $status) {
            $held = $this->request('api');
            $held['evidence_context']['status'] = $status;
            $held['evidence_context']['context_hash'] = app(SeoEvidenceCanonicalHasher::class)->hashWithout($held['evidence_context'], 'context_hash');
            $decision = $guard->admission('api', $held);
            $this->assertSame('HOLD', $decision['decision'], $status);
            $this->assertFalse($decision['execution_allowed'], $status);
        }
    }

    /** @return array<string, mixed> */
    private function request(string $caller): array
    {
        $context = [
            'schema_version' => 'seo.evidence_context.v1',
            'context_id' => str_repeat('b', 64),
            'context_version' => 1,
            'mission_id' => 'mission:test',
            'mission_type' => 'bounded_review',
            'role_id' => 'seo.expert.technical_search_authority',
            'page_family' => 'tests',
            'locale' => 'en',
            'built_at' => '2026-08-29T09:59:00Z',
            'expires_at' => '2026-08-29T10:30:00Z',
            'bundle_refs' => [['bundle_id' => 'bundle:test', 'bundle_version' => 1, 'bundle_hash' => str_repeat('c', 64)]],
            'source_capability_states' => ['available'],
            'evidence_summary' => ['bundle_count' => 1, 'private_data_present' => false],
            'payload' => ['revision_hash' => str_repeat('a', 64)],
            'status' => 'READY',
            'execution_allowed' => false,
            'model_invocation' => false,
            'tool_invocation' => false,
            'write_permissions' => [],
            'tool_allowlist' => [],
            'egress_allowlist' => [],
        ];
        $context['context_hash'] = app(SeoEvidenceCanonicalHasher::class)->hash($context);

        return [
            'schema_version' => 'seo.policy_admission_request.v1',
            'caller_type' => $caller,
            'mission_id' => 'mission:test',
            'mission_type' => 'bounded_review',
            'requested_role_id' => 'seo.expert.technical_search_authority',
            'family' => 'tests',
            'locale' => 'en',
            'claim_risk' => 'R1',
            'autonomy' => 'L0',
            'budget' => ['model_calls' => 0, 'tool_calls' => 0, 'execution_seconds' => 0, 'cost_amount' => 0, 'currency' => 'USD'],
            'deadline_seconds' => 0,
            'tool_scope' => [],
            'egress_scope' => [],
            'evidence_context' => $context,
            'request_metadata' => ['source_label' => 'test', 'correlation_hash' => str_repeat('d', 64)],
        ];
    }
}
