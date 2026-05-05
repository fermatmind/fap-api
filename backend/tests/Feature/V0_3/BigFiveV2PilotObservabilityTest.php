<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveV2PilotRuntimeObservability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveV2PilotObservabilityTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_flag_off_emits_safe_observability_log(): void
    {
        Log::spy();
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_observability_flag_off');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        Log::shouldHaveReceived('info')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->matchesSafeContext($context)
                && ($context['pilot_flag_state'] ?? null) === 'disabled'
                && ($context['payload_attached'] ?? null) === false
                && ($context['fallback_reason'] ?? null) === 'pilot_runtime_disabled'),
        )->once();
    }

    public function test_access_denied_emits_safe_observability_log(): void
    {
        Log::spy();
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', []);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_observability_denied');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        Log::shouldHaveReceived('info')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->matchesSafeContext($context)
                && ($context['pilot_flag_state'] ?? null) === 'enabled'
                && ($context['access_gate_decision'] ?? null) === 'deny'
                && ($context['fallback_reason'] ?? null) === 'pilot_access_allowlist_empty'),
        )->once();
    }

    public function test_payload_attached_emits_counts_without_payload_body_or_pii(): void
    {
        Log::spy();
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_observability_attached');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertIsArray($response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY));
        Log::shouldHaveReceived('info')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->matchesSafeContext($context)
                && ($context['payload_attached'] ?? null) === true
                && array_key_exists('selector_suppressed_ref_count', $context)
                && array_key_exists('unresolved_ref_count', $context)
                && is_array($context['surface_status_summary'] ?? null)),
        )->once();
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function matchesSafeContext(array $context): bool
    {
        return isset($context['attempt_hash'])
            && is_string($context['attempt_hash'])
            && strlen($context['attempt_hash']) === 64
            && ! array_key_exists('attempt_id', $context)
            && ! array_key_exists('anon_id', $context)
            && ! array_key_exists('user_id', $context)
            && ! array_key_exists('payload_body', $context)
            && ($context['scale_code'] ?? null) === BigFiveResultPageV2Contract::SCALE_CODE;
    }
}
