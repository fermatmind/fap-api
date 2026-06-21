<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\AssetAgent;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetValidator;
use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetPackageLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class BigFiveResultPageV2AssetAgent
{
    public const SCHEMA_VERSION = 'fap.big5.result_page_v2.asset_agent.audit.v0.1';

    private const OPS_REPORT_SCHEMA_VERSION = 'fap.big5.result_page_v2.asset_agent.ops_report.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/big5_result_page_v2_agent';

    private const SOURCE_LEDGER_RELATIVE_PATH = 'content_assets/big5/result_page_v2/source_ledger';

    private const SOURCE_LEDGER_PRIMARY_FILENAME = 'source_ledger.json';

    private const SOURCE_LEDGER_ALLOWED_LABELS = [
        'public_domain_source',
        'citation_only',
        'structure_reference_only',
        'forbidden_copy_source',
    ];

    private const SOURCE_LEDGER_REQUIRED_SOURCE_IDS = [
        'ipip_official',
        'bfi_2_colby',
        'bigfive_web_github',
        'internal_big5_v2_formal_doc',
        'internal_big5_twenty_thousand_word_final_doc',
        'existing_big5_result_page_v2_asset_packs',
        'restricted_bfi2_item_text_and_proprietary_reports',
    ];

    private const SELECTOR_ASSET_RELATIVE_PATH = 'selector_ready_assets/v0_3_p0_full/assets.jsonl';

    private const CONTENT_ASSET_SCHEMA_RELATIVE_PATH = 'content_assets/big5/result_page_v2/governance/content_asset_factory_spec/big5_content_asset_schema_v0_1.json';

    private const FORBIDDEN_PUBLIC_FIELDS = [
        'attempt_id',
        'private_url',
        'private_path',
        'raw_score',
        'raw_scores',
        'domain_vector',
        'facet_vector',
        'percentile',
        'percentiles',
        'editor_notes',
        'qa_notes',
        'selection_guidance',
        'import_policy',
        'internal_metadata',
        'fixed_type',
        'user_confirmed_type',
    ];

    private const FORBIDDEN_PUBLIC_TERMS = [
        'raw score',
        'raw scores',
        'raw_score',
        'raw_scores',
        'domain_vector',
        'facet_vector',
        'percentile',
        'percentiles',
        'fixed type',
        'fixed_type',
        'official 32 type',
        'diagnosis',
        'therapy',
        'treatment',
        'hiring screen',
        'success prediction',
    ];

    public function __construct(
        private readonly BigFiveV2AssetPackageLoader $packageLoader = new BigFiveV2AssetPackageLoader,
        private readonly BigFiveResultPageV2SelectorAssetValidator $selectorValidator = new BigFiveResultPageV2SelectorAssetValidator,
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
            base_path(BigFiveV2AssetPackageLoader::ROOT_RELATIVE_PATH)
        );
        $sourceLedgerDir = $this->optionalPath(
            (string) ($options['source_ledger_dir'] ?? ''),
            base_path(self::SOURCE_LEDGER_RELATIVE_PATH)
        );
        $strict = ($options['strict'] ?? false) === true;

        $inventory = $this->buildInventory($contentAssetRoot, $sourceLedgerDir);
        $assets = $this->collectSelectorAssets($contentAssetRoot);
        $validationReport = $this->buildValidationReport($assets);
        $safetyReport = $this->buildSafetyReport($assets);
        $opsReport = $this->buildOpsReport($inventory, $validationReport, $safetyReport, $assets, $artifactDir, $runId);
        $safetyReport = $this->withOpsReport($safetyReport, $opsReport);
        $qaSummary = $this->buildQaSummary($validationReport, $safetyReport, $opsReport);
        $goNoGo = $this->buildGoNoGo($inventory, $validationReport, $safetyReport, $opsReport);
        $strictFailures = $this->strictFailures($inventory, $validationReport, $safetyReport);

        $this->ensureDirectory($artifactDir);

        $artifacts = [
            'input_inventory.json' => $this->writeJson($artifactDir.'/input_inventory.json', $inventory),
            'validation_report.json' => $this->writeJson($artifactDir.'/validation_report.json', $validationReport),
            'safety_report.json' => $this->writeJson($artifactDir.'/safety_report.json', $safetyReport),
            'qa_eval_summary.json' => $this->writeJson($artifactDir.'/qa_eval_summary.json', $qaSummary),
            'ops_report_summary.json' => $this->writeJson($artifactDir.'/ops_report_summary.json', $opsReport),
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
                'selector_asset_count' => count($assets),
                'validation_error_count' => (int) ($validationReport['error_count'] ?? 0),
                'leak_hit_count' => (int) ($safetyReport['leak_scan']['hit_count'] ?? 0),
                'source_ledger_valid' => (bool) ($inventory['source_ledger']['valid'] ?? false),
                'asset_inventory_valid' => (bool) ($inventory['asset_inventory']['valid'] ?? false),
                'ready_for_pilot' => false,
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
     *   source_ledger_dir?:string
     * }  $options
     * @return array<string,mixed>
     */
    public function generateCandidates(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $sourceLedgerDir = $this->optionalPath(
            (string) ($options['source_ledger_dir'] ?? ''),
            base_path(self::SOURCE_LEDGER_RELATIVE_PATH)
        );

        $this->ensureDirectory($artifactDir);

        $sourceLedger = $this->sourceLedgerSummary($sourceLedgerDir);
        $selectorCandidates = $this->draftSelectorAssetCandidates();
        $contentCandidates = $this->draftContentAssetCandidates();
        $validation = $this->candidateValidationReport($selectorCandidates, $contentCandidates);
        $leakScan = $this->candidateLeakScan($selectorCandidates, $contentCandidates);

        $ok = (bool) ($sourceLedger['valid'] ?? false)
            && ((int) ($validation['error_count'] ?? 0)) === 0
            && ((int) ($leakScan['hit_count'] ?? 0)) === 0;

        $summary = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'candidate_generator',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'run_id' => $runId,
            'source_ledger' => [
                'valid' => (bool) ($sourceLedger['valid'] ?? false),
                'primary_ledger_path' => $sourceLedger['primary_ledger_path'] ?? null,
                'allowed_source_labels' => $sourceLedger['allowed_source_labels'] ?? [],
                'bfi_2_policy_valid' => (bool) ($sourceLedger['bfi_2_policy_valid'] ?? false),
                'errors' => $sourceLedger['errors'] ?? [],
            ],
            'candidate_counts' => [
                'selector_asset' => count($selectorCandidates),
                'content_asset' => count($contentCandidates),
            ],
            'validation' => $validation,
            'leak_scan' => $leakScan,
            'negative_guarantees' => $this->candidateNegativeGuarantees(),
        ];

        $artifacts = [
            'selector_asset_candidates.jsonl' => $this->writeJsonl($artifactDir.'/selector_asset_candidates.jsonl', $selectorCandidates),
            'content_asset_candidates.jsonl' => $this->writeJsonl($artifactDir.'/content_asset_candidates.jsonl', $contentCandidates),
            'candidate_generation_summary.json' => $this->writeJson($artifactDir.'/candidate_generation_summary.json', $summary),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $artifacts,
            'summary' => [
                'selector_candidate_count' => count($selectorCandidates),
                'content_candidate_count' => count($contentCandidates),
                'validation_error_count' => (int) ($validation['error_count'] ?? 0),
                'leak_hit_count' => (int) ($leakScan['hit_count'] ?? 0),
                'source_ledger_valid' => (bool) ($sourceLedger['valid'] ?? false),
                'ready_for_pilot' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
            'negative_guarantees' => $this->candidateNegativeGuarantees(),
        ];
    }

    /**
     * @param  array{
     *   run_id?:string,
     *   artifact_dir?:string,
     *   candidate_dir?:string,
     *   staging_output_dir?:string,
     *   allow_staging_write?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function stageCandidates(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $candidateDir = trim((string) ($options['candidate_dir'] ?? '')) === ''
            ? ''
            : $this->absolutePath((string) ($options['candidate_dir'] ?? ''));
        $stagingOutputDir = $this->optionalPath(
            (string) ($options['staging_output_dir'] ?? ''),
            base_path('content_assets/big5/result_page_v2/staging_candidate_imports/'.$runId)
        );
        $allowStagingWrite = ($options['allow_staging_write'] ?? false) === true;

        $this->ensureDirectory($artifactDir);

        $selectorCandidates = $candidateDir === '' ? [] : $this->readJsonl($candidateDir.'/selector_asset_candidates.jsonl');
        $contentCandidates = $candidateDir === '' ? [] : $this->readJsonl($candidateDir.'/content_asset_candidates.jsonl');
        $reviewManifest = $candidateDir === '' ? null : $this->readOptionalJson($candidateDir.'/review_manifest.json');
        $validation = $this->candidateValidationReport($selectorCandidates, $contentCandidates);
        $reviewErrors = $this->reviewManifestErrors($reviewManifest);
        $leakScan = $this->candidateLeakScan($selectorCandidates, $contentCandidates);

        $stagingWritePerformed = false;
        $stagingArtifacts = [];
        $ok = ((int) ($validation['error_count'] ?? 0)) === 0
            && $reviewErrors === []
            && ((int) ($leakScan['hit_count'] ?? 0)) === 0;

        $repairLog = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_repair_log',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'repair_required' => ! $ok,
            'entries' => array_values(array_merge(
                (array) ($validation['errors'] ?? []),
                $reviewErrors,
                array_map(static fn (array $hit): string => 'leak: '.(string) ($hit['value'] ?? ''), (array) ($leakScan['hits'] ?? []))
            )),
        ];

        $validationReport = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_validation',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'candidate_dir' => $this->redactPath($candidateDir),
            'staging_output_dir' => $this->redactPath($stagingOutputDir),
            'allow_staging_write' => $allowStagingWrite,
            'staging_write_performed' => false,
            'review_manifest' => [
                'present' => $reviewManifest !== null,
                'valid' => $reviewErrors === [],
                'errors' => $reviewErrors,
            ],
            'candidate_counts' => [
                'selector_asset' => count($selectorCandidates),
                'content_asset' => count($contentCandidates),
            ],
            'candidate_validation' => $validation,
            'leak_scan' => $leakScan,
            'negative_guarantees' => $allowStagingWrite ? $this->stagingImportNegativeGuarantees() : $this->candidateNegativeGuarantees(),
        ];

        if ($ok && $allowStagingWrite) {
            $this->ensureDirectory($stagingOutputDir);
            $validationReport['staging_write_performed'] = true;
            $stagingArtifacts = [
                'selector_asset_candidates.staging.jsonl' => $this->writeJsonl($stagingOutputDir.'/selector_asset_candidates.staging.jsonl', $selectorCandidates),
                'content_asset_candidates.staging.jsonl' => $this->writeJsonl($stagingOutputDir.'/content_asset_candidates.staging.jsonl', $contentCandidates),
                'staging_import_manifest.json' => $this->writeJson($stagingOutputDir.'/staging_import_manifest.json', [
                    'schema_version' => self::SCHEMA_VERSION,
                    'task' => 'staging_import_manifest',
                    'runtime_use' => 'staging_only',
                    'production_use_allowed' => false,
                    'ready_for_pilot' => false,
                    'ready_for_runtime' => false,
                    'ready_for_production' => false,
                    'candidate_dir' => $this->redactPath($candidateDir),
                    'selector_asset_candidate_count' => count($selectorCandidates),
                    'content_asset_candidate_count' => count($contentCandidates),
                    'review_manifest' => [
                        'review_status' => (string) ($reviewManifest['review_status'] ?? ''),
                        'reviewed_at' => (string) ($reviewManifest['reviewed_at'] ?? ''),
                    ],
                    'negative_guarantees' => $this->stagingImportNegativeGuarantees(),
                ]),
                'staging_import_validation_report.json' => $this->writeJson($stagingOutputDir.'/staging_import_validation_report.json', $validationReport),
                'repair_log.json' => $this->writeJson($stagingOutputDir.'/repair_log.json', $repairLog),
            ];
            $stagingWritePerformed = true;
        }

        $artifactSummary = [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'staging_import_gate',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
            'ok' => $ok,
            'staging_write_performed' => $stagingWritePerformed,
            'staging_artifacts' => $stagingArtifacts,
            'validation_report' => $validationReport,
            'repair_log' => $repairLog,
        ];

        $artifacts = [
            'staging_import_summary.json' => $this->writeJson($artifactDir.'/staging_import_summary.json', $artifactSummary),
            'staging_import_validation_report.json' => $this->writeJson($artifactDir.'/staging_import_validation_report.json', $validationReport),
            'repair_log.json' => $this->writeJson($artifactDir.'/repair_log.json', $repairLog),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => $ok,
            'status' => $ok ? 'success' : 'blocked',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $artifacts,
            'staging_artifacts' => $stagingArtifacts,
            'summary' => [
                'selector_candidate_count' => count($selectorCandidates),
                'content_candidate_count' => count($contentCandidates),
                'validation_error_count' => (int) ($validation['error_count'] ?? 0),
                'review_error_count' => count($reviewErrors),
                'leak_hit_count' => (int) ($leakScan['hit_count'] ?? 0),
                'staging_write_performed' => $stagingWritePerformed,
                'ready_for_pilot' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
            'negative_guarantees' => $allowStagingWrite ? $this->stagingImportNegativeGuarantees() : $this->candidateNegativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildInventory(string $contentAssetRoot, string $sourceLedgerDir): array
    {
        $packageInventory = $this->packageLoader->inventory($contentAssetRoot)->toArray();
        $sourceLedger = $this->sourceLedgerSummary($sourceLedgerDir);

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
                'selector_asset_schema' => BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION,
            ],
            'asset_inventory' => [
                'package_count' => (int) ($packageInventory['package_count'] ?? 0),
                'file_count' => (int) ($packageInventory['file_count'] ?? 0),
                'valid' => (bool) ($packageInventory['valid'] ?? false),
                'errors' => array_slice((array) ($packageInventory['errors'] ?? []), 0, 50),
            ],
            'source_ledger' => $sourceLedger,
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function buildValidationReport(array $assets): array
    {
        $errors = $this->selectorValidator->validateAssetSet($assets);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'selector_asset_validation',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'selector_asset_schema' => BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION,
            'asset_count' => count($assets),
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 100),
            'truncated' => count($errors) > 100,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function buildSafetyReport(array $assets): array
    {
        $hits = [];
        foreach ($assets as $asset) {
            $sourceFile = (string) ($asset['_source_file'] ?? 'unknown');
            if (is_array($asset['public_payload'] ?? null)) {
                $hits = array_merge($hits, $this->scanPayload((array) $asset['public_payload'], $sourceFile, 'public_payload'));
            }
            if (($asset['shareable'] ?? false) === true && is_array($asset['public_payload'] ?? null)) {
                $hits = array_merge($hits, $this->scanPayload((array) $asset['public_payload'], $sourceFile, 'shareable_public_payload'));
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'safety_claim_scan',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
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
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $validationReport
     * @param  array<string,mixed>  $safetyReport
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function buildOpsReport(
        array $inventory,
        array $validationReport,
        array $safetyReport,
        array $assets,
        string $artifactDir,
        string $runId,
    ): array {
        $metrics = $this->opsMetrics($inventory, $validationReport, $safetyReport, $assets);
        $previous = $this->previousOpsReport($artifactDir, $runId);

        return [
            'schema_version' => self::OPS_REPORT_SCHEMA_VERSION,
            'task' => 'ops_report_standardization',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'run_id' => $runId,
            'metric_schema' => $this->opsMetricSchema(),
            'metrics' => $metrics,
            'diff_summary' => $this->opsDiffSummary($metrics, $previous),
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $opsReport
     * @return array<string,mixed>
     */
    private function withOpsReport(array $payload, array $opsReport): array
    {
        $payload['ops_report_schema_version'] = self::OPS_REPORT_SCHEMA_VERSION;
        $payload['ops_metrics'] = $opsReport['metrics'] ?? [];
        $payload['ops_diff_summary'] = $opsReport['diff_summary'] ?? [];

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $validationReport
     * @param  array<string,mixed>  $safetyReport
     * @param  array<string,mixed>  $opsReport
     * @return array<string,mixed>
     */
    private function buildQaSummary(array $validationReport, array $safetyReport, array $opsReport): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'qa_eval_runner',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'selector_validation' => [
                'asset_count' => (int) ($validationReport['asset_count'] ?? 0),
                'error_count' => (int) ($validationReport['error_count'] ?? 0),
            ],
            'leak_scan' => $safetyReport['leak_scan'] ?? [],
            'ops_report_schema_version' => self::OPS_REPORT_SCHEMA_VERSION,
            'ops_metrics' => $opsReport['metrics'] ?? [],
            'ops_diff_summary' => $opsReport['diff_summary'] ?? [],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $validationReport
     * @param  array<string,mixed>  $safetyReport
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,mixed>
     */
    private function opsMetrics(array $inventory, array $validationReport, array $safetyReport, array $assets): array
    {
        $counts = $this->assetCounts($assets);
        $strictFailures = $this->strictFailures($inventory, $validationReport, $safetyReport);
        $shareSafetyMissing = array_values(array_filter([
            ((int) ($counts['registry_key']['share_safety_registry'] ?? 0)) > 0 ? null : 'share_safety_registry',
            ((int) ($counts['reading_mode']['share_safe'] ?? 0)) > 0 ? null : 'share_safe_reading_mode',
        ]));

        return [
            'p0_blocker_count' => count($strictFailures),
            'registry_gap_count' => count($this->missingValues(
                BigFiveResultPageV2SelectorAssetContract::REGISTRY_KEYS,
                (array) ($counts['registry_key'] ?? [])
            )),
            'module_gap_count' => count($this->missingValues(
                BigFiveResultPageV2Contract::MODULE_KEYS,
                (array) ($counts['module_key'] ?? [])
            )),
            'scope_gap_count' => count($this->missingValues(
                BigFiveResultPageV2Contract::INTERPRETATION_SCOPES,
                (array) ($counts['scope'] ?? [])
            )),
            'reading_mode_gap_count' => count($this->missingValues(
                BigFiveResultPageV2SelectorAssetContract::READING_MODES,
                (array) ($counts['reading_mode'] ?? [])
            )),
            'share_safety_missing_count' => count($shareSafetyMissing),
            'share_safety_registry_count' => (int) ($counts['registry_key']['share_safety_registry'] ?? 0),
            'share_safe_reading_mode_count' => (int) ($counts['reading_mode']['share_safe'] ?? 0),
            'shareable_true_count' => count(array_filter($assets, static fn (array $asset): bool => ($asset['shareable'] ?? false) === true)),
            'norm_unavailable_missing' => ((int) ($counts['scope']['norm_unavailable'] ?? 0)) === 0,
            'norm_unavailable_count' => (int) ($counts['scope']['norm_unavailable'] ?? 0),
            'low_quality_missing' => ((int) ($counts['scope']['low_quality'] ?? 0)) === 0,
            'low_quality_count' => (int) ($counts['scope']['low_quality'] ?? 0),
            'forbidden_leak_hit_count' => (int) data_get($safetyReport, 'leak_scan.hit_count', 0),
            'validation_error_count' => (int) ($validationReport['error_count'] ?? 0),
            'asset_record_count' => count($assets),
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $assets
     * @return array<string,array<string,int>>
     */
    private function assetCounts(array $assets): array
    {
        $counts = [
            'registry_key' => [],
            'module_key' => [],
            'scope' => [],
            'reading_mode' => [],
        ];

        foreach ($assets as $asset) {
            foreach (['registry_key', 'module_key', 'scope'] as $key) {
                $value = $asset[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $counts[$key][$value] = ($counts[$key][$value] ?? 0) + 1;
                }
            }
            foreach ((array) ($asset['reading_modes'] ?? []) as $mode) {
                if (is_string($mode) && $mode !== '') {
                    $counts['reading_mode'][$mode] = ($counts['reading_mode'][$mode] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $expected
     * @param  array<string,int>  $observed
     * @return list<string>
     */
    private function missingValues(array $expected, array $observed): array
    {
        return array_values(array_filter(
            $expected,
            static fn (string $value): bool => ! array_key_exists($value, $observed)
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function opsMetricSchema(): array
    {
        return [
            'schema_version' => self::OPS_REPORT_SCHEMA_VERSION,
            'required_metric_keys' => [
                'p0_blocker_count',
                'registry_gap_count',
                'module_gap_count',
                'scope_gap_count',
                'reading_mode_gap_count',
                'share_safety_missing_count',
                'norm_unavailable_missing',
                'low_quality_missing',
                'forbidden_leak_hit_count',
                'ready_for_pilot',
                'ready_for_runtime',
                'ready_for_production',
            ],
            'ready_flag_defaults' => [
                'ready_for_pilot' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $currentMetrics
     * @param  array<string,mixed>|null  $previousReport
     * @return array<string,mixed>
     */
    private function opsDiffSummary(array $currentMetrics, ?array $previousReport): array
    {
        if ($previousReport === null) {
            return [
                'comparison_status' => 'no_previous_run',
                'previous_run_id' => null,
                'metric_deltas' => [],
            ];
        }

        $previousMetrics = (array) ($previousReport['metrics'] ?? []);
        $deltas = [];
        foreach ($this->opsMetricSchema()['required_metric_keys'] as $key) {
            $current = $currentMetrics[$key] ?? null;
            $previous = $previousMetrics[$key] ?? null;
            $deltas[$key] = [
                'previous' => $previous,
                'current' => $current,
                'delta' => (is_numeric($current) && is_numeric($previous))
                    ? ((int) $current - (int) $previous)
                    : null,
                'changed' => $current !== $previous,
            ];
        }

        return [
            'comparison_status' => 'compared',
            'previous_run_id' => (string) ($previousReport['run_id'] ?? ''),
            'metric_deltas' => $deltas,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function previousOpsReport(string $artifactDir, string $runId): ?array
    {
        $artifactRoot = dirname($artifactDir);
        if (! is_dir($artifactRoot)) {
            return null;
        }

        $candidates = [];
        foreach (scandir($artifactRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $runId) {
                continue;
            }

            $path = $artifactRoot.'/'.$entry.'/ops_report_summary.json';
            if (is_file($path)) {
                $candidates[$path] = filemtime($path) ?: 0;
            }
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);
        $path = (string) array_key_first($candidates);
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $validationReport
     * @param  array<string,mixed>  $safetyReport
     */
    private function buildGoNoGo(array $inventory, array $validationReport, array $safetyReport, array $opsReport): string
    {
        $lines = [
            '# Big Five Result Page V2 Asset Agent Harness GO/NO-GO',
            '',
            '- runtime_use: staging_only',
            '- production_use_allowed: false',
            '- ready_for_runtime: false',
            '- ready_for_production: false',
            '- cms_write_performed: false',
            '- runtime_change_performed: false',
            '- frontend_fallback_allowed: false',
            '',
            '## Gate Status',
            '',
            '- source_ledger_valid: '.((bool) data_get($inventory, 'source_ledger.valid') ? 'true' : 'false'),
            '- asset_inventory_valid: '.((bool) data_get($inventory, 'asset_inventory.valid') ? 'true' : 'false'),
            '- selector_validation_errors: '.(string) ($validationReport['error_count'] ?? 0),
            '- leak_hit_count: '.(string) data_get($safetyReport, 'leak_scan.hit_count', 0),
            '',
            '## Ops Metrics',
            '',
        ];

        foreach ($this->opsMetricSchema()['required_metric_keys'] as $key) {
            $lines[] = '- '.$key.': '.$this->markdownScalar(data_get($opsReport, 'metrics.'.$key));
        }

        $lines = array_merge($lines, [
            '',
            '## Diff Summary',
            '',
            '- comparison_status: '.(string) data_get($opsReport, 'diff_summary.comparison_status', 'unknown'),
            '- previous_run_id: '.$this->markdownScalar(data_get($opsReport, 'diff_summary.previous_run_id')),
            '',
            '## Deferred',
            '',
            '- No selector assets are generated.',
            '- No CMS import is performed.',
            '- No runtime wrapper, pilot gate, production import gate, or rollout gate is changed.',
        ]);

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function markdownScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }

    /**
     * @return list<string>
     */
    private function strictFailures(array $inventory, array $validationReport, array $safetyReport): array
    {
        $failures = [];
        if (data_get($inventory, 'asset_inventory.valid') !== true) {
            $failures[] = 'asset_inventory_invalid';
        }
        if (data_get($inventory, 'source_ledger.valid') !== true) {
            $failures[] = 'source_ledger_invalid';
        }
        if ((int) ($validationReport['error_count'] ?? 0) > 0) {
            $failures[] = 'selector_validation_errors';
        }
        if ((int) data_get($safetyReport, 'leak_scan.hit_count', 0) > 0) {
            $failures[] = 'forbidden_leak_hits';
        }

        return array_values(array_unique($failures));
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceLedgerSummary(string $sourceLedgerDir): array
    {
        if (! is_dir($sourceLedgerDir)) {
            return [
                'exists' => false,
                'valid' => false,
                'json_count' => 0,
                'primary_ledger_path' => null,
                'allowed_source_labels' => self::SOURCE_LEDGER_ALLOWED_LABELS,
                'required_source_ids_present' => [],
                'label_counts' => [],
                'bfi_2_policy_valid' => false,
                'errors' => ['source ledger directory missing'],
                'files' => [],
            ];
        }

        $files = [];
        $errors = [];
        foreach ($this->filesUnder($sourceLedgerDir) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $relativePath = $this->redactPath($file->getPathname());
            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
            if (! is_array($decoded)) {
                $errors[] = "{$relativePath} is not valid JSON";
            }

            $files[] = [
                'relative_path' => $relativePath,
                'sha256' => hash_file('sha256', $file->getPathname()) ?: '',
                'size' => filesize($file->getPathname()) ?: 0,
                'schema_version' => is_array($decoded) ? (string) ($decoded['schema_version'] ?? '') : '',
            ];
        }

        $primaryLedgerPath = $sourceLedgerDir.'/'.self::SOURCE_LEDGER_PRIMARY_FILENAME;
        $primaryLedger = null;
        if (! is_file($primaryLedgerPath)) {
            $errors[] = self::SOURCE_LEDGER_PRIMARY_FILENAME.' missing';
        } else {
            $decoded = json_decode((string) file_get_contents($primaryLedgerPath), true);
            if (! is_array($decoded)) {
                $errors[] = self::SOURCE_LEDGER_PRIMARY_FILENAME.' is not valid JSON';
            } else {
                $primaryLedger = $decoded;
                $errors = array_merge($errors, $this->sourceLedgerContractErrors($primaryLedger));
            }
        }

        $sources = is_array($primaryLedger) ? (array) ($primaryLedger['sources'] ?? []) : [];

        return [
            'exists' => true,
            'valid' => $errors === [] && $files !== [] && is_array($primaryLedger),
            'json_count' => count($files),
            'primary_ledger_path' => is_file($primaryLedgerPath) ? $this->redactPath($primaryLedgerPath) : null,
            'allowed_source_labels' => self::SOURCE_LEDGER_ALLOWED_LABELS,
            'required_source_ids_present' => $this->presentSourceIds($sources),
            'label_counts' => $this->sourceLabelCounts($sources),
            'bfi_2_policy_valid' => $this->bfi2PolicyValid($sources),
            'errors' => $errors,
            'files' => $files,
        ];
    }

    /**
     * @param  array<string,mixed>  $ledger
     * @return list<string>
     */
    private function sourceLedgerContractErrors(array $ledger): array
    {
        $errors = [];

        if (($ledger['runtime_use'] ?? null) !== 'not_runtime') {
            $errors[] = 'source_ledger runtime_use must be not_runtime';
        }
        if (($ledger['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'source_ledger production_use_allowed must be false';
        }
        foreach (['ready_for_pilot', 'ready_for_runtime', 'ready_for_production'] as $flag) {
            if (($ledger[$flag] ?? null) !== false) {
                $errors[] = "source_ledger {$flag} must be false";
            }
        }

        $labels = array_values((array) ($ledger['allowed_source_labels'] ?? []));
        sort($labels);
        $expectedLabels = self::SOURCE_LEDGER_ALLOWED_LABELS;
        sort($expectedLabels);
        if ($labels !== $expectedLabels) {
            $errors[] = 'source_ledger allowed_source_labels must match the fixed source label contract';
        }

        $sources = (array) ($ledger['sources'] ?? []);
        if ($sources === []) {
            $errors[] = 'source_ledger sources missing';
        }

        $presentIds = $this->presentSourceIds($sources);
        foreach (self::SOURCE_LEDGER_REQUIRED_SOURCE_IDS as $sourceId) {
            if (! in_array($sourceId, $presentIds, true)) {
                $errors[] = "source_ledger missing required source_id {$sourceId}";
            }
        }

        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                $errors[] = "source_ledger source row {$index} must be an object";

                continue;
            }

            $sourceId = (string) ($source['source_id'] ?? 'unknown');
            $label = (string) ($source['source_label'] ?? '');
            if (! in_array($label, self::SOURCE_LEDGER_ALLOWED_LABELS, true)) {
                $errors[] = "source_ledger {$sourceId} has invalid source_label {$label}";
            }
            if (! is_array($source['copy_policy'] ?? null)) {
                $errors[] = "source_ledger {$sourceId} missing copy_policy";
            }
        }

        if (! $this->bfi2PolicyValid($sources)) {
            $errors[] = 'source_ledger bfi_2_colby must be structure_reference_only with copy_allowed=false and item/prose copy bans';
        }

        return $errors;
    }

    /**
     * @param  list<mixed>|array<int,mixed>  $sources
     * @return list<string>
     */
    private function presentSourceIds(array $sources): array
    {
        $ids = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceId = (string) ($source['source_id'] ?? '');
            if ($sourceId !== '') {
                $ids[] = $sourceId;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<mixed>|array<int,mixed>  $sources
     * @return array<string,int>
     */
    private function sourceLabelCounts(array $sources): array
    {
        $counts = array_fill_keys(self::SOURCE_LEDGER_ALLOWED_LABELS, 0);
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $label = (string) ($source['source_label'] ?? '');
            if ($label !== '') {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<mixed>|array<int,mixed>  $sources
     */
    private function bfi2PolicyValid(array $sources): bool
    {
        $bfi2 = null;
        foreach ($sources as $source) {
            if (is_array($source) && ($source['source_id'] ?? null) === 'bfi_2_colby') {
                $bfi2 = $source;

                break;
            }
        }

        if (! is_array($bfi2)) {
            return false;
        }

        $disallowedUse = strtolower(implode(' ', array_map('strval', (array) ($bfi2['disallowed_use'] ?? []))));

        return ($bfi2['source_label'] ?? null) === 'structure_reference_only'
            && data_get($bfi2, 'copy_policy.copy_allowed') === false
            && str_contains($disallowedUse, 'item text')
            && str_contains($disallowedUse, 'body prose');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function draftSelectorAssetCandidates(): array
    {
        return [
            [
                'version' => BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION,
                'asset_key' => 'candidate_m4_boundary_method_non_runtime_v0_1',
                'registry_key' => 'boundary_registry',
                'module_key' => 'module_00_trust_bar',
                'block_key' => 'module_00_trust_bar.candidate_m4_boundary_method_non_runtime_v0_1',
                'block_kind' => 'trust_bar',
                'slot_key' => 'trust_bar.boundary_core',
                'trigger' => [
                    'reading_mode' => ['quick', 'standard'],
                    'scenario' => ['global'],
                    'interpretation_scopes' => ['all'],
                    'source_label' => 'citation_only',
                ],
                'priority' => 90,
                'mutual_exclusion_group' => 'candidate_m4.boundary_method',
                'can_stack_with' => ['method_registry'],
                'reading_modes' => ['quick', 'standard'],
                'scenario' => 'global',
                'scope' => 'all',
                'required_evidence_level' => 'descriptive',
                'evidence_level' => 'descriptive',
                'safety_level' => 'boundary',
                'shareable' => false,
                'shareable_policy' => 'not_shareable',
                'fallback_policy' => 'backend_required',
                'content_source' => 'gpt_selector_asset_batch',
                'provenance' => [
                    'runtime_use' => 'staging_only',
                    'production_use_allowed' => false,
                    'source_id' => 'internal_big5_v2_formal_doc',
                    'source_label' => 'citation_only',
                    'candidate_stage' => 'm4_generate_candidates',
                ],
                'replacement_policy' => [
                    'runtime_use' => 'staging_only',
                    'production_use_allowed' => false,
                    'requires_human_review' => true,
                    'requires_staging_import_gate' => true,
                ],
                'forbidden_public_fields' => [],
                'review_status' => 'draft',
                'public_payload' => [
                    'candidate_ref' => 'candidate_content_m4_method_boundary_v0_1',
                    'payload_kind' => 'selector_asset_candidate',
                    'runtime_use' => 'staging_only',
                    'production_use_allowed' => false,
                    'ready_for_pilot' => false,
                ],
                'internal_metadata' => [],
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function draftContentAssetCandidates(): array
    {
        return [
            [
                'asset_id' => 'candidate_content_m4_method_boundary_v0_1',
                'asset_key' => 'candidate_m4_method_boundary_contract_scaffold',
                'asset_version' => 'v0_1',
                'asset_type' => 'method_boundary',
                'asset_layer' => 'L0_governance',
                'module_key' => 'module_10_method_privacy',
                'section_key' => 'method_privacy',
                'slot_key' => 'method_boundary.method_norm_privacy',
                'copy_role' => 'schema_candidate',
                'reading_mode' => ['standard'],
                'title_zh' => null,
                'body_zh' => null,
                'short_body_zh' => null,
                'cta_zh' => null,
                'applies_to' => [
                    'scale_code' => ['BIG5_OCEAN'],
                    'source_label' => ['citation_only'],
                    'candidate_stage' => ['m4_generate_candidates'],
                ],
                'avoid_when' => [
                    [
                        'field' => 'qa_status',
                        'operator' => 'not_equals',
                        'value' => 'draft',
                        'action' => 'manual_review_required',
                    ],
                ],
                'can_combine_with' => ['boundary_registry'],
                'cannot_combine_with' => ['runtime_use', 'production_use'],
                'dedupe_group' => 'candidate_m4.method_boundary',
                'selection_priority' => 0,
                'selection_specificity' => 0,
                'fallback_allowed' => false,
                'render_surface' => [],
                'body_quality' => [
                    'body_chars' => 0,
                    'sentence_count' => 0,
                    'has_trait_layer' => false,
                    'has_score_band_layer' => false,
                    'has_coupling_layer' => false,
                    'has_facet_layer' => false,
                    'has_real_world_layer' => false,
                    'has_cost_layer' => false,
                    'has_strength_layer' => false,
                    'has_action_layer' => false,
                    'has_boundary_layer' => true,
                    'has_editorial_leakage' => false,
                    'template_risk' => 'candidate_scaffold_only',
                    'rendered_preview_required' => true,
                ],
                'safety_tags' => [
                    'candidate_scaffold',
                    'not_user_visible',
                    'staging_only',
                    'source_trace_required',
                ],
                'qa_status' => 'draft',
                'ready_for_pilot' => false,
                'runtime_use' => 'staging_only',
                'production_use_allowed' => false,
                'source_trace' => [
                    'source_pack' => 'BIG5_RESULT_PAGE_V2_SOURCE_LEDGER',
                    'source_role' => 'citation_only',
                    'derived_from' => [
                        'internal_big5_v2_formal_doc',
                        'source_ledger.json',
                    ],
                    'forbidden_copy_sources_excluded' => true,
                    'bfi_2_copy_used' => false,
                ],
                'repair_log_refs' => [],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $selectorCandidates
     * @param  list<array<string,mixed>>  $contentCandidates
     * @return array<string,mixed>
     */
    private function candidateValidationReport(array $selectorCandidates, array $contentCandidates): array
    {
        $errors = [];
        if ($selectorCandidates === []) {
            $errors[] = 'selector_asset candidates missing';
        }
        if ($contentCandidates === []) {
            $errors[] = 'content_asset candidates missing';
        }
        foreach ($this->selectorValidator->validateAssetSet($selectorCandidates) as $error) {
            $errors[] = 'selector_asset: '.$error;
        }
        foreach ($contentCandidates as $index => $candidate) {
            foreach ($this->validateContentAssetCandidate($candidate) as $error) {
                $errors[] = "content_asset {$index}: {$error}";
            }
        }

        return [
            'status' => $errors === [] ? 'pass' : 'blocked',
            'selector_asset_candidate_count' => count($selectorCandidates),
            'content_asset_candidate_count' => count($contentCandidates),
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return list<string>
     */
    private function validateContentAssetCandidate(array $candidate): array
    {
        $schema = $this->contentAssetSchema();
        $errors = [];

        foreach ((array) ($schema['required_fields'] ?? []) as $field) {
            if (is_string($field) && ! array_key_exists($field, $candidate)) {
                $errors[] = "content asset missing {$field}";
            }
        }

        foreach ([
            'asset_type' => (array) ($schema['asset_type_enum'] ?? []),
            'asset_layer' => (array) ($schema['asset_layer_enum'] ?? []),
            'qa_status' => (array) data_get($schema, 'field_definitions.qa_status.allowed', []),
            'runtime_use' => (array) data_get($schema, 'field_definitions.runtime_use.allowed', []),
        ] as $field => $allowed) {
            if (! in_array((string) ($candidate[$field] ?? ''), $allowed, true)) {
                $errors[] = "{$field} is invalid: ".(string) ($candidate[$field] ?? '');
            }
        }

        foreach ((array) ($candidate['reading_mode'] ?? []) as $mode) {
            if (! in_array($mode, (array) data_get($schema, 'field_definitions.reading_mode.allowed', []), true)) {
                $errors[] = "reading_mode is invalid: {$mode}";
            }
        }

        if (($candidate['ready_for_pilot'] ?? null) !== false) {
            $errors[] = 'ready_for_pilot must be false';
        }
        if (($candidate['runtime_use'] ?? null) !== 'staging_only') {
            $errors[] = 'runtime_use must be staging_only';
        }
        if (($candidate['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'production_use_allowed must be false';
        }
        if (! is_array($candidate['source_trace'] ?? null)) {
            $errors[] = 'source_trace must be an object';
        }
        if (data_get($candidate, 'source_trace.bfi_2_copy_used') !== false) {
            $errors[] = 'source_trace.bfi_2_copy_used must be false';
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    private function contentAssetSchema(): array
    {
        $path = base_path(self::CONTENT_ASSET_SCHEMA_RELATIVE_PATH);
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Content asset schema is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $selectorCandidates
     * @param  list<array<string,mixed>>  $contentCandidates
     * @return array<string,mixed>
     */
    private function candidateLeakScan(array $selectorCandidates, array $contentCandidates): array
    {
        $hits = [];
        foreach ($selectorCandidates as $index => $candidate) {
            $hits = array_merge(
                $hits,
                $this->scanPayload((array) ($candidate['public_payload'] ?? []), "selector_asset_candidate:{$index}", 'selector_public_payload')
            );
        }
        foreach ($contentCandidates as $index => $candidate) {
            $publicFields = array_intersect_key($candidate, array_flip(['title_zh', 'body_zh', 'short_body_zh', 'cta_zh']));
            $hits = array_merge(
                $hits,
                $this->scanPayload($publicFields, "content_asset_candidate:{$index}", 'content_public_fields')
            );
        }

        return [
            'status' => $hits === [] ? 'pass' : 'blocked',
            'hit_count' => count($hits),
            'hits' => $hits,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readOptionalJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>|null  $reviewManifest
     * @return list<string>
     */
    private function reviewManifestErrors(?array $reviewManifest): array
    {
        if ($reviewManifest === null) {
            return ['review_manifest.json missing'];
        }

        $errors = [];
        if (($reviewManifest['human_reviewed'] ?? null) !== true) {
            $errors[] = 'review_manifest human_reviewed must be true';
        }
        if (($reviewManifest['review_status'] ?? null) !== 'approved_for_staging') {
            $errors[] = 'review_manifest review_status must be approved_for_staging';
        }
        if (($reviewManifest['runtime_use'] ?? null) !== 'staging_only') {
            $errors[] = 'review_manifest runtime_use must be staging_only';
        }
        if (($reviewManifest['production_use_allowed'] ?? null) !== false) {
            $errors[] = 'review_manifest production_use_allowed must be false';
        }
        if (($reviewManifest['ready_for_pilot'] ?? null) !== false) {
            $errors[] = 'review_manifest ready_for_pilot must be false';
        }
        if ((string) ($reviewManifest['reviewed_by'] ?? '') === '') {
            $errors[] = 'review_manifest reviewed_by missing';
        }
        if ((string) ($reviewManifest['reviewed_at'] ?? '') === '') {
            $errors[] = 'review_manifest reviewed_at missing';
        }
        foreach (['selector_asset_candidates.jsonl', 'content_asset_candidates.jsonl'] as $filename) {
            if (! in_array($filename, (array) ($reviewManifest['approved_candidate_files'] ?? []), true)) {
                $errors[] = "review_manifest approved_candidate_files missing {$filename}";
            }
        }

        return $errors;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectSelectorAssets(string $contentAssetRoot): array
    {
        $path = rtrim($contentAssetRoot, '/').'/'.self::SELECTOR_ASSET_RELATIVE_PATH;
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $assets = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded)) {
                    $decoded['_source_file'] = $this->redactPath($path);
                    $assets[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $assets;
    }

    /**
     * @return list<array<string,string>>
     */
    private function scanPayload(array $payload, string $sourceFile, string $surface): array
    {
        $hits = [];
        $this->scanForbiddenKeys($payload, $sourceFile, $surface, 'payload', $hits);

        $flat = strtolower(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        foreach (self::FORBIDDEN_PUBLIC_TERMS as $term) {
            if (str_contains($flat, strtolower($term))) {
                $hits[] = [
                    'surface' => $surface,
                    'source_file' => $this->redactPath($sourceFile),
                    'kind' => 'term',
                    'value' => $term,
                ];
            }
        }

        return $hits;
    }

    /**
     * @param  list<array<string,string>>  $hits
     */
    private function scanForbiddenKeys(array $payload, string $sourceFile, string $surface, string $path, array &$hits): void
    {
        foreach ($payload as $key => $value) {
            $keyString = (string) $key;
            $nextPath = $path.'.'.$keyString;
            if (in_array($keyString, self::FORBIDDEN_PUBLIC_FIELDS, true)) {
                $hits[] = [
                    'surface' => $surface,
                    'source_file' => $this->redactPath($sourceFile),
                    'kind' => 'field',
                    'value' => $nextPath,
                ];
            }
            if (is_array($value)) {
                $this->scanForbiddenKeys($value, $sourceFile, $surface, $nextPath, $hits);
            }
        }
    }

    private function artifactDir(string $artifactDir, string $runId): string
    {
        $root = trim($artifactDir) !== ''
            ? $this->absolutePath($artifactDir)
            : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return rtrim($root, '/').'/'.$runId;
    }

    private function optionalPath(string $path, string $default): string
    {
        return trim($path) === '' ? $default : $this->absolutePath($path);
    }

    private function absolutePath(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid path.');
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function sanitizeRunId(string $runId): string
    {
        $runId = trim($runId) !== '' ? trim($runId) : gmdate('Ymd\THis\Z');
        $runId = preg_replace('/[^A-Za-z0-9_.-]/', '-', $runId) ?: gmdate('Ymd\THis\Z');

        return trim($runId, '.-') !== '' ? $runId : gmdate('Ymd\THis\Z');
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create artifact directory: '.$this->redactPath($path));
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeJson(string $path, array $payload): array
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded) || file_put_contents($path, $encoded.PHP_EOL) === false) {
            throw new RuntimeException('Unable to write artifact: '.$this->redactPath($path));
        }

        return $this->fileRef($path);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function writeJsonl(string $path, array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($encoded)) {
                throw new RuntimeException('Unable to encode JSONL artifact: '.$this->redactPath($path));
            }
            $lines[] = $encoded;
        }

        if (file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL) === false) {
            throw new RuntimeException('Unable to write artifact: '.$this->redactPath($path));
        }

        return $this->fileRef($path);
    }

    /**
     * @return array<string,mixed>
     */
    private function writeText(string $path, string $text): array
    {
        if (file_put_contents($path, $text) === false) {
            throw new RuntimeException('Unable to write artifact: '.$this->redactPath($path));
        }

        return $this->fileRef($path);
    }

    /**
     * @return array<string,mixed>
     */
    private function fileRef(string $path): array
    {
        return [
            'relative_path' => $this->redactPath($path),
            'sha256' => hash_file('sha256', $path) ?: '',
            'size' => filesize($path) ?: 0,
        ];
    }

    /**
     * @return list<SplFileInfo>
     */
    private function filesUnder(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function redactPath(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    /**
     * @return array<string,bool>
     */
    private function negativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'frontend_copy_write' => false,
            'selector_asset_generation' => false,
            'runtime_flag_change' => false,
            'release_snapshot_change' => false,
            'production_import_gate_change' => false,
            'rollout_gate_change' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function candidateNegativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'content_assets_write' => false,
            'frontend_copy_write' => false,
            'final_result_payload_generation' => false,
            'runtime_flag_change' => false,
            'release_snapshot_change' => false,
            'production_import_gate_change' => false,
            'rollout_gate_change' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function stagingImportNegativeGuarantees(): array
    {
        return [
            'database_write' => false,
            'cms_write' => false,
            'frontend_copy_write' => false,
            'final_result_payload_generation' => false,
            'runtime_flag_change' => false,
            'release_snapshot_change' => false,
            'production_import_gate_change' => false,
            'rollout_gate_change' => false,
        ];
    }
}
