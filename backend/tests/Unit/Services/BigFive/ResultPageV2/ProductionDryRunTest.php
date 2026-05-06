<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class ProductionDryRunTest extends TestCase
{
    private const BASE_PATH = 'content_assets/big5/result_page_v2/governance/production_dry_run_go_no_go_v0_1';

    private const REQUIRED_STATUS_SECTIONS = [
        'release_snapshot_status',
        'import_gate_status',
        'runtime_gate_status',
        'all_surface_qa_status',
        'rollback_readiness',
        'approval_audit_evidence',
        'fail_closed_evidence',
        'production_enablement_status',
        'final_go_no_go',
    ];

    public function test_dry_run_report_package_exists_without_production_enablement(): void
    {
        $this->assertFileExists(base_path(self::BASE_PATH.'/README.md'));
        $this->assertFileExists(base_path(self::BASE_PATH.'/manifest.json'));
        $this->assertFileExists(base_path(self::BASE_PATH.'/big5_v2_production_dry_run_go_no_go_report_v0_1.json'));

        $manifest = $this->jsonFile('manifest.json');

        $this->assertSame('big5_v2_production_dry_run_go_no_go', $manifest['package'] ?? null);
        $this->assertSame('production_dry_run_report', $manifest['mode'] ?? null);
        $this->assertProductionDisabled($manifest);
    }

    public function test_report_contains_required_gate_statuses(): void
    {
        $report = $this->report();

        foreach (self::REQUIRED_STATUS_SECTIONS as $section) {
            $this->assertArrayHasKey($section, $report, $section);
            $this->assertNotSame([], $report[$section], $section);
        }

        $this->assertSame('pass', $report['release_snapshot_status']['status'] ?? null);
        $this->assertTrue((bool) ($report['release_snapshot_status']['immutable'] ?? false));
        $this->assertSame('pass', $report['import_gate_status']['status'] ?? null);
        $this->assertSame('pass_disabled_by_default', $report['runtime_gate_status']['status'] ?? null);
        $this->assertFalse((bool) ($report['runtime_gate_status']['default_on'] ?? true));
        $this->assertSame('pass', $report['all_surface_qa_status']['status'] ?? null);
        $this->assertSame('pass', $report['rollback_readiness']['status'] ?? null);
        $this->assertSame('present_no_production_approval', $report['approval_audit_evidence']['status'] ?? null);
        $this->assertSame('pass', $report['fail_closed_evidence']['status'] ?? null);
    }

    public function test_report_is_explicit_no_go_until_human_production_approval_exists(): void
    {
        $report = $this->report();

        $this->assertProductionDisabled($report);
        $this->assertSame('disabled', $report['production_enablement_status'] ?? null);
        $this->assertFalse((bool) ($report['explicit_human_production_approval'] ?? true));
        $this->assertFalse((bool) ($report['go_recommended'] ?? true));
        $this->assertSame('NO-GO', $report['final_go_no_go'] ?? null);
        $this->assertTrue((bool) ($report['human_approval_required_before_go'] ?? false));
        $this->assertContains('human_production_approval_missing', array_column((array) ($report['blockers'] ?? []), 'code'));
    }

    public function test_report_keeps_cms_dynamic_norms_and_scoring_out_of_scope(): void
    {
        $outOfScope = (array) ($this->report()['out_of_scope'] ?? []);

        $this->assertSame('out_of_scope_not_connected', $outOfScope['cms'] ?? null);
        $this->assertSame('out_of_scope_not_connected', $outOfScope['dynamic_norms'] ?? null);
        $this->assertFalse((bool) ($outOfScope['scoring_changes'] ?? true));
        $this->assertFalse((bool) ($outOfScope['content_body_generation'] ?? true));
        $this->assertFalse((bool) ($outOfScope['frontend_fallback_prose'] ?? true));
    }

    public function test_report_files_do_not_enable_production(): void
    {
        foreach ([
            'manifest.json',
            'big5_v2_production_dry_run_go_no_go_report_v0_1.json',
        ] as $fileName) {
            $normalized = $this->normalizedJson($fileName);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"approved_for_production":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $fileName);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::BASE_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);
        $this->assertNotSame([], $entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $path = base_path(self::BASE_PATH.'/'.$fileName);

            $this->assertFileExists($path);
            $this->assertSame($expectedHash, hash_file('sha256', $path), $fileName);
        }
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertProductionDisabled(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        return $this->jsonFile('big5_v2_production_dry_run_go_no_go_report_v0_1.json');
    }

    private function normalizedJson(string $fileName): string
    {
        $json = file_get_contents(base_path(self::BASE_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $normalized = preg_replace('/\s+/', '', $json);
        $this->assertIsString($normalized, $fileName);

        return $normalized;
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
