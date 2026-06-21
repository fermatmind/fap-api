<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\AssetAgent;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2SelectorAssetContract;
use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetPackageLoader;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

final class BigFiveResultPageV2AssetAgent
{
    public const SCHEMA_VERSION = 'fap.big5.result_page_v2.asset_agent.audit.v0.1';

    public const DEFAULT_ARTIFACT_RELATIVE_DIR = 'artifacts/big5_result_page_v2_agent';

    private const COVERAGE_MATRIX_RELATIVE_PATH = 'content_assets/big5/result_page_v2/personalization_coverage_matrix_v0_2.json';

    private const REGISTRY_RELATIVE_PATH = 'content_packs/BIG5_OCEAN/v2/registry';

    private const LEAK_FORBIDDEN_TERMS = [
        'type_code',
        'fixed_type',
        'user_confirmed_type',
        'raw score',
        'raw scores',
        'raw_score',
        'raw_scores',
        'internal metadata',
        'internal_metadata',
    ];

    private const SHARE_FORBIDDEN_TERMS = [
        'percentile',
        'percentiles',
        'raw_score',
        'raw_scores',
        'raw score',
        'raw scores',
        'domain_vector',
        'facet_vector',
    ];

    private const TEST_FILTERS = [
        'BigFiveResultPageV2SelectorAssetValidatorTest',
        'BigFiveResultPageV2ContentAssetLookupTest',
        'BigFiveResultPageV2DeterministicSelectorTest',
        'BigFiveResultPageV2StagingComposerTest',
        'BigFiveResultPageV2ValidatorTest',
    ];

    public function __construct(
        private readonly BigFiveV2AssetPackageLoader $packageLoader = new BigFiveV2AssetPackageLoader,
    ) {}

    /**
     * @param  array{
     *   run_id?:string,
     *   artifact_dir?:string,
     *   content_asset_root?:string,
     *   registry_root?:string,
     *   coverage_matrix_path?:string,
     *   strict?:bool,
     *   run_tests?:bool
     * }  $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $runId = $this->sanitizeRunId((string) ($options['run_id'] ?? ''));
        $artifactDir = $this->artifactDir((string) ($options['artifact_dir'] ?? ''), $runId);
        $contentAssetRoot = (string) ($options['content_asset_root'] ?? base_path(BigFiveV2AssetPackageLoader::ROOT_RELATIVE_PATH));
        $registryRoot = (string) ($options['registry_root'] ?? base_path(self::REGISTRY_RELATIVE_PATH));
        $coverageMatrixPath = (string) ($options['coverage_matrix_path'] ?? base_path(self::COVERAGE_MATRIX_RELATIVE_PATH));
        $strict = ($options['strict'] ?? false) === true;
        $runTests = ($options['run_tests'] ?? false) === true;

        $matrix = $this->readJsonObject($coverageMatrixPath);
        $records = $this->collectAssetRecords($contentAssetRoot);
        $inventory = $this->buildInventory($contentAssetRoot, $registryRoot, $coverageMatrixPath, $matrix, $records);
        $sourceClassification = $this->sourceClassification();
        $batchPlan = $this->buildBatchPlan($matrix, $records);
        $qaSummary = $this->buildQaSummary($records, $runTests);
        $promotionReport = $this->buildPromotionReport($inventory, $batchPlan, $qaSummary);
        $repairLog = $this->buildRepairLog($inventory, $batchPlan, $qaSummary);

        $this->ensureDirectory($artifactDir);

        $files = [
            'inventory_gaps.json' => $inventory,
            'source_license_classification.json' => $sourceClassification,
            'p0_batch_plan.json' => $batchPlan,
            'qa_eval_summary.json' => $qaSummary,
            'repair_log.json' => $repairLog,
        ];

        $written = [];
        foreach ($files as $filename => $payload) {
            $written[$filename] = $this->writeJson($artifactDir.'/'.$filename, $payload);
        }
        $written['promotion_gate_report.md'] = $this->writeText(
            $artifactDir.'/promotion_gate_report.md',
            $this->renderPromotionMarkdown($promotionReport)
        );

        $strictFailures = $this->strictFailures($inventory, $batchPlan, $qaSummary, $promotionReport);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => ! $strict || $strictFailures === [],
            'status' => ($strict && $strictFailures !== []) ? 'blocked' : 'success',
            'run_id' => $runId,
            'artifact_dir' => $artifactDir,
            'artifacts' => $written,
            'strict' => $strict,
            'strict_failures' => $strictFailures,
            'summary' => [
                'asset_record_count' => (int) ($inventory['asset_record_count'] ?? 0),
                'p0_batch_count' => (int) ($batchPlan['p0_batch_count'] ?? 0),
                'p0_blocker_count' => count((array) ($promotionReport['p0_blockers'] ?? [])),
                'leak_hit_count' => (int) data_get($qaSummary, 'leak_scan.hit_count', 0),
                'ready_for_pilot' => false,
                'ready_for_runtime' => false,
                'ready_for_production' => false,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $matrix
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function buildInventory(string $contentAssetRoot, string $registryRoot, string $coverageMatrixPath, array $matrix, array $records): array
    {
        $packageInventory = $this->packageLoader->inventory($contentAssetRoot)->toArray();
        $counts = $this->recordCounts($records);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'inventory_scanner',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'inputs' => [
                'content_asset_root' => $this->redactPath($contentAssetRoot),
                'registry_root' => $this->redactPath($registryRoot),
                'coverage_matrix_path' => $this->redactPath($coverageMatrixPath),
                'contracts' => [
                    'payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
                    'payload_schema' => BigFiveResultPageV2Contract::SCHEMA_VERSION,
                    'selector_asset_schema' => BigFiveResultPageV2SelectorAssetContract::SCHEMA_VERSION,
                ],
            ],
            'asset_packages' => [
                'package_count' => (int) ($packageInventory['package_count'] ?? 0),
                'file_count' => (int) ($packageInventory['file_count'] ?? 0),
                'valid' => (bool) ($packageInventory['valid'] ?? false),
                'errors' => (array) ($packageInventory['errors'] ?? []),
            ],
            'asset_record_count' => count($records),
            'counts' => $counts,
            'gaps' => [
                'registry' => $this->missingValues(BigFiveResultPageV2SelectorAssetContract::REGISTRY_KEYS, (array) ($counts['registry_key'] ?? [])),
                'module' => $this->missingValues(BigFiveResultPageV2Contract::MODULE_KEYS, (array) ($counts['module_key'] ?? [])),
                'scope' => $this->missingValues(BigFiveResultPageV2Contract::INTERPRETATION_SCOPES, (array) ($counts['scope'] ?? [])),
                'reading_mode' => $this->missingValues(BigFiveResultPageV2SelectorAssetContract::READING_MODES, (array) ($counts['reading_mode'] ?? [])),
                'share_safety' => $this->shareSafetyGaps($records, $counts),
                'norm_unavailable' => $this->scopeGap('norm_unavailable', $counts),
                'low_quality' => $this->scopeGap('low_quality', $counts),
            ],
            'registry_files' => $this->registryFileSummary($registryRoot),
            'coverage_matrix' => [
                'schema' => (string) ($matrix['schema'] ?? ''),
                'entry_count' => count((array) ($matrix['entries'] ?? [])),
                'p0_entry_count' => count(array_filter(
                    (array) ($matrix['entries'] ?? []),
                    static fn (mixed $entry): bool => is_array($entry) && ($entry['priority_tier'] ?? null) === 'P0'
                )),
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceClassification(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'source_license_classifier',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'classifications' => [
                [
                    'source_id' => 'ipip_official',
                    'label' => 'public_domain_source',
                    'allowed_use' => 'May be cited and used as an open psychometric source; keep scale citations with derived assets.',
                    'copy_policy' => 'Allowed for IPIP source material, but generated FermatMind assets must still pass local safety and validator gates.',
                ],
                [
                    'source_id' => 'bfi_2_colby',
                    'label' => 'structure_reference_only',
                    'allowed_use' => 'Use only for high-level domain/facet terminology and structure comparison.',
                    'copy_policy' => 'Do not copy BFI-2 items, scoring text, report copy, or body prose.',
                ],
                [
                    'source_id' => 'open_source_bigfive_web',
                    'label' => 'structure_reference_only',
                    'allowed_use' => 'Use as implementation and packaging reference for open-source Big Five projects.',
                    'copy_policy' => 'Do not copy product-facing result-page copy into FermatMind assets.',
                ],
                [
                    'source_id' => 'open_source_big_five_data',
                    'label' => 'citation_only',
                    'allowed_use' => 'Use as public dataset context only when cited and reviewed.',
                    'copy_policy' => 'Do not import rows, raw respondent data, or user-like samples into result-page assets.',
                ],
                [
                    'source_id' => 'openpsychometrics_ipip_bffm',
                    'label' => 'citation_only',
                    'allowed_use' => 'Use as public reference context for IPIP-style Big Five testing.',
                    'copy_policy' => 'Do not copy page prose or test-item presentation.',
                ],
                [
                    'source_id' => 'proprietary_personality_reports_and_bfi2_item_text',
                    'label' => 'forbidden_copy_source',
                    'allowed_use' => 'None for copy generation.',
                    'copy_policy' => 'Forbidden as a copy source for selector_asset or content_asset body text.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $matrix
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function buildBatchPlan(array $matrix, array $records): array
    {
        $counts = $this->recordCounts($records);
        $p0Entries = array_values(array_filter(
            (array) ($matrix['entries'] ?? []),
            static fn (mixed $entry): bool => is_array($entry) && ($entry['priority_tier'] ?? null) === 'P0'
        ));

        usort($p0Entries, static fn (array $left, array $right): int => ((int) ($right['priority'] ?? 0)) <=> ((int) ($left['priority'] ?? 0)));

        $batches = [];
        foreach ($p0Entries as $index => $entry) {
            $registryKey = (string) ($entry['registry_key'] ?? '');
            $moduleKey = (string) ($entry['target_module'] ?? '');
            $scope = (string) ($entry['scope'] ?? '');
            $observed = $this->observedForMatrixEntry($records, $entry);

            $batches[] = [
                'batch_id' => sprintf('P0-%02d-%s', $index + 1, $this->slug((string) ($entry['coverage_group'] ?? 'coverage'))),
                'priority_tier' => 'P0',
                'priority' => (int) ($entry['priority'] ?? 0),
                'coverage_group' => (string) ($entry['coverage_group'] ?? ''),
                'registry_key' => $registryKey,
                'target_module' => $moduleKey,
                'slot_key' => (string) ($entry['slot_key'] ?? ''),
                'scope' => $scope,
                'reading_modes' => array_values((array) ($entry['reading_modes'] ?? [])),
                'shareable_policy' => (string) ($entry['shareable_policy'] ?? ''),
                'safety_level' => (string) ($entry['safety_level'] ?? ''),
                'fallback_policy' => (string) ($entry['fallback_policy'] ?? ''),
                'estimated_block_count' => (int) ($entry['estimated_block_count'] ?? 0),
                'declared_missing_blocks' => (int) ($entry['missing_blocks'] ?? 0),
                'observed_selector_records' => $observed,
                'current_counts' => [
                    'registry' => (int) (($counts['registry_key'][$registryKey] ?? 0)),
                    'module' => (int) (($counts['module_key'][$moduleKey] ?? 0)),
                    'scope' => $scope === '' ? 0 : (int) (($counts['scope'][$scope] ?? 0)),
                ],
                'output_policy' => [
                    'allowed_artifact_type' => 'plan_only',
                    'candidate_generation_allowed' => false,
                    'runtime_use' => 'staging_only',
                    'production_use_allowed' => false,
                ],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'asset_planner',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'coverage_matrix_schema' => (string) ($matrix['schema'] ?? ''),
            'p0_batch_count' => count($batches),
            'batches' => $batches,
            'deferred' => [
                'candidate_generation' => 'Milestone 2 only; do not emit selector_asset or content_asset candidates in this audit command.',
                'runtime_import' => 'Requires release snapshot, production import gate, rollout gate, audit fields, and Ops panel.',
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function buildQaSummary(array $records, bool $runTests): array
    {
        $leakScan = $this->leakScan($records);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'qa_eval_runner',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'required_test_filters' => self::TEST_FILTERS,
            'test_execution' => $runTests ? $this->runTargetedTests() : [
                'status' => 'not_run',
                'reason' => 'Pass --run-tests to execute targeted PHPUnit filters from this audit command.',
                'commands' => array_map(
                    static fn (string $filter): string => 'php artisan test --filter='.$filter,
                    self::TEST_FILTERS
                ),
            ],
            'leak_scan' => $leakScan,
            'forbidden_terms' => [
                'public_payload' => self::LEAK_FORBIDDEN_TERMS,
                'shareable_public_payload' => self::SHARE_FORBIDDEN_TERMS,
            ],
            'negative_guarantees' => $this->negativeGuarantees(),
        ];
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $batchPlan
     * @param  array<string,mixed>  $qaSummary
     * @return array<string,mixed>
     */
    private function buildPromotionReport(array $inventory, array $batchPlan, array $qaSummary): array
    {
        $p0Blockers = [];
        foreach ((array) data_get($inventory, 'gaps.registry', []) as $missing) {
            $p0Blockers[] = 'missing_registry:'.$missing;
        }
        foreach ((array) data_get($inventory, 'gaps.module', []) as $missing) {
            $p0Blockers[] = 'missing_module:'.$missing;
        }
        if ((int) data_get($qaSummary, 'leak_scan.hit_count', 0) > 0) {
            $p0Blockers[] = 'forbidden_public_payload_leak_hits';
        }
        foreach ((array) ($batchPlan['batches'] ?? []) as $batch) {
            if ((int) ($batch['declared_missing_blocks'] ?? 0) > 0) {
                $p0Blockers[] = 'p0_missing_blocks:'.(string) ($batch['coverage_group'] ?? 'unknown');
            }
        }

        $p0Blockers = array_values(array_unique($p0Blockers));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'promotion_gate_reporter',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'coverage_qa' => [
                'status' => $p0Blockers === [] ? 'pass_for_staging_review' : 'blocked',
                'p0_batch_count' => (int) ($batchPlan['p0_batch_count'] ?? 0),
            ],
            'safety_qa' => [
                'status' => ((int) data_get($qaSummary, 'leak_scan.hit_count', 0) === 0) ? 'pass_for_staging_review' : 'blocked',
                'leak_hit_count' => (int) data_get($qaSummary, 'leak_scan.hit_count', 0),
            ],
            'editorial_qa' => [
                'status' => 'not_run',
                'reason' => 'Milestone 1 scanner does not generate body copy; editorial QA belongs to reviewed candidate batches.',
            ],
            'mapping_qa' => [
                'status' => data_get($inventory, 'gaps.registry', []) === [] ? 'pass_for_staging_review' : 'blocked',
                'missing_registry_count' => count((array) data_get($inventory, 'gaps.registry', [])),
            ],
            'rendered_preview_qa' => [
                'status' => 'not_run',
                'reason' => 'No rendered payload is generated by this agent.',
            ],
            'repair_log' => 'repair_log.json',
            'p0_blockers' => $p0Blockers,
            'ready_for_pilot' => false,
            'ready_for_runtime' => false,
            'ready_for_production' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $batchPlan
     * @param  array<string,mixed>  $qaSummary
     * @return array<string,mixed>
     */
    private function buildRepairLog(array $inventory, array $batchPlan, array $qaSummary): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'repair_log',
            'runtime_use' => 'staging_only',
            'production_use_allowed' => false,
            'entries' => [
                [
                    'area' => 'inventory',
                    'status' => data_get($inventory, 'asset_packages.valid') === true ? 'observed' : 'needs_repair',
                    'summary' => 'Package inventory scanned without raw payload export.',
                ],
                [
                    'area' => 'p0_planning',
                    'status' => ((int) ($batchPlan['p0_batch_count'] ?? 0) > 0) ? 'planned' : 'needs_repair',
                    'summary' => 'P0 coverage batches derived from personalization coverage matrix.',
                ],
                [
                    'area' => 'leak_scan',
                    'status' => ((int) data_get($qaSummary, 'leak_scan.hit_count', 0) === 0) ? 'pass' : 'blocked',
                    'summary' => 'Public payload and shareable payload scan completed.',
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,mixed>
     */
    private function leakScan(array $records): array
    {
        $hits = [];

        foreach ($records as $record) {
            $publicPayload = $record['public_payload'] ?? null;
            if (is_array($publicPayload)) {
                $hits = array_merge(
                    $hits,
                    $this->scanPayloadTerms($publicPayload, self::LEAK_FORBIDDEN_TERMS, (string) ($record['_source_file'] ?? 'unknown'), 'public_payload')
                );
            }

            if (($record['shareable'] ?? false) === true && is_array($publicPayload)) {
                $hits = array_merge(
                    $hits,
                    $this->scanPayloadTerms($publicPayload, self::SHARE_FORBIDDEN_TERMS, (string) ($record['_source_file'] ?? 'unknown'), 'shareable_public_payload')
                );
            }
        }

        return [
            'status' => $hits === [] ? 'pass' : 'blocked',
            'hit_count' => count($hits),
            'hits' => array_slice($hits, 0, 100),
            'truncated' => count($hits) > 100,
            'scan_scope' => 'asset public_payload fields only; internal metadata is counted by key presence but not exported as raw payload',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $terms
     * @return list<array<string,string>>
     */
    private function scanPayloadTerms(array $payload, array $terms, string $sourceFile, string $surface): array
    {
        $flat = strtolower($this->flattenForScan($payload));
        $hits = [];

        foreach ($terms as $term) {
            if (str_contains($flat, strtolower($term))) {
                $hits[] = [
                    'surface' => $surface,
                    'term' => $term,
                    'source_file' => $this->redactPath($sourceFile),
                ];
            }
        }

        return $hits;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function flattenForScan(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @return array<string,mixed>
     */
    private function runTargetedTests(): array
    {
        $results = [];
        $allPassed = true;

        foreach (self::TEST_FILTERS as $filter) {
            $process = new Process([PHP_BINARY, 'artisan', 'test', '--filter='.$filter], base_path());
            $process->setTimeout(300);
            $process->run();

            $passed = $process->isSuccessful();
            $allPassed = $allPassed && $passed;
            $results[] = [
                'filter' => $filter,
                'exit_code' => $process->getExitCode(),
                'passed' => $passed,
                'output_tail' => $this->tail($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        }

        return [
            'status' => $allPassed ? 'pass' : 'failed',
            'results' => $results,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function registryFileSummary(string $registryRoot): array
    {
        if (! is_dir($registryRoot)) {
            return [
                'exists' => false,
                'file_count' => 0,
                'directories' => [],
            ];
        }

        $directories = [];
        $fileCount = 0;
        foreach ($this->filesUnder($registryRoot) as $file) {
            $fileCount++;
            $relative = $this->relativePath($file->getPath(), $registryRoot);
            $directories[$relative === '' ? '.' : $relative] = ($directories[$relative === '' ? '.' : $relative] ?? 0) + 1;
        }
        ksort($directories);

        return [
            'exists' => true,
            'file_count' => $fileCount,
            'directories' => $directories,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function collectAssetRecords(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $records = [];
        foreach ($this->filesUnder($root) as $file) {
            $extension = strtolower($file->getExtension());
            if (! in_array($extension, ['json', 'jsonl'], true)) {
                continue;
            }

            $records = array_merge($records, $this->recordsFromFile($file));
        }

        return $records;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recordsFromFile(SplFileInfo $file): array
    {
        if ($file->getExtension() === 'jsonl') {
            return $this->recordsFromJsonl($file);
        }

        $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->assetLikeRecords($decoded, $this->relativePath($file->getPathname(), base_path()));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recordsFromJsonl(SplFileInfo $file): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return [];
        }

        $records = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded) && $this->isAssetLike($decoded)) {
                    $decoded['_source_file'] = $this->relativePath($file->getPathname(), base_path());
                    $records[] = $decoded;
                }
            }
        } finally {
            fclose($handle);
        }

        return $records;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<array<string,mixed>>
     */
    private function assetLikeRecords(array $decoded, string $sourceFile): array
    {
        $records = [];
        $candidates = $this->isList($decoded) ? $decoded : [$decoded];
        if (isset($decoded['assets']) && is_array($decoded['assets'])) {
            $candidates = $decoded['assets'];
        } elseif (isset($decoded['records']) && is_array($decoded['records'])) {
            $candidates = $decoded['records'];
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $this->isAssetLike($candidate)) {
                $candidate['_source_file'] = $sourceFile;
                $records[] = $candidate;
            }
        }

        return $records;
    }

    /**
     * @param  array<mixed>  $record
     */
    private function isAssetLike(array $record): bool
    {
        foreach (['asset_key', 'registry_key', 'module_key', 'scope', 'reading_modes', 'shareable_policy'] as $key) {
            if (array_key_exists($key, $record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return array<string,array<string,int>>
     */
    private function recordCounts(array $records): array
    {
        $counts = [
            'registry_key' => [],
            'module_key' => [],
            'scope' => [],
            'reading_mode' => [],
            'shareable_policy' => [],
            'safety_level' => [],
            'review_status' => [],
        ];

        foreach ($records as $record) {
            foreach (['registry_key', 'module_key', 'scope', 'shareable_policy', 'safety_level', 'review_status'] as $key) {
                $value = $record[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $counts[$key][$value] = ($counts[$key][$value] ?? 0) + 1;
                }
            }

            foreach ((array) ($record['reading_modes'] ?? []) as $mode) {
                if (is_string($mode) && $mode !== '') {
                    $counts['reading_mode'][$mode] = ($counts['reading_mode'][$mode] ?? 0) + 1;
                }
            }
        }

        foreach ($counts as $key => $values) {
            ksort($values);
            $counts[$key] = $values;
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
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,array<string,int>>  $counts
     * @return array<string,mixed>
     */
    private function shareSafetyGaps(array $records, array $counts): array
    {
        $shareableCount = count(array_filter($records, static fn (array $record): bool => ($record['shareable'] ?? false) === true));
        $registryCount = (int) (($counts['registry_key']['share_safety_registry'] ?? 0));
        $shareSafeModeCount = (int) (($counts['reading_mode']['share_safe'] ?? 0));

        return [
            'share_safety_registry_count' => $registryCount,
            'share_safe_reading_mode_count' => $shareSafeModeCount,
            'shareable_true_count' => $shareableCount,
            'missing' => array_values(array_filter([
                $registryCount > 0 ? null : 'share_safety_registry',
                $shareSafeModeCount > 0 ? null : 'share_safe_reading_mode',
            ])),
        ];
    }

    /**
     * @param  array<string,array<string,int>>  $counts
     * @return array<string,mixed>
     */
    private function scopeGap(string $scope, array $counts): array
    {
        $count = (int) (($counts['scope'][$scope] ?? 0));

        return [
            'scope' => $scope,
            'count' => $count,
            'missing' => $count === 0,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @param  array<string,mixed>  $entry
     */
    private function observedForMatrixEntry(array $records, array $entry): int
    {
        $registryKey = (string) ($entry['registry_key'] ?? '');
        $moduleKey = (string) ($entry['target_module'] ?? '');
        $scope = (string) ($entry['scope'] ?? '');
        $requiredModes = array_values((array) ($entry['reading_modes'] ?? []));

        return count(array_filter($records, static function (array $record) use ($registryKey, $moduleKey, $scope, $requiredModes): bool {
            if (($record['registry_key'] ?? null) !== $registryKey) {
                return false;
            }
            if (($record['module_key'] ?? null) !== $moduleKey) {
                return false;
            }
            if ($scope !== '' && ! in_array($scope, ['all', 'all_scopes', 'quality_acceptable', 'facet_supported'], true) && ($record['scope'] ?? null) !== $scope) {
                return false;
            }
            $recordModes = (array) ($record['reading_modes'] ?? []);
            if ($requiredModes !== [] && array_intersect($requiredModes, $recordModes) === []) {
                return false;
            }

            return true;
        }));
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

    /**
     * @return array<string,mixed>
     */
    private function readJsonObject(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($json)) {
            throw new RuntimeException('Unable to read JSON file: '.$this->redactPath($path));
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid JSON file: '.$this->redactPath($path));
        }

        return $decoded;
    }

    private function artifactDir(string $artifactDir, string $runId): string
    {
        $root = trim($artifactDir) !== ''
            ? $this->absolutePath($artifactDir)
            : base_path(self::DEFAULT_ARTIFACT_RELATIVE_DIR);

        return rtrim($root, '/').'/'.$runId;
    }

    private function sanitizeRunId(string $runId): string
    {
        $runId = trim($runId);
        if ($runId === '') {
            $runId = gmdate('Ymd\THis\Z');
        }

        $runId = preg_replace('/[^A-Za-z0-9_.-]/', '-', $runId) ?: gmdate('Ymd\THis\Z');

        return trim($runId, '.-') !== '' ? $runId : gmdate('Ymd\THis\Z');
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid artifact directory.');
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create artifact directory: '.$this->redactPath($path));
        }
        if (! is_writable($path)) {
            throw new RuntimeException('Artifact directory is not writable: '.$this->redactPath($path));
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
            'path' => $path,
            'relative_path' => $this->relativePath($path, base_path()),
            'size' => filesize($path) ?: 0,
            'sha256' => hash_file('sha256', $path) ?: '',
        ];
    }

    /**
     * @param  array<string,mixed>  $promotionReport
     */
    private function renderPromotionMarkdown(array $promotionReport): string
    {
        $lines = [
            '# Big Five Result Page V2 Asset Agent Promotion Gate Report',
            '',
            '- runtime_use: staging_only',
            '- production_use_allowed: false',
            '- ready_for_pilot: false',
            '- ready_for_runtime: false',
            '- ready_for_production: false',
            '',
            '## Gate Status',
            '',
        ];

        foreach (['coverage_qa', 'safety_qa', 'editorial_qa', 'mapping_qa', 'rendered_preview_qa'] as $key) {
            $gate = (array) ($promotionReport[$key] ?? []);
            $lines[] = '- '.$key.': '.(string) ($gate['status'] ?? 'unknown');
        }

        $lines[] = '';
        $lines[] = '## P0 Blockers';
        $lines[] = '';
        $blockers = (array) ($promotionReport['p0_blockers'] ?? []);
        if ($blockers === []) {
            $lines[] = '- none';
        } else {
            foreach ($blockers as $blocker) {
                $lines[] = '- '.(string) $blocker;
            }
        }

        $lines[] = '';
        $lines[] = '## Negative Guarantees';
        $lines[] = '';
        foreach ($this->negativeGuarantees() as $key => $value) {
            $lines[] = '- '.$key.': '.($value ? 'true' : 'false');
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @param  array<string,mixed>  $inventory
     * @param  array<string,mixed>  $batchPlan
     * @param  array<string,mixed>  $qaSummary
     * @param  array<string,mixed>  $promotionReport
     * @return list<string>
     */
    private function strictFailures(array $inventory, array $batchPlan, array $qaSummary, array $promotionReport): array
    {
        $failures = [];
        if (data_get($inventory, 'asset_packages.valid') !== true) {
            $failures[] = 'asset_package_inventory_invalid';
        }
        if ((int) ($batchPlan['p0_batch_count'] ?? 0) === 0) {
            $failures[] = 'p0_batch_plan_empty';
        }
        if ((int) data_get($qaSummary, 'leak_scan.hit_count', 0) > 0) {
            $failures[] = 'forbidden_leak_hits';
        }
        if ((array) ($promotionReport['p0_blockers'] ?? []) !== []) {
            $failures[] = 'p0_blockers_present';
        }

        return array_values(array_unique($failures));
    }

    private function redactPath(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }

    private function relativePath(string $path, string $basePath): string
    {
        $normalizedPath = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $normalizedBase = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $basePath), '/').'/';

        return str_starts_with($normalizedPath, $normalizedBase)
            ? substr($normalizedPath, strlen($normalizedBase))
            : $normalizedPath;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?: 'coverage');

        return trim($slug, '-') ?: 'coverage';
    }

    private function tail(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $tail = array_slice($lines, -20);

        return implode("\n", $tail);
    }

    /**
     * @param  array<mixed>  $array
     */
    private function isList(array $array): bool
    {
        return array_is_list($array);
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
            'result_payload_generation' => false,
            'runtime_flag_change' => false,
            'release_snapshot_change' => false,
            'production_import_gate_change' => false,
            'rollout_gate_change' => false,
            'ops_panel_change' => false,
            'production_env_change' => false,
        ];
    }
}
