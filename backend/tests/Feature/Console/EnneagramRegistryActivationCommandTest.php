<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

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

    /**
     * @return array{release_id:string,output_dir:string}
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
        ];
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
