<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageRenderedQaSmokeHarness;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageRenderedQaSmokeHarnessTest extends TestCase
{
    public function test_harness_writes_evidence_bundle_plan_without_production_execution(): void
    {
        $candidateDir = storage_path('framework/testing/rendered_qa_smoke_candidate');
        $artifactRoot = storage_path('framework/testing/rendered_qa_smoke_artifacts/plan');
        File::ensureDirectoryExists($candidateDir);
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageRenderedQaSmokeHarness::class)->run([
            'run_id' => 'unit-plan',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $candidateDir,
            'web_repo_dir' => base_path('../fap-web'),
            'release_id' => 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4',
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.web_rendered_qa_command_ready', false));
        $this->assertTrue((bool) data_get($summary, 'summary.api_smoke_command_ready', false));
        $this->assertTrue((bool) data_get($summary, 'summary.rollback_simulation_plan_ready', false));
        $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));
        $this->assertTrue((bool) data_get($summary, 'summary.production_manual_gate_required', false));

        $report = $this->readJson($artifactRoot.'/unit-plan/rendered_qa_smoke_harness_report.json');
        $this->assertFalse((bool) data_get($report, 'rollback_simulation.production_rollback_allowed_for_agent', true));
        $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_activation_happened', true));
        $this->assertFalse((bool) data_get($report, 'negative_guarantees.frontend_change_happened', true));
        $this->assertStringNotContainsString('/Users/rainie/', json_encode($report, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringNotContainsString('/private/tmp/', json_encode($report, JSON_UNESCAPED_SLASHES) ?: '');
    }

    public function test_strict_harness_fails_without_candidate_dir(): void
    {
        $artifactRoot = storage_path('framework/testing/rendered_qa_smoke_artifacts/missing');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageRenderedQaSmokeHarness::class)->run([
            'run_id' => 'missing-candidate',
            'artifact_dir' => $artifactRoot,
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('candidate_dir_missing', $summary['errors'] ?? []);
    }

    public function test_harness_validates_supplied_evidence_reports(): void
    {
        $candidateDir = storage_path('framework/testing/rendered_qa_smoke_candidate_evidence');
        $evidenceDir = storage_path('framework/testing/rendered_qa_smoke_evidence/pass');
        $artifactRoot = storage_path('framework/testing/rendered_qa_smoke_artifacts/evidence');
        File::ensureDirectoryExists($candidateDir);
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        File::deleteDirectory($artifactRoot);

        foreach (['web_rendered_qa', 'api_smoke', 'rollback_simulation'] as $key) {
            File::put($evidenceDir.'/'.$key.'_report.json', json_encode(['ok' => true, 'status' => 'success'], JSON_PRETTY_PRINT));
        }

        $summary = app(EnneagramResultPageRenderedQaSmokeHarness::class)->run([
            'run_id' => 'evidence-pass',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $candidateDir,
            'evidence_dir' => $evidenceDir,
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.evidence_valid', false));
    }

    public function test_harness_fails_closed_on_failed_evidence_report(): void
    {
        $candidateDir = storage_path('framework/testing/rendered_qa_smoke_candidate_failed_evidence');
        $evidenceDir = storage_path('framework/testing/rendered_qa_smoke_evidence/fail');
        $artifactRoot = storage_path('framework/testing/rendered_qa_smoke_artifacts/failed');
        File::ensureDirectoryExists($candidateDir);
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        File::deleteDirectory($artifactRoot);

        File::put($evidenceDir.'/web_rendered_qa_report.json', json_encode(['ok' => false, 'status' => 'failed'], JSON_PRETTY_PRINT));
        File::put($evidenceDir.'/api_smoke_report.json', json_encode(['ok' => true, 'status' => 'success'], JSON_PRETTY_PRINT));
        File::put($evidenceDir.'/rollback_simulation_report.json', json_encode(['ok' => true, 'status' => 'success'], JSON_PRETTY_PRINT));

        $summary = app(EnneagramResultPageRenderedQaSmokeHarness::class)->run([
            'run_id' => 'evidence-fail',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $candidateDir,
            'evidence_dir' => $evidenceDir,
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('evidence_report_failed:web_rendered_qa', $summary['errors'] ?? []);
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
