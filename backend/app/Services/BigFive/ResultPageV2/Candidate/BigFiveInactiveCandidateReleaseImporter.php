<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Candidate;

use App\Models\ContentPackRelease;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class BigFiveInactiveCandidateReleaseImporter
{
    public function __construct(
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

        $this->ensureDirectory($outputDir);
        $this->assertRequiredCandidateArtifacts($candidateDir);

        $candidateManifestPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_manifest.json';
        $candidateHashesPath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_hashes.json';
        $payloadDir = $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads';

        $candidateManifest = $this->decodeJsonFile($candidateManifestPath);
        $candidateHashes = $this->decodeJsonFile($candidateHashesPath);
        $payloadsManifest = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads_manifest.json');
        $payloadHashes = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'candidate_payload_hashes.json');
        $sourceMappingReport = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'source_mapping_report.json');
        $metadataLeakageReport = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'metadata_leakage_report.json');
        $forbiddenClaimReport = $this->decodeJsonFile($candidateDir.DIRECTORY_SEPARATOR.'forbidden_claim_report.json');

        $candidateManifestHashActual = hash_file('sha256', $candidateManifestPath) ?: '';
        $expectedCandidateManifestHash = trim((string) ($contracts['candidate_manifest_sha256'] ?? $candidateHashes['candidate_manifest_sha256'] ?? ''));
        if ($expectedCandidateManifestHash !== '' && $candidateManifestHashActual !== $expectedCandidateManifestHash) {
            throw new RuntimeException('Candidate manifest hash mismatch: '.$candidateManifestHashActual);
        }
        if ((string) ($candidateHashes['candidate_manifest_sha256'] ?? '') !== $candidateManifestHashActual) {
            throw new RuntimeException('candidate_hashes.json candidate_manifest_sha256 mismatch.');
        }

        $sourceAssetsPath = base_path(BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH);
        $sourceAssetsShaActual = hash_file('sha256', $sourceAssetsPath) ?: '';
        $expectedSourceAssetsSha = trim((string) ($contracts['source_assets_sha256'] ?? $candidateHashes['source_assets_sha256'] ?? ''));
        if ($expectedSourceAssetsSha !== '' && $sourceAssetsShaActual !== $expectedSourceAssetsSha) {
            throw new RuntimeException('Source assets hash mismatch: '.$sourceAssetsShaActual);
        }
        if ((string) ($candidateHashes['source_assets_sha256'] ?? '') !== $sourceAssetsShaActual) {
            throw new RuntimeException('candidate_hashes.json source_assets_sha256 mismatch.');
        }

        $payloadFiles = File::glob($payloadDir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        sort($payloadFiles, SORT_STRING);
        if (count($payloadFiles) !== BigFiveCandidatePackageContract::EXPECTED_SOURCE_ASSET_COUNT) {
            throw new RuntimeException('candidate_payloads count mismatch: expected 325 got '.count($payloadFiles));
        }
        if ((int) ($candidateManifest['payload_count'] ?? -1) !== BigFiveCandidatePackageContract::EXPECTED_SOURCE_ASSET_COUNT
            || (int) ($payloadsManifest['payload_count'] ?? -1) !== BigFiveCandidatePackageContract::EXPECTED_SOURCE_ASSET_COUNT) {
            throw new RuntimeException('Candidate payload count metadata mismatch.');
        }

        $recordedPayloadHashes = (array) ($payloadHashes['payload_file_sha256'] ?? []);
        foreach ($payloadFiles as $payloadFile) {
            $fileName = basename($payloadFile);
            if (($recordedPayloadHashes[$fileName] ?? null) !== (hash_file('sha256', $payloadFile) ?: '')) {
                throw new RuntimeException('candidate_payload_hashes.json mismatch for '.$fileName);
            }
        }

        $this->assertGovernance($candidateManifest, $sourceMappingReport, $metadataLeakageReport, $forbiddenClaimReport);

        $runtimeSourceHashBefore = hash_file('sha256', $sourceAssetsPath) ?: '';
        $releaseId = $this->deterministicReleaseId($candidateManifestHashActual);
        $storagePath = 'private/content_releases/BIG5_OCEAN/result_page_v2/'.$releaseId;
        $storageRoot = storage_path('app/'.$storagePath);
        $this->resetDirectory($storageRoot);
        $this->copyCandidateArtifacts($candidateDir, $storageRoot);

        $releaseManifestHash = 'sha256:'.$candidateManifestHashActual;
        $releasePayload = [
            'schema_version' => BigFiveCandidatePackageContract::INACTIVE_RELEASE_SCHEMA_VERSION,
            'release_kind' => 'inactive_candidate',
            'release_id' => $releaseId,
            'activation_state' => 'inactive',
            'candidate_source_directory' => $candidateDir,
            'candidate_manifest_sha256' => $candidateManifestHashActual,
            'source_assets_sha256' => $sourceAssetsShaActual,
            'candidate_payload_count' => count($payloadFiles),
            'storage_layout' => [
                'candidate_root' => $storagePath.'/candidate',
            ],
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
        ];

        $now = now();
        $release = ContentPackRelease::query()->updateOrCreate(
            ['id' => $releaseId],
            [
                'action' => BigFiveCandidatePackageContract::RELEASE_ACTION,
                'region' => 'GLOBAL',
                'locale' => 'global',
                'dir_alias' => BigFiveCandidatePackageContract::PACK_VERSION,
                'from_version_id' => null,
                'to_version_id' => null,
                'from_pack_id' => null,
                'to_pack_id' => BigFiveCandidatePackageContract::PACK_ID,
                'status' => 'success',
                'message' => 'Inactive BIG5 result page V2 candidate materialized without activation',
                'created_by' => 'ops',
                'manifest_hash' => $releaseManifestHash,
                'compiled_hash' => 'sha256:'.$sourceAssetsShaActual,
                'content_hash' => $releaseManifestHash,
                'pack_version' => BigFiveCandidatePackageContract::PACK_VERSION,
                'manifest_json' => $releasePayload,
                'storage_path' => $storagePath,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->manifestCatalogService->upsertManifest([
            'content_pack_release_id' => (string) $release->getKey(),
            'manifest_hash' => $releaseManifestHash,
            'schema_version' => BigFiveCandidatePackageContract::INACTIVE_RELEASE_SCHEMA_VERSION,
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'pack_id' => BigFiveCandidatePackageContract::PACK_ID,
            'pack_version' => BigFiveCandidatePackageContract::PACK_VERSION,
            'compiled_hash' => 'sha256:'.$sourceAssetsShaActual,
            'content_hash' => $releaseManifestHash,
            'payload_json' => $releasePayload,
        ]);

        if (DB::table('content_pack_activations')
            ->where('pack_id', BigFiveCandidatePackageContract::PACK_ID)
            ->where('pack_version', BigFiveCandidatePackageContract::PACK_VERSION)
            ->where('release_id', $releaseId)
            ->exists()) {
            throw new RuntimeException('Inactive import unexpectedly created activation row.');
        }

        $runtimeSourceHashAfter = hash_file('sha256', $sourceAssetsPath) ?: '';
        if ($runtimeSourceHashAfter !== $runtimeSourceHashBefore) {
            throw new RuntimeException('Inactive import changed Big Five selector source assets.');
        }

        $summary = [
            'verdict' => 'PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_GATE',
            'candidate_source_directory' => $candidateDir,
            'inactive_release_id' => $releaseId,
            'inactive_release_storage_path' => $storagePath,
            'candidate_manifest_hash_expected' => $expectedCandidateManifestHash ?: $candidateManifestHashActual,
            'candidate_manifest_hash_actual' => $candidateManifestHashActual,
            'source_assets_hash_expected' => $expectedSourceAssetsSha ?: $sourceAssetsShaActual,
            'source_assets_hash_actual' => $sourceAssetsShaActual,
            'candidate_payload_count' => count($payloadFiles),
            'release_metadata_result' => [
                'content_pack_release_id' => (string) $release->getKey(),
                'content_pack_release_action' => BigFiveCandidatePackageContract::RELEASE_ACTION,
                'content_release_manifest_hash' => $releaseManifestHash,
                'inactive' => true,
            ],
            'runtime_default_source_result' => [
                'preserved' => true,
                'source_assets_hash_before' => $runtimeSourceHashBefore,
                'source_assets_hash_after' => $runtimeSourceHashAfter,
            ],
            'activation_happened' => false,
            'production_import_happened' => false,
            'full_replacement_happened' => false,
        ];

        File::put($outputDir.DIRECTORY_SEPARATOR.'big5_inactive_import_summary.json', $this->encodeJson($summary));
        File::put($outputDir.DIRECTORY_SEPARATOR.'BigFiveInactiveImport.md', implode("\n", [
            '# Big Five Result Page V2 Inactive Import',
            '- verdict: '.$summary['verdict'],
            '- inactive_release_id: '.$releaseId,
            '- inactive_release_storage_path: `'.$storagePath.'`',
            '- activation_happened: false',
            '- production_import_happened: false',
        ])."\n");

        return $summary;
    }

    private function assertRequiredCandidateArtifacts(string $candidateDir): void
    {
        foreach (BigFiveCandidatePackageContract::REQUIRED_CANDIDATE_FILES as $file) {
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
     * @param  array<string,mixed>  $sourceMappingReport
     * @param  array<string,mixed>  $metadataLeakageReport
     * @param  array<string,mixed>  $forbiddenClaimReport
     */
    private function assertGovernance(
        array $candidateManifest,
        array $sourceMappingReport,
        array $metadataLeakageReport,
        array $forbiddenClaimReport,
    ): void {
        if ((string) ($candidateManifest['schema_version'] ?? '') !== BigFiveCandidatePackageContract::MANIFEST_SCHEMA_VERSION) {
            throw new RuntimeException('Candidate manifest schema_version mismatch.');
        }
        foreach (['production_use_allowed', 'ready_for_runtime', 'ready_for_production', 'activation_happened', 'production_import_happened', 'full_replacement_happened'] as $flag) {
            if ((bool) ($candidateManifest[$flag] ?? true)) {
                throw new RuntimeException('Candidate manifest flag must be false: '.$flag);
            }
        }
        if ((string) ($candidateManifest['runtime_use'] ?? '') !== 'staging_only') {
            throw new RuntimeException('Candidate manifest runtime_use must be staging_only.');
        }
        if ((int) ($sourceMappingReport['source_mapping_failure_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['missing_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['fallback_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['blocked_count'] ?? -1) !== 0
            || (int) ($sourceMappingReport['duplicate_selection_count'] ?? -1) !== 0) {
            throw new RuntimeException('Source mapping report contains failures.');
        }
        if ((int) ($metadataLeakageReport['metadata_leak_count'] ?? -1) !== 0) {
            throw new RuntimeException('Metadata leakage report is not clean.');
        }
        if ((int) ($forbiddenClaimReport['forbidden_claim_count'] ?? -1) !== 0) {
            throw new RuntimeException('Forbidden claim report is not clean.');
        }
    }

    private function deterministicReleaseId(string $candidateManifestHashActual): string
    {
        return 'big5_result_page_v2_candidate_'.Str::lower(substr($candidateManifestHashActual, 0, 16));
    }

    private function copyCandidateArtifacts(string $candidateDir, string $storageRoot): void
    {
        $candidateRoot = $storageRoot.DIRECTORY_SEPARATOR.'candidate';
        $this->resetDirectory($candidateRoot);

        foreach (BigFiveCandidatePackageContract::REQUIRED_CANDIDATE_FILES as $file) {
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

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
