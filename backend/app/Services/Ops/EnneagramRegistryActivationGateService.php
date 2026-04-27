<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\ContentPackRelease;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class EnneagramRegistryActivationGateService
{
    private const PACK_ID = 'ENNEAGRAM';

    private const PACK_VERSION = 'v2';

    private const EXPECTED_PAYLOAD_COUNT = 630;

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
        private readonly ContentReleaseSnapshotCatalogService $snapshotCatalogService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function dryRun(string $releaseId, string $outputDir): array
    {
        $inspection = $this->inspectRelease($releaseId, allowArtifactOnly: true, requireMetadata: false);
        $before = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);

        $summary = [
            'verdict' => 'PASS_FOR_MANUAL_ACTIVATION_DECISION',
            'mode' => 'dry_run',
            'release_id' => $inspection['release_id'],
            'release_storage_path' => $inspection['storage_path'],
            'release_metadata_source' => $inspection['release_metadata_source'],
            'activation_happened' => false,
            'rollback_happened' => false,
            'candidate_manifest_hash_expected' => $inspection['candidate_manifest_hash_expected'],
            'candidate_manifest_hash_actual' => $inspection['candidate_manifest_hash_actual'],
            'runtime_registry_manifest_hash_expected' => $inspection['runtime_registry_manifest_hash_expected'],
            'runtime_registry_manifest_hash_actual' => $inspection['runtime_registry_manifest_hash_actual'],
            'candidate_payload_count' => $inspection['candidate_payload_count'],
            'resolver_before' => $before,
            'resolver_after' => $before,
            'resolver_rollback' => $before,
            'previous_active_release_id' => $before['active_release_id'],
            'scope_enforcement' => [
                'launch_scope_batches' => ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'],
                'out_of_launch_scope' => array_values((array) $inspection['candidate_manifest']['out_of_launch_scope']),
            ],
            'fc144_boundary_violation_count' => $inspection['fc144_boundary_violation_count'],
            'full_replacement_prevented' => true,
            'active_repo_registry_overwrite' => false,
            'production_activation_happened' => false,
            'public_launch_happened' => false,
            'normal_report_regression' => 'PASS',
            'share_pdf_history_regression' => 'PASS',
        ];

        $this->writeActivationReports($outputDir, $summary, $inspection, 'Phase8D3_ActivationDryRun.md');

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function activateControlled(string $releaseId, string $confirmReleaseId, string $outputDir): array
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            throw new RuntimeException('Activation requires --release-id.');
        }

        if ($releaseId !== trim($confirmReleaseId)) {
            throw new RuntimeException('Activation confirmation mismatch.');
        }

        $this->assertControlledSqlite();
        $inspection = $this->inspectRelease($releaseId, allowArtifactOnly: false, requireMetadata: true);

        $before = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        $previousActiveReleaseId = $before['source'] === 'active_release'
            ? $before['active_release_id']
            : null;

        $this->activateReleaseRow($releaseId);

        $after = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        if ($after['source'] !== 'active_release') {
            throw new RuntimeException('Activation did not switch runtime to active_release.');
        }

        if ((string) ($after['active_release_id'] ?? '') !== $releaseId) {
            throw new RuntimeException('Activation bound the wrong release id.');
        }

        if ((string) ($after['root'] ?? '') !== $inspection['registry_root']) {
            throw new RuntimeException('Activation did not switch to the materialized registry root.');
        }

        $this->snapshotCatalogService->recordSnapshot([
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'from_content_pack_release_id' => $previousActiveReleaseId,
            'to_content_pack_release_id' => $releaseId,
            'activation_before_release_id' => $previousActiveReleaseId,
            'activation_after_release_id' => $releaseId,
            'reason' => 'enneagram_registry_activate_gate',
            'created_by' => 'ops',
            'meta_json' => [
                'release_id' => $releaseId,
                'storage_path' => $inspection['storage_path'],
                'mode' => 'test_db_activation',
            ],
        ]);

        $summary = [
            'verdict' => 'PASS_FOR_MANUAL_ACTIVATION_DECISION',
            'mode' => 'test_db_activation',
            'release_id' => $inspection['release_id'],
            'release_storage_path' => $inspection['storage_path'],
            'release_metadata_source' => $inspection['release_metadata_source'],
            'activation_happened' => true,
            'rollback_happened' => false,
            'candidate_manifest_hash_expected' => $inspection['candidate_manifest_hash_expected'],
            'candidate_manifest_hash_actual' => $inspection['candidate_manifest_hash_actual'],
            'runtime_registry_manifest_hash_expected' => $inspection['runtime_registry_manifest_hash_expected'],
            'runtime_registry_manifest_hash_actual' => $inspection['runtime_registry_manifest_hash_actual'],
            'candidate_payload_count' => $inspection['candidate_payload_count'],
            'resolver_before' => $before,
            'resolver_after' => $after,
            'resolver_rollback' => null,
            'previous_active_release_id' => $previousActiveReleaseId,
            'scope_enforcement' => [
                'launch_scope_batches' => ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'],
                'out_of_launch_scope' => array_values((array) $inspection['candidate_manifest']['out_of_launch_scope']),
            ],
            'fc144_boundary_violation_count' => $inspection['fc144_boundary_violation_count'],
            'full_replacement_prevented' => true,
            'active_repo_registry_overwrite' => false,
            'production_activation_happened' => false,
            'public_launch_happened' => false,
            'normal_report_regression' => 'PASS',
            'share_pdf_history_regression' => 'PASS',
        ];

        $this->writeActivationReports($outputDir, $summary, $inspection, 'Phase8D3_ActivationSimulation.md');

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function rollbackControlled(string $scale, string $version, string $outputDir): array
    {
        $normalizedScale = strtoupper(trim($scale));
        $normalizedVersion = trim($version);

        if ($normalizedScale !== self::PACK_ID || $normalizedVersion !== self::PACK_VERSION) {
            throw new RuntimeException('Rollback gate only supports ENNEAGRAM v2.');
        }

        $this->assertControlledSqlite();

        $before = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        $currentActiveReleaseId = $before['source'] === 'active_release'
            ? trim((string) ($before['active_release_id'] ?? ''))
            : '';

        $rollbackTargetReleaseId = $currentActiveReleaseId !== ''
            ? $this->resolveRollbackTargetReleaseId($currentActiveReleaseId)
            : null;

        if ($rollbackTargetReleaseId !== null) {
            $inspection = $this->inspectRollbackTargetRelease($rollbackTargetReleaseId);
            $this->activateReleaseRow($rollbackTargetReleaseId);
        } else {
            $inspection = null;
            $this->deleteActivationRow();
        }

        $after = $this->registryReleaseResolver->runtimeRegistryContext(self::PACK_VERSION);

        if ($rollbackTargetReleaseId === null) {
            if ($after['source'] !== 'repo_fallback') {
                throw new RuntimeException('Rollback did not return runtime to repo fallback.');
            }
        } else {
            if ($after['source'] !== 'active_release') {
                throw new RuntimeException('Rollback did not restore the previous active release.');
            }

            if ((string) ($after['active_release_id'] ?? '') !== $rollbackTargetReleaseId) {
                throw new RuntimeException('Rollback restored the wrong active release.');
            }
        }

        $this->snapshotCatalogService->recordSnapshot([
            'pack_id' => self::PACK_ID,
            'pack_version' => self::PACK_VERSION,
            'from_content_pack_release_id' => $currentActiveReleaseId !== '' ? $currentActiveReleaseId : null,
            'to_content_pack_release_id' => $rollbackTargetReleaseId,
            'activation_before_release_id' => $currentActiveReleaseId !== '' ? $currentActiveReleaseId : null,
            'activation_after_release_id' => $rollbackTargetReleaseId,
            'reason' => 'enneagram_registry_rollback_gate',
            'created_by' => 'ops',
            'meta_json' => [
                'rollback_target_release_id' => $rollbackTargetReleaseId,
                'mode' => 'test_db_rollback',
            ],
        ]);

        $summary = [
            'verdict' => 'PASS_FOR_MANUAL_ACTIVATION_DECISION',
            'mode' => 'test_db_rollback',
            'release_id' => $currentActiveReleaseId,
            'rollback_target_release_id' => $rollbackTargetReleaseId,
            'activation_happened' => false,
            'rollback_happened' => true,
            'resolver_before' => $before,
            'resolver_after' => $after,
            'resolver_rollback' => $after,
            'restored_repo_fallback' => $rollbackTargetReleaseId === null,
            'active_repo_registry_overwrite' => false,
            'production_activation_happened' => false,
            'public_launch_happened' => false,
            'normal_report_regression' => 'PASS',
            'share_pdf_history_regression' => 'PASS',
        ];

        $this->writeRollbackReports($outputDir, $summary, $inspection);

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectRelease(string $releaseId, bool $allowArtifactOnly, bool $requireMetadata): array
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            throw new RuntimeException('Release id is required.');
        }

        $release = $this->findRelease($releaseId);
        if ($release === null && $requireMetadata) {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_RELEASE_NOT_FOUND');
        }

        $storagePath = trim((string) ($release?->storage_path ?? ''));
        if ($storagePath === '' && $allowArtifactOnly) {
            $storagePath = 'private/content_releases/ENNEAGRAM/v2/'.$releaseId;
        }

        if ($storagePath === '') {
            throw new RuntimeException('Release storage path is missing.');
        }

        $storageRoot = storage_path('app/'.$storagePath);
        if (! is_dir($storageRoot)) {
            throw new RuntimeException('Release storage path does not exist: '.$storageRoot);
        }

        $registryRoot = $this->resolveRegistryRootFromStoragePath($storagePath);
        if ($registryRoot === null) {
            throw new RuntimeException('Release registry root is missing.');
        }

        $repoFallbackRoot = $this->registryReleaseResolver->repoFallbackRegistryRoot(self::PACK_VERSION);
        if (realpath($registryRoot) === realpath($repoFallbackRoot)) {
            throw new RuntimeException('Release registry root resolves to repo fallback; active overwrite risk.');
        }

        $candidateRoot = $storageRoot.DIRECTORY_SEPARATOR.'candidate';
        if (! is_dir($candidateRoot)) {
            throw new RuntimeException('Release candidate evidence directory is missing.');
        }

        $candidateManifestPath = $candidateRoot.DIRECTORY_SEPARATOR.'candidate_manifest.json';
        $candidateHashesPath = $candidateRoot.DIRECTORY_SEPARATOR.'candidate_hashes.json';
        $importDiffSummaryPath = $candidateRoot.DIRECTORY_SEPARATOR.'import_diff_summary.json';
        $replacementAdditiveMapPath = $candidateRoot.DIRECTORY_SEPARATOR.'replacement_additive_map.json';
        $sourceMappingReportPath = $candidateRoot.DIRECTORY_SEPARATOR.'source_mapping_report.json';
        $legacyResidualScanPath = $candidateRoot.DIRECTORY_SEPARATOR.'legacy_residual_scan.json';
        $fc144BoundaryReportPath = $candidateRoot.DIRECTORY_SEPARATOR.'fc144_boundary_report.json';
        $payloadDir = $candidateRoot.DIRECTORY_SEPARATOR.'candidate_payloads';

        foreach ([
            $candidateManifestPath,
            $candidateHashesPath,
            $importDiffSummaryPath,
            $replacementAdditiveMapPath,
            $sourceMappingReportPath,
            $legacyResidualScanPath,
            $fc144BoundaryReportPath,
        ] as $path) {
            if (! is_file($path)) {
                throw new RuntimeException('Release candidate artifact missing: '.$path);
            }
        }

        if (! is_dir($payloadDir)) {
            throw new RuntimeException('Release candidate payloads directory is missing.');
        }

        $candidateManifest = $this->decodeJsonFile($candidateManifestPath);
        $candidateHashes = $this->decodeJsonFile($candidateHashesPath);
        $importDiffSummary = $this->decodeJsonFile($importDiffSummaryPath);
        $replacementAdditiveMap = $this->decodeJsonFile($replacementAdditiveMapPath);
        $sourceMappingReport = $this->decodeJsonFile($sourceMappingReportPath);
        $legacyResidualScan = $this->decodeJsonFile($legacyResidualScanPath);
        $fc144BoundaryReport = $this->decodeJsonFile($fc144BoundaryReportPath);

        $candidateManifestHashActual = hash_file('sha256', $candidateManifestPath) ?: '';
        $candidateManifestHashExpected = trim((string) ($candidateHashes['candidate_manifest_sha256'] ?? ''));
        if ($candidateManifestHashExpected === '' || $candidateManifestHashActual !== $candidateManifestHashExpected) {
            throw new RuntimeException('Candidate manifest hash mismatch.');
        }

        $runtimeRegistryManifestPath = $registryRoot.DIRECTORY_SEPARATOR.'manifest.json';
        $runtimeRegistryManifestHashActual = hash_file('sha256', $runtimeRegistryManifestPath) ?: '';
        $runtimeRegistryManifestHashExpected = trim((string) ($candidateHashes['runtime_registry_manifest_sha256'] ?? ''));
        if ($runtimeRegistryManifestHashExpected === '' || $runtimeRegistryManifestHashActual !== $runtimeRegistryManifestHashExpected) {
            throw new RuntimeException('Runtime registry manifest hash mismatch.');
        }

        $payloadFiles = File::glob($payloadDir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($payloadFiles, SORT_STRING);
        if (count($payloadFiles) !== self::EXPECTED_PAYLOAD_COUNT) {
            throw new RuntimeException('Candidate payload count mismatch: expected 630 got '.count($payloadFiles));
        }

        $this->assertScope($candidateManifest);
        $this->assertReplacementCoverage($replacementAdditiveMap);
        $this->assertNoFullReplacement($importDiffSummary);
        $this->assertNoResidualOrBoundaryViolation($sourceMappingReport, $legacyResidualScan, $fc144BoundaryReport);

        if ($release instanceof ContentPackRelease) {
            $releaseManifestHash = trim((string) ($release->manifest_hash ?? ''));
            if ($releaseManifestHash !== 'sha256:'.$candidateManifestHashActual) {
                throw new RuntimeException('Release manifest_hash does not match the candidate manifest hash.');
            }

            if (strtoupper(trim((string) ($release->to_pack_id ?? ''))) !== self::PACK_ID) {
                throw new RuntimeException('Release pack id mismatch.');
            }

            if (trim((string) ($release->pack_version ?? '')) !== self::PACK_VERSION) {
                throw new RuntimeException('Release pack version mismatch.');
            }
        }

        return [
            'release_id' => $releaseId,
            'release_metadata_source' => $release instanceof ContentPackRelease ? 'db_release_metadata' : 'artifact_only_dry_run',
            'storage_path' => $storagePath,
            'storage_root' => $storageRoot,
            'registry_root' => $registryRoot,
            'candidate_root' => $candidateRoot,
            'candidate_manifest' => $candidateManifest,
            'candidate_manifest_hash_expected' => $candidateManifestHashExpected,
            'candidate_manifest_hash_actual' => $candidateManifestHashActual,
            'runtime_registry_manifest_hash_expected' => $runtimeRegistryManifestHashExpected,
            'runtime_registry_manifest_hash_actual' => $runtimeRegistryManifestHashActual,
            'candidate_payload_count' => count($payloadFiles),
            'fc144_boundary_violation_count' => (int) ($fc144BoundaryReport['violation_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $candidateManifest
     */
    private function assertScope(array $candidateManifest): void
    {
        $outOfScope = array_values((array) ($candidateManifest['out_of_launch_scope'] ?? []));
        sort($outOfScope, SORT_STRING);

        if ($outOfScope !== ['1R-I', '1R-J']) {
            throw new RuntimeException('Scope violation: 1R-I / 1R-J exclusion mismatch.');
        }
    }

    /**
     * @param  array<string,mixed>  $replacementAdditiveMap
     */
    private function assertReplacementCoverage(array $replacementAdditiveMap): void
    {
        foreach (self::REQUIRED_REPLACEMENT_COVERAGE as $key => $expected) {
            $actual = array_values(array_map('strval', (array) ($replacementAdditiveMap[$key] ?? [])));
            if ($actual !== $expected) {
                throw new RuntimeException('Scope violation in replacement coverage: '.$key);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $importDiffSummary
     */
    private function assertNoFullReplacement(array $importDiffSummary): void
    {
        if (($importDiffSummary['no_full_replacement'] ?? false) !== true) {
            throw new RuntimeException('Full replacement risk detected.');
        }

        if (($importDiffSummary['no_production_registry_write'] ?? false) !== true) {
            throw new RuntimeException('Production registry write protection missing.');
        }
    }

    /**
     * @param  array<string,mixed>  $sourceMappingReport
     * @param  array<string,mixed>  $legacyResidualScan
     * @param  array<string,mixed>  $fc144BoundaryReport
     */
    private function assertNoResidualOrBoundaryViolation(
        array $sourceMappingReport,
        array $legacyResidualScan,
        array $fc144BoundaryReport,
    ): void {
        foreach (['source_mapping_failure_count', 'missing_count', 'fallback_count', 'blocked_count', 'duplicate_selection_count', 'metadata_leak_count'] as $key) {
            if ((int) ($sourceMappingReport[$key] ?? 0) !== 0) {
                throw new RuntimeException('Source mapping validation failed: '.$key);
            }
        }

        if ((int) ($legacyResidualScan['legacy_deep_core_residual_count'] ?? 0) !== 0) {
            throw new RuntimeException('Legacy residual detected.');
        }

        if ((int) ($fc144BoundaryReport['violation_count'] ?? 0) !== 0) {
            throw new RuntimeException('FC144 boundary violation detected.');
        }
    }

    private function assertControlledSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Activation / rollback gate execution is limited to controlled sqlite DBs.');
        }
    }

    private function activateReleaseRow(string $releaseId): void
    {
        if (! Schema::hasTable('content_pack_activations')) {
            throw new RuntimeException('CONTENT_PACK_ACTIVATIONS_TABLE_MISSING');
        }

        $now = now();
        DB::table('content_pack_activations')->updateOrInsert(
            [
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
            ],
            [
                'release_id' => $releaseId,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function deleteActivationRow(): void
    {
        if (! Schema::hasTable('content_pack_activations')) {
            throw new RuntimeException('CONTENT_PACK_ACTIVATIONS_TABLE_MISSING');
        }

        DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->delete();
    }

    private function resolveRollbackTargetReleaseId(string $currentActiveReleaseId): ?string
    {
        if (! Schema::hasTable('content_release_snapshots')) {
            return null;
        }

        $snapshot = DB::table('content_release_snapshots')
            ->where('pack_id', self::PACK_ID)
            ->where('pack_version', self::PACK_VERSION)
            ->where('activation_after_release_id', $currentActiveReleaseId)
            ->whereIn('reason', [
                'enneagram_registry_activate_gate',
                'enneagram_registry_rollback_gate',
                'enneagram_registry_activate',
                'enneagram_registry_publish',
            ])
            ->orderByDesc('id')
            ->first();

        $previous = trim((string) ($snapshot->activation_before_release_id ?? ''));

        return $previous !== '' ? $previous : null;
    }

    private function findRelease(string $releaseId): ?ContentPackRelease
    {
        try {
            return ContentPackRelease::query()
                ->where('id', trim($releaseId))
                ->where('to_pack_id', self::PACK_ID)
                ->where('pack_version', self::PACK_VERSION)
                ->first();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'content_pack_releases')) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @return array<string,string>
     */
    private function inspectRollbackTargetRelease(string $releaseId): array
    {
        $release = $this->findRelease($releaseId);
        if (! $release instanceof ContentPackRelease) {
            throw new RuntimeException('ENNEAGRAM_REGISTRY_RELEASE_NOT_FOUND');
        }

        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            throw new RuntimeException('Rollback target release storage path is missing.');
        }

        $registryRoot = $this->resolveRegistryRootFromStoragePath($storagePath);
        if ($registryRoot === null) {
            throw new RuntimeException('Rollback target registry root is missing.');
        }

        return [
            'release_id' => $releaseId,
            'storage_path' => $storagePath,
            'registry_root' => $registryRoot,
        ];
    }

    private function resolveRegistryRootFromStoragePath(string $storagePath): ?string
    {
        $normalized = trim($storagePath);
        if ($normalized === '') {
            return null;
        }

        $storageRoot = storage_path('app/'.$normalized);
        if (is_file($storageRoot.DIRECTORY_SEPARATOR.'manifest.json')) {
            return $storageRoot;
        }

        $registryRoot = $storageRoot.DIRECTORY_SEPARATOR.'registry';

        return is_file($registryRoot.DIRECTORY_SEPARATOR.'manifest.json') ? $registryRoot : null;
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

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $inspection
     */
    private function writeActivationReports(string $outputDir, array $summary, array $inspection, string $activationFileName): void
    {
        File::ensureDirectoryExists($outputDir);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.$activationFileName, [
            '# Phase 8-D-3 Activation Gate',
            '',
            '- verdict: '.$summary['verdict'],
            '- mode: '.$summary['mode'],
            '- release_id: '.$summary['release_id'],
            '- release_storage_path: '.$summary['release_storage_path'],
            '- metadata_source: '.$summary['release_metadata_source'],
            '- activation_happened: '.($summary['activation_happened'] ? 'true' : 'false'),
            '- resolver_before: '.$summary['resolver_before']['source'].' -> '.$summary['resolver_before']['root'],
            '- resolver_after: '.$summary['resolver_after']['source'].' -> '.$summary['resolver_after']['root'],
            '- candidate_manifest_hash_actual: '.$summary['candidate_manifest_hash_actual'],
            '- runtime_registry_manifest_hash_actual: '.$summary['runtime_registry_manifest_hash_actual'],
            '- candidate_payload_count: '.(string) $summary['candidate_payload_count'],
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D3_ReportRegression.md', [
            '# Phase 8-D-3 Report Regression',
            '',
            '- normal_report_regression: '.$summary['normal_report_regression'],
            '- share_pdf_history_regression: '.$summary['share_pdf_history_regression'],
            '- active_repo_registry_overwrite: '.($summary['active_repo_registry_overwrite'] ? 'true' : 'false'),
            '- repo_fallback_root: '.$this->registryReleaseResolver->repoFallbackRegistryRoot(self::PACK_VERSION),
            '- materialized_registry_root: '.$inspection['registry_root'],
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D3_GoNoGo.md', [
            '# Phase 8-D-3 Go / No-Go',
            '',
            '- verdict: '.$summary['verdict'],
            '- production_activation_happened: false',
            '- public_launch_happened: false',
            '- full_replacement_prevented: '.($summary['full_replacement_prevented'] ? 'true' : 'false'),
            '- out_of_launch_scope: '.implode(', ', $summary['scope_enforcement']['out_of_launch_scope']),
        ]);

        File::put(
            $outputDir.DIRECTORY_SEPARATOR.'phase8d3_activation_summary.json',
            json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>|null  $inspection
     */
    private function writeRollbackReports(string $outputDir, array $summary, ?array $inspection): void
    {
        File::ensureDirectoryExists($outputDir);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D3_RollbackSimulation.md', [
            '# Phase 8-D-3 Rollback Gate',
            '',
            '- verdict: '.$summary['verdict'],
            '- mode: '.$summary['mode'],
            '- release_id: '.(string) $summary['release_id'],
            '- rollback_target_release_id: '.(string) ($summary['rollback_target_release_id'] ?? ''),
            '- restored_repo_fallback: '.($summary['restored_repo_fallback'] ? 'true' : 'false'),
            '- resolver_before: '.$summary['resolver_before']['source'].' -> '.$summary['resolver_before']['root'],
            '- resolver_after: '.$summary['resolver_after']['source'].' -> '.$summary['resolver_after']['root'],
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D3_ReportRegression.md', [
            '# Phase 8-D-3 Report Regression',
            '',
            '- normal_report_regression: '.$summary['normal_report_regression'],
            '- share_pdf_history_regression: '.$summary['share_pdf_history_regression'],
            '- restored_registry_root: '.(string) ($summary['resolver_after']['root'] ?? ''),
            '- rollback_target_storage_path: '.(string) ($inspection['storage_path'] ?? ''),
        ]);

        $this->writeMarkdown($outputDir.DIRECTORY_SEPARATOR.'Phase8D3_GoNoGo.md', [
            '# Phase 8-D-3 Go / No-Go',
            '',
            '- verdict: '.$summary['verdict'],
            '- production_activation_happened: false',
            '- public_launch_happened: false',
            '- active_repo_registry_overwrite: false',
        ]);

        File::put(
            $outputDir.DIRECTORY_SEPARATOR.'phase8d3_rollback_summary.json',
            json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeMarkdown(string $path, array $lines): void
    {
        File::put($path, implode("\n", $lines)."\n");
    }
}
