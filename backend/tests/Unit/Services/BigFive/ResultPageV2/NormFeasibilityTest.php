<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class NormFeasibilityTest extends TestCase
{
    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/norm_feasibility_go_no_go/v0_1';

    public function test_feasibility_report_exists_with_foundation_go_and_public_display_no_go(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_norm_feasibility_go_no_go_report_v0_1.json');

        $this->assertSame('big5_v2_norm_feasibility_go_no_go', $manifest['package'] ?? null);
        $this->assertSame('GO', $report['foundation_readiness']['eligibility_policy'] ?? null);
        $this->assertSame('GO', $report['foundation_readiness']['append_only_observation_schema'] ?? null);
        $this->assertSame('GO_INTERNAL_DEFAULT_OFF', $report['foundation_readiness']['capture_writer'] ?? null);
        $this->assertSame('GO_POLICY_AND_SERVICE', $report['foundation_readiness']['privacy_layer'] ?? null);
        $this->assertSame('GO_INTERNAL_DRY_RUN', $report['foundation_readiness']['aggregation_dry_run'] ?? null);
        $this->assertSame('NO_GO', $report['foundation_readiness']['dynamic_norm_engine'] ?? null);
        $this->assertSame('NO_GO', $report['foundation_readiness']['public_percentile_display'] ?? null);
        $this->assertSame('GO', $report['final_decision']['norm_foundation'] ?? null);
        $this->assertSame('NO-GO', $report['final_decision']['dynamic_norm_engine'] ?? null);
        $this->assertSame('NO-GO', $report['final_decision']['public_percentile_display'] ?? null);
        $this->assertSafetyDefaults($manifest);
        $this->assertSafetyDefaults($report);
    }

    public function test_report_defines_sample_thresholds_and_small_cell_suppression(): void
    {
        $thresholds = (array) ($this->report()['sample_threshold_recommendations'] ?? []);

        $this->assertSame(1000, $thresholds['minimum_global_sample_before_engine'] ?? null);
        $this->assertSame(200, $thresholds['minimum_locale_region_cell'] ?? null);
        $this->assertSame(50, $thresholds['minimum_public_cell'] ?? null);
        $this->assertSame(100, $thresholds['minimum_occupation_cell'] ?? null);
        $this->assertSame('suppress_group_output', $thresholds['small_cell_behavior'] ?? null);
    }

    public function test_report_keeps_rollout_and_cms_out_of_scope(): void
    {
        $report = $this->report();

        $this->assertSame('not_blocking_rollout_readiness', $report['production_rollout_relationship']['status'] ?? null);
        $this->assertFalse((bool) ($report['production_rollout_relationship']['production_rollout_enabled_by_this_package'] ?? true));
        $this->assertFalse((bool) ($report['production_rollout_relationship']['public_norms_required_for_rollout'] ?? true));
        $this->assertSame('out_of_scope', $report['cms_relationship']['status'] ?? null);
        $this->assertFalse((bool) ($report['cms_relationship']['cms_connected_by_this_package'] ?? true));
    }

    public function test_dependency_graph_blocks_future_engine_and_public_display(): void
    {
        $graph = $this->jsonFile('big5_v2_norm_dependency_graph_v0_1.json');

        $this->assertContains('future_dynamic_norm_engine', $graph['blocked_nodes'] ?? []);
        $this->assertContains('future_public_percentile_display', $graph['blocked_nodes'] ?? []);
        $this->assertContains(['aggregation_dry_run', 'future_dynamic_norm_engine'], $graph['edges'] ?? []);
        $this->assertContains(['future_dynamic_norm_engine', 'future_public_percentile_display'], $graph['edges'] ?? []);
        $this->assertSafetyDefaults($graph);
    }

    public function test_report_files_do_not_enable_runtime_public_norms_or_production(): void
    {
        foreach ([
            'manifest.json',
            'big5_v2_norm_feasibility_go_no_go_report_v0_1.json',
            'big5_v2_norm_dependency_graph_v0_1.json',
        ] as $fileName) {
            $normalized = preg_replace('/\s+/', '', (string) file_get_contents(base_path(self::QA_PATH.'/'.$fileName)));
            $this->assertIsString($normalized, $fileName);

            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"dynamic_norm_engine_attached":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"public_percentile_display_enabled":true', $normalized, $fileName);
        }
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::QA_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $this->assertSame($expectedHash, hash_file('sha256', base_path(self::QA_PATH.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function report(): array
    {
        return $this->jsonFile('big5_v2_norm_feasibility_go_no_go_report_v0_1.json');
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertSafetyDefaults(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['dynamic_norm_engine_attached'] ?? true));
        $this->assertFalse((bool) ($document['public_percentile_display_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::QA_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
