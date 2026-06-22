<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Enneagram\Assets\Agent\EnneagramResultPageCandidateStagingHarness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramResultPageCandidateStagingHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_harness_validates_candidate_and_runs_inactive_staging_import_without_activation(): void
    {
        $fixture = $this->makeCandidateFixture('harness_inactive_import');
        $artifactRoot = storage_path('framework/testing/enneagram_candidate_staging_harness_artifacts/import');

        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageCandidateStagingHarness::class)->run([
            'run_id' => 'unit-run',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $fixture['candidate_dir'],
            'output_dir' => $fixture['output_dir'],
            'expected_candidate_manifest_sha256' => $fixture['contracts']['candidate_manifest_sha256'],
            'expected_runtime_registry_sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            'run_staging_import' => true,
            'strict' => true,
        ]);

        $this->assertTrue((bool) ($summary['ok'] ?? false));
        $this->assertTrue((bool) data_get($summary, 'summary.candidate_contract_valid', false));
        $this->assertSame(630, (int) data_get($summary, 'summary.candidate_payload_count'));
        $this->assertTrue((bool) data_get($summary, 'summary.run_staging_import', false));
        $this->assertFalse((bool) data_get($summary, 'summary.production_execution_allowed_for_agent', true));
        $this->assertNotEmpty(data_get($summary, 'summary.inactive_release_id'));
        $this->assertFalse(DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->exists());

        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->runtimeRegistryRoot());

        $report = $this->readJson($artifactRoot.'/unit-run/candidate_export_staging_import_report.json');
        $this->assertFalse((bool) data_get($report, 'execution.activation_allowed', true));
        $this->assertFalse((bool) data_get($report, 'execution.production_import_allowed', true));
        $this->assertFalse((bool) data_get($report, 'negative_guarantees.production_activation_happened', true));
        $this->assertFileExists($artifactRoot.'/unit-run/staging_import_summary.json');
    }

    public function test_harness_fails_closed_on_hash_mismatch(): void
    {
        $fixture = $this->makeCandidateFixture('harness_hash_mismatch');
        $artifactRoot = storage_path('framework/testing/enneagram_candidate_staging_harness_artifacts/hash');

        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageCandidateStagingHarness::class)->run([
            'run_id' => 'hash-run',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $fixture['candidate_dir'],
            'output_dir' => $fixture['output_dir'],
            'expected_candidate_manifest_sha256' => str_repeat('0', 64),
            'expected_runtime_registry_sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('candidate_manifest_hash_mismatch', $summary['errors'] ?? []);
    }

    public function test_harness_fails_closed_on_payload_count_mismatch(): void
    {
        $fixture = $this->makeCandidateFixture('harness_count_mismatch');
        unlink($fixture['candidate_dir'].'/candidate_payloads/payload_000.json');
        $artifactRoot = storage_path('framework/testing/enneagram_candidate_staging_harness_artifacts/count');

        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageCandidateStagingHarness::class)->run([
            'run_id' => 'count-run',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $fixture['candidate_dir'],
            'output_dir' => $fixture['output_dir'],
            'expected_candidate_manifest_sha256' => $fixture['contracts']['candidate_manifest_sha256'],
            'expected_runtime_registry_sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('candidate_payload_count_mismatch', $summary['errors'] ?? []);
    }

    public function test_harness_fails_closed_on_launch_scope_mismatch(): void
    {
        $fixture = $this->makeCandidateFixture('harness_scope_mismatch');
        $manifest = $this->readJson($fixture['candidate_dir'].'/candidate_manifest.json');
        unset($manifest['candidate_items_by_batch']['1R-H']);
        $contracts = $this->rewriteManifestAndHashes($fixture['candidate_dir'], $manifest);
        $artifactRoot = storage_path('framework/testing/enneagram_candidate_staging_harness_artifacts/scope');

        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageCandidateStagingHarness::class)->run([
            'run_id' => 'scope-run',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $fixture['candidate_dir'],
            'output_dir' => $fixture['output_dir'],
            'expected_candidate_manifest_sha256' => $contracts['candidate_manifest_sha256'],
            'expected_runtime_registry_sha256' => $contracts['runtime_registry_manifest_sha256'],
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('launch_scope_mismatch', $summary['errors'] ?? []);
    }

    public function test_harness_fails_closed_on_metadata_leakage_count(): void
    {
        $fixture = $this->makeCandidateFixture('harness_metadata_leakage');
        $sourceMapping = $this->readJson($fixture['candidate_dir'].'/source_mapping_report.json');
        $sourceMapping['metadata_leak_count'] = 1;
        File::put($fixture['candidate_dir'].'/source_mapping_report.json', json_encode($sourceMapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $artifactRoot = storage_path('framework/testing/enneagram_candidate_staging_harness_artifacts/leakage');

        File::deleteDirectory($artifactRoot);

        $summary = app(EnneagramResultPageCandidateStagingHarness::class)->run([
            'run_id' => 'leakage-run',
            'artifact_dir' => $artifactRoot,
            'candidate_dir' => $fixture['candidate_dir'],
            'output_dir' => $fixture['output_dir'],
            'expected_candidate_manifest_sha256' => $fixture['contracts']['candidate_manifest_sha256'],
            'expected_runtime_registry_sha256' => $fixture['contracts']['runtime_registry_manifest_sha256'],
            'strict' => true,
        ]);

        $this->assertFalse((bool) ($summary['ok'] ?? true));
        $this->assertContains('metadata_leak_count_nonzero', $summary['errors'] ?? []);
    }

    /**
     * @return array{candidate_dir:string,output_dir:string,contracts:array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}}
     */
    private function makeCandidateFixture(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/enneagram_candidate_staging_harness/'.$suffix);
        $outputDir = storage_path('framework/testing/enneagram_candidate_staging_harness_output/'.$suffix);
        File::deleteDirectory($candidateDir);
        File::deleteDirectory($outputDir);
        File::ensureDirectoryExists($candidateDir);
        File::ensureDirectoryExists($candidateDir.'/candidate_payloads');

        $runtimeHash = hash_file('sha256', base_path('content_packs/ENNEAGRAM/v2/registry/manifest.json')) ?: '';
        $manifest = [
            'schema_version' => 'enneagram.production_import_candidate.v1',
            'generated_at' => now()->toIso8601String(),
            'mode' => 'dry_run_candidate_only',
            'production_import_happened' => false,
            'full_replacement_happened' => false,
            'out_of_launch_scope' => ['1R-I', '1R-J'],
            'source_assets' => ['1R-A' => '/tmp/a.json'],
            'source_versions' => [
                'batch_1r_a' => 'enneagram_content_expansion_batch_1R_A.v6',
                'batch_1r_b' => 'enneagram_content_expansion_batch_1R_B_legacy_core_rewrite.v3',
                'batch_1r_c' => 'enneagram_content_expansion_batch_1R_C_low_resonance_objection_handling.v1',
                'batch_1r_d' => 'enneagram_content_expansion_batch_1R_D_partial_resonance_deep_branch.v1',
                'batch_1r_e' => 'enneagram_content_expansion_batch_1R_E_diffuse_top3_convergence.v1',
                'batch_1r_f' => 'enneagram_content_expansion_batch_1R_F_close_call_36_pair_completion.v1',
                'batch_1r_g' => 'enneagram_content_expansion_batch_1R_G_scene_localization.v1',
                'batch_1r_h' => 'enneagram_content_expansion_batch_1R_H_fc144_recommendation.v1',
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

        foreach ([
            'candidate_hashes.json' => ['candidate_manifest_sha256' => $manifestHash, 'runtime_registry_manifest_sha256' => $runtimeHash],
            'import_diff_summary.json' => ['no_full_replacement' => true, 'no_production_registry_write' => true],
            'replacement_additive_map.json' => $manifest['replacement_coverage'],
            'source_mapping_report.json' => ['source_mapping_failure_count' => 0, 'missing_count' => 0, 'fallback_count' => 0, 'blocked_count' => 0, 'duplicate_selection_count' => 0, 'metadata_leak_count' => 0],
            'legacy_residual_scan.json' => ['legacy_deep_core_residual_count' => 0],
            'fc144_boundary_report.json' => ['violation_count' => 0],
            'forbidden_claim_report.json' => ['violation_count' => 0],
            'phase8b_summary.json' => ['verdict' => 'PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA'],
            'candidate_payloads_manifest.json' => ['total_payload_count' => 630],
            'candidate_payload_hashes.json' => ['candidate_payloads_manifest_sha256' => 'fixture'],
            'candidate_payload_source_mapping.json' => ['source_mapping_failure_count' => 0],
        ] as $file => $payload) {
            File::put($candidateDir.'/'.$file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        File::put($candidateDir.'/rollback_plan.md', "# rollback\n");

        for ($i = 0; $i < 630; $i++) {
            File::put($candidateDir.'/candidate_payloads/payload_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'.json', json_encode([
                'fixture_index' => $i,
                'enneagram_report_v2' => ['schema_version' => 'enneagram.report.v2'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [
            'candidate_dir' => $candidateDir,
            'output_dir' => $outputDir,
            'contracts' => [
                'candidate_manifest_sha256' => $manifestHash,
                'runtime_registry_manifest_sha256' => $runtimeHash,
            ],
        ];
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
     * @param  array<string,mixed>  $manifest
     * @return array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}
     */
    private function rewriteManifestAndHashes(string $candidateDir, array $manifest): array
    {
        File::put($candidateDir.'/candidate_manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $manifestHash = hash_file('sha256', $candidateDir.'/candidate_manifest.json') ?: '';
        $hashes = $this->readJson($candidateDir.'/candidate_hashes.json');
        $hashes['candidate_manifest_sha256'] = $manifestHash;
        File::put($candidateDir.'/candidate_hashes.json', json_encode($hashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return [
            'candidate_manifest_sha256' => $manifestHash,
            'runtime_registry_manifest_sha256' => (string) ($hashes['runtime_registry_manifest_sha256'] ?? ''),
        ];
    }
}
