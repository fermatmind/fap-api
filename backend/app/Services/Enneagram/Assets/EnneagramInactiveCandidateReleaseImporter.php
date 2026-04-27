<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

use App\Models\ContentPackRelease;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class EnneagramInactiveCandidateReleaseImporter
{
    private const DEFAULT_EXPECTED_CANDIDATE_MANIFEST_SHA256 = '87f7eb874eb162ff158b5d3ac5e4393218d045054b2f0e3e0eddc09c6c3ea556';

    private const DEFAULT_EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const EXPECTED_PAYLOAD_COUNT = 630;

    private const PACK_ID = 'ENNEAGRAM';

    private const PACK_VERSION = 'v2';

    private const RELEASE_ACTION = 'enneagram_registry_import_inactive_candidate';

    /**
     * @var list<string>
     */
    private const REQUIRED_CANDIDATE_FILES = [
        'candidate_manifest.json',
        'candidate_hashes.json',
        'rollback_plan.md',
        'import_diff_summary.json',
        'replacement_additive_map.json',
        'source_mapping_report.json',
        'legacy_residual_scan.json',
        'fc144_boundary_report.json',
        'phase8b_summary.json',
        'candidate_payloads_manifest.json',
        'candidate_payload_hashes.json',
        'candidate_payload_source_mapping.json',
    ];

    /**
     * @var array<string,list<string>>
     */
    private const REQUIRED_REPLACEMENT_COVERAGE = [
        'batch_1r_a_replaces' => ['page1_summary', 'type_summary', 'deep_dive_intro'],
        'batch_1r_b_replaces' => [
            'core_motivation',
            'core_fear',
            'core_desire',
            'self_image',
            'attention_pattern',
            'strength',
            'blindspot',
            'stress_pattern',
            'relationship_pattern',
            'work_pattern',
            'growth_direction',
            'daily_observation',
            'boundary',
        ],
        'batch_1r_c_adds' => ['low_resonance_response'],
        'batch_1r_d_adds' => ['partial_resonance_response'],
        'batch_1r_e_adds' => ['diffuse_convergence_response'],
        'batch_1r_f_adds' => ['close_call_pair'],
        'batch_1r_g_adds' => ['scene_localization_response'],
        'batch_1r_h_adds' => ['fc144_recommendation_response'],
    ];

    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
        private readonly EnneagramRegistryReleaseResolver $registryReleaseResolver,
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
    ) {}

    /**
     * @param  array<string,string>  $contracts
     * @return array<string,mixed>
     */
    public function import(string $candidateDir, string $outputDir, array $contracts = []): array
    {
        $candidateDir = rtrim(trim($candidateDir), DIRECTORY_SEPARATOR);
        $outputDir = rtrim(trim($outputDir), DIRECTORY_SEPARATOR);

        if ($candidateDir === '' || ! is_dir($candidateDir)) {
            throw new RuntimeException('Candidate directory does not exist: '.$candidateDir);
        }

        if ($outputDir === '') {
            throw new RuntimeException('Output directory is required.');
        }

        $expectedCandidateHash = trim($contracts['candidate_manifest_sha256'] ?? self::DEFAULT_EXPECTED_CANDIDATE_MANIFEST_SHA256);
        $expectedRuntimeRegistryHash = trim($contracts['runtime_registry_manifest_sha256'] ?? self::DEFAULT_EXPECTED_RUNTIME_REGISTRY_MANIFEST_SHA256);

        $this->ensureDirectory($outputDir);
        $this->assertRequiredCandidateArtifacts($candidateDir);

        $candidateManifestPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_manifest.json';
        $candidateHashesPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_hashes.json';
        $payloadDir = $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads';

        $candidateManifest = $this->decodeJsonFile($candidateManifestPath);
        $candidateHashes = $this->decodeJsonFile($candidateHashesPath);
        $phase8bSummary = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'phase8b_summary.json');
        $importDiffSummary = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'import_diff_summary.json');
        $replacementAdditiveMap = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'replacement_additive_map.json');
        $sourceMappingReport = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'source_mapping_report.json');
        $legacyResidualScan = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'legacy_residual_scan.json');
        $fc144BoundaryReport = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'fc144_boundary_report.json');

        $candidateManifestHashActual = hash_file('sha256', $candidateManifestPath) ?: '';
        if ($candidateManifestHashActual !== $expectedCandidateHash) {
            throw new RuntimeException('Candidate manifest hash mismatch: '.$candidateManifestHashActual);
        }

        if ((string) ($candidateHashes['candidate_manifest_sha256'] ?? '') !== $expectedCandidateHash) {
            throw new RuntimeException('candidate_hashes.json candidate_manifest_sha256 mismatch.');
        }

        if ((string) ($candidateHashes['runtime_registry_manifest_sha256'] ?? '') !== $expectedRuntimeRegistryHash) {
            throw new RuntimeException('candidate_hashes.json runtime_registry_manifest_sha256 mismatch.');
        }

        $runtimeRegistryManifestPath = $this->registryReleaseResolver->runtimeRegistryRoot(self::PACK_VERSION)
            .DIRECTORY_SEPARATOR.'manifest.json';
        $runtimeRegistryHashActual = hash_file('sha256', $runtimeRegistryManifestPath) ?: '';
        if ($runtimeRegistryHashActual !== $expectedRuntimeRegistryHash) {
            throw new RuntimeException('Runtime registry manifest hash mismatch: '.$runtimeRegistryHashActual);
        }

        $runtimeRegistryReleaseHashActual = $this->trimShaPrefix($this->packLoader->resolveRegistryReleaseHash(self::PACK_VERSION));

        $payloadFiles = File::glob($payloadDir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($payloadFiles, SORT_STRING);
        if (count($payloadFiles) !== self::EXPECTED_PAYLOAD_COUNT) {
            throw new RuntimeException('candidate_payloads count mismatch: expected 630 got '.count($payloadFiles));
        }

        $this->assertLaunchScope($candidateManifest);
        $this->assertGovernance($candidateManifest, $phase8bSummary, $importDiffSummary, $replacementAdditiveMap, $sourceMappingReport, $legacyResidualScan, $fc144BoundaryReport);

        $runtimeContextBefore = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        $releaseId = $this->deterministicReleaseId($candidateManifestHashActual);
        $storagePath = 'private/content_releases/ENNEAGRAM/v2/'.$releaseId;
        $storageRoot = storage_path('app/'.$storagePath);

        $this->resetDirectory($storageRoot);
        $this->copyRepoRegistry($storageRoot.DIRECTORY_SEPARATOR.'registry');
        $this->copyCandidateArtifacts($candidateDir, $storageRoot);

        $releaseManifestHash = 'sha256:'.$candidateManifestHashActual;
        $releasePayload = [
            'schema_version' => 'enneagram.inactive_candidate_release_manifest.v1',
            'release_kind' => 'inactive_candidate',
            'release_id' => $releaseId,
            'activation_state' => 'inactive',
            'candidate_source_directory' => $candidateDir,
            'candidate_manifest_sha256' => $candidateManifestHashActual,
            'runtime_registry_manifest_sha256' => $expectedRuntimeRegistryHash,
            'candidate_payload_count' => count($payloadFiles),
            'out_of_launch_scope' => array_values((array) ($candidateManifest['out_of_launch_scope'] ?? [])),
            'replacement_coverage' => $candidateManifest['replacement_coverage'] ?? [],
            'candidate_items_by_batch' => $candidateManifest['candidate_items_by_batch'] ?? [],
            'source_versions' => $candidateManifest['source_versions'] ?? [],
            'source_assets' => $candidateManifest['source_assets'] ?? [],
            'storage_layout' => [
                'registry_root' => $storagePath.'/registry',
                'candidate_root' => $storagePath.'/candidate',
            ],
            'imported_at' => now()->toIso8601String(),
        ];

        $now = now();
        $release = ContentPackRelease::query()->updateOrCreate(
            ['id' => $releaseId],
            [
                'action' => self::RELEASE_ACTION,
                'region' => 'GLOBAL',
                'locale' => 'global',
                'dir_alias' => self::PACK_VERSION,
                'from_version_id' => null,
                'to_version_id' => null,
                'from_pack_id' => null,
                'to_pack_id' => self::PACK_ID,
                'status' => 'success',
                'message' => 'Inactive ENNEAGRAM candidate release materialized without activation',
                'created_by' => 'ops',
                'manifest_hash' => $releaseManifestHash,
                'compiled_hash' => 'sha256:'.$expectedRuntimeRegistryHash,
                'content_hash' => $releaseManifestHash,
                'pack_version' => self::PACK_VERSION,
                'manifest_json' => $releasePayload,
                'storage_path' => $storagePath,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->manifestCatalogService->upsertManifest([
            'content_pack_release_id' => (string) $release->getKey(),
            'manifest_hash' => $releaseManifestHash,
            'schema_version' => 'enneagram.inactive_candidate_release_manifest.v1',
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'compiled_hash' => 'sha256:'.$expectedRuntimeRegistryHash,
            'content_hash' => $releaseManifestHash,
            'payload_json' => $releasePayload,
        ]);

        $activationRowExists = DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->where('release_id', $releaseId)
            ->exists();

        if ($activationRowExists) {
            throw new RuntimeException('Inactive import unexpectedly created activation row.');
        }

        $runtimeContextAfter = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        $runtimeFallbackPreserved = $runtimeContextBefore['root'] === $runtimeContextAfter['root']
            && $runtimeContextAfter['source'] === 'repo_fallback';

        if (! $runtimeFallbackPreserved) {
            throw new RuntimeException('Inactive import changed runtime registry root before activation.');
        }

        $summary = [
            'verdict' => 'PASS_FOR_PHASE_8D_3_ACTIVATION_ROLLBACK_GATE',
            'candidate_source_directory' => $candidateDir,
            'candidate_payload_output_directory' => $payloadDir,
            'inactive_release_id' => $releaseId,
            'inactive_release_storage_path' => $storagePath,
            'candidate_manifest_hash_expected' => $expectedCandidateHash,
            'candidate_manifest_hash_actual' => $candidateManifestHashActual,
            'runtime_registry_manifest_hash_expected' => $expectedRuntimeRegistryHash,
            'runtime_registry_manifest_hash_actual' => $runtimeRegistryHashActual,
            'runtime_registry_release_hash_actual' => $runtimeRegistryReleaseHashActual,
            'candidate_payload_count' => count($payloadFiles),
            'materialized_registry_artifact_result' => [
                'registry_root' => $storageRoot.DIRECTORY_SEPARATOR.'registry',
                'candidate_root' => $storageRoot.DIRECTORY_SEPARATOR.'candidate',
                'exists' => true,
            ],
            'release_metadata_result' => [
                'content_pack_release_id' => (string) $release->getKey(),
                'content_pack_release_action' => self::RELEASE_ACTION,
                'content_release_manifest_hash' => $releaseManifestHash,
                'inactive' => true,
            ],
            'runtime_default_repo_fallback_result' => [
                'preserved' => $runtimeFallbackPreserved,
                'runtime_context_before' => $runtimeContextBefore,
                'runtime_context_after' => $runtimeContextAfter,
            ],
            'activation_happened' => false,
            'production_import_happened' => false,
            'full_replacement_happened' => false,
            'normal_report_unchanged_before_activation' => true,
            'share_pdf_history_unchanged_before_activation' => true,
            'scope_enforcement' => [
                'launch_scope_batches' => ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'],
                'out_of_launch_scope' => array_values((array) ($candidateManifest['out_of_launch_scope'] ?? [])),
            ],
            'fc144_boundary_violation_count' => (int) ($fc144BoundaryReport['violation_count'] ?? 0),
        ];

        $this->writeReportFiles($outputDir, $summary, $releasePayload, $importDiffSummary);

        return $summary;
    }

    private function assertRequiredCandidateArtifacts(string $candidateDir): void
    {
        foreach (self::REQUIRED_CANDIDATE_FILES as $file) {
            if (! is_file($candidateDir.DIRECTORY_SEPARATOR.$file)) {
                throw new RuntimeException('Missing required candidate artifact: '.$file);
            }
        }

        if (! is_dir($candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads')) {
            throw new RuntimeException('Missing candidate_payloads directory.');
        }
    }

    /**
     * @param  array<string,mixed>  $candidateManifest
     */
    private function assertLaunchScope(array $candidateManifest): void
    {
        $outOfScope = array_values((array) ($candidateManifest['out_of_launch_scope'] ?? []));
        sort($outOfScope, SORT_STRING);

        if ($outOfScope !== ['1R-I', '1R-J']) {
            throw new RuntimeException('Launch scope mismatch for out_of_launch_scope.');
        }
    }

    /**
     * @param  array<string,mixed>  $candidateManifest
     * @param  array<string,mixed>  $phase8bSummary
     * @param  array<string,mixed>  $importDiffSummary
     * @param  array<string,mixed>  $replacementAdditiveMap
     * @param  array<string,mixed>  $sourceMappingReport
     * @param  array<string,mixed>  $legacyResidualScan
     * @param  array<string,mixed>  $fc144BoundaryReport
     */
    private function assertGovernance(
        array $candidateManifest,
        array $phase8bSummary,
        array $importDiffSummary,
        array $replacementAdditiveMap,
        array $sourceMappingReport,
        array $legacyResidualScan,
        array $fc144BoundaryReport,
    ): void {
        if ((bool) ($candidateManifest['production_import_happened'] ?? true)) {
            throw new RuntimeException('Candidate manifest indicates production import happened.');
        }

        if ((bool) ($candidateManifest['full_replacement_happened'] ?? true)) {
            throw new RuntimeException('Candidate manifest indicates full replacement happened.');
        }

        if ((string) ($phase8bSummary['verdict'] ?? '') !== 'PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA') {
            throw new RuntimeException('Phase 8-B summary verdict is not PASS_FOR_PRODUCTION_EQUIVALENT_E2E_QA.');
        }

        if (! (bool) ($importDiffSummary['no_full_replacement'] ?? false)) {
            throw new RuntimeException('Import diff summary indicates full replacement risk.');
        }

        if (! (bool) ($importDiffSummary['no_production_registry_write'] ?? false)) {
            throw new RuntimeException('Import diff summary indicates production registry write.');
        }

        foreach (self::REQUIRED_REPLACEMENT_COVERAGE as $key => $expectedValues) {
            $actual = array_values((array) ($candidateManifest['replacement_coverage'][$key] ?? $replacementAdditiveMap[$key] ?? []));
            if ($actual !== $expectedValues) {
                throw new RuntimeException('Replacement coverage mismatch for '.$key);
            }
        }

        if ((int) ($sourceMappingReport['source_mapping_failure_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['missing_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['fallback_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['blocked_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['duplicate_selection_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['metadata_leak_count'] ?? -1) !== 0) {
            throw new RuntimeException('Source mapping report contains failures.');
        }

        if ((int) ($legacyResidualScan['legacy_deep_core_residual_count'] ?? -1) !== 0) {
            throw new RuntimeException('Legacy residual scan is not clean.');
        }

        if ((int) ($fc144BoundaryReport['violation_count'] ?? -1) !== 0) {
            throw new RuntimeException('FC144 boundary report is not clean.');
        }
    }

    private function deterministicReleaseId(string $candidateManifestHashActual): string
    {
        return 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_'.Str::lower(substr($candidateManifestHashActual, 0, 8));
    }

    private function copyRepoRegistry(string $targetRegistryRoot): void
    {
        $sourceRegistryRoot = $this->registryReleaseResolver->repoFallbackRegistryRoot(self::PACK_VERSION);
        $this->resetDirectory($targetRegistryRoot);

        if (! File::copyDirectory($sourceRegistryRoot, $targetRegistryRoot)) {
            throw new RuntimeException('Failed to copy repo registry into inactive release artifact.');
        }
    }

    private function copyCandidateArtifacts(string $candidateDir, string $storageRoot): void
    {
        $candidateRoot = $storageRoot.DIRECTORY_SEPARATOR.'candidate';
        $this->resetDirectory($candidateRoot);

        foreach (self::REQUIRED_CANDIDATE_FILES as $file) {
            File::copy($candidateDir.DIRECTORY_SEPARATOR.$file, $candidateRoot.DIRECTORY_SEPARATOR.$file);
        }

        if (! File::copyDirectory($candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads', $candidateRoot.DIRECTORY_SEPARATOR.'candidate_payloads')) {
            throw new RuntimeException('Failed to copy candidate_payloads into inactive release artifact.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: '.$path);
        }

        return $decoded;
    }

    private function ensureDirectory(string $path): void
    {
        File::ensureDirectoryExists($path);
    }

    private function resetDirectory(string $path): void
    {
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);
    }

    private function trimShaPrefix(string $value): string
    {
        return str_starts_with($value, 'sha256:') ? substr($value, 7) : $value;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $releasePayload
     * @param  array<string,mixed>  $importDiffSummary
     */
    private function writeReportFiles(string $outputDir, array $summary, array $releasePayload, array $importDiffSummary): void
    {
        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D2B_InactiveImport.md', [
            '# Phase8D2B Inactive Import',
            '- verdict: '.$summary['verdict'],
            '- inactive_release_id: '.$summary['inactive_release_id'],
            '- inactive_release_storage_path: `'.$summary['inactive_release_storage_path'].'`',
            '- activation_happened: false',
            '- production_import_happened: false',
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D2B_HashVerification.md', [
            '# Phase8D2B Hash Verification',
            '- candidate_manifest_hash_expected: `'.$summary['candidate_manifest_hash_expected'].'`',
            '- candidate_manifest_hash_actual: `'.$summary['candidate_manifest_hash_actual'].'`',
            '- runtime_registry_manifest_hash_expected: `'.$summary['runtime_registry_manifest_hash_expected'].'`',
            '- runtime_registry_manifest_hash_actual: `'.$summary['runtime_registry_manifest_hash_actual'].'`',
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D2B_ImportDiff.md', [
            '# Phase8D2B Import Diff',
            '- no_full_replacement: '.((bool) ($importDiffSummary['no_full_replacement'] ?? false) ? 'true' : 'false'),
            '- no_production_registry_write: '.((bool) ($importDiffSummary['no_production_registry_write'] ?? false) ? 'true' : 'false'),
            '- replaces: `'.json_encode($importDiffSummary['replaces'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'`',
            '- adds: `'.json_encode($importDiffSummary['adds'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'`',
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D2B_RollbackPlan.md', [
            '# Phase8D2B Rollback Plan',
            '- release remains inactive',
            '- no activation row is created',
            '- rollback before activation = remove or supersede inactive release metadata',
            '- runtime default remains repo fallback until explicit activation',
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D2B_GoNoGo.md', [
            '# Phase8D2B Go / No-Go',
            '- verdict: '.$summary['verdict'],
            '- no production import activation',
            '- no full replacement',
            '- no active registry overwrite',
            '- no candidate activation',
            '- normal /report unchanged while inactive',
            '- public launch remains blocked',
        ]);

        file_put_contents(
            $outputDir.DIRECTORY_SEPARATOR.'phase8d2b_summary.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        file_put_contents(
            $outputDir.DIRECTORY_SEPARATOR.'phase8d2b_release_payload.json',
            json_encode($releasePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeMarkdown(string $path, array $lines): void
    {
        file_put_contents($path, implode("\n", $lines)."\n");
    }
}
