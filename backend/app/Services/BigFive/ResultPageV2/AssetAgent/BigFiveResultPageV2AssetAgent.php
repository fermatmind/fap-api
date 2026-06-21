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
}
