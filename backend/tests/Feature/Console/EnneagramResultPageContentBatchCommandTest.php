<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class EnneagramResultPageContentBatchCommandTest extends TestCase
{
    public function test_content_batch_command_writes_run_scoped_artifacts(): void
    {
        $root = $this->tempDir('enneagram-content-batch-command');

        try {
            $this->artisan('enneagram:result-page-content-batch', [
                'action' => 'evaluate',
                '--run-id' => 'command-run',
                '--artifact-dir' => $root,
                '--source-id' => 'batch_1r_a_asset_stream',
                '--module-key' => 'pilot_baseline_reflection',
                '--result-type' => 'type_1',
                '--scope' => 'pilot',
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $report = $this->readJson($root.'/command-run/batch_automation_report.json');
            $this->assertSame(1, (int) data_get($report, 'input.payload_count'));
            $this->assertFalse((bool) data_get($report, 'input.bulk_generation_allowed', true));
            $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_activation_happened', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_content_batch_command_rejects_bad_payload_json(): void
    {
        $this->artisan('enneagram:result-page-content-batch', [
            'action' => 'evaluate',
            '--public-payload-json' => '{bad',
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
