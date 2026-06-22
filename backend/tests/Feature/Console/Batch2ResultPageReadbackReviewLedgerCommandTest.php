<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class Batch2ResultPageReadbackReviewLedgerCommandTest extends TestCase
{
    public function test_command_reports_batch2_readback_review_ledger_authority_without_writes(): void
    {
        $root = $this->tempDir('batch2-result-page-readback-review-ledger');

        try {
            $this->artisan('result-page:batch2-readback-review-ledger', [
                '--run-id' => 'batch2-readback',
                '--artifact-dir' => $root,
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $report = $this->readJson($root.'/batch2-readback/batch2_result_page_readback_review_ledger_report.json');

            $this->assertSame('fap.result_page.batch2_readback_review_ledger.v0.1', $report['schema_version'] ?? null);
            $this->assertSame('not_runtime', $report['runtime_use'] ?? null);
            $this->assertFalse((bool) ($report['production_use_allowed'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($report['ready_for_production'] ?? true));
            $this->assertSame('backend_readback_review_authority_only', $report['authority_state'] ?? null);
            $this->assertSame('GO_FOR_FA30-WEB-02_FRONTEND_RUNTIME_QA_ONLY', $report['go_no_go'] ?? null);
            $this->assertSame('NO_GO', $report['production_go_no_go'] ?? null);
            $this->assertSame('FA30-WEB-02', $report['next_allowed_pr'] ?? null);

            $this->assertSame('pass', data_get($report, 'bigfive.status'));
            $this->assertTrue((bool) data_get($report, 'bigfive.review_manifest.present', false));
            $this->assertTrue((bool) data_get($report, 'bigfive.review_manifest.human_reviewed', false));
            $this->assertSame('approved_for_staging', data_get($report, 'bigfive.review_manifest.review_status'));
            $this->assertSame('staging_only', data_get($report, 'bigfive.review_manifest.runtime_use'));
            $this->assertFalse((bool) data_get($report, 'bigfive.review_manifest.production_use_allowed', true));
            $this->assertSame(0, data_get($report, 'bigfive.summary.validation_error_count'));
            $this->assertSame(0, data_get($report, 'bigfive.summary.review_error_count'));
            $this->assertSame(0, data_get($report, 'bigfive.summary.leak_hit_count'));
            $this->assertFalse((bool) data_get($report, 'bigfive.summary.staging_write_performed', true));

            $this->assertSame('pass', data_get($report, 'enneagram.status'));
            $this->assertTrue((bool) data_get($report, 'enneagram.source_ledger.valid', false));
            $this->assertFalse((bool) data_get($report, 'enneagram.source_ledger.ready_for_generation', true));
            $this->assertFalse((bool) data_get($report, 'enneagram.source_ledger.ready_for_import', true));
            $this->assertFalse((bool) data_get($report, 'enneagram.source_ledger.ready_for_activation', true));
            $this->assertSame(1, data_get($report, 'enneagram.batch_summary.payload_count'));
            $this->assertFalse((bool) data_get($report, 'enneagram.batch_summary.bulk_generation_allowed', true));
            $this->assertTrue((bool) data_get($report, 'enneagram.batch_summary.source_mapping_zero_failures', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.batch_summary.metadata_leakage_zero', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.batch_summary.forbidden_claim_zero', false));
            $this->assertTrue((bool) data_get($report, 'enneagram.batch_summary.fc144_boundary_zero', false));
            $this->assertFalse((bool) data_get($report, 'enneagram.batch_summary.production_execution_allowed_for_agent', true));

            foreach ([
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

            $artifactBlob = (string) file_get_contents($root.'/batch2-readback/batch2_result_page_readback_review_ledger_report.json');
            foreach (['attempt_id', 'raw_score', 'percentile', 'fixed_type', 'user_confirmed_type', 'type_code'] as $forbiddenToken) {
                $this->assertStringNotContainsString($forbiddenToken, $artifactBlob);
            }
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_command_rejects_invalid_enneagram_payload_json(): void
    {
        $this->artisan('result-page:batch2-readback-review-ledger', [
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
