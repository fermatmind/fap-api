<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class Batch2ResultPageDryRunGateCommandTest extends TestCase
{
    public function test_command_reports_bigfive_and_enneagram_batch2_dry_run_gate_without_writes(): void
    {
        $root = $this->tempDir('batch2-result-page-dry-run-gate');

        try {
            $this->artisan('result-page:batch2-dry-run-gate', [
                '--run-id' => 'batch2-gate',
                '--artifact-dir' => $root,
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $report = $this->readJson($root.'/batch2-gate/batch2_result_page_dry_run_gate_report.json');

            $this->assertSame('fap.result_page.batch2_dry_run_gate.v0.1', $report['schema_version'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_production'] ?? true));
            $this->assertSame('GO_FOR_BATCH2_READBACK_REVIEW_LEDGER_ONLY', $report['go_no_go'] ?? null);
            $this->assertSame('NO_GO', $report['production_go_no_go'] ?? null);
            $this->assertSame('FA30-API-03', $report['next_allowed_pr'] ?? null);

            $this->assertSame('pass', data_get($report, 'bigfive.status'));
            $this->assertSame(0, data_get($report, 'bigfive.summary.validation_error_count'));
            $this->assertSame(0, data_get($report, 'bigfive.summary.review_error_count'));
            $this->assertSame(0, data_get($report, 'bigfive.summary.leak_hit_count'));
            $this->assertFalse((bool) data_get($report, 'bigfive.summary.staging_write_performed', true));

            $this->assertSame('pass', data_get($report, 'enneagram.status'));
            $this->assertSame(1, data_get($report, 'enneagram.summary.payload_count'));
            $this->assertFalse((bool) data_get($report, 'enneagram.summary.bulk_generation_allowed', true));
            $this->assertTrue((bool) data_get($report, 'enneagram.summary.source_mapping_zero_failures', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.summary.metadata_leakage_zero', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.summary.forbidden_claim_zero', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.summary.fc144_boundary_zero', false));
            $this->assertFalse((bool) data_get($report, 'enneagram.summary.production_execution_allowed_for_agent', true));

            foreach ([
                'bigfive_candidate_generation_happened',
                'bigfive_staging_write_happened',
                'enneagram_bulk_generation_happened',
                'candidate_import_happened',
                'production_activation_happened',
                'runtime_switch_happened',
                'production_write_happened',
                'frontend_change_happened',
            ] as $guarantee) {
                $this->assertFalse((bool) data_get($report, 'negative_guarantees.'.$guarantee, true), $guarantee);
            }

            $artifactBlob = (string) file_get_contents($root.'/batch2-gate/batch2_result_page_dry_run_gate_report.json');
            foreach (['attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $artifactBlob);
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_command_rejects_invalid_enneagram_payload_json(): void
    {
        $this->artisan('result-page:batch2-dry-run-gate', [
            '--enneagram-public-payload-json' => '{bad',
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(1);
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
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
