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
                'input_inventory.json',
                'validation_report.json',
                'safety_report.json',
                'qa_eval_summary.json',
                'ops_report_summary.json',
                'go_no_go.md',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $inventory = $this->readJson($runDir.'/input_inventory.json');
            $this->assertSame('staging_only', $inventory['runtime_use'] ?? null);
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));
            $this->assertSame('fap.big5.result_page_v2.selector_asset.v0.1', data_get($inventory, 'inputs.selector_asset_schema'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.valid'));
            $this->assertStringEndsWith(
                'content_assets/big5/result_page_v2/source_ledger/source_ledger.json',
                (string) data_get($inventory, 'source_ledger.primary_ledger_path')
            );
            $this->assertSame([
                'public_domain_source',
                'citation_only',
                'structure_reference_only',
                'forbidden_copy_source',
            ], data_get($inventory, 'source_ledger.allowed_source_labels'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.bfi_2_policy_valid'));

            $validation = $this->readJson($runDir.'/validation_report.json');
            $this->assertSame(325, (int) ($validation['asset_count'] ?? 0));
            $this->assertSame(0, (int) ($validation['error_count'] ?? -1));
            $this->assertSame([], (array) ($validation['errors'] ?? []));

            $safety = $this->readJson($runDir.'/safety_report.json');
            $this->assertSame('pass', data_get($safety, 'leak_scan.status'));

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('ready_for_runtime: false', $goNoGo);
            $this->assertStringContainsString('production_use_allowed: false', $goNoGo);
            $this->assertStringContainsString('## Ops Metrics', $goNoGo);
            $this->assertStringContainsString('comparison_status: no_previous_run', $goNoGo);

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
                    'input_inventory.json',
                    'validation_report.json',
                    'safety_report.json',
                    'qa_eval_summary.json',
                    'ops_report_summary.json',
                    'go_no_go.md',
                ]
            ));
            $this->assertStringContainsString('private_url', $allArtifacts);
            $this->assertStringContainsString('attempt_id', $allArtifacts);
            $this->assertStringNotContainsString(sys_get_temp_dir(), $allArtifacts);
            $this->assertStringNotContainsString('body_zh', $allArtifacts);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_source_ledger_contract_fixes_source_labels_and_bfi2_copy_boundary(): void
    {
        $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
            'run_id' => 'source-ledger-contract',
            'artifact_dir' => $this->tempDir('big5-v2-agent-source-ledger'),
        ]);
        $artifactDir = (string) ($summary['artifact_dir'] ?? '');

        try {
            $inventory = $this->readJson($artifactDir.'/input_inventory.json');
            $sourceLedger = (array) data_get($inventory, 'source_ledger', []);

            $this->assertTrue((bool) ($sourceLedger['valid'] ?? false));
            $this->assertSame([], $sourceLedger['errors'] ?? ['unexpected']);
            $this->assertSame([
                'public_domain_source',
                'citation_only',
                'structure_reference_only',
                'forbidden_copy_source',
            ], $sourceLedger['allowed_source_labels'] ?? []);
            $this->assertSame(1, data_get($sourceLedger, 'label_counts.public_domain_source'));
            $this->assertGreaterThanOrEqual(1, (int) data_get($sourceLedger, 'label_counts.citation_only', 0));
            $this->assertGreaterThanOrEqual(1, (int) data_get($sourceLedger, 'label_counts.structure_reference_only', 0));
            $this->assertSame(1, data_get($sourceLedger, 'label_counts.forbidden_copy_source'));
            $this->assertTrue((bool) ($sourceLedger['bfi_2_policy_valid'] ?? false));

            foreach ([
                'ipip_official',
                'bfi_2_colby',
                'bigfive_web_github',
                'internal_big5_v2_formal_doc',
                'internal_big5_twenty_thousand_word_final_doc',
                'existing_big5_result_page_v2_asset_packs',
                'restricted_bfi2_item_text_and_proprietary_reports',
            ] as $sourceId) {
                $this->assertContains($sourceId, (array) ($sourceLedger['required_source_ids_present'] ?? []));
            }
        } finally {
            $this->deleteDirectory(dirname($artifactDir));
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

    public function test_generate_candidates_writes_staging_only_selector_and_content_asset_drafts(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-candidates');

        try {
            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'generate-candidates',
                '--run-id' => 'candidate-run',
                '--artifact-dir' => $artifactRoot,
                '--json' => true,
            ])->assertExitCode(0);

            $runDir = $artifactRoot.'/candidate-run';
            foreach ([
                'selector_asset_candidates.jsonl',
                'content_asset_candidates.jsonl',
                'candidate_generation_summary.json',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $selectorCandidates = $this->readJsonl($runDir.'/selector_asset_candidates.jsonl');
            $contentCandidates = $this->readJsonl($runDir.'/content_asset_candidates.jsonl');
            $summary = $this->readJson($runDir.'/candidate_generation_summary.json');

            $this->assertCount(1, $selectorCandidates);
            $this->assertCount(1, $contentCandidates);
            $this->assertSame('staging_only', $summary['runtime_use'] ?? null);
            $this->assertFalse((bool) ($summary['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($summary['ready_for_production'] ?? true));
            $this->assertSame('pass', data_get($summary, 'validation.status'));
            $this->assertSame(0, data_get($summary, 'validation.error_count'));
            $this->assertSame('pass', data_get($summary, 'leak_scan.status'));
            $this->assertSame(0, data_get($summary, 'leak_scan.hit_count'));
            $this->assertTrue((bool) data_get($summary, 'source_ledger.valid'));
            $this->assertTrue((bool) data_get($summary, 'source_ledger.bfi_2_policy_valid'));

            $selector = $selectorCandidates[0];
            $this->assertSame('draft', $selector['review_status'] ?? null);
            $this->assertFalse((bool) ($selector['shareable'] ?? true));
            $this->assertSame('staging_only', data_get($selector, 'public_payload.runtime_use'));
            $this->assertFalse((bool) data_get($selector, 'public_payload.production_use_allowed', true));

            $content = $contentCandidates[0];
            $this->assertSame('draft', $content['qa_status'] ?? null);
            $this->assertSame('staging_only', $content['runtime_use'] ?? null);
            $this->assertFalse((bool) ($content['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($content['ready_for_pilot'] ?? true));
            $this->assertFalse((bool) data_get($content, 'source_trace.bfi_2_copy_used', true));

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'selector_asset_candidates.jsonl',
                    'content_asset_candidates.jsonl',
                    'candidate_generation_summary.json',
                ]
            ));
            foreach ([
                'private_url',
                'attempt_id',
                'raw_score',
                'percentile',
                'fixed_type',
                'user_confirmed_type',
                'type_code',
            ] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_stage_candidates_fails_closed_without_human_review_manifest(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-stage-missing-review');
        $stagingDir = $artifactRoot.'/staging-package';

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-run',
                'artifact_dir' => $artifactRoot,
            ]);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'stage-candidates',
                '--run-id' => 'stage-run',
                '--artifact-dir' => $artifactRoot,
                '--candidate-dir' => $artifactRoot.'/candidate-run',
                '--staging-output-dir' => $stagingDir,
                '--allow-staging-write' => true,
                '--json' => true,
            ])->assertExitCode(1);

            $summary = $this->readJson($artifactRoot.'/stage-run/staging_import_summary.json');
            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertFalse((bool) ($summary['staging_write_performed'] ?? true));
            $this->assertStringContainsString(
                'review_manifest.json missing',
                implode("\n", (array) data_get($summary, 'repair_log.entries', []))
            );
            $this->assertFileDoesNotExist($stagingDir.'/staging_import_manifest.json');
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_stage_candidates_imports_only_reviewed_candidates_to_staging_package(): void
    {
        $artifactRoot = $this->tempDir('big5-v2-agent-stage-reviewed');
        $candidateDir = $artifactRoot.'/candidate-run';
        $stagingDir = $artifactRoot.'/content_assets/big5/result_page_v2/staging_candidate_imports/reviewed-run';

        try {
            app(BigFiveResultPageV2AssetAgent::class)->generateCandidates([
                'run_id' => 'candidate-run',
                'artifact_dir' => $artifactRoot,
            ]);
            file_put_contents($candidateDir.'/review_manifest.json', json_encode([
                'schema_version' => 'fap.big5.result_page_v2.staging_review_manifest.v0.1',
                'human_reviewed' => true,
                'review_status' => 'approved_for_staging',
                'runtime_use' => 'staging_only',
                'production_use_allowed' => false,
                'ready_for_pilot' => false,
                'reviewed_by' => 'unit_test_editorial_gate',
                'reviewed_at' => '2026-06-21T00:00:00Z',
                'approved_candidate_files' => [
                    'selector_asset_candidates.jsonl',
                    'content_asset_candidates.jsonl',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $this->artisan('big5:result-page-v2-agent', [
                'action' => 'stage-candidates',
                '--run-id' => 'stage-run',
                '--artifact-dir' => $artifactRoot,
                '--candidate-dir' => $candidateDir,
                '--staging-output-dir' => $stagingDir,
                '--allow-staging-write' => true,
                '--json' => true,
            ])->assertExitCode(0);

            foreach ([
                'selector_asset_candidates.staging.jsonl',
                'content_asset_candidates.staging.jsonl',
                'staging_import_manifest.json',
                'staging_import_validation_report.json',
                'repair_log.json',
            ] as $filename) {
                $this->assertFileExists($stagingDir.'/'.$filename);
            }

            $summary = $this->readJson($artifactRoot.'/stage-run/staging_import_summary.json');
            $validation = $this->readJson($stagingDir.'/staging_import_validation_report.json');
            $manifest = $this->readJson($stagingDir.'/staging_import_manifest.json');

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertTrue((bool) ($summary['staging_write_performed'] ?? false));
            $this->assertTrue((bool) ($validation['staging_write_performed'] ?? false));
            $this->assertSame('pass', data_get($validation, 'candidate_validation.status'));
            $this->assertSame('pass', data_get($validation, 'leak_scan.status'));
            $this->assertSame('staging_only', $manifest['runtime_use'] ?? null);
            $this->assertFalse((bool) ($manifest['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($manifest['ready_for_pilot'] ?? true));

            $allStagingArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($stagingDir.'/'.$filename),
                [
                    'selector_asset_candidates.staging.jsonl',
                    'content_asset_candidates.staging.jsonl',
                    'staging_import_manifest.json',
                    'staging_import_validation_report.json',
                    'repair_log.json',
                ]
            ));
            foreach (['private_url', 'attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $allStagingArtifacts);
            }
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_strict_mode_rejects_public_payload_and_shareable_score_leaks(): void
    {
        $root = $this->tempDir('big5-v2-agent-leak');
        $contentRoot = $root.'/content_assets/big5/result_page_v2';
        mkdir($contentRoot.'/selector_ready_assets/v0_3_p0_full', 0777, true);

        file_put_contents($contentRoot.'/selector_ready_assets/v0_3_p0_full/assets.jsonl', json_encode([
            'version' => 'fap.big5.result_page_v2.selector_asset.v0.1',
            'asset_key' => 'leaky_asset',
            'registry_key' => 'share_safety_registry',
            'module_key' => 'module_08_share_save',
            'block_key' => 'module_08_share_save.share_safety.leaky',
            'block_kind' => 'share_save',
            'slot_key' => 'share_save.safety_transform',
            'trigger' => [
                'score_bands' => [],
                'interpretation_scopes' => ['share_safe_summary_only'],
                'reading_mode' => ['quick'],
                'scenario' => ['share'],
            ],
            'priority' => 10,
            'mutual_exclusion_group' => 'share_safety.leaky',
            'can_stack_with' => [],
            'reading_modes' => ['quick'],
            'scenario' => 'share',
            'scope' => 'share_safe_summary_only',
            'required_evidence_level' => 'descriptive',
            'evidence_level' => 'descriptive',
            'safety_level' => 'share_safe',
            'shareable' => true,
            'shareable_policy' => 'required_for_every_shareable_true_block',
            'fallback_policy' => 'share_safe_summary_only',
            'content_source' => 'fixture',
            'provenance' => 'unit-test',
            'replacement_policy' => 'unit-test',
            'forbidden_public_fields' => [],
            'review_status' => 'fixture_only',
            'public_payload' => [
                'summary' => 'This share block exposes raw_score percentile and fixed_type.',
            ],
            'internal_metadata' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        try {
            $summary = app(BigFiveResultPageV2AssetAgent::class)->audit([
                'run_id' => 'strict-leak',
                'artifact_dir' => $root.'/artifacts',
                'content_asset_root' => $contentRoot,
                'source_ledger_dir' => $root.'/missing-source-ledger',
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('forbidden_leak_hits', $summary['strict_failures']);

            $safety = $this->readJson($root.'/artifacts/strict-leak/safety_report.json');
            $this->assertSame('blocked', data_get($safety, 'leak_scan.status'));
            $this->assertGreaterThanOrEqual(3, (int) data_get($safety, 'leak_scan.hit_count'));
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

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded);
            $rows[] = $decoded;
        }

        return $rows;
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
