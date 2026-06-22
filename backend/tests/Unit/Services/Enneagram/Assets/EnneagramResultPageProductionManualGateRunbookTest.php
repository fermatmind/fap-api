<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPagePendingProductionGateStore;
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

    public function test_manual_gate_writes_single_pending_gate_from_green_evidence_bundle(): void
    {
        $artifactRoot = storage_path('framework/testing/production_manual_gate_artifacts/pending_gate');
        $evidenceDir = storage_path('framework/testing/production_manual_gate_evidence/pass');
        File::deleteDirectory($artifactRoot);
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        app(EnneagramResultPagePendingProductionGateStore::class)->delete();

        foreach (['candidate_export_staging_import', 'web_rendered_qa', 'api_smoke', 'rollback_simulation'] as $gate) {
            File::put($evidenceDir.'/'.$gate.'.json', json_encode(['gate' => $gate, 'ok' => true], JSON_PRETTY_PRINT));
        }

        $summary = app(EnneagramResultPageProductionManualGateRunbook::class)->run([
            'run_id' => 'manual-pending-gate',
            'artifact_dir' => $artifactRoot,
            'evidence_dir' => $evidenceDir,
            'write_pending_gate' => true,
            'pending_gate_ttl_minutes' => 120,
            'release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'confirm_release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'candidate_manifest_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => '60 minutes after activation',
            'post_activation_smoke_acknowledged' => true,
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.pending_gate_written', false));
        $this->assertSame(EnneagramResultPagePendingProductionGateStore::APPROVAL_PHRASE, data_get($summary, 'summary.approval_phrase_required'));

        $packet = $this->readJson(storage_path('app/'.EnneagramResultPagePendingProductionGateStore::DEFAULT_RELATIVE_PATH));
        $this->assertSame('pending', $packet['status'] ?? null);
        $this->assertTrue((bool) ($packet['single_pending_gate'] ?? false));
        $this->assertSame('我同意', $packet['approval_phrase'] ?? null);
        $this->assertSame(EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID, data_get($packet, 'locked_contract.release_id'));
        $this->assertTrue((bool) data_get($packet, 'authorization_scope.phrase_authorizes_only_this_pending_gate', false));
        $this->assertFalse((bool) data_get($packet, 'authorization_scope.permanent_authorization', true));
        $this->assertFalse((bool) data_get($packet, 'authorization_scope.agent_may_decide_production_rollout', true));
    }

    public function test_manual_gate_refuses_pending_gate_without_green_evidence_bundle(): void
    {
        $artifactRoot = storage_path('framework/testing/production_manual_gate_artifacts/pending_gate_missing_evidence');
        File::deleteDirectory($artifactRoot);
        app(EnneagramResultPagePendingProductionGateStore::class)->delete();

        $summary = app(EnneagramResultPageProductionManualGateRunbook::class)->run([
            'run_id' => 'manual-pending-gate-no-evidence',
            'artifact_dir' => $artifactRoot,
            'write_pending_gate' => true,
            'release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'confirm_release_id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            'candidate_manifest_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            'runtime_registry_sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            'rollback_window' => '60 minutes after activation',
            'post_activation_smoke_acknowledged' => true,
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertStringContainsString('evidence_dir_missing', implode("\n", $summary['errors'] ?? []));
        $this->assertFileDoesNotExist(storage_path('app/'.EnneagramResultPagePendingProductionGateStore::DEFAULT_RELATIVE_PATH));
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
