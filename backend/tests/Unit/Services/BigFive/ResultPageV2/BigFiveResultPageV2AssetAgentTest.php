<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\AssetAgent\BigFiveResultPageV2AssetAgent;
use Tests\TestCase;

final class BigFiveResultPageV2AssetAgentTest extends TestCase
{
    public function test_audit_command_writes_redacted_staging_reports_without_runtime_changes(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-command');

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'audit',
                '--run-id' => 'unit-run',
                '--artifact-dir' => $artifactRoot,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/unit-run';
            foreach ([
                'inventory_gaps.json',
                'source_license_classification.json',
                'p0_batch_plan.json',
                'qa_eval_summary.json',
                'ops_report_summary.json',
                'promotion_gate_report.md',
                'repair_log.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $inventory = $this->readJson($runDir.'/inventory_gaps.json');
            $this->assertSame('staging_only', $inventory['runtime_use'] ?? null);
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));
            $this->assertSame('big5_result_page_v2', data_get($inventory, 'inputs.contracts.payload_key'));

            $classification = $this->readJson($runDir.'/source_license_classification.json');
            $labels = array_column((array) ($classification['classifications'] ?? []), 'label', 'source_id');
            $this->assertSame('public_domain_source', $labels['ipip_official'] ?? null);
            $this->assertSame('structure_reference_only', $labels['bfi_2_colby'] ?? null);
            $this->assertSame('forbidden_copy_source', $labels['proprietary_personality_reports_and_bfi2_item_text'] ?? null);

            $plan = $this->readJson($runDir.'/p0_batch_plan.json');
            $this->assertGreaterThanOrEqual(8, (int) ($plan['p0_batch_count'] ?? 0));
            $this->assertFalse((bool) data_get($plan, 'batches.0.output_policy.candidate_generation_allowed', true));

            $promotionReport = (string) file_get_contents($runDir.'/promotion_gate_report.md');
            $this->assertStringContainsString('ready_for_runtime: false', $promotionReport);
            $this->assertStringContainsString('production_use_allowed: false', $promotionReport);
            $this->assertStringContainsString('## Ops Metrics', $promotionReport);
            $this->assertStringContainsString('comparison_status: no_previous_run', $promotionReport);

            $qa = $this->readJson($runDir.'/qa_eval_summary.json');
            $this->assertSame(
                'fap.big5.result_page_v2.asset_agent.ops_report.v0.1',
                $qa['ops_report_schema_version'] ?? null
            );
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_pilot', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_runtime', true));
            $this->assertFalse((bool) data_get($qa, 'ops_metrics.ready_for_production', true));

            $opsReport = $this->readJson($runDir.'/ops_report_summary.json');
            $this->assertSame(
                'fap.big5.result_page_v2.asset_agent.ops_report.v0.1',
                $opsReport['schema_version'] ?? null
            );
            foreach ([
                'p0_blocker_count',
                'registry_gap_count',
                'module_gap_count',
                'scope_gap_count',
                'reading_mode_gap_count',
                'share_safety_missing_count',
                'norm_unavailable_missing',
                'low_quality_missing',
                'forbidden_leak_hit_count',
                'ready_for_pilot',
                'ready_for_runtime',
                'ready_for_production',
            ] as $metricKey) {
                $this->assertArrayHasKey($metricKey, (array) ($opsReport['metrics'] ?? []));
            }
            $this->assertSame('no_previous_run', data_get($opsReport, 'diff_summary.comparison_status'));

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'inventory_gaps.json',
                    'source_license_classification.json',
                    'p0_batch_plan.json',
                    'qa_eval_summary.json',
                    'ops_report_summary.json',
                    'promotion_gate_report.md',
                    'repair_log.json',
                ]
            ));
            $this->assertStringNotContainsString('private_url', $allArtifacts);
            $this->assertStringNotContainsString('attempt_id', $allArtifacts);
            $this->assertStringNotContainsString('body_zh', $allArtifacts);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_audit_ops_report_compares_against_previous_run(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-ops-diff');

        try {
            app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'run-a',
                'artifact_dir' => $artifactRoot,
            ]);
            usleep(1000);
            app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'run-b',
                'artifact_dir' => $artifactRoot,
            ]);

            $opsReport = $this->readJson($artifactRoot.'/run-b/ops_report_summary.json');
            $this->assertSame('compared', data_get($opsReport, 'diff_summary.comparison_status'));
            $this->assertSame('run-a', data_get($opsReport, 'diff_summary.previous_run_id'));
            $this->assertArrayHasKey(
                'p0_blocker_count',
                (array) data_get($opsReport, 'diff_summary.metric_deltas', [])
            );
            $this->assertSame(
                0,
                data_get($opsReport, 'diff_summary.metric_deltas.p0_blocker_count.delta')
            );
            $this->assertFalse((bool) data_get($opsReport, 'metrics.ready_for_production', true));
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_strict_mode_rejects_public_payload_and_shareable_score_leaks(): void
    {
        $root = $this->tempDir('big5-v2-agent-leak');
        $contentRoot = $root.'/content_assets/big5/result_page_v2';
        $registryRoot = $root.'/content_packs/BIG5_OCEAN/v2/registry';
        mkdir($contentRoot.'/selector_ready_assets/v0_1', 0777, true);
        mkdir($registryRoot.'/shared', 0777, true);

        file_put_contents($registryRoot.'/shared/trait_labels.json', '{}');
        file_put_contents($contentRoot.'/selector_ready_assets/v0_1/assets.jsonl', json_encode([
            'version' => 'fap.big5.result_page_v2.selector_asset.v0.1',
            'asset_key' => 'leaky_asset',
            'registry_key' => 'share_safety_registry',
            'module_key' => 'module_08_share_save',
            'block_key' => 'module_08_share_save.share_safety.leaky',
            'block_kind' => 'share_save',
            'slot_key' => 'share_save.safety_transform',
            'reading_modes' => ['share_safe'],
            'scope' => 'share_safe_summary_only',
            'shareable' => true,
            'shareable_policy' => 'required_for_every_shareable_true_block',
            'safety_level' => 'share_safe',
            'public_payload' => [
                'summary' => 'This share block exposes raw_score percentile and fixed_type.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        $matrixPath = $contentRoot.'/personalization_coverage_matrix_v0_2.json';
        file_put_contents($matrixPath, json_encode([
            'schema' => 'fap.big5.result_page_v2.personalization_coverage_matrix.v0.2',
            'entries' => [
                [
                    'registry_key' => 'share_safety_registry',
                    'coverage_group' => 'share_transforms_forbidden_fields_safe_quote_pool',
                    'target_module' => 'module_08_share_save',
                    'slot_key' => 'share_save.safety_transform',
                    'reading_modes' => ['share_safe'],
                    'scope' => 'share_safe_summary_only',
                    'priority' => 100,
                    'priority_tier' => 'P0',
                    'shareable_policy' => 'required_for_every_shareable_true_block',
                    'safety_level' => 'share_safe',
                    'fallback_policy' => 'share_safe_summary_only',
                    'missing_blocks' => 1,
                    'estimated_block_count' => 1,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'strict-leak',
                'artifact_dir' => $root.'/artifacts',
                'content_asset_root' => $contentRoot,
                'registry_root' => $registryRoot,
                'coverage_matrix_path' => $matrixPath,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('forbidden_leak_hits', $summary['strict_failures']);

            $qa = $this->readJson($root.'/artifacts/strict-leak/qa_eval_summary.json');
            $this->assertSame('blocked', data_get($qa, 'leak_scan.status'));
            $this->assertGreaterThanOrEqual(3, (int) data_get($qa, 'leak_scan.hit_count'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
