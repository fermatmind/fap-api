<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\BigFiveV2PilotRuntimeObservability;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteMatrixLookup;
use App\Services\Report\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveV2PublicPilotFailClosedTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    private const PUBLIC_SURFACE_POLICY_PATH = 'content_assets/big5/result_page_v2/qa/public_surface_policy/v0_1/big5_public_surface_disabled_or_pending_policy_v0_1.json';

    public function test_public_pilot_default_off_keeps_existing_report_response(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_result_page_v2.public_pilot_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_fail_closed_default_off');

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    public function test_public_pilot_gate_denied_keeps_existing_report_response(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_fail_closed_denied');
        $this->enablePublicPilot([
            'public_pilot_access_allowed_anon_ids' => ['different_anon'],
        ]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    public function test_public_pilot_allowlisted_request_attaches_result_page_payload_only(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_fail_closed_allowed');
        $this->enablePublicPilot([
            'public_pilot_access_allowed_anon_ids' => [$fixture['anon_id']],
        ]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertIsArray($payload[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
        $this->assertArrayNotHasKey('pdf', $payload);
        $this->assertArrayNotHasKey('share_card', $payload);
        $this->assertArrayNotHasKey('history', $payload);
        $this->assertArrayNotHasKey('compare', $payload);
    }

    public function test_public_pilot_invalid_percentile_fails_closed_without_payload(): void
    {
        Log::spy();
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_fail_closed_invalid_percentile');
        $this->enablePublicPilot([
            'public_pilot_access_allowed_anon_ids' => [$fixture['anon_id']],
        ]);
        $this->replaceDomainPercentiles($fixture, [
            'O' => 101,
            'C' => 32,
            'E' => 20,
            'A' => 55,
            'N' => 68,
        ]);

        $payload = $this->appendWrapperPayload($fixture);

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(fn (array $context): bool => $this->safeGenerationFailureContext($context, routeInputCreated: false)),
        )->once();
    }

    public function test_route_matrix_lookup_does_not_fabricate_missing_rows(): void
    {
        $this->assertNull((new BigFiveV2RouteMatrixLookup)->lookup('O9_C9_E9_A9_N9'));
    }

    public function test_composer_failure_observability_is_count_only_and_not_public_payload(): void
    {
        Log::spy();
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_fail_closed_composer');

        app(BigFiveV2PilotRuntimeObservability::class)->recordPayloadGenerationFailed(
            $fixture['attempt'],
            $fixture['result'],
            new \RuntimeException('Big Five V2 pilot composer failed.'),
        );

        Log::shouldHaveReceived('warning')->with(
            BigFiveV2PilotRuntimeObservability::EVENT,
            Mockery::on(static fn (array $context): bool => ($context['route_input_created'] ?? null) === true
                && ($context['route_lookup_failed'] ?? null) === false
                && ($context['composer_failed'] ?? null) === true
                && ($context['payload_attached'] ?? null) === false
                && ! array_key_exists('payload_body', $context)
                && ! array_key_exists('attempt_id', $context)
                && strlen((string) ($context['attempt_hash'] ?? '')) === 64),
        )->once();
    }

    public function test_secondary_surfaces_remain_disabled_or_pending_for_public_pilot(): void
    {
        $policy = $this->decodeJson(self::PUBLIC_SURFACE_POLICY_PATH);
        $surfaces = [];
        foreach ((array) ($policy['surfaces'] ?? []) as $surface) {
            $surfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($surfaces);

        $this->assertSame(['compare', 'history', 'pdf', 'share_card'], array_keys($surfaces));
        foreach ($surfaces as $surfaceKey => $surface) {
            $this->assertSame('disabled_or_pending', $surface['policy_status'] ?? null, $surfaceKey);
            $this->assertSame('pending_surface', $surface['rendered_status'] ?? null, $surfaceKey);
            $this->assertSame('disabled', $surface['public_pilot_default'] ?? null, $surfaceKey);
            $this->assertFalse((bool) ($surface['can_count_as_pass'] ?? true), $surfaceKey);
            $this->assertSame([], $surface['evidence'] ?? null, $surfaceKey);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function enablePublicPilot(array $overrides = []): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_result_page_v2.public_pilot_enabled', true);
        config()->set('big5_result_page_v2.public_pilot_surface_scope', 'result_page_only');
        config()->set('big5_result_page_v2.public_pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.public_pilot_production_allowlist_enabled', false);
        config()->set('big5_result_page_v2.public_pilot_allowed_scale_codes', ['BIG5_OCEAN']);
        config()->set('big5_result_page_v2.public_pilot_allowed_form_codes', ['big5_90']);
        config()->set('big5_result_page_v2.public_pilot_allowed_locales', ['zh-CN']);
        config()->set('big5_result_page_v2.public_pilot_rollout_percentage', 0);
        config()->set('big5_result_page_v2.public_pilot_access_allowed_attempt_ids', []);
        config()->set('big5_result_page_v2.public_pilot_access_allowed_user_ids', []);
        config()->set('big5_result_page_v2.public_pilot_access_allowed_anon_ids', []);
        config()->set('big5_result_page_v2.public_pilot_access_allowed_org_ids', []);
        config()->set('big5_report_engine.v2_bridge_enabled', false);

        foreach ($overrides as $key => $value) {
            config()->set('big5_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @return array<string,mixed>
     */
    private function appendWrapperPayload(array $fixture): array
    {
        return app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            [
                'locked' => false,
                'access_level' => ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                ],
                'report' => ['sections' => $fixture['legacy_sections']],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $fixture
     * @param  array<string,int>  $domainPercentiles
     */
    private function replaceDomainPercentiles(array $fixture, array $domainPercentiles): void
    {
        $result = $fixture['result'];
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        data_set($resultJson, 'normed_json.scores_0_100.domains_percentile', $domainPercentiles);
        data_set($resultJson, 'breakdown_json.score_result.scores_0_100.domains_percentile', $domainPercentiles);
        data_set($resultJson, 'axis_scores_json.score_result.scores_0_100.domains_percentile', $domainPercentiles);
        $result->result_json = $resultJson;
        $result->save();
    }

    private function safeGenerationFailureContext(array $context, bool $routeInputCreated): bool
    {
        return ($context['fallback_reason'] ?? null) === 'payload_generation_failed'
            && ($context['payload_attached'] ?? null) === false
            && ($context['payload_validation_failed'] ?? null) === false
            && ($context['route_input_created'] ?? null) === $routeInputCreated
            && ! array_key_exists('attempt_id', $context)
            && ! array_key_exists('payload_body', $context)
            && strlen((string) ($context['attempt_hash'] ?? '')) === 64;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function decodeJson(string $relativePath): array
    {
        $json = file_get_contents(base_path($relativePath));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
