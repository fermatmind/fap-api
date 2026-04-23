<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveReportEngineBridgeFlagTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_flag_off_keeps_live_report_response_without_v2_bridge_field(): void
    {
        config()->set('big5_report_engine.v2_bridge_enabled', false);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_bridge_flag_off');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey('big5_report_engine_v2', $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        $this->assertSame('big5.public_projection.v1', $response->json('big5_public_projection_v1.schema_version'));
    }
}
