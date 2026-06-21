<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\AssetAgent\RiasecResultPageAssetAgent;
use Tests\TestCase;

final class RiasecResultPageAssetAgentTest extends TestCase
{
    public function test_audit_command_writes_staging_only_reports_without_runtime_changes(): void
    {
        $artifactRoot = $this->tempDir('riasec-result-agent-command');

        try {
            $summary = app(RiasecResultPageAssetAgent::class)->audit([
                'run_id' => 'unit-run',
                'artifact_dir' => $artifactRoot,
                'content_asset_root' => $this->backendPath('content_assets/riasec'),
                'source_ledger_dir' => $this->backendPath('content_assets/riasec/result_page_v2/source_ledger'),
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));

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
            $this->assertFalse((bool) ($inventory['ready_for_runtime'] ?? true));
            $this->assertFalse((bool) ($inventory['ready_for_production'] ?? true));
            $this->assertSame('riasec.deep_copy_slot_schema.v1', data_get($inventory, 'inputs.content_slot_schema'));
            $this->assertTrue((bool) data_get($inventory, 'source_ledger.valid'));
            $this->assertGreaterThanOrEqual(6, (int) data_get($inventory, 'source_ledger.source_id_count', 0));
            $this->assertTrue((bool) data_get($inventory, 'asset_inventory.valid'));
            $this->assertGreaterThanOrEqual(20, (int) data_get($inventory, 'asset_inventory.file_count', 0));

            $validation = $this->readJson($runDir.'/validation_report.json');
            $this->assertTrue((bool) ($validation['content_slot_contract_reused'] ?? false));
            $this->assertSame(0, (int) ($validation['error_count'] ?? -1));

            $safety = $this->readJson($runDir.'/safety_report.json');
            $this->assertContains(data_get($safety, 'leak_scan.status'), ['pass', 'blocked']);
            $this->assertGreaterThanOrEqual(0, (int) data_get($safety, 'leak_scan.hit_count', -1));

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('ready_for_runtime: false', $goNoGo);
            $this->assertStringContainsString('production_use_allowed: false', $goNoGo);
            $this->assertStringContainsString('NO-GO for asset generation', $goNoGo);

            $allArtifacts = implode("\n", array_map(
                static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
                [
                    'input_inventory.json',
                    'validation_report.json',
                    'safety_report.json',
                    'go_no_go.md',
                ]
            ));
            $this->assertStringNotContainsString(sys_get_temp_dir(), $allArtifacts);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_strict_audit_fails_closed_when_source_ledger_is_missing(): void
    {
        $artifactRoot = $this->tempDir('riasec-result-agent-missing-ledger');
        $sourceLedgerRoot = $this->tempDir('riasec-result-agent-empty-ledger');

        try {
            $summary = app(RiasecResultPageAssetAgent::class)->audit([
                'run_id' => 'missing-ledger',
                'artifact_dir' => $artifactRoot,
                'source_ledger_dir' => $sourceLedgerRoot,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));

            $summary = $this->readJson($artifactRoot.'/missing-ledger/input_inventory.json');
            $this->assertFalse((bool) data_get($summary, 'source_ledger.valid', true));

            $goNoGo = (string) file_get_contents($artifactRoot.'/missing-ledger/go_no_go.md');
            $this->assertStringContainsString('source_ledger_invalid', $goNoGo);
        } finally {
            $this->deleteDirectory($artifactRoot);
            $this->deleteDirectory($sourceLedgerRoot);
        }
    }

    public function test_strict_audit_blocks_forbidden_public_field_leaks(): void
    {
        $artifactRoot = $this->tempDir('riasec-result-agent-leak-artifacts');
        $assetRoot = $this->tempDir('riasec-result-agent-leak-assets');
        file_put_contents($assetRoot.'/leaky.json', json_encode([
            'public_payload' => [
                'title' => 'Leak fixture',
                'attempt_id' => 'private-attempt-id',
            ],
        ], JSON_PRETTY_PRINT));

        try {
            $summary = app(RiasecResultPageAssetAgent::class)->audit([
                'run_id' => 'leak-run',
                'artifact_dir' => $artifactRoot,
                'content_asset_root' => $assetRoot,
                'source_ledger_dir' => $this->backendPath('content_assets/riasec/result_page_v2/source_ledger'),
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('forbidden_public_payload_leaks', (array) ($summary['strict_failures'] ?? []));

            $safety = $this->readJson($artifactRoot.'/leak-run/safety_report.json');
            $this->assertSame('blocked', data_get($safety, 'leak_scan.status'));
            $this->assertSame('attempt_id', data_get($safety, 'leak_scan.hits.0.value'));
        } finally {
            $this->deleteDirectory($artifactRoot);
            $this->deleteDirectory($assetRoot);
        }
    }

    private function tempDir(string $prefix): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        return $path;
    }

    private function backendPath(string $relativePath): string
    {
        return dirname(__DIR__, 4).'/'.$relativePath;
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
