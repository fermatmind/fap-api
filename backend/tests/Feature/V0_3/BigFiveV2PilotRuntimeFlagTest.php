<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveV2PilotRuntimeFlagTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_pilot_runtime_flag_defaults_false_and_keeps_current_report_response(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $this->app->bind(BigFiveResultPageV2TransformerContract::class, static fn (): BigFiveResultPageV2TransformerContract => new class implements BigFiveResultPageV2TransformerContract
        {
            public function transform(array $input): array
            {
                throw new \RuntimeException('legacy transformer must not run when all V2 gates are disabled');
            }
        });
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_default_false');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_pilot_runtime_flag_is_disabled_in_production_without_explicit_allowlist(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['production', 'testing']);
        config()->set('big5_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_prod_blocked');

        $payload = app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            ['report' => ['sections' => $fixture['legacy_sections']]],
        );

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }

    public function test_pilot_runtime_flag_attaches_o59_payload_in_allowed_non_production_environment(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_allowed');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $payload = $response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY);
        $this->assertIsArray($payload);
        $this->assertSame(BigFiveV2PilotPayloadComposer::CONTENT_VERSION, $payload['content_version'] ?? null);
        $this->assertSame(BigFiveV2PilotPayloadComposer::PACKAGE_VERSION, $payload['package_version'] ?? null);
        $this->assertSame('pilot_o59_staging_payload_v0_1', $payload['fixture_key'] ?? null);
        $this->assertSame('sensitive_independent_thinker', $payload['canonical_profile_key'] ?? null);
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'production_use_allowed'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'runtime_use'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'ready_for_runtime'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'ready_for_production'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'selector_basis'));
        $this->assertFalse($this->containsKeyRecursive($payload, 'source_reference'));
    }

    public function test_pilot_runtime_flag_rolls_back_by_setting_config_false(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_rollback');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
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
