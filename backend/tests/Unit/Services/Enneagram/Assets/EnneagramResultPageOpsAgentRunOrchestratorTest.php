<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageOpsAgentRunOrchestrator;
use Tests\TestCase;

final class EnneagramResultPageOpsAgentRunOrchestratorTest extends TestCase
{
    public function test_orchestrator_writes_deterministic_run_plan_without_production_permissions(): void
    {
        $root = $this->tempDir('enneagram-ops-runner');

        try {
            $options = [
                'artifact_dir' => $root,
                'mode' => 'auto-to-pr',
                'scope_id' => 'ops-agent-runner',
                'changed_files' => [
                    'backend/app/Services/Enneagram/Assets/Agent/EnneagramResultPageOpsAgentRunOrchestrator.php',
                ],
                'strict' => true,
            ];
            $first = app(EnneagramResultPageOpsAgentRunOrchestrator::class)->plan($options);
            $second = app(EnneagramResultPageOpsAgentRunOrchestrator::class)->plan($options);

            $this->assertTrue((bool) ($first['ok'] ?? false));
            $this->assertSame($first['run_id'] ?? null, $second['run_id'] ?? null);
            $this->assertFalse((bool) data_get($first, 'summary.production_execution_allowed_for_agent', true));
            $this->assertTrue((bool) data_get($first, 'summary.production_manual_gate_required', false));

            $report = $this->readJson($root.'/'.$first['run_id'].'/ops_agent_run_orchestrator_plan.json');
            $this->assertSame(EnneagramResultPageOpsAgentRunOrchestrator::SCHEMA_VERSION, $report['schema_version'] ?? null);
            $this->assertSame('file_backed_manifest', data_get($report, 'queue_item.queue_backend'));
            $this->assertSame('planned', data_get($report, 'queue_item.current_state'));
            $this->assertStringStartsWith('codex/enneagram-ops-agent-runner', (string) data_get($report, 'branch_plan.branch_name'));
            $this->assertTrue((bool) data_get($report, 'pull_request_contract.auto_create_pr_allowed', false));
            $this->assertTrue((bool) data_get($report, 'github_checks_contract.required_checks_must_be_green_before_merge', false));
            $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_activation_happened', true));
            $this->assertArtifactsDoNotLeakPrivatePaths((string) file_get_contents($root.'/'.$first['run_id'].'/ops_agent_run_orchestrator_plan.json'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_external_blocker_is_recorded_as_non_blocking_sidecar_payload(): void
    {
        $root = $this->tempDir('enneagram-ops-runner-external');

        try {
            $summary = app(EnneagramResultPageOpsAgentRunOrchestrator::class)->plan([
                'run_id' => 'external-run',
                'artifact_dir' => $root,
                'mode' => 'auto-to-report',
                'scope_id' => 'ops-agent-runner',
                'simulate_external_blocker' => true,
                'strict' => true,
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertTrue((bool) data_get($summary, 'summary.train_can_continue', false));

            $sidecar = $this->readJson($root.'/external-run/sidecar_issue_payload.json');
            $this->assertTrue((bool) ($sidecar['dry_run'] ?? false));
            $this->assertTrue((bool) data_get($sidecar, 'body.external_blockers_recorded', false));
            $this->assertFalse((bool) data_get($sidecar, 'body.production_activation_executed', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_current_pr_scope_failure_blocks_train(): void
    {
        $root = $this->tempDir('enneagram-ops-runner-scope-failure');

        try {
            $summary = app(EnneagramResultPageOpsAgentRunOrchestrator::class)->plan([
                'run_id' => 'scope-failure-run',
                'artifact_dir' => $root,
                'mode' => 'auto-to-pr',
                'scope_id' => 'ops-agent-runner',
                'simulate_current_scope_failure' => true,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('scope_validation_failed', $summary['errors'] ?? []);
            $this->assertContains('current_pr_scope_failure', $summary['errors'] ?? []);
            $this->assertFalse((bool) data_get($summary, 'summary.train_can_continue', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_scope_validation_rejects_out_of_scope_changed_files(): void
    {
        $root = $this->tempDir('enneagram-ops-runner-out-of-scope');

        try {
            $summary = app(EnneagramResultPageOpsAgentRunOrchestrator::class)->plan([
                'run_id' => 'out-of-scope-run',
                'artifact_dir' => $root,
                'mode' => 'auto-to-pr',
                'scope_id' => 'ops-agent-runner',
                'changed_files' => [
                    'backend/routes/api.php',
                ],
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('scope_validation_failed', $summary['errors'] ?? []);

            $report = $this->readJson($root.'/out-of-scope-run/ops_agent_run_orchestrator_plan.json');
            $this->assertContains('backend/routes/api.php', data_get($report, 'scope_validation.out_of_scope_files'));
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

    private function assertArtifactsDoNotLeakPrivatePaths(string $artifact): void
    {
        foreach ([
            '/Users/rainie/',
            '/private/tmp/',
            'production_activation_happened": true',
            'production_write_happened": true',
        ] as $blocked) {
            $this->assertStringNotContainsString($blocked, $artifact);
        }
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
