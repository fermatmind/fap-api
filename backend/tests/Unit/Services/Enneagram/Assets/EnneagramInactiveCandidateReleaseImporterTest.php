<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Enneagram\Assets;

use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class EnneagramInactiveCandidateReleaseImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_materializes_inactive_release_without_activation_and_keeps_runtime_fallback(): void
    {
        $fixture = $this->makeCandidateFixture('importer_inactive');

        $summary = app(EnneagramInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['output_dir'],
            $fixture['contracts']
        );

        $this->assertSame('PASS_FOR_PHASE_8D_3_ACTIVATION_ROLLBACK_GATE', $summary['verdict']);
        $this->assertSame(630, $summary['candidate_payload_count']);
        $this->assertFalse(DB::table('content_pack_activations')
            ->where('pack_id', 'ENNEAGRAM')
            ->where('pack_version', 'v2')
            ->exists());
        $this->assertDatabaseHas('content_pack_releases', [
            'id' => $summary['inactive_release_id'],
            'to_pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'action' => 'enneagram_registry_import_inactive_candidate',
        ]);
        $this->assertDatabaseHas('content_release_manifests', [
            'content_pack_release_id' => $summary['inactive_release_id'],
            'pack_id' => 'ENNEAGRAM',
            'pack_version' => 'v2',
            'manifest_hash' => 'sha256:'.$fixture['contracts']['candidate_manifest_sha256'],
        ]);
        $this->assertDirectoryExists(storage_path('app/'.$summary['inactive_release_storage_path'].'/registry'));
        $this->assertDirectoryExists(storage_path('app/'.$summary['inactive_release_storage_path'].'/candidate/candidate_payloads'));
        $this->assertCount(
            630,
            File::glob(storage_path('app/'.$summary['inactive_release_storage_path'].'/candidate/candidate_payloads/*.json')) ?: []
        );

        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->runtimeRegistryRoot());
    }

    public function test_explicit_activation_row_can_resolve_materialized_release_root(): void
    {
        $fixture = $this->makeCandidateFixture('importer_activation_override');
        $summary = app(EnneagramInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['output_dir'],
            $fixture['contracts']
        );

        DB::table('content_pack_activations')->updateOrInsert(
            ['pack_id' => 'ENNEAGRAM', 'pack_version' => 'v2'],
            [
                'release_id' => $summary['inactive_release_id'],
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $this->assertSame(
            storage_path('app/'.$summary['inactive_release_storage_path'].'/registry'),
            $resolver->runtimeRegistryRoot()
        );
    }

    /**
     * @return array{candidate_dir:string,output_dir:string,contracts:array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}}
     */
    private function makeCandidateFixture(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/enneagram_inactive_candidate/'.$suffix);
        $outputDir = storage_path('framework/testing/enneagram_inactive_candidate_output/'.$suffix);
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
            'replaces' => ['1R-A' => ['page1_summary'], '1R-B' => ['core_motivation']],
            'adds' => ['1R-C' => ['low_resonance_response']],
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
        File::put($candidateDir.'/legacy_residual_scan.json', json_encode([
            'legacy_deep_core_residual_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/fc144_boundary_report.json', json_encode([
            'violation_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/phase8b_summary.json', json_encode([
            'verdict' => 'PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payloads_manifest.json', json_encode([
            'total_payload_count' => 630,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payload_hashes.json', json_encode([
            'candidate_payloads_manifest_sha256' => 'fixture',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        File::put($candidateDir.'/candidate_payload_source_mapping.json', json_encode([
            'source_mapping_failure_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

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
}
