<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPageAgentReadiness;
use Tests\TestCase;

final class EnneagramResultPageAgentReadinessTest extends TestCase
{
    public function test_audit_service_writes_control_packet_and_validator_artifacts_without_generation_or_activation(): void
    {
        $artifactRoot = $this->tempDir('enneagram-agent-readiness');

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'unit-run',
                'artifact_dir' => $artifactRoot,
                'source_ledger_dir' => $this->sourceLedgerDir(),
            ]);

            $this->assertTrue((bool) ($summary['ok'] ?? false));

            $runDir = $artifactRoot.'/unit-run';
            $artifactFiles = [
                'control_packet.json',
                'readiness_inventory.json',
                'source_ledger_inventory.json',
                'validator_harness_report.json',
                'source_mapping_contract_report.json',
                'metadata_leakage_report.json',
                'forbidden_claim_report.json',
                'validation_commands.json',
                'safety_policy.json',
                'go_no_go.md',
            ];

            foreach ($artifactFiles as $filename) {
                $this->assertFileExists($runDir.'/'.$filename);
            }

            $controlPacket = $this->readJson($runDir.'/control_packet.json');
            $this->assertSame(EnneagramResultPageAgentReadiness::SCHEMA_VERSION, $controlPacket['schema_version'] ?? null);
            $this->assertSame('backend', $controlPacket['content_authority'] ?? null);
            $this->assertSame('ENNEAGRAM', $controlPacket['scale'] ?? null);
            $this->assertFalse((bool) data_get($controlPacket, 'negative_guarantees.bulk_content_generation_happened', true));
            $this->assertFalse((bool) data_get($controlPacket, 'negative_guarantees.candidate_payload_creation_happened', true));
            $this->assertFalse((bool) data_get($controlPacket, 'negative_guarantees.activation_happened', true));
            $this->assertFalse((bool) data_get($controlPacket, 'agent_batches.0.generation_allowed', true));

            $inventory = $this->readJson($runDir.'/readiness_inventory.json');
            $this->assertSame(630, (int) ($inventory['expected_payload_count'] ?? 0));
            $this->assertSame(
                'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
                data_get($inventory, 'runtime_registry.expected_sha256')
            );
            $this->assertFalse((bool) ($inventory['production_use_allowed'] ?? true));

            $sourceLedger = $this->readJson($runDir.'/source_ledger_inventory.json');
            $this->assertTrue((bool) data_get($sourceLedger, 'source_ledger.valid'));
            $this->assertSame(
                EnneagramResultPageAgentReadiness::SOURCE_LEDGER_SCHEMA_VERSION,
                data_get($sourceLedger, 'source_ledger.schema_version')
            );

            $validator = $this->readJson($runDir.'/validator_harness_report.json');
            $this->assertFalse((bool) data_get($validator, 'candidate_dir.provided', true));
            $this->assertTrue((bool) data_get($validator, 'candidate_contract.valid', false));
            $this->assertSame(0, (int) ($validator['error_count'] ?? -1));

            $commands = $this->readJson($runDir.'/validation_commands.json');
            $futureExport = implode("\n", (array) ($commands['future_candidate_export_gate'] ?? []));
            $this->assertStringContainsString('enneagram:export-production-equivalent-candidate-payloads', $futureExport);
            $this->assertStringContainsString('total_payload_count == 630', $futureExport);

            $safety = $this->readJson($runDir.'/safety_policy.json');
            $this->assertContains('fixed_type_certainty', $safety['forbidden_claim_families'] ?? []);
            $this->assertContains('e105_fc144_score_comparison', $safety['forbidden_claim_families'] ?? []);

            $goNoGo = (string) file_get_contents($runDir.'/go_no_go.md');
            $this->assertStringContainsString('candidate_generation_allowed: false', $goNoGo);
            $this->assertStringContainsString('no activation', $goNoGo);

            $this->assertArtifactsDoNotLeakPrivateMaterial($runDir, $artifactFiles);
        } finally {
            $this->deleteDirectory($artifactRoot);
        }
    }

    public function test_source_ledger_contract_has_required_ids_hashes_scope_and_negative_guarantees(): void
    {
        $path = $this->sourceLedgerDir().'/source_ledger.json';
        $ledger = $this->readJson($path);

        $this->assertSame('fap.enneagram.result_page.source_ledger.v0.1', $ledger['schema_version'] ?? null);
        $this->assertSame('not_runtime', $ledger['runtime_use'] ?? null);
        $this->assertFalse((bool) ($ledger['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($ledger['cms_write_performed'] ?? true));
        $this->assertFalse((bool) ($ledger['runtime_change_performed'] ?? true));
        $this->assertFalse((bool) ($ledger['frontend_fallback_allowed'] ?? true));
        $this->assertFalse((bool) ($ledger['activation_happened'] ?? true));
        $this->assertSame(
            'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0',
            data_get($ledger, 'candidate_contract.baseline_candidate_manifest_sha256')
        );
        $this->assertSame(
            'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
            data_get($ledger, 'candidate_contract.runtime_registry_manifest_sha256')
        );
        $this->assertSame(630, (int) data_get($ledger, 'candidate_contract.expected_payload_count'));
        $this->assertSame(['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'], data_get($ledger, 'candidate_contract.launch_scope'));
        $this->assertSame(['1R-I', '1R-J'], data_get($ledger, 'candidate_contract.out_of_launch_scope'));

        $sourceIds = array_map(
            static fn (array $source): string => (string) ($source['source_id'] ?? ''),
            (array) ($ledger['sources'] ?? [])
        );
        sort($sourceIds);

        $expected = [
            'batch_1r_a_asset_stream',
            'batch_1r_b_asset_stream',
            'batch_1r_c_asset_stream',
            'batch_1r_d_asset_stream',
            'batch_1r_e_asset_stream',
            'batch_1r_f_asset_stream',
            'batch_1r_g_asset_stream',
            'batch_1r_h_asset_stream',
            'enneagram_e105_compiled_questions',
            'enneagram_fc144_compiled_questions',
            'enneagram_v2_runtime_registry',
            'fc144_boundary_policy',
            'forbidden_claim_policy',
            'phase8b_candidate_baseline_a9fd',
        ];
        sort($expected);
        $this->assertSame($expected, $sourceIds);
    }

    public function test_strict_mode_rejects_malformed_source_ledger(): void
    {
        $root = $this->tempDir('enneagram-agent-ledger-strict');
        $sourceLedgerDir = $root.'/ledger';
        mkdir($sourceLedgerDir, 0777, true);
        file_put_contents($sourceLedgerDir.'/source_ledger.json', json_encode([
            'schema_version' => 'bad',
            'runtime_use' => 'runtime',
        ], JSON_PRETTY_PRINT));

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'strict-ledger',
                'artifact_dir' => $root.'/artifacts',
                'source_ledger_dir' => $sourceLedgerDir,
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('source_ledger_invalid', $summary['strict_failures']);

            $inventory = $this->readJson($root.'/artifacts/strict-ledger/source_ledger_inventory.json');
            $this->assertFalse((bool) data_get($inventory, 'source_ledger.valid', true));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_strict_mode_rejects_incomplete_candidate_directory(): void
    {
        $root = $this->tempDir('enneagram-agent-strict');
        $candidateDir = $root.'/candidate';
        mkdir($candidateDir, 0777, true);

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'strict-run',
                'artifact_dir' => $root.'/artifacts',
                'candidate_dir' => $candidateDir,
                'source_ledger_dir' => $this->sourceLedgerDir(),
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('missing_candidate_artifact:candidate_manifest.json', $summary['strict_failures']);

            $inventory = $this->readJson($root.'/artifacts/strict-run/readiness_inventory.json');
            $this->assertContains('candidate_hashes.json', data_get($inventory, 'candidate_dir.missing_required_files'));
        } finally {
            $this->deleteDirectory($root);
        }
    }

    public function test_strict_mode_rejects_candidate_payload_with_forbidden_claim_and_metadata_leakage(): void
    {
        $root = $this->tempDir('enneagram-agent-leak-strict');
        $candidateDir = $root.'/candidate';
        $this->writeLeakyCandidateFixture($candidateDir);

        try {
            $summary = app(EnneagramResultPageAgentReadiness::class)->audit([
                'run_id' => 'strict-leak',
                'artifact_dir' => $root.'/artifacts',
                'candidate_dir' => $candidateDir,
                'source_ledger_dir' => $this->sourceLedgerDir(),
                'strict' => true,
            ]);

            $this->assertFalse((bool) ($summary['ok'] ?? true));
            $this->assertContains('metadata_leakage_hits', $summary['strict_failures']);
            $this->assertContains('forbidden_claim_hits', $summary['strict_failures']);

            $metadata = $this->readJson($root.'/artifacts/strict-leak/metadata_leakage_report.json');
            $this->assertGreaterThan(0, (int) ($metadata['hit_count'] ?? 0));

            $claims = $this->readJson($root.'/artifacts/strict-leak/forbidden_claim_report.json');
            $this->assertGreaterThan(0, (int) ($claims['hit_count'] ?? 0));
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

    private function sourceLedgerDir(): string
    {
        return dirname(__DIR__, 5).'/content_assets/enneagram/result_page/source_ledger';
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

    /**
     * @param  list<string>  $artifactFiles
     */
    private function assertArtifactsDoNotLeakPrivateMaterial(string $runDir, array $artifactFiles): void
    {
        $allArtifacts = implode("\n", array_map(
            static fn (string $filename): string => (string) file_get_contents($runDir.'/'.$filename),
            $artifactFiles
        ));

        foreach ([
            '/Users/rainie/',
            '/private/tmp/',
            'attempt_id',
            'private_url',
            'raw_score',
            'domain_vector',
            'frontend fallback copy',
        ] as $blocked) {
            $this->assertStringNotContainsString($blocked, $allArtifacts);
        }
    }

    private function writeLeakyCandidateFixture(string $candidateDir): void
    {
        mkdir($candidateDir.'/candidate_payloads', 0777, true);
        file_put_contents($candidateDir.'/candidate_payloads/leaky.json', json_encode([
            'asset_key' => 'leaky',
            'public_payload' => [
                'attempt_id' => 'blocked-example',
                'body' => 'FC144 is more accurate and replaces your result.',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        file_put_contents($candidateDir.'/candidate_manifest.json', json_encode([
            'out_of_launch_scope' => ['1R-I', '1R-J'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($candidateDir.'/candidate_hashes.json', json_encode([
            'candidate_manifest_sha256' => hash_file('sha256', $candidateDir.'/candidate_manifest.json'),
            'runtime_registry_manifest_sha256' => 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($candidateDir.'/rollback_plan.md', "# rollback\n");
        file_put_contents($candidateDir.'/import_diff_summary.json', '{}');
        file_put_contents($candidateDir.'/replacement_additive_map.json', '{}');
        file_put_contents($candidateDir.'/source_mapping_report.json', json_encode([
            'source_mapping_failure_count' => 0,
            'fallback_source_count' => 0,
            'blocked_source_count' => 0,
            'duplicate_selection_count' => 0,
            'branch_provenance_mismatch_count' => 0,
        ], JSON_PRETTY_PRINT));
        file_put_contents($candidateDir.'/legacy_residual_scan.json', json_encode([
            'legacy_deep_core_residual_count' => 0,
        ], JSON_PRETTY_PRINT));
        file_put_contents($candidateDir.'/fc144_boundary_report.json', json_encode([
            'violation_count' => 0,
        ], JSON_PRETTY_PRINT));
        file_put_contents($candidateDir.'/phase8b_summary.json', '{}');
        file_put_contents($candidateDir.'/candidate_payloads_manifest.json', '{}');
        file_put_contents($candidateDir.'/candidate_payload_hashes.json', '{}');
        file_put_contents($candidateDir.'/candidate_payload_source_mapping.json', '{}');
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
