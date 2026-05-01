<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveResultPageV2RuntimeGateTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_gate_disabled_keeps_report_response_without_result_page_v2_payload(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_gate_off');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertArrayNotHasKey(BigFiveLiveRuntimeBridge::RESPONSE_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        $this->assertSame('big5.public_projection.v1', $response->json('big5_public_projection_v1.schema_version'));
    }

    public function test_gate_disabled_does_not_call_transformer(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, static fn (): BigFiveResultPageV2TransformerContract => new class implements BigFiveResultPageV2TransformerContract
        {
            public function transform(array $input): array
            {
                throw new \RuntimeException('transformer must not run when gate is disabled');
            }
        });
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_gate_off_no_transform');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_gate_enabled_does_not_add_result_page_v2_to_non_big_five_report(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', true);
        $fixture = $this->createNonBigFiveBridgeFixture('SDS_20', 'anon_non_big5_result_page_v2_gate_on');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertArrayNotHasKey(BigFiveLiveRuntimeBridge::RESPONSE_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_gate_enabled_adds_validator_clean_result_page_v2_payload(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_gate_on');
        $this->forceQualityLevel($fixture['result'], 'B');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $payload = $response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY);
        $this->assertIsArray($payload);
        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope([
            BigFiveResultPageV2Contract::PAYLOAD_KEY => $payload,
        ]));
        $this->assertSame(BigFiveResultPageV2Contract::SCHEMA_VERSION, $payload['schema_version'] ?? null);
        $this->assertSame(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload['payload_key'] ?? null);
        $this->assertSame('BIG5_OCEAN', $payload['scale_code'] ?? null);
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_gate_enabled_preserves_old_big5_report_engine_v2_payload(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', true);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_old_bridge');
        $expectedV2 = app(BigFiveLiveRuntimeBridge::class)->build(
            $fixture['attempt'],
            $fixture['result'],
            'BIG5_OCEAN'
        );
        $this->assertIsArray($expectedV2);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertEquals($expectedV2, $response->json(BigFiveLiveRuntimeBridge::RESPONSE_KEY));
        $this->assertIsArray($response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_norm_unavailable_runtime_payload_suppresses_percentile_and_normal_curve_fields(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_norm_missing');
        $this->forceQualityLevel($fixture['result'], 'B');
        $this->forceNormStatus($fixture['result'], 'MISSING');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $payload = $response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY);
        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope([
            BigFiveResultPageV2Contract::PAYLOAD_KEY => $payload,
        ]));
        $this->assertSame('norm_unavailable', data_get($payload, 'projection_v2.interpretation_scope'));
        $this->assertNull(data_get($payload, 'projection_v2.domains.O.score'));
        $this->assertNull(data_get($payload, 'projection_v2.domains.O.percentile'));
        $this->assertSame(['domain_bands', 'interpretation_scope', 'safety_flags'], data_get($payload, 'projection_v2.public_fields'));
        $this->assertFalse($this->containsKeyRecursive((array) $payload, 'normal_curve'));
        $this->assertFalse($this->containsKeyRecursive((array) $payload, 'show_normal_curve'));
    }

    public function test_low_quality_runtime_payload_outputs_degraded_boundary_safe_modules(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_low_quality');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $payload = $response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY);
        $this->assertSame([], app(BigFiveResultPageV2Validator::class)->validateEnvelope([
            BigFiveResultPageV2Contract::PAYLOAD_KEY => $payload,
        ]));
        $this->assertSame('low_quality', data_get($payload, 'projection_v2.interpretation_scope'));
        $this->assertSame([
            'module_00_trust_bar',
            'module_09_feedback_data_flywheel',
            'module_10_method_privacy',
        ], array_map(
            static fn (array $module): string => (string) $module['module_key'],
            (array) ($payload['modules'] ?? [])
        ));
        foreach ((array) ($payload['modules'] ?? []) as $module) {
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                $this->assertContains($block['safety_level'], ['boundary', 'degraded']);
            }
        }
    }

    public function test_invalid_runtime_payload_is_omitted_without_breaking_legacy_report(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, static fn (): BigFiveResultPageV2TransformerContract => new class implements BigFiveResultPageV2TransformerContract
        {
            public function transform(array $input): array
            {
                return [
                    BigFiveResultPageV2Contract::PAYLOAD_KEY => [
                        'payload_key' => 'invalid',
                    ],
                ];
            }
        });
        Log::spy();
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_invalid_omit');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        Log::shouldHaveReceived('warning')
            ->with('BIG5_RESULT_PAGE_V2_RUNTIME_PAYLOAD_INVALID', Mockery::type('array'))
            ->once();
    }

    public function test_runtime_payload_does_not_expose_type_or_shareable_raw_score_leaks(): void
    {
        config()->set('big5_result_page_v2.enabled', true);
        config()->set('big5_report_engine.v2_bridge_enabled', true);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_result_page_v2_public_safety');
        $this->forceQualityLevel($fixture['result'], 'B');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $payload = (array) $response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY);
        $this->assertFalse($this->containsKeyRecursive($payload, 'user_confirmed_type'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'fixed_type'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'type_name'));
        foreach ((array) ($payload['modules'] ?? []) as $module) {
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                if (($block['shareable'] ?? false) === true) {
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'raw_scores'));
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'domains'));
                    $this->assertFalse($this->containsKeyRecursive((array) $block, 'facets'));
                }
            }
        }
    }

    private function forceQualityLevel(object $result, string $qualityLevel): void
    {
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        data_set($resultJson, 'normed_json.quality.level', $qualityLevel);
        $result->forceFill(['result_json' => $resultJson])->save();
    }

    private function forceNormStatus(object $result, string $normStatus): void
    {
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        data_set($resultJson, 'normed_json.norms.status', $normStatus);
        data_set($resultJson, 'normed_json.norms.norm_status', $normStatus);
        $result->forceFill(['result_json' => $resultJson])->save();
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function containsKeyRecursive(array $payload, string $key): bool
    {
        foreach ($payload as $currentKey => $value) {
            if ($currentKey === $key) {
                return true;
            }
            if (is_array($value) && $this->containsKeyRecursive($value, $key)) {
                return true;
            }
        }

        return false;
    }
}
