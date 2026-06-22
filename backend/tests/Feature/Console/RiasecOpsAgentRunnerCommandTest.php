<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class RiasecOpsAgentRunnerCommandTest extends TestCase
{
    public function test_ops_runner_command_writes_pr_train_plan_artifacts(): void
    {
        $root = $this->tempDir('riasec-ops-runner-command');

        try {
            $this->artisan('riasec:result-page-ops-runner', [
                'action' => 'plan',
                '--run-id' => 'command-run',
                '--artifact-dir' => $root,
                '--mode' => 'auto-to-pr',
                '--scope-id' => 'ops-agent-pr-train-orchestrator',
                '--changed-file' => [
                    'backend/app/Services/Riasec/Ops/RiasecResultPageOpsAgentRunOrchestrator.php',
                ],
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(0);

            $report = $this->readJson($root.'/command-run/ops_agent_pr_train_orchestrator_plan.json');
            $this->assertSame('auto-to-pr', $report['mode'] ?? null);
            $this->assertTrue((bool) data_get($report, 'scope_validation.valid', false));
            $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_write_happened', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_ops_runner_command_blocks_current_scope_failure(): void
    {
        $root = $this->tempDir('riasec-ops-runner-command-block');

        try {
            $this->artisan('riasec:result-page-ops-runner', [
                'action' => 'plan',
                '--run-id' => 'command-block',
                '--artifact-dir' => $root,
                '--mode' => 'auto-to-pr',
                '--scope-id' => 'ops-agent-pr-train-orchestrator',
                '--simulate-current-scope-failure' => true,
                '--strict' => true,
                '--json' => true,
            ])->assertExitCode(1);

            $report = $this->readJson($root.'/command-block/ops_agent_pr_train_orchestrator_plan.json');
            $this->assertContains('current_pr_scope_failure', $report['errors'] ?? []);
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
