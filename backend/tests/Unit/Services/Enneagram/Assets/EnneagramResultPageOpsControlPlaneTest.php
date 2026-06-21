<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageOpsControlPlane;
use Tests\TestCase;

final class EnneagramResultPageOpsControlPlaneTest extends TestCase
{
    public function test_control_plane_audit_writes_report_for_auto_to_report_without_production_permissions(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane');

        try {
            $summary = app(EnneagramResultPageOpsControlPlane::class)->audit([
                'run_id' => 'unit-run',
                'artifact_dir' => $root,
                'mode' => 'auto-to-report',
                'strict' => true,
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertSame('success', $summary['status'] ?? null);
            $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));
            $this->assertTrue((bool) data_get($summary, 'summary.manual_approval_required_for_production', false));

            $reportPath = $root.'/unit-run/ops_agent_control_plane_report.json';
            $this->assertFileExists($reportPath);
            $report = $this->readJson($reportPath);

            $this->assertSame(EnneagramResultPageOpsControlPlane::SCHEMA_VERSION, $report['schema_version'] ?? null);
            $this->assertSame(['auto-to-pr', 'auto-to-staging', 'auto-to-report', 'production-manual-gate'], $report['allowed_modes'] ?? []);
            $this->assertSame('allowed', data_get($report, 'mode_decision.status'));
            $this->assertFalse((bool) data_get($report, 'mode_decision.may_write_production', true));
            $this->assertFalse((bool) data_get($report, 'mode_decision.may_activate_production', true));
            $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_activation_happened', true));
            $this->assertArtifactsDoNotLeakPrivatePaths((string) file_get_contents($reportPath));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_auto_modes_reject_simulated_production_rollout(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-prod-block');

        try {
            $summary = app(EnneagramResultPageOpsControlPlane::class)->audit([
                'run_id' => 'blocked-run',
                'artifact_dir' => $root,
                'mode' => 'auto-to-staging',
                'simulate_production_rollout' => true,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('automatic_production_rollout_blocked', $summary['errors'] ?? []);
            $this->assertTrue((bool) data_get($summary, 'summary.production_rollout_blocked', false));

            $report = $this->readJson($root.'/blocked-run/ops_agent_control_plane_report.json');
            $this->assertFalse((bool) data_get($report, 'mode_decision.may_write_production', true));
            $this->assertFalse((bool) data_get($report, 'mode_decision.may_activate_production', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_production_manual_gate_validates_exact_approval_but_never_allows_agent_execution(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-manual');

        try {
            $summary = app(EnneagramResultPageOpsControlPlane::class)->audit([
                'run_id' => 'manual-run',
                'artifact_dir' => $root,
                'mode' => 'production-manual-gate',
                'strict' => true,
                'approval' => [
                    'release_id' => EnneagramResultPageOpsControlPlane::EXACT_RELEASE_ID,
                    'confirm_release_id' => EnneagramResultPageOpsControlPlane::EXACT_RELEASE_ID,
                    'candidate_manifest_sha256' => EnneagramResultPageOpsControlPlane::EXACT_CANDIDATE_MANIFEST_SHA256,
                    'runtime_registry_sha256' => EnneagramResultPageOpsControlPlane::EXACT_RUNTIME_REGISTRY_SHA256,
                    'rollback_window' => '2026-06-22T00:00:00Z/2026-06-22T02:00:00Z',
                    'post_activation_smoke_plan' => 'Run API result smoke and rollback simulation.',
                ],
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));
            $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));

            $report = $this->readJson($root.'/manual-run/ops_agent_control_plane_report.json');
            $this->assertSame('manual_approval_required', data_get($report, 'mode_decision.status'));
            $this->assertTrue((bool) data_get($report, 'mode_decision.approval_contract_valid', false));
            $this->assertFalse((bool) data_get($report, 'production_manual_gate.execution_allowed_for_agent', true));
            $this->assertSame(EnneagramResultPageOpsControlPlane::EXACT_RELEASE_ID, data_get($report, 'production_manual_gate.exact_release_id'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_production_manual_gate_rejects_missing_or_mismatched_approval_fields(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-manual-bad');

        try {
            $summary = app(EnneagramResultPageOpsControlPlane::class)->audit([
                'run_id' => 'manual-bad-run',
                'artifact_dir' => $root,
                'mode' => 'production-manual-gate',
                'strict' => true,
                'approval' => [
                    'release_id' => 'wrong',
                ],
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('release_id_mismatch', $summary['errors'] ?? []);
            $this->assertContains('confirm_release_id_mismatch', $summary['errors'] ?? []);
            $this->assertContains('missing_approval_field:rollback_window', $summary['errors'] ?? []);
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_strict_mode_rejects_malformed_control_plane_contract(): void
    {
        $root = $this->tempDir('enneagram-ops-control-plane-contract-bad');
        $contractPath = $root.'/bad_contract.json';
        file_put_contents($contractPath, json_encode([
            'schema_version' => 'bad',
            'runtime_use' => 'runtime',
            'production_use_allowed' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $summary = app(EnneagramResultPageOpsControlPlane::class)->audit([
                'run_id' => 'bad-contract-run',
                'artifact_dir' => $root.'/artifacts',
                'contract_path' => $contractPath,
                'mode' => 'auto-to-report',
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

    private function assertArtifactsDoNotLeakPrivatePaths(string $artifact): void
    {
        foreach ([
            '/Users/rainie/',
            '/private/tmp/',
            'content_pack_activations write happened',
            'production_activation_happened": true',
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
