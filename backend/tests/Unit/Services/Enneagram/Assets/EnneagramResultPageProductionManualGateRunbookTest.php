<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageProductionManualGateRunbook;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageProductionManualGateRunbookTest extends TestCase
{
    public function test_manual_gate_packet_passes_only_with_exact_release_and_hashes(): void
    {
        $artifactRoot = storage_path('framework/testing/production_manual_gate_artifacts/pass');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageProductionManualGateRunbook::class)->run([
            'run_id' => 'manual-pass',
            'artifact_dir' => $artifactRoot,
            'release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'confirm_release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'candidate_manifest_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => '2026-06-22T01:00:00Z/2026-06-22T02:00:00Z',
            'post_activation_smoke_acknowledged' => true,
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.manual_approval_packet_valid', false));
        $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));
        $this->assertTrue((bool) data_get($summary, 'summary.production_manual_gate_required', false));
        $this->assertFileExists($artifactRoot.'/manual-pass/manual_approval_packet.json');

        $packet = $this->readJson($artifactRoot.'/manual-pass/manual_approval_packet.json');
        $this->assertFalse((bool) data_get($packet, 'production_execution_allowed_for_agent', true));
        $this->assertTrue((bool) data_get($packet, 'manual_human_approval_required', false));
    }

    public function test_manual_gate_fails_closed_on_release_mismatch(): void
    {
        $artifactRoot = storage_path('framework/testing/production_manual_gate_artifacts/release_mismatch');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageProductionManualGateRunbook::class)->run([
            'run_id' => 'manual-fail',
            'artifact_dir' => $artifactRoot,
            'release_id' => 'wrong-release',
            'confirm_release_id' => 'wrong-release',
            'candidate_manifest_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => '2026-06-22T01:00:00Z/2026-06-22T02:00:00Z',
            'post_activation_smoke_acknowledged' => true,
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('release_id_mismatch', $summary['errors'] ?? []);
        $this->assertContains('confirm_release_id_mismatch', $summary['errors'] ?? []);
    }

    public function test_manual_gate_fails_closed_without_smoke_acknowledgement(): void
    {
        $artifactRoot = storage_path('framework/testing/production_manual_gate_artifacts/no_smoke_ack');
        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageProductionManualGateRunbook::class)->run([
            'run_id' => 'manual-no-smoke',
            'artifact_dir' => $artifactRoot,
            'release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'confirm_release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'candidate_manifest_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => '2026-06-22T01:00:00Z/2026-06-22T02:00:00Z',
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('post_activation_smoke_acknowledgement_missing', $summary['errors'] ?? []);
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
