<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageProductionManualGateRunbook;
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
}
