<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use Tests\TestCase;

final class PublicPercentileGovernanceTest extends TestCase
{
    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/public_percentile_governance/v0_1';

    public function test_public_percentile_governance_report_exists_and_keeps_public_display_no_go(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_public_percentile_governance_report_v0_1.json');

        $this->assertSame('big5_v2_public_percentile_governance', $manifest['package'] ?? null);
        $this->assertSame('GO', data_get($report, 'readiness.immutable_norm_snapshots'));
        $this->assertSame('GO', data_get($report, 'readiness.norm_recomputation'));
        $this->assertSame('GO', data_get($report, 'readiness.segmented_aggregation'));
        $this->assertSame('GO', data_get($report, 'readiness.drift_monitoring'));
        $this->assertSame('GO', data_get($report, 'readiness.internal_percentile_runtime'));
        $this->assertSame('NO-GO', data_get($report, 'readiness.public_percentile_display'));
        $this->assertSame('NO-GO', data_get($report, 'public_percentile_go_no_go.decision'));
        $this->assertTrue((bool) data_get($report, 'public_percentile_go_no_go.future_approval_required'));
        $this->assertFalse((bool) data_get($report, 'sparse_segment_policy.fallback_to_fake_percentile_allowed'));
        $this->assertFalse((bool) data_get($report, 'sparse_segment_policy.fallback_to_public_prose_allowed'));
        $this->assertSafetyDefaults($manifest);
        $this->assertSafetyDefaults($report);
    }

    public function test_public_percentile_dependency_graph_blocks_future_public_display(): void
    {
        $graph = $this->jsonFile('big5_v2_public_percentile_dependency_graph_v0_1.json');

        $this->assertContains('future_public_percentile_display', $graph['blocked_nodes'] ?? []);
        $this->assertContains(['internal_percentile_runtime', 'future_public_percentile_display'], $graph['edges'] ?? []);
        $this->assertContains('public_percentile_display_not_approved', $graph['blockers'] ?? []);
        $this->assertSafetyDefaults($graph);
    }

    public function test_report_files_do_not_enable_public_percentile_runtime_or_production(): void
    {
        foreach ([
            'manifest.json',
            'big5_v2_public_percentile_governance_report_v0_1.json',
            'big5_v2_public_percentile_dependency_graph_v0_1.json',
        ] as $fileName) {
            $normalized = preg_replace('/\s+/', '', (string) file_get_contents(base_path(self::QA_PATH.'/'.$fileName)));
            $this->assertIsString($normalized, $fileName);
            $this->assertStringNotContainsString('"production_use_allowed":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"ready_for_production":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"production_rollout_enabled":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"dynamic_norm_engine_attached":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"public_percentile_display_enabled":true', $normalized, $fileName);
            $this->assertStringNotContainsString('"fallback_to_fake_percentile_allowed":true', $normalized, $fileName);
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
