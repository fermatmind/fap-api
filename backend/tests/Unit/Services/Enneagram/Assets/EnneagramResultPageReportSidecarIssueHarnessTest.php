<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageReportSidecarIssueHarness;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageReportSidecarIssueHarnessTest extends TestCase
{
    public function test_external_blocker_creates_sidecar_payload_and_allows_train_when_required_gates_are_green(): void
    {
        $artifactRoot = storage_path('framework/testing/report_sidecar_artifacts/external');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageReportSidecarIssueHarness::class)->run([
            'run_id' => 'external-blocker',
            'artifact_dir' => $artifactRoot,
            'blocker_source' => 'external',
            'blocker_reason' => 'staging API unavailable outside current PR scope',
            'github_checks_green' => true,
            'scope_validation_green' => true,
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.train_can_continue', false));
        $this->assertTrue((bool) data_get($summary, 'summary.sidecar_issue_payload_created', false));
        $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));
        $this->assertFileExists($artifactRoot.'/external-blocker/sidecar_issue_payload.json');
        $this->assertFileExists($artifactRoot.'/external-blocker/ops_report.md');
    }

    public function test_current_pr_blocker_fails_closed(): void
    {
        $artifactRoot = storage_path('framework/testing/report_sidecar_artifacts/current');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageReportSidecarIssueHarness::class)->run([
            'run_id' => 'current-blocker',
            'artifact_dir' => $artifactRoot,
            'blocker_source' => 'current_pr',
            'blocker_reason' => 'scope validation failed',
            'github_checks_green' => true,
            'scope_validation_green' => true,
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertFalse((bool) data_get($summary, 'summary.train_can_continue', true));
        $this->assertContains('current_pr_or_required_gate_blocker', $summary['errors'] ?? []);
    }

    public function test_readiness_summary_requires_manual_production_gate_even_when_non_manual_gates_pass(): void
    {
        $evidenceDir = storage_path('framework/testing/report_sidecar_evidence/pass');
        $artifactRoot = storage_path('framework/testing/report_sidecar_artifacts/readiness');
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        File::deleteDirectory($artifactRoot);

        foreach (['candidate_export_staging_import', 'web_rendered_qa', 'api_smoke', 'rollback_simulation'] as $gate) {
            File::put($evidenceDir.'/'.$gate.'.json', json_encode(['gate' => $gate, 'ok' => true], JSON_PRETTY_PRINT));
        }

        $summary = app(EnneagramResultPageReportSidecarIssueHarness::class)->run([
            'run_id' => 'readiness-pass',
            'artifact_dir' => $artifactRoot,
            'evidence_dir' => $evidenceDir,
            'blocker_source' => 'none',
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.release_ready_for_manual_production_gate', false));

        $readiness = $this->readJson($artifactRoot.'/readiness-pass/release_readiness_summary.json');
        $this->assertSame('manual_required', data_get($readiness, 'gates.production_manual_gate.status'));
        $this->assertFalse((bool) data_get($readiness, 'production_execution_allowed_for_agent', true));
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
}
