<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class EnneagramResultPageOpsControlPlaneCommandTest extends TestCase
{
    public function test_control_plane_command_writes_read_only_report(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-command');

        try {
            $this->artisan('enneagram:result-page-ops-control-plane', [
                'action' => 'audit',
                '--run-id' => 'command-run',
                '--artifact-dir' => $root,
                '--mode' => 'auto-to-pr',
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $report = $this->readJson($root.'/command-run/ops_agent_control_plane_report.json');
            $this->assertSame('auto-to-pr', data_get($report, 'mode_decision.mode'));
            $this->assertTrue((bool) data_get($report, 'mode_decision.may_create_pull_request', false));
            $this->assertFalse((bool) data_get($report, 'mode_decision.may_write_production', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_control_plane_command_fails_closed_for_automatic_production_rollout(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-command-block');

        try {
            $this->artisan('enneagram:result-page-ops-control-plane', [
                'action' => 'audit',
                '--run-id' => 'command-block',
                '--artifact-dir' => $root,
                '--mode' => 'auto-to-staging',
                '--simulate-production-rollout' => true,
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(1);

            $report = $this->readJson($root.'/command-block/ops_agent_control_plane_report.json');
            $this->assertContains('automatic_production_rollout_blocked', $report['errors'] ?? []);
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
