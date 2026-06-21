<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\AssetAgent;

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

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/big5_result_page_v2_agent';

    private const SOURCE_LEDGER_RELATIVE_PATH = 'content_assets/big5/result_page_v2/source_ledger';

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
        $goNoGo = $this->buildGoNoGo($inventory, $validationReport, $safetyReport);
        $strictFailures = $this->strictFailures($inventory, $validationReport, $safetyReport);

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
                'selector_asset_count' => count($assets),
                'validation_error_count' => (int) ($validationReport['error_count'] ?? 0),
                'leak_hit_count' => (int) ($safetyReport['leak_scan']['hit_count'] ?? 0),
                'source_ledger_valid' => (bool) ($inventory['source_ledger']['valid'] ?? false),
                'asset_inventory_valid' => (bool) ($inventory['asset_inventory']['valid'] ?? false),
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
     */
    private function buildGoNoGo(array $inventory, array $validationReport, array $safetyReport): string
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
            '## Deferred',
            '',
            '- No selector assets are generated.',
            '- No CMS import is performed.',
            '- No runtime wrapper, pilot gate, production import gate, or rollout gate is changed.',
        ];

        return implode(PHP_EOL, $lines).PHP_EOL;
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

        return [
            'exists' => true,
            'valid' => $errors === [] && $files !== [],
            'json_count' => count($files),
            'errors' => $errors,
            'files' => $files,
        ];
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
