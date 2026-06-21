<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Candidate;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BigFiveProductionEquivalentCandidatePayloadExporter
{
    /**
     * @return array<string,mixed>
     */
    public function export(string $candidateDir, string $outputDir): array
    {
        $candidateDir = rtrim(trim($candidateDir), DIRECTORY_SEPARATOR);
        $outputDir = rtrim(trim($outputDir), DIRECTORY_SEPARATOR);
        if ($candidateDir === '') {
            throw new RuntimeException('Candidate directory is required.');
        }
        if ($outputDir === '') {
            throw new RuntimeException('Output directory is required.');
        }

        $sourceAssetsPath = base_path(BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH);
        $sourceManifestPath = base_path(BigFiveCandidatePackageContract::SOURCE_MANIFEST_RELATIVE_PATH);
        $sourceAssetsSha = hash_file('sha256', $sourceAssetsPath) ?: '';
        $sourceManifest = $this->decodeJsonFile($sourceManifestPath);
        if ((int) ($sourceManifest['asset_count'] ?? -1) !== BigFiveCandidatePackageContract::EXPECTED_SOURCE_ASSET_COUNT) {
            throw new RuntimeException('Big Five selector asset manifest count mismatch.');
        }
        if ((string) ($sourceManifest['sha256_json'] ?? '') !== $sourceAssetsSha) {
            throw new RuntimeException('Big Five selector asset manifest sha256_json mismatch.');
        }

        $assets = $this->decodeJsonFile($sourceAssetsPath);
        if (! array_is_list($assets) || count($assets) !== BigFiveCandidatePackageContract::EXPECTED_SOURCE_ASSET_COUNT) {
            throw new RuntimeException('Big Five selector asset count mismatch.');
        }

        $this->resetDirectory($candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads');
        $this->ensureDirectory($outputDir);

        $seenAssetKeys = [];
        $payloadFiles = [];
        $sourceMappingRows = [];
        $metadataLeakHits = [];
        $forbiddenClaimHits = [];
        $coverage = [
            'registry_counts' => [],
            'module_counts' => [],
            'safety_level_counts' => [],
            'shareable_counts' => ['true' => 0, 'false' => 0],
            'scope_counts' => [],
        ];

        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                throw new RuntimeException("Selector asset {$index} must be an object.");
            }

            $this->assertSelectorAssetShape($asset, $index);

            $assetKey = (string) $asset['asset_key'];
            if (isset($seenAssetKeys[$assetKey])) {
                throw new RuntimeException('Duplicate selector asset key: '.$assetKey);
            }
            $seenAssetKeys[$assetKey] = true;

            $publicPayload = $asset['public_payload'];
            if (! is_array($publicPayload)) {
                throw new RuntimeException('Selector asset public_payload must be an object: '.$assetKey);
            }

            $metadataLeakHits = array_merge($metadataLeakHits, $this->metadataLeakHits($publicPayload, $assetKey));
            $forbiddenClaimHits = array_merge($forbiddenClaimHits, $this->forbiddenClaimHits($publicPayload, $assetKey));

            $payload = [
                'schema_version' => BigFiveCandidatePackageContract::PAYLOAD_SCHEMA_VERSION,
                'scale_code' => BigFiveCandidatePackageContract::PACK_ID,
                'source_asset_key' => $assetKey,
                'source_assets_sha256' => $sourceAssetsSha,
                'registry_key' => (string) $asset['registry_key'],
                'module_key' => (string) $asset['module_key'],
                'block_key' => (string) $asset['block_key'],
                'block_kind' => (string) $asset['block_kind'],
                'slot_key' => (string) $asset['slot_key'],
                'scope' => (string) $asset['scope'],
                'safety_level' => (string) $asset['safety_level'],
                'evidence_level' => (string) $asset['evidence_level'],
                'shareable' => (bool) $asset['shareable'],
                'fallback_policy' => (string) $asset['fallback_policy'],
                'content_source' => 'selector_ready_asset',
                'runtime_use' => 'staging_only',
                'production_use_allowed' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
                'public_payload' => $publicPayload,
            ];

            $fileName = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT).'_'.$this->safeFileName($assetKey).'.json';
            $filePath = $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads'.DIRECTORY_SEPARATOR.$fileName;
            File::put($filePath, $this->encodeJson($payload));
            $payloadFiles[$fileName] = $filePath;

            $sourceMappingRows[$assetKey] = [
                'payload_file' => $fileName,
                'source_asset_key' => $assetKey,
                'source_assets_sha256' => $sourceAssetsSha,
                'mapped' => true,
            ];

            $this->increment($coverage['registry_counts'], (string) $asset['registry_key']);
            $this->increment($coverage['module_counts'], (string) $asset['module_key']);
            $this->increment($coverage['safety_level_counts'], (string) $asset['safety_level']);
            $this->increment($coverage['scope_counts'], (string) $asset['scope']);
            $coverage['shareable_counts'][(bool) $asset['shareable'] ? 'true' : 'false']++;
        }

        if ($metadataLeakHits !== []) {
            throw new RuntimeException('Big Five candidate metadata leakage detected: '.count($metadataLeakHits));
        }
        if ($forbiddenClaimHits !== []) {
            throw new RuntimeException('Big Five candidate forbidden claim detected: '.count($forbiddenClaimHits));
        }

        ksort($payloadFiles);
        ksort($sourceMappingRows);
        $payloadFileHashes = [];
        foreach ($payloadFiles as $fileName => $path) {
            $payloadFileHashes[$fileName] = hash_file('sha256', $path) ?: '';
        }

        $candidateManifest = [
            'schema_version' => BigFiveCandidatePackageContract::MANIFEST_SCHEMA_VERSION,
            'mode' => 'dry_run_candidate_only',
            'scale_code' => BigFiveCandidatePackageContract::PACK_ID,
            'pack_version' => BigFiveCandidatePackageContract::PACK_VERSION,
            'source_assets' => [
                'selector_ready_assets' => BigFiveCandidatePackageContract::SOURCE_ASSETS_RELATIVE_PATH,
                'selector_ready_assets_sha256' => $sourceAssetsSha,
                'selector_ready_assets_count' => count($assets),
                'selector_ready_manifest' => BigFiveCandidatePackageContract::SOURCE_MANIFEST_RELATIVE_PATH,
            ],
            'payload_count' => count($payloadFiles),
            'coverage' => $coverage,
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'activation_happened' => false,
            'production_import_happened' => false,
            'full_replacement_happened' => false,
        ];
        File::put($candidateDir.DIRECTORY_SEPARATOR.'candidate_manifest.json', $this->encodeJson($candidateManifest));
        $candidateManifestSha = hash_file('sha256', $candidateDir.DIRECTORY_SEPARATOR.'candidate_manifest.json') ?: '';

        $candidateHashes = [
            'candidate_manifest_sha256' => $candidateManifestSha,
            'source_assets_sha256' => $sourceAssetsSha,
            'payload_file_sha256' => $payloadFileHashes,
        ];
        $payloadsManifest = [
            'schema_version' => BigFiveCandidatePackageContract::PAYLOADS_MANIFEST_SCHEMA_VERSION,
            'payload_dir' => $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads',
            'payload_count' => count($payloadFiles),
            'source_assets_sha256' => $sourceAssetsSha,
            'payload_files' => array_keys($payloadFiles),
        ];
        $sourceMappingReport = [
            'source_mapping_failure_count' => 0,
            'missing_count' => 0,
            'fallback_count' => 0,
            'blocked_count' => 0,
            'duplicate_selection_count' => 0,
            'mapped_count' => count($sourceMappingRows),
            'source_assets_sha256' => $sourceAssetsSha,
        ];
        $payloadSourceMapping = [
            'source_mapping_failure_count' => 0,
            'rows' => array_values($sourceMappingRows),
        ];
        $metadataLeakageReport = [
            'metadata_leak_count' => 0,
            'hits' => [],
        ];
        $forbiddenClaimReport = [
            'forbidden_claim_count' => 0,
            'hits' => [],
        ];

        File::put($candidateDir.DIRECTORY_SEPARATOR.'candidate_hashes.json', $this->encodeJson($candidateHashes));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads_manifest.json', $this->encodeJson($payloadsManifest));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'candidate_payload_hashes.json', $this->encodeJson([
            'candidate_payloads_manifest_sha256' => hash('sha256', $this->encodeJson($payloadsManifest)),
            'payload_file_sha256' => $payloadFileHashes,
        ]));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'candidate_payload_source_mapping.json', $this->encodeJson($payloadSourceMapping));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'source_mapping_report.json', $this->encodeJson($sourceMappingReport));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'metadata_leakage_report.json', $this->encodeJson($metadataLeakageReport));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'forbidden_claim_report.json', $this->encodeJson($forbiddenClaimReport));
        File::put($candidateDir.DIRECTORY_SEPARATOR.'rollback_plan.md', "# Big Five Result Page V2 Candidate Rollback\n\nNo activation is performed. Delete or supersede the inactive candidate release before runtime activation.\n");

        $summary = [
            'verdict' => 'PASS_FOR_BIG5_RESULT_PAGE_V2_INACTIVE_IMPORT_DRY_RUN',
            'candidate_source_directory' => $candidateDir,
            'candidate_payload_output_directory' => $candidateDir.DIRECTORY_SEPARATOR.'candidate_payloads',
            'candidate_manifest_sha256' => $candidateManifestSha,
            'source_assets_sha256' => $sourceAssetsSha,
            'payload_count' => count($payloadFiles),
            'coverage' => $coverage,
            'source_mapping_result' => $sourceMappingReport,
            'metadata_leakage_result' => $metadataLeakageReport,
            'forbidden_claim_result' => $forbiddenClaimReport,
            'activation_happened' => false,
            'production_import_happened' => false,
            'full_replacement_happened' => false,
        ];

        File::put($outputDir.DIRECTORY_SEPARATOR.'big5_candidate_export_summary.json', $this->encodeJson($summary));
        File::put($outputDir.DIRECTORY_SEPARATOR.'BigFiveCandidatePayloadCoverage.md', $this->markdown([
            '# Big Five Result Page V2 Candidate Payload Coverage',
            '- verdict: '.$summary['verdict'],
            '- payload_count: '.count($payloadFiles),
            '- source_assets_sha256: `'.$sourceAssetsSha.'`',
            '- candidate_manifest_sha256: `'.$candidateManifestSha.'`',
            '- activation_happened: false',
            '- production_import_happened: false',
        ]));

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function assertSelectorAssetShape(array $asset, int $index): void
    {
        foreach (BigFiveResultPageV2SelectorAssetContract::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $asset)) {
                throw new RuntimeException("Selector asset {$index} missing required field {$field}.");
            }
        }

        if ((string) ($asset['version'] ?? '') !== BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION) {
            throw new RuntimeException("Selector asset {$index} has invalid version.");
        }

        foreach (['asset_key', 'registry_key', 'module_key', 'block_key', 'block_kind', 'slot_key'] as $field) {
            if (trim((string) ($asset[$field] ?? '')) === '') {
                throw new RuntimeException("Selector asset {$index} field {$field} must not be empty.");
            }
        }

        if (! is_array($asset['public_payload'] ?? null)) {
            throw new RuntimeException("Selector asset {$index} public_payload must be an object.");
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array{asset_key:string,path:string,key:string}>
     */
    private function metadataLeakHits(array $payload, string $assetKey, string $path = 'public_payload'): array
    {
        $hits = [];
        foreach ($payload as $key => $value) {
            $currentPath = $path.'.'.(string) $key;
            if (is_string($key) && in_array($key, BigFiveCandidatePackageContract::PUBLIC_PAYLOAD_FORBIDDEN_KEYS, true)) {
                $hits[] = ['asset_key' => $assetKey, 'path' => $currentPath, 'key' => $key];
            }
            if (is_array($value)) {
                $hits = array_merge($hits, $this->metadataLeakHits($value, $assetKey, $currentPath));
            }
        }

        return $hits;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array{asset_key:string,pattern:string}>
     */
    private function forbiddenClaimHits(array $payload, string $assetKey): array
    {
        $text = $this->encodeJson($payload);
        $hits = [];
        foreach (BigFiveCandidatePackageContract::FORBIDDEN_CLAIM_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) !== 1) {
                continue;
            }
            if ($this->containsAllowedBoundaryFragment($text)) {
                continue;
            }
            $hits[] = ['asset_key' => $assetKey, 'pattern' => $pattern];
        }

        return $hits;
    }

    private function containsAllowedBoundaryFragment(string $text): bool
    {
        foreach (BigFiveCandidatePackageContract::ALLOWED_BOUNDARY_FRAGMENTS as $fragment) {
            if (stripos($text, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('JSON file does not exist: '.$path);
        }

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
     * @param  array<string,int>  $counts
     */
    private function increment(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        ksort($counts);
    }

    private function safeFileName(string $assetKey): string
    {
        $safe = strtolower((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $assetKey));

        return substr(trim($safe, '_'), 0, 160);
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @param  list<string>  $lines
     */
    private function markdown(array $lines): string
    {
        return implode("\n", $lines)."\n";
    }
}
