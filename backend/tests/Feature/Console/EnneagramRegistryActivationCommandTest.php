<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Enneagram\Assets\Agent\EnneagramResultPagePendingProductionGateStore;
use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramRegistryActivationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_command_dry_run_passes_with_artifact_only_release_inspection(): void
    {
        $releaseId = 'enneagram_test_activation_dry_run_release';
        $storagePath = 'private/content_releases/ENNEAGRAM/v2/'.$releaseId;
        $storageRoot = storage_path('app/'.$storagePath);
        File::deleteDirectory($storageRoot);
        File::ensureDirectoryExists($storageRoot.'/registry');
        File::ensureDirectoryExists($storageRoot.'/candidate/candidate_payloads');
        File::copyDirectory(base_path('content_packs/ENNEAGRAM/v2/registry'), $storageRoot.'/registry');

        $candidateDir = storage_path('framework/testing/enneagram_activation_dry_run/candidate');
        File::deleteDirectory($candidateDir);
        File::ensureDirectoryExists($candidateDir.'/candidate_payloads');
        $this->seedCandidateArtifacts($candidateDir);
        File::copyDirectory($candidateDir, $storageRoot.'/candidate');

        $outputDir = storage_path('framework/testing/enneagram_activation_dry_run/output');
        File::deleteDirectory($outputDir);

        $this->artisan('enneagram:activate-registry-release', [
            '--release-id' => $releaseId,
            '--output-dir' => $outputDir,
            '--dry-run' => true,
            '--json' => true,
        ])->assertExitCode(0);

        $summary = json_decode((string) File::get($outputDir.'/phase8d3_activation_summary.json'), true);
        $this->assertSame('PASS_FOR_MANUAL_ACTIVATION_DECISION', $summary['verdict'] ?? null);
        $this->assertSame('artifact_only_dry_run', $summary['release_metadata_source'] ?? null);
        $this->assertFalse(DB::table('content_pack_activations')->exists());
    }

    public function test_activation_command_executes_controlled_sqlite_activation(): void
    {
        $fixture = $this->importInactiveFixture('activation_command_execute');
        $outputDir = $fixture['output_dir'].'/activate';

        $this->artisan('enneagram:activate-registry-release', [
            '--release-id' => $fixture['release_id'],
            '--confirm-release-id' => $fixture['release_id'],
            '--output-dir' => $outputDir,
            '--json' => true,
        ])->assertExitCode(0);

        $summary = json_decode((string) File::get($outputDir.'/phase8d3_activation_summary.json'), true);
        $this->assertSame('PASS_FOR_MANUAL_ACTIVATION_DECISION', $summary['verdict'] ?? null);
        $this->assertTrue($summary['activation_happened'] ?? false);
        $this->assertSame($fixture['release_id'], DB::table('content_pack_activations')->where('pack_id', 'ENNEAGRAM')->where('pack_version', 'v2')->value('release_id'));
    }

    public function test_inactive_candidate_activation_command_requires_exact_hash_contract(): void
    {
        $fixture = $this->importInactiveFixture('inactive_candidate_activation_command');
        $outputDir = $fixture['output_dir'].'/inactive_candidate_activate';

        $this->artisan('enneagram:activate-inactive-candidate-release', [
            '--release-id' => $fixture['release_id'],
            '--confirm-release-id' => $fixture['release_id'],
            '--candidate-manifest-sha256' => $fixture['contracts']['candidate_manifest_sha256'],
            '--runtime-registry-sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            '--output-dir' => $outputDir,
            '--actor' => 'activation_command_test',
            '--json' => true,
        ])->assertExitCode(0);

        $summary = json_decode((string) File::get($outputDir.'/phase8d3_activation_summary.json'), true);
        $this->assertSame('PASS_PRODUCTION_ACTIVATION_COMPLETED', $summary['verdict'] ?? null);
        $this->assertTrue($summary['production_activation_happened'] ?? false);
        $this->assertSame($fixture['release_id'], DB::table('content_pack_activations')->where('pack_id', 'ENNEAGRAM')->where('pack_version', 'v2')->value('release_id'));
    }

    public function test_inactive_candidate_activation_command_can_consume_pending_gate_with_simple_approval_phrase(): void
    {
        $fixture = $this->importInactiveFixture('inactive_candidate_activation_command_pending_gate');
        $pendingGate = $this->writePendingGateForFixture($fixture, 'activation-pending-gate');
        $outputDir = $fixture['output_dir'].'/inactive_candidate_activate_pending_gate';

        $this->artisan('enneagram:activate-inactive-candidate-release', [
            '--use-pending-gate' => true,
            '--approval-phrase' => EnneagramResultPagePendingProductionGateStore::APPROVAL_PHRASE,
            '--output-dir' => $outputDir,
            '--actor' => 'activation_pending_gate_test',
            '--json' => true,
        ])->assertExitCode(0);

        $summary = json_decode((string) File::get($outputDir.'/phase8d3_activation_summary.json'), true);
        $this->assertSame('PASS_PRODUCTION_ACTIVATION_COMPLETED', $summary['verdict'] ?? null);
        $this->assertTrue($summary['production_activation_happened'] ?? false);
        $this->assertSame($fixture['release_id'], DB::table('content_pack_activations')->where('pack_id', 'ENNEAGRAM')->where('pack_version', 'v2')->value('release_id'));

        $packet = json_decode((string) File::get(storage_path('app/'.EnneagramResultPagePendingProductionGateStore::DEFAULT_RELATIVE_PATH)), true);
        $this->assertSame($pendingGate['pending_gate_id'], $packet['pending_gate_id'] ?? null);
        $this->assertSame('activated', $packet['status'] ?? null);
    }

    public function test_inactive_candidate_activation_command_rejects_pending_gate_without_exact_approval_phrase(): void
    {
        $fixture = $this->importInactiveFixture('inactive_candidate_activation_command_pending_gate_bad_phrase');
        $this->writePendingGateForFixture($fixture, 'activation-pending-gate-bad-phrase');

        $this->artisan('enneagram:activate-inactive-candidate-release', [
            '--use-pending-gate' => true,
            '--approval-phrase' => '同意',
            '--output-dir' => $fixture['output_dir'].'/inactive_candidate_activate_pending_gate_bad_phrase',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertFalse(DB::table('content_pack_activations')->exists());
    }

    public function test_inactive_candidate_activation_command_fails_closed_on_hash_mismatch(): void
    {
        $fixture = $this->importInactiveFixture('inactive_candidate_activation_command_hash_mismatch');

        $this->artisan('enneagram:activate-inactive-candidate-release', [
            '--release-id' => $fixture['release_id'],
            '--confirm-release-id' => $fixture['release_id'],
            '--candidate-manifest-sha256' => hash('sha256', 'wrong'),
            '--runtime-registry-sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            '--output-dir' => $fixture['output_dir'].'/inactive_candidate_activate_mismatch',
            '--json' => true,
        ])->assertExitCode(1);

        $this->assertFalse(DB::table('content_pack_activations')->exists());
    }

    /**
     * @return array{release_id:string,output_dir:string,contracts:array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}}
     */
    private function importInactiveFixture(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/enneagram_activation_command/'.$suffix.'/candidate');
        $outputDir = storage_path('framework/testing/enneagram_activation_command/'.$suffix.'/output');
        File::deleteDirectory(dirname($candidateDir));
        File::deleteDirectory(dirname($outputDir));
        File::ensureDirectoryExists($candidateDir.'/candidate_payloads');
        $contracts = $this->seedCandidateArtifacts($candidateDir);

        $summary = app(EnneagramInactiveCandidateReleaseImporter::class)->import(
            $candidateDir,
            $outputDir,
            $contracts
        );

        return [
            'release_id' => (string) $summary['inactive_release_id'],
            'output_dir' => $outputDir,
            'contracts' => $contracts,
        ];
    }

    /**
     * @param  array{release_id:string,output_dir:string,contracts:array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}}  $fixture
     * @return array<string,mixed>
     */
    private function writePendingGateForFixture(array $fixture, string $runId): array
    {
        $evidenceDir = storage_path('framework/testing/enneagram_activation_command/'.$runId.'/evidence');
        File::deleteDirectory($evidenceDir);
        File::ensureDirectoryExists($evidenceDir);
        foreach (['candidate_export_staging_import', 'web_rendered_qa', 'api_smoke', 'rollback_simulation'] as $gate) {
            File::put($evidenceDir.'/'.$gate.'.json', json_encode(['gate' => $gate, 'ok' => true], JSON_PRETTY_PRINT));
        }

        $store = app(EnneagramResultPagePendingProductionGateStore::class);
        $store->delete();

        return $store->write([
            'valid' => true,
            'release_id' => $fixture['release_id'],
            'confirm_release_id' => $fixture['release_id'],
            'candidate_manifest_sha256' => $fixture['contracts']['candidate_manifest_sha256'],
            'runtime_registry_sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            'rollback_window' => '60 minutes after activation',
            'post_activation_smoke_acknowledged' => true,
            'production_execution_allowed_for_agent' => false,
            'manual_human_approval_required' => true,
        ], $runId, $evidenceDir, 120);
    }

    /**
     * @return array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}
     */
    private function seedCandidateArtifacts(string $candidateDir): array
    {
        File::ensureDirectoryExists($candidateDir.'/candidate_payloads');
        $runtimeHash = hash_file('sha256', base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json')) ?: '';
        $manifest = [
            'schema_version' => 'enneagram.production_import_candidate.v1',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'dry_run_candidate_only',
            'production_import_happened' => false,
            'full_replacement_happened' => false,
            'out_of_launch_scope' => ['1R-I', '1R-J'],
            'source_assets' => [],
            'source_versions' => [
                'batch_1r_a' => 'a',
                'batch_1r_b' => 'b',
                'batch_1r_c' => 'c',
                'batch_1r_d' => 'd',
                'batch_1r_e' => 'e',
                'batch_1r_f' => 'f',
                'batch_1r_g' => 'g',
                'batch_1r_h' => 'h',
            ],
            'replacement_coverage' => [
                'batch_1r_a_replaces' => ['page1_summary', 'type_summary', 'deep_dive_intro'],
                'batch_1r_b_replaces' => [
                    'core_motivation', 'core_fear', 'core_desire', 'self_image', 'attention_pattern',
                    'strength', 'blindspot', 'stress_pattern', 'relationship_pattern', 'work_pattern',
                    'growth_direction', 'daily_observation', 'boundary',
                ],
                'batch_1r_c_adds' => ['low_resonance_response'],
                'batch_1r_d_adds' => ['partial_resonance_response'],
                'batch_1r_e_adds' => ['diffuse_convergence_response'],
                'batch_1r_f_adds' => ['close_call_pair'],
                'batch_1r_g_adds' => ['scene_localization_response'],
                'batch_1r_h_adds' => ['fc144_recommendation_response'],
            ],
            'candidate_item_count' => 1332,
            'candidate_items_by_batch' => [
                '1R-A' => 315, '1R-B' => 423, '1R-C' => 108, '1R-D' => 90,
                '1R-E' => 108, '1R-F' => 36, '1R-G' => 162, '1R-H' => 90,
            ],
            'candidate_category_sources' => [],
            'payload_counts' => [
                'baseline' => 36, 'low_resonance' => 108, 'partial_resonance' => 90,
                'diffuse_convergence' => 108, 'close_call_pair' => 36, 'scene_localization' => 162,
                'fc144_recommendation' => 90,
            ],
            'runtime_registry_manifest' => [
                'path' => base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json'),
                'sha256' => $runtimeHash,
            ],
        ];

        File::put($candidateDir.'/candidate_manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $manifestHash = hash_file('sha256', $candidateDir.'/candidate_manifest.json') ?: '';
        File::put($candidateDir.'/candidate_hashes.json', json_encode([
            'candidate_manifest_sha256' => $manifestHash,
            'runtime_registry_manifest_sha256' => $runtimeHash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/rollback_plan.md', "# rollback\n");
        File::put($candidateDir.'/import_diff_summary.json', json_encode([
            'replaces' => [],
            'adds' => [],
            'no_full_replacement' => true,
            'no_production_registry_write' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/replacement_additive_map.json', json_encode($manifest['replacement_coverage'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/source_mapping_report.json', json_encode([
            'source_mapping_failure_count' => 0,
            'missing_count' => 0,
            'fallback_count' => 0,
            'blocked_count' => 0,
            'duplicate_selection_count' => 0,
            'metadata_leak_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/forbidden_claim_report.json', json_encode(['violation_count' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/legacy_residual_scan.json', json_encode(['legacy_deep_core_residual_count' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/fc144_boundary_report.json', json_encode(['violation_count' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/phase8b_summary.json', json_encode(['verdict' => 'PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payloads_manifest.json', json_encode(['total_payload_count' => 630], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payload_hashes.json', json_encode(['candidate_payloads_manifest_sha256' => 'fixture'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payload_source_mapping.json', json_encode(['source_mapping_failure_count' => 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        for ($i = 0; $i < 630; $i++) {
            File::put($candidateDir.'/candidate_payloads/payload_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.json', '{}');
        }

        return [
            'candidate_manifest_sha256' => $manifestHash,
            'runtime_registry_manifest_sha256' => $runtimeHash,
        ];
    }
}
