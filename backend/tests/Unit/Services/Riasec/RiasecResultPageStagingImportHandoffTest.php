<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use PHPUnit\Framework\TestCase;

final class RiasecResultPageStagingImportHandoffTest extends TestCase
{
    public function test_staging_import_handoff_is_governance_only_and_no_touch(): void
    {
        $base = dirname(__DIR__, 4).'/content_assets/riasec/result_page_v2/governance/staging_import_handoff_v0_1';

        $manifest = $this->readJson($base.'/staging_import_handoff_manifest.json');
        $packageList = $this->readJson($base.'/import_package_list.json');
        $checksumInventory = $this->readJson($base.'/checksum_inventory.json');
        $acceptanceMatrix = $this->readJson($base.'/acceptance_matrix.json');
        $noTouchPolicy = $this->readJson($base.'/runtime_no_touch_policy.json');

        foreach ([$manifest, $packageList, $checksumInventory, $acceptanceMatrix, $noTouchPolicy] as $payload) {
            self::assertSame('staging_only', $payload['runtime_use']);
            self::assertFalse($payload['production_use_allowed']);
            self::assertFalse($payload['ready_for_runtime']);
            self::assertFalse($payload['ready_for_production']);
            self::assertFalse($payload['cms_write_performed']);
            self::assertFalse($payload['runtime_change_performed']);
            self::assertFalse($payload['frontend_fallback_allowed']);
            self::assertFalse($payload['private_payload_exported']);
        }

        self::assertFalse($manifest['import_execution_allowed_in_this_pr']);
        self::assertFalse($manifest['cms_write_allowed']);
        self::assertFalse($manifest['runtime_wrapper_enablement_allowed']);
        self::assertFalse($manifest['production_import_gate_open']);
        self::assertFalse($manifest['production_rollout_allowed']);
        self::assertSame('RIASEC-RESULT-STAGING-IMPORT-DRY-RUN-01', $manifest['next_allowed_pr']);

        self::assertSame(6, $packageList['candidate_input_count']);
        foreach ($packageList['candidate_inputs'] as $candidate) {
            self::assertContains($candidate['import_action'], ['read_only_reference', 'dry_run_candidate_only', 'read_only_gate']);
        }

        self::assertSame(6, $checksumInventory['file_count']);
        foreach ($checksumInventory['files'] as $file) {
            $path = dirname(__DIR__, 4).'/'.substr((string) $file['path'], strlen('backend/'));
            self::assertFileExists($path);
            self::assertSame($file['sha256'], hash_file('sha256', $path), (string) $file['id']);
        }

        $gateStatuses = array_column($acceptanceMatrix['acceptance_gates'], 'status', 'id');
        self::assertSame('pass', $gateStatuses['cms_write_not_authorized'] ?? null);
        self::assertSame('pass', $gateStatuses['production_gate_closed'] ?? null);
        self::assertSame('GO_FOR_STAGING_IMPORT_DRY_RUN_PR_ONLY', $acceptanceMatrix['go_no_go']);
        self::assertSame('NO_GO', $acceptanceMatrix['production_go_no_go']);

        self::assertContains('backend/routes/**', $noTouchPolicy['no_touch_runtime_paths']);
        self::assertContains('staging import execution', $noTouchPolicy['forbidden_in_this_pr']);
        self::assertContains('fail-closed import harness', $noTouchPolicy['next_stage_requires']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
