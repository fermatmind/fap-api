<?php

declare(strict_types=1);

namespace App\Services\Riasec\AssetAgent;

use App\Services\Riasec\RiasecContentRegistrySlotContract;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class RiasecResultPageAssetAgent
{
    public const SCHEMA_VERSION = 'fap.riasec.result_page_v2.asset_agent.audit.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/riasec_result_page_v2_agent';

    private const CONTENT_ASSET_RELATIVE_PATH = 'content_assets/riasec/result_page_v2';

    private const SELECTOR_READY_RELATIVE_PATH = 'content_assets/riasec/result_page_v2/selector_ready_assets';

    private const SOURCE_LEDGER_RELATIVE_PATH = 'content_assets/riasec/result_page_v2/source_ledger';

    private const SOURCE_LEDGER_PRIMARY_FILENAME = 'riasec_result_source_ledger_v0_1.json';

    private const REQUIRED_SOURCE_IDS = [
        'public_onet_interest_profiler_overview',
        'public_onet_interest_profiler_manual',
        'public_dol_onet_tools',
        'bibliographic_holland_1997',
        'internal_riasec_v73_pack_docs',
        'existing_riasec_asset_pack_snapshot',
    ];

    private const FORBIDDEN_PUBLIC_FIELDS = [
        'attempt_id',
        'user_id',
        'private_url',
        'private_path',
        'raw_score',
        'raw_scores',
        'score_vector',
        'dimension_vector',
        'percentile',
        'percentiles',
        'editor_notes',
        'qa_notes',
        'internal_metadata',
        'snapshot_id',
        'selection_guidance',
        'import_policy',
    ];

    private const FORBIDDEN_PUBLIC_TERMS = [
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'success prediction',
        'success probability',
        'hiring suitability',
        'ability proof',
        'skill inference',
        '140Q more accurate',
        'raw score delta',
        '60Q wrong',
        '职业匹配',
        '岗位匹配',
        '匹配度',
        '推荐职业',
        '职业推荐',
        '岗位胜任',
        '成功概率',
        '职业成功',
        '更准确',
        '更准',
        '最终答案',
        '你就是',
        '天生适合',
        '招聘筛选',
    ];

    /**
     * String paths that intentionally define boundaries rather than public claims.
     *
     * @var list<string>
     */
    private const BOUNDARY_PATH_MARKERS = [
        'forbidden_claim',
        'required_boundar',
        'boundary',
        'disallowed',
        'limitation',
        'not_',
        'allowed',
        'policy',
        'guard',
        'claim_rows',
        'source_records',
    ];

    public function __construct(
        private readonly RiasecContentRegistrySlotContract $slotContract = new RiasecContentRegistrySlotContract,
    ) {}

    /**
     * @param  array{
     *   run_id?:string,
     *   artifact_dir?:string,
     *   content_asset_root?:string,
     *   source_ledger_dir?:string,
     *   strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contentAssetRoot = $this->optionalPath(
            (string) ($options['content_asset_root'] ?? ''),
            base_path(self::CONTENT_ASSET_RELATIVE_PATH)
        );
        $sourceLedgerDir = $this->optionalPath(
            (string) ($options['source_ledger_dir'] ?? ''),
            base_path(self::SOURCE_LEDGER_RELATIVE_PATH)
        );
        $strict = ($options['strict'] ?? false) === true;

        $inventory = $this->buildInventory($contentAssetRoot, $sourceLedgerDir);
        $validationReport = $this->buildValidationReport($inventory);
        $safetyReport = $this->buildSafetyReport($contentAssetRoot);
        $strictFailures = $this->strictFailures($inventory, $validationReport, $safetyReport);
        $goNoGo = $this->buildGoNoGo($inventory, $validationReport, $safetyReport, $strictFailures);

        $this->ensureDirectory($artifactDir);

        $artifacts = [
            'input_inventory.json' => $this->writeJson($artifactDir.'/input_inventory.json', $inventory),
            'validation_report.json' => $this->writeJson($artifactDir.'/validation_report.json', $validationReport),
            'safety_report.json' => $this->writeJson($artifactDir.'/safety_report.json', $safetyReport),
            'go_no_go.md' => $this->writeText($artifactDir.'/go_no_go.md', $goNoGo),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => ! $strict || $strictFailures === [],
            'status' => ($strict && $strictFailures !== []) ? 'blocked' : 'success',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $artifacts,
            'strict' => $strict,
            'strict_failures' => $strictFailures,
            'summary' => [
                'asset_file_count' => (int) data_get($inventory, 'asset_inventory.file_count', 0),
                'source_ledger_valid' => (bool) data_get($inventory, 'source_ledger.valid', false),
                'asset_inventory_valid' => (bool) data_get($inventory, 'asset_inventory.valid', false),
                'validation_error_count' => (int) ($validationReport['error_count'] ?? 0),
                'leak_hit_count' => (int) data_get($safetyReport, 'leak_scan.hit_count', 0),
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array{
     *   run_id?:string,
     *   artifact_dir?:string,
     *   content_asset_root?:string,
     *   strict?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function stagingImportDryRun(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? 'riasec-result-page-staging-import-dry-run'));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $selectorReadyRoot = $this->optionalPath(
            (string) ($options['content_asset_root'] ?? ''),
            base_path(self::SELECTOR_READY_RELATIVE_PATH)
        );
        $strict = ($options['strict'] ?? false) === true;

        $checksumInventory = $this->selectorReadyChecksumInventory($selectorReadyRoot);
        $publicLeakScan = $this->selectorReadyPublicLeakScan($selectorReadyRoot);
        $failClosedReport = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_fail_closed_policy',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_change_performed' => false,
            'frontend_fallback_allowed' => false,
            'private_payload_exported' => false,
            'import_allowed' => false,
            'runtime_selector_wiring_allowed' => false,
            'production_rollout_allowed' => false,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
        $errors = [];
        if (! (bool) ($checksumInventory['valid'] ?? false)) {
            $errors[] = 'selector_ready_inventory_invalid';
        }
        if (((int) data_get($publicLeakScan, 'leak_scan.hit_count', 0)) > 0) {
            $errors[] = 'forbidden_public_payload_leaks';
        }

        $dryRunReport = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_dry_run',
            'run_id' => $runId,
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_change_performed' => false,
            'frontend_fallback_allowed' => false,
            'private_payload_exported' => false,
            'selector_ready_root' => $this->redactPath($selectorReadyRoot),
            'selector_ready_package_count' => (int) ($checksumInventory['package_count'] ?? 0),
            'selector_ready_asset_count' => (int) ($checksumInventory['asset_count'] ?? 0),
            'checksum_inventory_valid' => (bool) ($checksumInventory['valid'] ?? false),
            'public_leak_scan_status' => (string) data_get($publicLeakScan, 'leak_scan.status', 'blocked'),
            'fail_closed_policy_present' => true,
            'error_count' => count(array_unique($errors)),
            'errors' => array_values(array_unique($errors)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];

        $this->ensureDirectory($artifactDir);
        $artifacts = [
            'staging_import_dry_run_report.json' => $this->writeJson($artifactDir.'/staging_import_dry_run_report.json', $dryRunReport),
            'checksum_inventory.json' => $this->writeJson($artifactDir.'/checksum_inventory.json', $checksumInventory),
            'public_leak_scan_report.json' => $this->writeJson($artifactDir.'/public_leak_scan_report.json', $publicLeakScan),
            'fail_closed_report.json' => $this->writeJson($artifactDir.'/fail_closed_report.json', $failClosedReport),
            'go_no_go.md' => $this->writeText($artifactDir.'/go_no_go.md', $this->buildStagingImportGoNoGo($dryRunReport)),
        ];
        $ok = $errors === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => ! $strict || $ok,
            'status' => ($strict && ! $ok) ? 'blocked' : 'success',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $artifacts,
            'strict' => $strict,
            'summary' => [
                'selector_ready_package_count' => (int) ($checksumInventory['package_count'] ?? 0),
                'selector_ready_asset_count' => (int) ($checksumInventory['asset_count'] ?? 0),
                'checksum_inventory_valid' => (bool) ($checksumInventory['valid'] ?? false),
                'leak_hit_count' => (int) data_get($publicLeakScan, 'leak_scan.hit_count', 0),
                'cms_write_performed' => false,
                'runtime_change_performed' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
            'errors' => array_values(array_unique($errors)),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInventory(string $contentAssetRoot, string $sourceLedgerDir): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'input_inventory',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'inputs' => [
                'content_asset_root' => $this->redactPath($contentAssetRoot),
                'source_ledger_dir' => $this->redactPath($sourceLedgerDir),
                'content_slot_schema' => RiasecContentRegistrySlotContract::SCHEMA_VERSION,
            ],
            'asset_inventory' => $this->assetInventory($contentAssetRoot),
            'source_ledger' => $this->sourceLedgerSummary($sourceLedgerDir),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @return array<string,mixed>
     */
    private function buildValidationReport(array $inventory): array
    {
        $errors = [];

        if (! (bool) data_get($inventory, 'asset_inventory.valid', false)) {
            $errors[] = 'asset_inventory_invalid';
        }
        if (! (bool) data_get($inventory, 'source_ledger.valid', false)) {
            $errors[] = 'source_ledger_invalid';
        }

        $schema = $this->slotContract->schema();
        if (($schema['schema_version'] ?? null) !== RiasecContentRegistrySlotContract::SCHEMA_VERSION) {
            $errors[] = 'content_slot_contract_schema_mismatch';
        }
        if (($schema['scale_code'] ?? null) !== 'RIASEC') {
            $errors[] = 'content_slot_contract_scale_mismatch';
        }
        if (($schema['frontend_fallback_allowed'] ?? true) !== false) {
            $errors[] = 'content_slot_contract_frontend_fallback_not_blocked';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'content_asset_validation_harness',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'content_slot_contract_schema' => RiasecContentRegistrySlotContract::SCHEMA_VERSION,
            'content_slot_contract_reused' => true,
            'asset_file_count' => (int) data_get($inventory, 'asset_inventory.file_count', 0),
            'source_ledger_valid' => (bool) data_get($inventory, 'source_ledger.valid', false),
            'asset_inventory_valid' => (bool) data_get($inventory, 'asset_inventory.valid', false),
            'error_count' => count($errors),
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSafetyReport(string $contentAssetRoot): array
    {
        $hits = [];
        foreach ($this->assetFiles($contentAssetRoot) as $file) {
            $relativePath = $this->repoRelativePath($file->getPathname());
            $decoded = $this->readStructuredFile($file->getPathname());
            if ($decoded === null) {
                continue;
            }

            $payloads = array_is_list($decoded) ? $decoded : [$decoded];
            foreach ($payloads as $rowIndex => $payload) {
                if (is_array($payload)) {
                    $hits = array_merge($hits, $this->scanPublicPayloads($payload, $relativePath, (string) $rowIndex));
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'forbidden_public_payload_scan',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'forbidden_public_fields' => self::FORBIDDEN_PUBLIC_FIELDS,
            'forbidden_public_terms' => self::FORBIDDEN_PUBLIC_TERMS,
            'leak_scan' => [
                'status' => $hits === [] ? 'pass' : 'blocked',
                'hit_count' => count($hits),
                'hits' => array_slice($hits, 0, 100),
                'truncated' => count($hits) > 100,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assetInventory(string $contentAssetRoot): array
    {
        $files = [];
        $errors = [];

        foreach ($this->assetFiles($contentAssetRoot) as $file) {
            $path = $file->getPathname();
            $relativePath = $this->repoRelativePath($path);
            $extension = strtolower($file->getExtension());
            $format = $extension === 'jsonl' ? 'jsonl' : ($extension === 'json' ? 'json' : ($extension === 'md' ? 'markdown' : $extension));
            $fileErrors = $this->structuredFileErrors($path);

            foreach ($fileErrors as $error) {
                $errors[] = $relativePath.': '.$error;
            }

            $files[] = [
                'path' => $relativePath,
                'format' => $format,
                'bytes' => $file->getSize(),
                'sha256' => hash_file('sha256', $path),
                'valid' => $fileErrors === [],
            ];
        }

        return [
            'root' => $this->redactPath($contentAssetRoot),
            'file_count' => count($files),
            'files' => $files,
            'valid' => is_dir($contentAssetRoot) && $errors === [] && $files !== [],
            'errors' => array_slice($errors, 0, 100),
            'truncated' => count($errors) > 100,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLedgerSummary(string $sourceLedgerDir): array
    {
        $primaryPath = $this->findSourceLedger($sourceLedgerDir);
        $errors = [];
        $sourceIds = [];
        $claimCount = 0;

        if ($primaryPath === null) {
            $errors[] = 'missing_primary_source_ledger';
        } else {
            $ledger = $this->readJson($primaryPath);
            foreach ($this->requiredFalseFlags() as $flag) {
                if (($ledger[$flag] ?? null) !== false) {
                    $errors[] = 'invalid_'.$flag;
                }
            }
            if (($ledger['runtime_use'] ?? null) !== 'staging_only') {
                $errors[] = 'invalid_runtime_use';
            }
            $records = is_array($ledger['source_records'] ?? null) ? $ledger['source_records'] : [];
            $sourceIds = array_values(array_filter(array_map(
                static fn (mixed $record): string => is_array($record) ? (string) ($record['source_id'] ?? '') : '',
                $records
            )));
            foreach (self::REQUIRED_SOURCE_IDS as $sourceId) {
                if (! in_array($sourceId, $sourceIds, true)) {
                    $errors[] = 'missing_source_id_'.$sourceId;
                }
            }
            $claims = is_array($ledger['claim_rows'] ?? null) ? $ledger['claim_rows'] : [];
            $claimCount = count($claims);
            foreach ($claims as $index => $claim) {
                if (! is_array($claim)) {
                    $errors[] = 'invalid_claim_row_'.$index;
                    continue;
                }
                foreach (['claim_id', 'claim_text', 'source_id', 'source_ref', 'permitted_use', 'limitations', 'disallowed_use', 'claim_status'] as $field) {
                    if (! array_key_exists($field, $claim)) {
                        $errors[] = 'claim_'.$index.'_missing_'.$field;
                    }
                }
            }
        }

        return [
            'primary_ledger_path' => $primaryPath === null ? null : $this->repoRelativePath($primaryPath),
            'valid' => $errors === [],
            'source_id_count' => count($sourceIds),
            'required_source_ids_present' => array_values(array_intersect(self::REQUIRED_SOURCE_IDS, $sourceIds)),
            'claim_row_count' => $claimCount,
            'errors' => $errors,
        ];
    }

    /**
     * @return list<string>
     */
    private function strictFailures(array $inventory, array $validationReport, array $safetyReport): array
    {
        $failures = [];
        if (! (bool) data_get($inventory, 'source_ledger.valid', false)) {
            $failures[] = 'source_ledger_invalid';
        }
        if (! (bool) data_get($inventory, 'asset_inventory.valid', false)) {
            $failures[] = 'asset_inventory_invalid';
        }
        if (((int) ($validationReport['error_count'] ?? 0)) > 0) {
            $failures[] = 'validation_errors';
        }
        if (((int) data_get($safetyReport, 'leak_scan.hit_count', 0)) > 0) {
            $failures[] = 'forbidden_public_payload_leaks';
        }

        return $failures;
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $validationReport
     * @param  array<string,mixed>  $safetyReport
     * @param  list<string>  $strictFailures
     */
    private function buildGoNoGo(array $inventory, array $validationReport, array $safetyReport, array $strictFailures): string
    {
        return implode("\n", [
            '# RIASEC Result Page Asset Agent Audit',
            '',
            'runtime_use: staging_only',
            'production_use_allowed: false',
            'ready_for_runtime: false',
            'ready_for_production: false',
            'cms_write_performed: false',
            'runtime_change_performed: false',
            'frontend_fallback_allowed: false',
            'private_payload_exported: false',
            '',
            '## Summary',
            '',
            '- source_ledger_valid: '.($this->boolText((bool) data_get($inventory, 'source_ledger.valid', false))),
            '- asset_inventory_valid: '.($this->boolText((bool) data_get($inventory, 'asset_inventory.valid', false))),
            '- asset_file_count: '.(string) data_get($inventory, 'asset_inventory.file_count', 0),
            '- validation_error_count: '.(string) ($validationReport['error_count'] ?? 0),
            '- leak_hit_count: '.(string) data_get($safetyReport, 'leak_scan.hit_count', 0),
            '- strict_failures: '.($strictFailures === [] ? 'none' : implode(', ', $strictFailures)),
            '',
            '## Decision',
            '',
            'GO for staging-only audit evidence. NO-GO for asset generation, CMS import, runtime wrapper enablement, pilot access, or production rollout.',
            '',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,string>>
     */
    private function scanPayload(array $payload, string $sourceFile, string $pathPrefix): array
    {
        $hits = [];
        foreach ($payload as $key => $value) {
            $path = $pathPrefix.'.'.(string) $key;
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, self::FORBIDDEN_PUBLIC_FIELDS, true)) {
                $hits[] = [
                    'source_file' => $sourceFile,
                    'path' => $path,
                    'type' => 'forbidden_field',
                    'value' => (string) $key,
                ];
            }

            if (is_array($value)) {
                $hits = array_merge($hits, $this->scanPayload($value, $sourceFile, $path));
                continue;
            }

            if (! is_string($value) || $this->isBoundaryPath($path)) {
                continue;
            }

            $lower = mb_strtolower($value);
            foreach (self::FORBIDDEN_PUBLIC_TERMS as $term) {
                if (mb_stripos($lower, mb_strtolower($term)) === false) {
                    continue;
                }
                $hits[] = [
                    'source_file' => $sourceFile,
                    'path' => $path,
                    'type' => 'forbidden_term',
                    'value' => $term,
                ];
            }
        }

        return $hits;
    }

    private function isBoundaryPath(string $path): bool
    {
        $path = strtolower($path);
        foreach (self::BOUNDARY_PATH_MARKERS as $marker) {
            if (str_contains($path, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function selectorReadyChecksumInventory(string $selectorReadyRoot): array
    {
        $packages = [];
        $assetCount = 0;
        $errors = [];

        foreach ($this->selectorReadyPackageDirs($selectorReadyRoot) as $packageDir) {
            $assetsPath = $packageDir.'/assets.jsonl';
            $manifestPath = $packageDir.'/manifest.json';
            $package = [
                'package_id' => basename($packageDir),
                'assets_path' => $this->repoRelativePath($assetsPath),
                'manifest_path' => is_file($manifestPath) ? $this->repoRelativePath($manifestPath) : null,
                'assets_sha256' => is_file($assetsPath) ? hash_file('sha256', $assetsPath) : null,
                'assets_line_count' => 0,
                'manifest_valid' => is_file($manifestPath),
                'assets_valid' => is_file($assetsPath),
            ];

            if (! is_file($assetsPath)) {
                $errors[] = basename($packageDir).': missing_assets_jsonl';
            } else {
                $fileErrors = $this->structuredFileErrors($assetsPath);
                if ($fileErrors !== []) {
                    $errors[] = basename($packageDir).': '.implode(',', $fileErrors);
                    $package['assets_valid'] = false;
                }
                $lineCount = count($this->readJsonLines($assetsPath));
                $package['assets_line_count'] = $lineCount;
                $assetCount += $lineCount;
            }

            if (is_file($manifestPath)) {
                $manifest = $this->readJson($manifestPath);
                foreach ($this->requiredFalseFlags() as $flag) {
                    if (($manifest[$flag] ?? null) !== false) {
                        $errors[] = basename($packageDir).': invalid_'.$flag;
                    }
                }
                if (($manifest['runtime_use'] ?? null) !== 'staging_only') {
                    $errors[] = basename($packageDir).': invalid_runtime_use';
                }
            } else {
                $errors[] = basename($packageDir).': missing_manifest_json';
            }

            $packages[] = $package;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_checksum_inventory',
            'runtime_use' => 'staging_only',
            'selector_ready_root' => $this->redactPath($selectorReadyRoot),
            'package_count' => count($packages),
            'asset_count' => $assetCount,
            'packages' => $packages,
            'valid' => is_dir($selectorReadyRoot) && $packages !== [] && $errors === [],
            'errors' => $errors,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function selectorReadyPublicLeakScan(string $selectorReadyRoot): array
    {
        $hits = [];
        foreach ($this->selectorReadyPackageDirs($selectorReadyRoot) as $packageDir) {
            $assetsPath = $packageDir.'/assets.jsonl';
            if (! is_file($assetsPath)) {
                continue;
            }
            foreach ($this->readJsonLines($assetsPath) as $rowIndex => $payload) {
                $hits = array_merge(
                    $hits,
                    $this->scanPublicPayloads($payload, $this->repoRelativePath($assetsPath), (string) $rowIndex)
                );
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_public_leak_scan',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'forbidden_public_fields' => self::FORBIDDEN_PUBLIC_FIELDS,
            'forbidden_public_terms' => self::FORBIDDEN_PUBLIC_TERMS,
            'leak_scan' => [
                'status' => $hits === [] ? 'pass' : 'blocked',
                'hit_count' => count($hits),
                'hits' => array_slice($hits, 0, 100),
                'truncated' => count($hits) > 100,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanPublicPayloads(array $payload, string $sourceFile, string $pathPrefix): array
    {
        $hits = [];
        if (isset($payload['public_payload']) && is_array($payload['public_payload'])) {
            $hits = array_merge($hits, $this->scanPayload($payload['public_payload'], $sourceFile, $pathPrefix.'.public_payload'));
        }

        foreach ($payload as $key => $value) {
            if (is_array($value) && $key !== 'public_payload') {
                $hits = array_merge($hits, $this->scanPublicPayloads($value, $sourceFile, $pathPrefix.'.'.(string) $key));
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function selectorReadyPackageDirs(string $selectorReadyRoot): array
    {
        if (! is_dir($selectorReadyRoot)) {
            return [];
        }

        $dirs = [];
        foreach (scandir($selectorReadyRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $selectorReadyRoot.'/'.$entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }
        sort($dirs);

        return $dirs;
    }

    private function buildStagingImportGoNoGo(array $dryRunReport): string
    {
        return implode("\n", [
            '# RIASEC Result Page Staging Import Dry Run',
            '',
            'runtime_use: staging_only',
            'production_use_allowed: false',
            'ready_for_runtime: false',
            'ready_for_production: false',
            'cms_write_performed: false',
            'runtime_change_performed: false',
            'frontend_fallback_allowed: false',
            'private_payload_exported: false',
            '',
            '## Summary',
            '',
            '- selector_ready_package_count: '.(string) ($dryRunReport['selector_ready_package_count'] ?? 0),
            '- selector_ready_asset_count: '.(string) ($dryRunReport['selector_ready_asset_count'] ?? 0),
            '- checksum_inventory_valid: '.($this->boolText((bool) ($dryRunReport['checksum_inventory_valid'] ?? false))),
            '- public_leak_scan_status: '.(string) ($dryRunReport['public_leak_scan_status'] ?? 'blocked'),
            '- error_count: '.(string) ($dryRunReport['error_count'] ?? 0),
            '',
            '## Decision',
            '',
            'GO for staging-only dry-run evidence when error_count is 0. NO-GO for CMS import, runtime wrapper enablement, pilot access, or production rollout.',
            '',
        ]);
    }

    /**
     * @return list<SplFileInfo>
     */
    private function assetFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if (! in_array(strtolower($file->getExtension()), ['json', 'jsonl', 'md'], true)) {
                continue;
            }
            $files[] = $file;
        }

        usort($files, static fn (SplFileInfo $left, SplFileInfo $right): int => strcmp($left->getPathname(), $right->getPathname()));

        return $files;
    }

    /**
     * @return list<string>
     */
    private function structuredFileErrors(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'md') {
            return [];
        }
        if ($extension === 'json') {
            try {
                $this->readJson($path);

                return [];
            } catch (RuntimeException $exception) {
                return [$exception->getMessage()];
            }
        }
        if ($extension === 'jsonl') {
            $errors = [];
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNumber => $line) {
                if (trim((string) $line) === '') {
                    continue;
                }
                try {
                    json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    $errors[] = 'jsonl_line_'.($lineNumber + 1).'_'.$exception->getMessage();
                }
            }

            return $errors;
        }

        return [];
    }

    /**
     * @return list<array<string,mixed>>|array<string,mixed>|null
     */
    private function readStructuredFile(string $path): array|null
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            return $this->readJson($path);
        }
        if ($extension !== 'jsonl') {
            return null;
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $decoded = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonLines(string $path): array
    {
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $decoded = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('missing_json_file');
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid_json_'.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('json_root_not_object');
        }

        return $decoded;
    }

    private function findSourceLedger(string $sourceLedgerDir): ?string
    {
        $direct = $sourceLedgerDir.'/v0_1/'.self::SOURCE_LEDGER_PRIMARY_FILENAME;
        if (is_file($direct)) {
            return $direct;
        }

        foreach ($this->assetFiles($sourceLedgerDir) as $file) {
            if ($file->getFilename() === self::SOURCE_LEDGER_PRIMARY_FILENAME) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function artifactDir(string $artifactDir, string $runId): string
    {
        $root = trim($artifactDir) === ''
            ? base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR)
            : $this->absolutePath($artifactDir);

        return rtrim($root, '/').'/'.$runId;
    }

    private function sanitizeRunId(string $runId): string
    {
        $runId = trim($runId);
        if ($runId === '') {
            $runId = 'riasec-result-page-agent-audit';
        }

        $runId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $runId) ?: 'riasec-result-page-agent-audit';

        return trim($runId, '.-') ?: 'riasec-result-page-agent-audit';
    }

    private function optionalPath(string $path, string $default): string
    {
        return trim($path) === '' ? $default : $this->absolutePath($path);
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim(base_path($path), '/');
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }
        if (! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create directory: '.$path);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,string>
     */
    private function writeJson(string $path, array $payload): array
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $this->artifactMeta($path);
    }

    /**
     * @return array<string,string>
     */
    private function writeText(string $path, string $payload): array
    {
        file_put_contents($path, $payload);

        return $this->artifactMeta($path);
    }

    /**
     * @return array<string,string>
     */
    private function artifactMeta(string $path): array
    {
        return [
            'relative_path' => $this->repoRelativePath($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    private function repoRelativePath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $this->redactPath($path);
    }

    private function redactPath(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return basename($path);
    }

    /**
     * @return list<string>
     */
    private function requiredFalseFlags(): array
    {
        return [
            'production_use_allowed',
            'ready_for_runtime',
            'ready_for_production',
            'cms_write_performed',
            'runtime_change_performed',
            'frontend_fallback_allowed',
            'private_payload_exported',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function negativeGuarantees(): array
    {
        return [
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'cms_write_performed' => false,
            'runtime_change_performed' => false,
            'frontend_fallback_allowed' => false,
            'private_payload_exported' => false,
        ];
    }

    private function boolText(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
