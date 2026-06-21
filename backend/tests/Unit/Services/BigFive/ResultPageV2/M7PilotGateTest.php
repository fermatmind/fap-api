<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class M7PilotGateTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/qa/m7_pilot_gate/v0_1';

    public function test_m7_pilot_gate_package_allows_only_allowlist_pilot(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_m7_pilot_gate_report_v0_1.json');
        $validation = $this->jsonFile('big5_v2_m7_pilot_gate_validation_v0_1.json');

        foreach ([$manifest, $report, $validation] as $document) {
            $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
            $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
            $this->assertTrue((bool) ($document['ready_for_pilot'] ?? false));
            $this->assertFalse((bool) ($document['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
            $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
            $this->assertSame('GO_ALLOWLIST_ONLY', $document['pilot_gate_decision'] ?? null);
            $this->assertSame('NO_GO', $document['production_decision'] ?? null);
        }

        $this->assertContains('production_public_pilot_allowlist_only', $report['allowed_pilot_modes'] ?? []);
        $this->assertContains('non_production_percentage_simulation', $report['allowed_pilot_modes'] ?? []);
        $this->assertContains('full_production_rollout', $report['blocked_modes'] ?? []);
        $this->assertContains('ops_dashboard_reporting', $report['blocked_modes'] ?? []);
    }

    public function test_m7_gate_requires_all_five_qa_families_before_pilot(): void
    {
        $report = $this->jsonFile('big5_v2_m7_pilot_gate_report_v0_1.json');

        foreach ([
            'coverage_qa',
            'safety_qa',
            'editorial_qa',
            'mapping_qa',
            'rendered_preview_qa',
        ] as $gate) {
            $this->assertSame('pass', data_get($report, "gate_status.{$gate}.status"), $gate);
            $this->assertNotSame('', (string) data_get($report, "gate_status.{$gate}.evidence"), $gate);
        }

        $this->assertSame('present_not_production_enabled', data_get($report, 'gate_status.release_snapshot.status'));
        $this->assertSame('policy_present_fail_closed', data_get($report, 'gate_status.production_import_gate.status'));
        $this->assertSame('allowlist_gate_ready_default_disabled', data_get($report, 'gate_status.rollout_gate.status'));
    }

    public function test_m7_runtime_contract_keeps_production_percentage_disabled_by_default(): void
    {
        $report = $this->jsonFile('big5_v2_m7_pilot_gate_report_v0_1.json');
        $contract = (array) ($report['runtime_configuration_contract'] ?? []);

        $this->assertTrue((bool) ($contract['public_pilot_enabled_required'] ?? false));
        $this->assertSame('result_page_only', $contract['public_pilot_surface_scope_required'] ?? null);
        $this->assertTrue((bool) ($contract['public_pilot_production_allowlist_enabled_required'] ?? false));
        $this->assertFalse((bool) ($contract['default_production_percentage_enabled'] ?? true));
        $this->assertSame(0, $contract['default_production_max_percentage'] ?? null);

        $this->assertFalse((bool) config('big5_result_page_v2.public_pilot_production_percentage_enabled'));
        $this->assertSame(0, (int) config('big5_result_page_v2.public_pilot_production_max_percentage'));
    }

    public function test_m7_gate_defers_m8_ops_and_does_not_make_legacy_engine_primary(): void
    {
        $report = $this->jsonFile('big5_v2_m7_pilot_gate_report_v0_1.json');
        $validation = $this->jsonFile('big5_v2_m7_pilot_gate_validation_v0_1.json');

        $this->assertContains('M8 Ops metrics', $report['must_not_touch'] ?? []);
        $this->assertContains('big5_report_engine_v2 primary path', $report['must_not_touch'] ?? []);
        $this->assertSame('pass', data_get($validation, 'checks.m8_ops_deferred'));
        $this->assertContains('m8_ops_metrics_not_connected', $validation['hard_blockers_before_production'] ?? []);
    }

    public function test_m7_files_do_not_enable_runtime_or_production(): void
    {
        foreach (glob(base_path(self::BASE_PATH.'/*')) ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $normalized = preg_replace('/\s+/', '', (string) file_get_contents($file));
            $this->assertIsString($normalized, $file);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_runtime":true', $normalized, $file);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $file);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $file);
            $this->assertStringNotContainsString('[objectObject]', $normalized, $file);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertCount(5, $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(self::BASE_PATH.'/'.$fileName)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
