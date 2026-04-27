<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Models\ContentPackRelease;
use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use App\Services\Ops\EnneagramRegistryActivationGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class EnneagramRegistryActivationGateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_switches_resolver_to_materialized_release_root(): void
    {
        $fixture = $this->importInactiveFixture('service_activate');

        $summary = app(EnneagramRegistryActivationGateService::class)->activateControlled(
            $fixture['release_id'],
            $fixture['release_id'],
            $fixture['output_dir'].'/activate'
        );

        $resolver = app(EnneagramRegistryReleaseResolver::class);

        $this->assertSame('PASS_FOR_MANUAL_ACTIVATION_DECISION', $summary['verdict']);
        $this->assertSame('active_release', $resolver->runtimeRegistryContext()['source']);
        $this->assertSame(
            storage_path('app/'.$fixture['storage_path'].'/registry'),
            $resolver->runtimeRegistryRoot()
        );
    }

    public function test_rollback_without_previous_active_release_returns_repo_fallback(): void
    {
        $fixture = $this->importInactiveFixture('service_rollback_fallback');
        $service = app(EnneagramRegistryActivationGateService::class);

        $service->activateControlled(
            $fixture['release_id'],
            $fixture['release_id'],
            $fixture['output_dir'].'/activate'
        );

        $summary = $service->rollbackControlled('ENNEAGRAM', 'v2', $fixture['output_dir'].'/rollback');

        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $this->assertSame('PASS_FOR_MANUAL_ACTIVATION_DECISION', $summary['verdict']);
        $this->assertTrue($summary['restored_repo_fallback']);
        $this->assertSame('repo_fallback', $resolver->runtimeRegistryContext()['source']);
        $this->assertSame(base_path('content_packs/ENNEAGRAM/v2/registry'), $resolver->runtimeRegistryRoot());
    }

    public function test_rollback_with_previous_active_release_restores_previous_release(): void
    {
        $previous = $this->createPublishedReleaseFixture('enneagram_previous_active_release');
        $fixture = $this->importInactiveFixture('service_restore_previous');
        $service = app(EnneagramRegistryActivationGateService::class);

        DB::table('content_pack_activations')->updateOrInsert(
            ['pack_id' => 'ENNEAGRAM', 'pack_version' => 'v2'],
            [
                'release_id' => $previous['release_id'],
                'activated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $service->activateControlled(
            $fixture['release_id'],
            $fixture['release_id'],
            $fixture['output_dir'].'/activate'
        );

        $summary = $service->rollbackControlled('ENNEAGRAM', 'v2', $fixture['output_dir'].'/rollback');

        $resolver = app(EnneagramRegistryReleaseResolver::class);
        $this->assertSame('PASS_FOR_MANUAL_ACTIVATION_DECISION', $summary['verdict']);
        $this->assertFalse($summary['restored_repo_fallback']);
        $this->assertSame($previous['release_id'], $resolver->runtimeRegistryContext()['active_release_id']);
        $this->assertSame(storage_path('app/'.$previous['storage_path'].'/registry'), $resolver->runtimeRegistryRoot());
    }

    public function test_activation_rejects_missing_storage_path(): void
    {
        ContentPackRelease::query()->create([
            'id' => 'enneagram_missing_storage_release',
            'action' => 'enneagram_registry_import_inactive_candidate',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'missing storage',
            'created_by' => 'test',
            'manifest_hash' => 'sha256:test',
            'compiled_hash' => 'sha256:test',
            'content_hash' => 'sha256:test',
            'pack_version' => 'v2',
            'manifest_json' => [],
            'storage_path' => 'private/content_releases/ENNEAGRAM/v2/missing_release',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Release storage path does not exist');

        app(EnneagramRegistryActivationGateService::class)->activateControlled(
            'enneagram_missing_storage_release',
            'enneagram_missing_storage_release',
            storage_path('framework/testing/enneagram_gate_errors/missing_storage')
        );
    }

    public function test_activation_rejects_hash_scope_and_full_replacement_violations(): void
    {
        $fixture = $this->importInactiveFixture('service_invalidations');
        $service = app(EnneagramRegistryActivationGateService::class);
        $candidateRoot = storage_path('app/'.$fixture['storage_path'].'/candidate');

        File::put(
            $candidateRoot.'/candidate_manifest.json',
            json_encode(['out_of_launch_scope' => ['1R-I']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Candidate manifest hash mismatch');

        $service->activateControlled(
            $fixture['release_id'],
            $fixture['release_id'],
            $fixture['output_dir'].'/invalid_hash'
        );
    }

    /**
     * @return array{release_id:string,storage_path:string,output_dir:string}
     */
    private function importInactiveFixture(string $suffix): array
    {
        $fixture = $this->makeCandidateFixture($suffix);
        $summary = app(EnneagramInactiveCandidateReleaseImporter::class)->import(
            $fixture['candidate_dir'],
            $fixture['output_dir'],
            $fixture['contracts']
        );

        return [
            'release_id' => (string) $summary['inactive_release_id'],
            'storage_path' => (string) $summary['inactive_release_storage_path'],
            'output_dir' => $fixture['output_dir'],
        ];
    }

    /**
     * @return array{release_id:string,storage_path:string}
     */
    private function createPublishedReleaseFixture(string $releaseId): array
    {
        $storagePath = 'private/content_releases/ENNEAGRAM/v2/'.$releaseId;
        $storageRoot = storage_path('app/'.$storagePath);
        File::deleteDirectory($storageRoot);
        File::ensureDirectoryExists(dirname($storageRoot));
        File::copyDirectory(base_path('content_packs/ENNEAGRAM/v2/registry'), $storageRoot.'/registry');

        ContentPackRelease::query()->create([
            'id' => $releaseId,
            'action' => 'enneagram_registry_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => 'v2',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => 'ENNEAGRAM',
            'status' => 'success',
            'message' => 'previous active release',
            'created_by' => 'test',
            'manifest_hash' => 'sha256:'.hash('sha256', $releaseId),
            'compiled_hash' => 'sha256:'.hash('sha256', $releaseId),
            'content_hash' => 'sha256:'.hash('sha256', $releaseId),
            'pack_version' => 'v2',
            'manifest_json' => ['release_id' => $releaseId],
            'storage_path' => $storagePath,
        ]);

        return [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
        ];
    }

    /**
     * @return array{candidate_dir:string,output_dir:string,contracts:array{candidate_manifest_sha256:string,runtime_registry_manifest_sha256:string}}
     */
    private function makeCandidateFixture(string $suffix): array
    {
        $candidateDir = storage_path('framework/testing/enneagram_activation_gate_candidate/'.$suffix);
        $outputDir = storage_path('framework/testing/enneagram_activation_gate_output/'.$suffix);
        File::deleteDirectory($candidateDir);
        File::deleteDirectory($outputDir);
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
            'candidate_dir' => $candidateDir,
            'output_dir' => $outputDir,
            'contracts' => [
                'candidate_manifest_sha256' => $manifestHash,
                'runtime_registry_manifest_sha256' => $runtimeHash,
            ],
        ];
    }
}
