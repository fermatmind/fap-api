<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2RuntimeWrapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveV2PublicPilotAccessGateTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_public_pilot_defaults_false_and_does_not_expose_payload(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', false);
        config()->set('big5_result_page_v2.public_pilot_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_default_false');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_public_pilot_gate_denies_non_allowlisted_request(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_denied');
        $this->enablePublicPilot([
            'public_pilot_rollout_percentage' => 0,
            'public_pilot_access_allowed_anon_ids' => ['different_anon'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_public_pilot_allowlisted_request_attaches_result_page_payload_only(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_allowed');
        $this->enablePublicPilot([
            'public_pilot_access_allowed_anon_ids' => [$fixture['anon_id']],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertIsArray($response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        $this->assertArrayNotHasKey('pdf', $response->json());
        $this->assertArrayNotHasKey('share_card', $response->json());
        $this->assertArrayNotHasKey('history', $response->json());
        $this->assertArrayNotHasKey('compare', $response->json());
    }

    public function test_public_pilot_rollout_can_allow_result_page_payload_without_internal_pilot_allowlist(): void
    {
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_rollout_allowed');
        $this->enablePublicPilot([
            'public_pilot_rollout_percentage' => 100,
            'public_pilot_access_allowed_anon_ids' => [],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertIsArray($response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_public_pilot_keeps_controlled_pilot_allowlist_behavior_unchanged(): void
    {
        config()->set('big5_result_page_v2.enabled', false);
        config()->set('big5_result_page_v2.pilot_runtime_enabled', true);
        config()->set('big5_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('big5_result_page_v2.public_pilot_enabled', false);
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_controlled_unchanged');
        config()->set('big5_result_page_v2.pilot_access_allowed_anon_ids', [$fixture['anon_id']]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertIsArray($response->json(BigFiveResultPageV2Contract::PAYLOAD_KEY));
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }

    public function test_public_pilot_denies_production_without_explicit_production_allowlist(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_public_pilot_prod_denied');
        $this->enablePublicPilot([
            'public_pilot_allowed_environments' => ['production', 'testing'],
            'public_pilot_production_allowlist_enabled' => false,
            'public_pilot_access_allowed_anon_ids' => [$fixture['anon_id']],
        ]);

        $payload = app(BigFiveResultPageV2RuntimeWrapper::class)->appendIfEnabled(
            $fixture['attempt'],
            $fixture['result'],
            ['report' => ['sections' => $fixture['legacy_sections']]],
        );

        $this->assertArrayNotHasKey(BigFiveResultPageV2Contract::PAYLOAD_KEY, $payload);
        $this->assertSame($fixture['legacy_sections'], data_get($payload, 'report.sections'));
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
}
