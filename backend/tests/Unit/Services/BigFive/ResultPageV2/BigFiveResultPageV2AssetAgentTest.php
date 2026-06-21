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
                'go_no_go.md',
            ] as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $inventory = $this->readJson($runDir.'/input_inventory.json');
            $this->assertSame('staging_only', $inventory['runtime_use'] ?? null);
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));
            $this->assertSame('fap.big5.result_page_v2.selector_asset.v0.1', data_get($inventory, 'inputs.selector_asset_schema'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.valid'));

            $validation = $this->readJson($runDir.'/validation_report.json');
            $this->assertSame(325, (int) ($validation['asset_count'] ?? 0));
            $this->assertSame(3, (int) ($validation['error_count'] ?? -1));
            $this->assertStringContainsString(
                'shareable=true selector assets must originate from share_safety_registry',
                implode("\n", (array) ($validation['errors'] ?? []))
            );

            $safety = $this->readJson($runDir.'/safety_report.json');
            $this->assertSame('pass', data_get($safety, 'leak_scan.status'));

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('ready_for_runtime: false', $goNoGo);
            $this->assertStringContainsString('production_use_allowed: false', $goNoGo);

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                ['input_inventory.json', 'validation_report.json', 'safety_report.json', 'go_no_go.md']
            ));
            $this->assertStringContainsString('private_url', $allArtifacts);
            $this->assertStringContainsString('attempt_id', $allArtifacts);
            $this->assertStringNotContainsString(sys_get_temp_dir(), $allArtifacts);
            $this->assertStringNotContainsString('body_zh', $allArtifacts);
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
