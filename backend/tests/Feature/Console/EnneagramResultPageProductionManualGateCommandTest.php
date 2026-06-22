<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPagePendingProductionGateStore;
use App\Services\Enneagram\Assets\Agent\EnneagramResultPageProductionManualGateRunbook;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageProductionManualGateCommandTest extends TestCase
{
    public function test_production_manual_gate_command_succeeds_with_exact_manual_approval_packet(): void
    {
        $this->artisan('enneagram:result-page-production-manual-gate', [
            'action' => 'audit',
            '--run-id' => 'command-manual-pass',
            '--artifact-dir' => sys_get_temp_dir().'/enneagram-production-manual-gate-command',
            '--release-id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            '--confirm-release-id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            '--candidate-manifest-sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            '--runtime-registry-sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            '--rollback-window' => '2026-06-22T01:00:00Z/2026-06-22T02:00:00Z',
            '--post-activation-smoke-acknowledged' => true,
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_production_manual_gate_command_can_write_pending_gate_from_green_evidence_bundle(): void
    {
        $evidenceDir = sys_get_temp_dir().'/enneagram-production-manual-gate-command-evidence';
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        app(EnneagramResultPagePendingProductionGateStore::class)->delete();

        foreach (['candidate_export_staging_import', 'web_rendered_qa', 'api_smoke', 'rollback_simulation'] as $gate) {
            File::put($evidenceDir.'/'.$gate.'.json', json_encode(['gate' => $gate, 'ok' => true], JSON_PRETTY_PRINT));
        }

        $this->artisan('enneagram:result-page-production-manual-gate', [
            'action' => 'audit',
            '--run-id' => 'command-manual-pending-gate',
            '--artifact-dir' => sys_get_temp_dir().'/enneagram-production-manual-gate-command-pending',
            '--evidence-dir' => $evidenceDir,
            '--write-pending-gate' => true,
            '--pending-gate-ttl-minutes' => 120,
            '--release-id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            '--confirm-release-id' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RELEASE_ID,
            '--candidate-manifest-sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_CANDIDATE_MANIFEST_SHA256,
            '--runtime-registry-sha256' => EnneagramResultPageProductionManualGateRunbook::EXPECTED_RUNTIME_REGISTRY_SHA256,
            '--rollback-window' => '60 minutes after activation',
            '--post-activation-smoke-acknowledged' => true,
            '--strict' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertFileExists(storage_path('app/'.EnneagramResultPagePendingProductionGateStore::DEFAULT_RELATIVE_PATH));
    }
}
