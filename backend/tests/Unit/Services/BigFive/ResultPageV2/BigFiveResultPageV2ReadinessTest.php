<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class BigFiveResultPageV2ReadinessTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/route_driven_pilot_readiness/v0_1';

    public function test_route_driven_readiness_report_is_advisory_and_not_production_go(): void
    {
        $report = $this->jsonFile('big5_route_driven_pilot_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_route_driven_pilot_readiness_summary_v0_1.json');

        foreach ([$report, $summary] as $document) {
            $this->assertSame('route_driven_pilot_readiness_advisory', $document['mode'] ?? null);
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_asset_review'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertFalse((bool) ($document['production_go'] ?? true));
        }

        $this->assertSame('ready_for_small_allowlisted_non_production_pilot', data_get($report, 'go_no_go.controlled_pilot.status'));
        $this->assertTrue((bool) data_get($report, 'go_no_go.controlled_pilot.can_enter'));
        $this->assertSame('no_go', data_get($report, 'go_no_go.public_pilot.status'));
        $this->assertFalse((bool) data_get($report, 'go_no_go.public_pilot.can_enter'));
        $this->assertSame('no_go', data_get($report, 'go_no_go.production.status'));
        $this->assertFalse((bool) data_get($report, 'go_no_go.production.can_enter'));
    }

    public function test_route_driven_readiness_tracks_evidence_counts_and_pending_surfaces(): void
    {
        $report = $this->jsonFile('big5_route_driven_pilot_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_route_driven_pilot_readiness_summary_v0_1.json');
        $surfaces = $this->surfacesByKey($report);

        $this->assertSame(9, data_get($report, 'evidence.route_driven_payload_fixture_count'));
        $this->assertSame(16, data_get($report, 'evidence.backend_golden_case_count'));
        $this->assertSame(9, $summary['route_driven_payload_fixture_count'] ?? null);
        $this->assertSame(8, $summary['canonical_profile_family_fixture_count'] ?? null);
        $this->assertSame(16, $summary['golden_case_count'] ?? null);

        $this->assertSame('pass', data_get($surfaces, 'result_page_desktop.status'));
        $this->assertNotSame([], data_get($surfaces, 'result_page_desktop.evidence'));
        $this->assertSame('pass', data_get($surfaces, 'result_page_mobile.status'));
        $this->assertNotSame([], data_get($surfaces, 'result_page_mobile.evidence'));

        foreach (['pdf', 'share_card', 'history', 'compare'] as $surfaceKey) {
            $this->assertSame('pending_surface', data_get($surfaces, "{$surfaceKey}.status"), $surfaceKey);
            $this->assertSame([], data_get($surfaces, "{$surfaceKey}.evidence"), $surfaceKey);
            $this->assertNotSame('', (string) data_get($surfaces, "{$surfaceKey}.blocker"), $surfaceKey);
        }

        $this->assertSame([
            'pass' => 2,
            'pending_surface' => 4,
            'fail' => 0,
        ], $report['status_counts'] ?? null);
        $this->assertSame($report['status_counts'] ?? null, $summary['rendered_surface_status_counts'] ?? null);
    }

    public function test_route_driven_readiness_keeps_runtime_and_access_gates_safe(): void
    {
        $report = $this->jsonFile('big5_route_driven_pilot_readiness_report_v0_1.json');
        $summary = $this->jsonFile('big5_route_driven_pilot_readiness_summary_v0_1.json');

        $this->assertTrue((bool) data_get($report, 'pilot_gate_status.pilot_runtime_flag_default_off'));
        $this->assertTrue((bool) data_get($report, 'pilot_gate_status.access_gate_default_deny'));
        $this->assertTrue((bool) data_get($report, 'pilot_gate_status.allowed_non_production_attach_path_tested'));
        $this->assertTrue((bool) data_get($report, 'pilot_gate_status.production_disabled'));
        $this->assertStringContainsString('flag', (string) data_get($report, 'pilot_gate_status.rollback'));

        $this->assertTrue((bool) ($summary['pilot_runtime_flag_default_off'] ?? false));
        $this->assertTrue((bool) ($summary['access_gate_default_deny'] ?? false));
        $this->assertTrue((bool) ($summary['production_disabled'] ?? false));
        $this->assertTrue((bool) ($summary['cms_out_of_scope'] ?? false));
        $this->assertTrue((bool) ($summary['dynamic_norms_out_of_scope'] ?? false));
        $this->assertTrue((bool) ($summary['no_body_generated'] ?? false));
    }

    public function test_route_driven_readiness_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertIsArray($entries);
        $this->assertCount(3, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path));
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function surfacesByKey(array $report): array
    {
        $surfaces = [];
        foreach ((array) ($report['surface_matrix'] ?? []) as $surface) {
            $surfaces[(string) ($surface['surface_key'] ?? '')] = $surface;
        }
        ksort($surfaces);

        return $surfaces;
    }

    /**
     * @return array<int|string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
