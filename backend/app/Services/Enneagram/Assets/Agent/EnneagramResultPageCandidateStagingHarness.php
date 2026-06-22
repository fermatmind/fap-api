<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets\Agent;

use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use App\Services\Enneagram\Assets\EnneagramProductionEquivalentCandidatePayloadExporter;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class EnneagramResultPageCandidateStagingHarness
{
    public const SCHEMA_VERSION = 'fap.enneagram.result_page.candidate_staging_harness.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/enneagram_result_page_candidate_staging_harness';

    public const DEFAULT_CONTRACT_RELATIVE_PATH = 'content_assets/enneagram/result_page/candidate_staging_harness/candidate_staging_harness_contract_v0_1.json';

    private const DEFAULT_EXPECTED_CANDIDATE_MANIFEST_SHA256 = 'a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0';

    private const DEFAULT_EXPECTED_RUNTIME_REGISTRY_SHA256 = 'ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f';

    private const EXPECTED_PAYLOAD_COUNT = 630;

    private const LAUNCH_SCOPE = ['1R-A', '1R-B', '1R-C', '1R-D', '1R-E', '1R-F', '1R-G', '1R-H'];

    private const OUT_OF_LAUNCH_SCOPE = ['1R-I', '1R-J'];

    public function __construct(
        private readonly EnneagramProductionEquivalentCandidatePayloadExporter $exporter,
        private readonly EnneagramInactiveCandidateReleaseImporter $importer,
    ) {}

    /**
     * @param  array{
     *     run_id?:string,
     *     artifact_dir?:string,
     *     contract_path?:string,
     *     candidate_dir?:string,
     *     output_dir?:string,
     *     expected_candidate_manifest_sha256?:string,
     *     expected_runtime_registry_sha256?:string,
     *     run_export?:bool,
     *     run_staging_import?:bool,
     *     strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $runId = $this->sanitizeSlug((string) ($options['run_id'] ?? 'candidate-staging-harness'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contractPath = $this->contractPath((string) ($options['contract_path'] ?? ''));
        $candidateDir = rtrim(trim((string) ($options['candidate_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $outputDir = rtrim(trim((string) ($options['output_dir'] ?? '')), DIRECTORY_SEPARATOR);
        $expectedCandidateHash = trim((string) ($options['expected_candidate_manifest_sha256'] ?? self::DEFAULT_EXPECTED_CANDIDATE_MANIFEST_SHA256));
        $expectedRuntimeHash = trim((string) ($options['expected_runtime_registry_sha256'] ?? self::DEFAULT_EXPECTED_RUNTIME_REGISTRY_SHA256));
        $runExport = ($options['run_export'] ?? false) === true;
        $runStagingImport = ($options['run_staging_import'] ?? false) === true;
        $strict = ($options['strict'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $contract = $this->readJson($contractPath, 'Candidate staging harness contract');
        $contractErrors = $this->contractErrors($contract);
        $candidateReport = $this->candidateReport($candidateDir, $expectedCandidateHash, $expectedRuntimeHash);

        $exportSummary = null;
        $stagingImportSummary = null;
        $executionErrors = [];

        if ($runExport) {
            try {
                $exportSummary = $this->exporter->export($candidateDir, $outputDir);
            } catch (RuntimeException $exception) {
                $executionErrors[] = 'export_failed:'.$exception->getMessage();
            }
        }

        if ($runStagingImport) {
            try {
                $stagingImportSummary = $this->importer->import($candidateDir, $outputDir, [
                    'candidate_manifest_sha256' => $expectedCandidateHash,
                    'runtime_registry_manifest_sha256' => $expectedRuntimeHash,
                ]);
            } catch (RuntimeException $exception) {
                $executionErrors[] = 'staging_import_failed:'.$exception->getMessage();
            }
        }

        $errors = array_values(array_unique(array_merge(
            $contractErrors,
            (array) ($candidateReport['errors'] ?? []),
            $executionErrors
        )));

        $report = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'candidate_export_staging_import_harness',
            'run_id' => $runId,
            'contract' => [
                'relative_path' => $this->relativePath($contractPath),
                'sha256' => hash_file('sha256', $contractPath) ?: '',
                'valid' => $contractErrors === [],
                'errors' => $contractErrors,
            ],
            'candidate_dir' => [
                'provided' => $candidateDir !== '',
                'path' => $candidateDir === '' ? null : $this->redactPath($candidateDir),
            ],
            'candidate_contract' => $candidateReport,
            'execution' => [
                'run_export' => $runExport,
                'run_staging_import' => $runStagingImport,
                'export_summary' => $exportSummary,
                'staging_import_summary' => $stagingImportSummary,
                'activation_allowed' => false,
                'production_import_allowed' => false,
                'runtime_switch_allowed' => false,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
            'error_count' => count($errors),
            'errors' => $errors,
        ];

        $artifacts = [
            'candidate_export_staging_import_report.json' => $this->writeJson($artifactDir.'/candidate_export_staging_import_report.json', $report),
        ];
        if (is_array($stagingImportSummary)) {
            $artifacts['staging_import_summary.json'] = $this->writeJson($artifactDir.'/staging_import_summary.json', $stagingImportSummary);
        }
        if (is_array($exportSummary)) {
            $artifacts['candidate_export_summary.json'] = $this->writeJson($artifactDir.'/candidate_export_summary.json', $exportSummary);
        }

        $ok = $errors === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $this->redactPath($artifactDir),
            'artifacts' => $artifacts,
            'strict' => $strict,
            'summary' => [
                'candidate_contract_valid' => (bool) ($candidateReport['valid'] ?? false),
                'candidate_payload_count' => (int) ($candidateReport['payload_count'] ?? 0),
                'run_export' => $runExport,
                'run_staging_import' => $runStagingImport,
                'inactive_release_id' => is_array($stagingImportSummary) ? (string) ($stagingImportSummary['inactive_release_id'] ?? '') : null,
                'activation_allowed' => false,
                'production_import_allowed' => false,
                'production_execution_allowed_for_agent' => false,
            ],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function candidateReport(string $candidateDir, string $expectedCandidateHash, string $expectedRuntimeHash): array
    {
        $errors = [];
        if ($candidateDir === '' || ! is_dir($candidateDir)) {
            return [
                'valid' => false,
                'payload_count' => 0,
                'errors' => ['candidate_dir_missing'],
            ];
        }

        $manifestPath = $candidateDir.'/candidate_manifest.json';
        $hashesPath = $candidateDir.'/candidate_hashes.json';
        $payloadDir = $candidateDir.'/candidate_payloads';
        $manifest = is_file($manifestPath) ? $this->readJson($manifestPath, 'Candidate manifest') : null;
        $hashes = is_file($hashesPath) ? $this->readJson($hashesPath, 'Candidate hashes') : null;

        if (! is_array($manifest)) {
            $errors[] = 'candidate_manifest_missing';
        }
        if (! is_array($hashes)) {
            $errors[] = 'candidate_hashes_missing';
        }

        $actualManifestHash = is_file($manifestPath) ? (hash_file('sha256', $manifestPath) ?: '') : '';
        if ($actualManifestHash !== $expectedCandidateHash) {
            $errors[] = 'candidate_manifest_hash_mismatch';
        }
        if ((string) data_get($hashes, 'candidate_manifest_sha256') !== $expectedCandidateHash) {
            $errors[] = 'candidate_hashes_manifest_hash_mismatch';
        }
        if ((string) data_get($hashes, 'runtime_registry_manifest_sha256') !== $expectedRuntimeHash) {
            $errors[] = 'runtime_registry_hash_mismatch';
        }

        $payloadFiles = is_dir($payloadDir) ? (File::glob($payloadDir.'/*.json') ?: []) : [];
        if (count($payloadFiles) !== self::EXPECTED_PAYLOAD_COUNT) {
            $errors[] = 'candidate_payload_count_mismatch';
        }

        $launchScope = array_values((array) data_get($manifest, 'launch_scope', []));
        if ($launchScope === []) {
            $launchScope = array_keys((array) data_get($manifest, 'candidate_items_by_batch', []));
        }
        sort($launchScope);
        $expectedLaunch = self::LAUNCH_SCOPE;
        sort($expectedLaunch);
        if ($launchScope !== $expectedLaunch) {
            $errors[] = 'launch_scope_mismatch';
        }

        $outOfLaunchScope = array_values((array) data_get($manifest, 'out_of_launch_scope', []));
        sort($outOfLaunchScope);
        $expectedOut = self::OUT_OF_LAUNCH_SCOPE;
        sort($expectedOut);
        if ($outOfLaunchScope !== $expectedOut) {
            $errors[] = 'out_of_launch_scope_mismatch';
        }

        foreach ([
            'source_mapping_report.json' => ['source_mapping_failure_count', 'missing_count', 'fallback_count', 'blocked_count', 'duplicate_selection_count', 'metadata_leak_count'],
            'legacy_residual_scan.json' => ['legacy_deep_core_residual_count', 'legacy_residual_count', 'residual_count'],
            'fc144_boundary_report.json' => ['violation_count', 'fc144_boundary_violation_count'],
            'forbidden_claim_report.json' => ['violation_count', 'forbidden_claim_violation_count', 'failure_count'],
        ] as $file => $counters) {
            $path = $candidateDir.'/'.$file;
            if (! is_file($path) && $file === 'forbidden_claim_report.json') {
                continue;
            }
            if (! is_file($path)) {
                $errors[] = 'candidate_report_missing:'.$file;
                continue;
            }
            $report = $this->readJson($path, $file);
            foreach ($counters as $counter) {
                $value = data_get($report, $counter);
                if ($value !== null && (int) $value !== 0) {
                    $errors[] = $counter.'_nonzero';
                }
            }
        }

        return [
            'valid' => $errors === [],
            'expected_candidate_manifest_sha256' => $expectedCandidateHash,
            'actual_candidate_manifest_sha256' => $actualManifestHash,
            'expected_runtime_registry_sha256' => $expectedRuntimeHash,
            'recorded_runtime_registry_sha256' => (string) data_get($hashes, 'runtime_registry_manifest_sha256', ''),
            'expected_payload_count' => self::EXPECTED_PAYLOAD_COUNT,
            'payload_count' => count($payloadFiles),
            'launch_scope' => self::LAUNCH_SCOPE,
            'actual_launch_scope' => $launchScope,
            'out_of_launch_scope' => $outOfLaunchScope,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return list<string>
     */
    private function contractErrors(array $contract): array
    {
        $errors = [];
        if (($contract['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version_mismatch';
        }
        if (($contract['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'runtime_use_must_be_not_runtime';
        }
        if (($contract['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'production_use_allowed_must_be_false';
        }
        if ((int) ($contract['expected_payload_count'] ?? 0) !== self::EXPECTED_PAYLOAD_COUNT) {
            $errors[] = 'expected_payload_count_mismatch';
        }
        if (data_get($contract, 'staging_import_policy.inactive_import_allowed') !== true) {
            $errors[] = 'inactive_import_must_be_allowed';
        }
        if (data_get($contract, 'staging_import_policy.activation_allowed') !== false) {
            $errors[] = 'activation_allowed_must_be_false';
        }
        if (data_get($contract, 'staging_import_policy.production_import_allowed') !== false) {
            $errors[] = 'production_import_allowed_must_be_false';
        }

        foreach ($this->negativeGuarantees() as $key => $expected) {
            if (data_get($contract, 'negative_guarantees.'.$key) !== $expected) {
                $errors[] = 'negative_guarantee_mismatch:'.$key;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'bulk_content_generation_happened' => false,
            'production_import_happened' => false,
            'production_activation_happened' => false,
            'runtime_switch_happened' => false,
            'production_write_happened' => false,
            'frontend_change_happened' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $label): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException($label.' is not valid JSON: '.$path);
        }

        return $decoded;
    }

    private function contractPath(string $path): string
    {
        return $path !== '' ? $path : base_path(self::DEFAULT_CONTRACT_RELATIVE_PATH);
    }

    private function artifactDir(string $root, string $runId): string
    {
        $artifactRoot = $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return $artifactRoot.DIRECTORY_SEPARATOR.$runId;
    }

    private function sanitizeSlug(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($value)) ?: '';

        return trim($sanitized, '-') ?: 'candidate-staging-harness';
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            File::makeDirectory($path, 0777, true);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function writeJson(string $path, array $payload): array
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [
            'relative_path' => $this->relativePath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $this->redactPath($path);
    }

    private function redactPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR);
        if (str_starts_with($path, $base)) {
            return 'backend'.substr($path, strlen($base));
        }

        return basename($path);
    }
}
