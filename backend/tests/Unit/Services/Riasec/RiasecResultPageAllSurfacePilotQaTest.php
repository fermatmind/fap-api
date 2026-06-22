<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Services\Report\RiasecReportComposer;
use Tests\TestCase;

final class RiasecResultPageAllSurfacePilotQaTest extends TestCase
{
    private const REPORT_PATH = __DIR__.'/../../../../content_assets/riasec/result_page_v2/qa/all_surface_pilot_qa/v0_1/riasec_result_page_v2_all_surface_pilot_qa_report_v0_1.json';

    public function test_all_surface_pilot_qa_report_is_staging_only_and_covers_required_surfaces(): void
    {
        $report = $this->qaReport();

        $this->assertSame('fap.riasec.result_page_v2.all_surface_pilot_qa_report.v0.1', $report['schema'] ?? null);
        $this->assertSame('staging_only', $report['runtime_use'] ?? null);
        $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
        $this->assertFalse((bool) ($report['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($report['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($report['runtime_wrapper_enablement_changed'] ?? true));
        $this->assertFalse((bool) ($report['frontend_fallback_allowed'] ?? true));
        $this->assertFalse((bool) ($report['production_gate_opened'] ?? true));

        $surfaces = array_column((array) ($report['surfaces'] ?? []), 'surface');
        sort($surfaces);
        $expectedSurfaces = [
            'compare',
            'fallback',
            'free',
            'history',
            'locked',
            'low_quality',
            'pdf',
            'result_page',
            'share',
        ];
        sort($expectedSurfaces);
        $this->assertSame($expectedSurfaces, $surfaces);

        foreach ((array) ($report['surfaces'] ?? []) as $surface) {
            $this->assertNotSame('', (string) ($surface['pilot_payload_policy'] ?? ''));
            $this->assertNotSame('', (string) ($surface['expected_payload_state'] ?? ''));
            $this->assertNotSame('', (string) ($surface['redaction_state'] ?? ''));
            $this->assertStringStartsWith('pass_', (string) ($surface['qa_decision'] ?? ''));
        }
    }

    public function test_all_surface_pilot_qa_forbids_private_public_payload_fields(): void
    {
        $encoded = json_encode($this->qaReport(), JSON_THROW_ON_ERROR);

        foreach ([
            'raw_score',
            'private_score',
            'score_vector',
            'percentile',
            'token',
            'email',
            'phone',
        ] as $forbiddenField) {
            $this->assertStringContainsString($forbiddenField, $encoded);
        }

        $this->assertStringNotContainsString('sk-', $encoded);
        $this->assertStringNotContainsString('Bearer ', $encoded);
        $this->assertStringNotContainsString('private_payload_exported\":true', $encoded);
        $this->assertStringNotContainsString('frontend_fallback_allowed\":true', $encoded);
        $this->assertStringNotContainsString('production_use_allowed\":true', $encoded);
    }

    public function test_runtime_wrapper_matches_all_surface_free_and_full_redaction_assertions(): void
    {
        $this->enablePilotGate();

        $fullPayload = $this->compose(ReportAccess::VARIANT_FULL);
        $this->assertIsArray($fullPayload);
        $this->assertSame('allow', data_get($fullPayload, 'gate.pilot_gate_decision'));
        $this->assertFalse((bool) data_get($fullPayload, 'redaction_policy.locked_payload_allowed', true));
        $this->assertFalse((bool) data_get($fullPayload, 'redaction_policy.free_payload_allowed', true));
        $this->assertFalse((bool) data_get($fullPayload, 'frontend_fallback_allowed', true));
        $this->assertSame('normal', data_get($fullPayload, 'selector_inputs.quality_state'));

        $freePayload = $this->compose(ReportAccess::VARIANT_FREE);
        $this->assertNull($freePayload);
    }

    /**
     * @return array<string,mixed>
     */
    private function qaReport(): array
    {
        $decoded = json_decode((string) file_get_contents(self::REPORT_PATH), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function enablePilotGate(): void
    {
        config()->set('riasec_result_page_v2.enabled', true);
        config()->set('riasec_result_page_v2.staging_runtime_enabled', false);
        config()->set('riasec_result_page_v2.pilot_runtime_enabled', true);
        config()->set('riasec_result_page_v2.pilot_kill_switch_enabled', false);
        config()->set('riasec_result_page_v2.allowed_environments', ['testing']);
        config()->set('riasec_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('riasec_result_page_v2.pilot_allowed_form_codes', ['riasec_60']);
        config()->set('riasec_result_page_v2.pilot_allowed_locales', ['zh-CN']);
        config()->set('riasec_result_page_v2.pilot_access_allowed_anon_ids', ['pilot_anon_all_surface']);
        config()->set('riasec_result_page_v2.production_runtime_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_manual_approval_granted', false);
    }

    private function compose(string $variant): ?array
    {
        $result = app(RiasecReportComposer::class)->composeVariant(
            $this->attempt(),
            $this->riasecResult(),
            $variant,
            [
                'snapshot_bound' => true,
                'riasec_result_page_v2_pilot' => true,
            ]
        );
        $this->assertTrue((bool) ($result['ok'] ?? false));

        return data_get($result, 'report._meta.result_page_v2');
    }

    private function attempt(): Attempt
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'pilot_attempt_all_surface';
        $attempt->anon_id = 'pilot_anon_all_surface';
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';

        return $attempt;
    }

    private function riasecResult(): Result
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';
        $result->result_json = [
            'top_code' => 'RIA',
            'primary_type' => 'R',
            'secondary_type' => 'I',
            'tertiary_type' => 'A',
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'quality_grade' => 'A',
            'quality_state' => 'normal',
            'scores_0_100' => [
                'R' => 100,
                'I' => 80,
                'A' => 60,
                'S' => 40,
                'E' => 20,
                'C' => 10,
            ],
        ];

        return $result;
    }
}
