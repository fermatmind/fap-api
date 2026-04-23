<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V0_3\Concerns\BuildsBigFiveReportEngineBridgeFixture;
use Tests\TestCase;

final class BigFiveReportEngineBridgeContractTest extends TestCase
{
    use BuildsBigFiveReportEngineBridgeFixture;
    use RefreshDatabase;

    public function test_flag_on_adds_full_v2_payload_as_top_level_sibling_without_rewriting_legacy_report(): void
    {
        config()->set('big5_report_engine.v2_bridge_enabled', true);
        $fixture = $this->createCanonicalBigFiveBridgeFixture('anon_big5_bridge_contract');

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
        $response->assertJsonPath('big5_report_engine_v2.schema_version', 'fap.big5.report.v1');
        $response->assertJsonPath('big5_report_engine_v2.scale_code', 'BIG5_OCEAN');
        $response->assertJsonPath('big5_report_engine_v2.form_code', 'big5_90');
        $response->assertJsonCount(8, 'big5_report_engine_v2.sections');
        $response->assertJsonPath('big5_report_engine_v2.sections.3.section_key', 'facet_details');
        $response->assertJsonPath('big5_report_engine_v2.sections.4.section_key', 'core_portrait');
        $response->assertJsonPath('big5_report_engine_v2.sections.6.section_key', 'action_plan');
        $this->assertNotEmpty($response->json('big5_report_engine_v2.engine_decisions.selected_synergies'));
        $this->assertNotEmpty($response->json('big5_report_engine_v2.engine_decisions.facet_anomalies'));
        $this->assertNotEmpty($response->json('big5_report_engine_v2.action_matrix.scenarios'));
        $this->assertSame($fixture['legacy_sections'], $response->json('report.sections'));
        $this->assertSame('big5.public_projection.v1', $response->json('big5_public_projection_v1.schema_version'));
        $this->assertEquals($expectedV2, $response->json('big5_report_engine_v2'));
        $snapshot = json_decode((string) file_get_contents(base_path('tests/Fixtures/big5_engine/expected_live_bridge_report_payload.json')), true);
        $snapshotPayload = [
            'report' => ['sections' => $response->json('report.sections')],
            'big5_report_engine_v2' => $response->json('big5_report_engine_v2'),
        ];
        $snapshotPayload['big5_report_engine_v2']['meta']['attempt_id'] = 'attempt_live_bridge_fixture';
        $snapshotPayload['big5_report_engine_v2']['meta']['result_id'] = 'result_live_bridge_fixture';
        $this->assertSame($snapshot, $snapshotPayload);

        $blocks = collect($response->json('big5_report_engine_v2.sections'))
            ->flatMap(fn (array $section): array => (array) ($section['blocks'] ?? []));
        $this->assertNotEmpty($blocks);
        foreach ($blocks as $block) {
            foreach (['atomic_refs', 'modifier_refs', 'synergy_refs', 'facet_refs', 'action_refs'] as $key) {
                $this->assertArrayHasKey($key, $block['provenance']);
                $this->assertIsArray($block['provenance'][$key]);
            }
        }
    }
}
