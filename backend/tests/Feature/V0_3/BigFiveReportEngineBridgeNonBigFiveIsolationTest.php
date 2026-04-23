<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveReportEngineBridgeNonBigFiveIsolationTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_flag_on_does_not_add_v2_bridge_field_to_non_big_five_report(): void
    {
        config()->set('big5_report_engine.v2_bridge_enabled', true);
        $fixture = $this->createNonBigFiveBridgeFixture('SDS_20', 'anon_non_big5_bridge_flag_on');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$fixture['token'],
            'X-Anon-Id' => $fixture['anon_id'],
        ])->getJson('/api/v0.3/attempts/'.$fixture['attempt_id'].'/report');

        $response->assertOk();
        $this->assertArrayNotHasKey('big5_report_engine_v2', $response->json());
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
    }
}
