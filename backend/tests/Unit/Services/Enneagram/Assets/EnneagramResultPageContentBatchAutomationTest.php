<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageContentBatchAutomation;
use Tests\TestCase;

final class EnneagramResultPageContentBatchAutomationTest extends TestCase
{
    public function test_content_batch_automation_writes_one_payload_and_reports_without_production_permissions(): void
    {
        $root = $this->tempDir('enneagram-content-batch');

        try {
            $summary = app(EnneagramResultPageContentBatchAutomation::class)->evaluate([
                'run_id' => 'unit-run',
                'artifact_dir' => $root,
                'strict' => true,
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertSame(1, (int) data_get($summary, 'summary.payload_count'));
            $this->assertFalse((bool) data_get($summary, 'summary.bulk_generation_allowed', true));
            $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));

            foreach ([
                'payload.json',
                'source_mapping_report.json',
                'safety_report.json',
                'diff_report.json',
                'rollback_report.json',
                'batch_automation_report.json',
            ] as $file) {
                $this->assertFileExists($root.'/unit-run/'.$file);
            }

            $payload = $this->readJson($root.'/unit-run/payload.json');
            $this->assertSame('not_runtime', $payload['runtime_use'] ?? null);
            $this->assertFalse((bool) ($payload['production_use_allowed'] ?? true));
            $this->assertSame('batch_1r_a_asset_stream', data_get($payload, 'source_trace.primary_source_id'));

            $sourceMapping = $this->readJson($root.'/unit-run/source_mapping_report.json');
            $this->assertSame(0, (int) ($sourceMapping['source_mapping_failure_count'] ?? -1));

            $safety = $this->readJson($root.'/unit-run/safety_report.json');
            $this->assertSame(0, (int) ($safety['metadata_leakage_hit_count'] ?? -1));
            $this->assertSame(0, (int) ($safety['forbidden_claim_hit_count'] ?? -1));
            $this->assertSame(0, (int) ($safety['fc144_boundary_violation_count'] ?? -1));

            $report = (string) file_get_contents($root.'/unit-run/batch_automation_report.json');
            $this->assertStringNotContainsString('/Users/rainie/', $report);
            $this->assertStringNotContainsString('/private/tmp/', $report);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_content_batch_automation_fails_closed_on_forbidden_claim(): void
    {
        $root = $this->tempDir('enneagram-content-batch-forbidden');

        try {
            $summary = app(EnneagramResultPageContentBatchAutomation::class)->evaluate([
                'run_id' => 'forbidden-run',
                'artifact_dir' => $root,
                'public_payload' => [
                    'heading' => 'Unsafe claim',
                    'body' => 'FC144 is more accurate and replaces your result.',
                ],
                'module_key' => 'fc144_second_lens',
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('safety_scan_failed', $summary['errors'] ?? []);

            $safety = $this->readJson($root.'/forbidden-run/safety_report.json');
            $this->assertGreaterThan(0, (int) ($safety['forbidden_claim_hit_count'] ?? 0));
            $this->assertGreaterThan(0, (int) ($safety['fc144_boundary_violation_count'] ?? 0));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_content_batch_automation_rejects_malformed_contract(): void
    {
        $root = $this->tempDir('enneagram-content-batch-contract');
        $contractPath = $root.'/bad_contract.json';
        file_put_contents($contractPath, json_encode([
            'schema_version' => 'bad',
            'production_use_allowed' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $summary = app(EnneagramResultPageContentBatchAutomation::class)->evaluate([
                'run_id' => 'bad-contract-run',
                'artifact_dir' => $root.'/artifacts',
                'contract_path' => $contractPath,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('schema_version_mismatch', $summary['errors'] ?? []);
            $this->assertContains('production_use_allowed_must_be_false', $summary['errors'] ?? []);
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
