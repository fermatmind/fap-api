<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2TransformerContract;
use App\Services\Report\ReportAccess;
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

    public function test_pilot_runtime_flag_does_not_attach_o59_payload_to_locked_report_response(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_allowed');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $payload = app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            [
                'locked' => true,
                'access_level' => ReportAccess::REPORT_ACCESS_FULL,
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                ],
                'report' => ['sections' => $fixture['legacy_sections']],
            ],
        );

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
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

    public function test_pilot_runtime_fails_closed_when_projection_is_locked_or_redacted(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_v2_pilot_locked_projection');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $payload = app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            [
                'report' => ['sections' => $fixture['legacy_sections']],
                'big5_public_projection_v1' => [
                    '_meta' => [
                        'locked' => true,
                        'redacted' => true,
                    ],
                    'trait_vector' => [
                        ['key' => 'O', 'percentile' => 59],
                        ['key' => 'C', 'percentile' => 32],
                        ['key' => 'E', 'percentile' => 20],
                        ['key' => 'A', 'percentile' => 55],
                        ['key' => 'N', 'percentile' => 68],
                    ],
                ],
            ],
        );

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
    }
}
