<?php

declare(strict_types=1);

require_once __DIR__.'/split_legacy_current.php';

final class CareerShardedCurrentAssemblyFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerShardedCurrentAssembler
{
    private const EXPECTED_SLUGS = 1046;

    private const EXPECTED_LOCALE_PAGES = 2092;

    private const EXPECTED_MODULE_ROWS = 20920;

    /** @return array<string,mixed> */
    public function assemble(
        string $repoRoot,
        string $candidateRoot,
        string $legacyAssetsPath,
        string $outputRoot,
    ): array {
        $repoRoot = $this->guardDirectory($repoRoot, 'REPOSITORY_ROOT_INVALID');
        $candidateRoot = $this->guardTemporaryDirectory($repoRoot, $candidateRoot, 'CANDIDATE_ROOT_INVALID');
        $outputRoot = $this->guardTemporaryDirectory($repoRoot, $outputRoot, 'ASSEMBLY_OUTPUT_ROOT_INVALID');
        if ($candidateRoot === $outputRoot) {
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_ROOTS_MUST_DIFFER');
        }
        $legacyAssetsPath = $this->guardLegacyAssets($repoRoot, $legacyAssetsPath);
        $repositoryStatusBefore = $this->repositoryStatus($repoRoot);
        $legacyAssetsShaBefore = hash_file('sha256', $legacyAssetsPath)
            ?: throw new CareerShardedCurrentAssemblyFailure('LEGACY_ASSETS_UNREADABLE');

        $this->assertCandidateInventory($candidateRoot);
        $this->assertOutputInventory($outputRoot);
        $manifest = $this->decodeObjectFile($candidateRoot.'/manifest.json', 'MANIFEST_INVALID');
        [$records, $lineAggregateSha] = $this->loadAndValidateCandidate($candidateRoot, $manifest);
        $this->assertCandidateReports($repoRoot, $candidateRoot, $manifest, $legacyAssetsShaBefore);
        ksort($records, SORT_STRING);

        $legacyHandle = fopen($legacyAssetsPath, 'rb');
        if ($legacyHandle === false) {
            throw new CareerShardedCurrentAssemblyFailure('LEGACY_ASSETS_UNREADABLE');
        }
        $temporaryAssetsPath = $outputRoot.'/assets.jsonl.assembly.tmp';
        $outputHandle = fopen($temporaryAssetsPath, 'wb');
        if ($outputHandle === false) {
            fclose($legacyHandle);
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_WRITE_FAILED');
        }

        $counts = [
            'public_projection_hash_identical' => 0,
            'seo_hash_identical' => 0,
            'faq_and_schema_hash_identical' => 0,
            'sources_and_claim_bindings_hash_identical' => 0,
            'cta_and_internal_links_identical' => 0,
            'component_order_and_payload_identical' => 0,
            'zh_presentation_v1_projection_identical' => 0,
        ];
        $assembledRows = 0;
        try {
            foreach ($records as $slug => $localeRecords) {
                $assembled = $this->assembleRecords($localeRecords);
                $legacyLine = fgets($legacyHandle);
                if ($legacyLine === false) {
                    throw new CareerShardedCurrentAssemblyFailure('LEGACY_COVERAGE_MISMATCH');
                }
                $legacyBytes = rtrim($legacyLine, "\r\n");
                $legacy = $this->decodeCanonicalRow($legacyBytes);
                if (($legacy['canonical_slug'] ?? null) !== $slug) {
                    throw new CareerShardedCurrentAssemblyFailure('LEGACY_SLUG_ORDER_MISMATCH');
                }
                $assembledBytes = CareerLegacyCurrentSharder::canonicalJson($assembled);
                if (! hash_equals($legacyBytes, $assembledBytes)) {
                    throw new CareerShardedCurrentAssemblyFailure('FULL_ROW_EQUIVALENCE_MISMATCH');
                }
                foreach (['en', 'zh-CN'] as $locale) {
                    $this->assertProjectionEquality($legacy, $assembled, $locale, $counts);
                }
                if (fwrite($outputHandle, $assembledBytes."\n") === false) {
                    throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_WRITE_FAILED');
                }
                $assembledRows++;
            }
            if (fgets($legacyHandle) !== false || $assembledRows !== self::EXPECTED_SLUGS) {
                throw new CareerShardedCurrentAssemblyFailure('LEGACY_COVERAGE_MISMATCH');
            }
        } finally {
            fclose($legacyHandle);
            fclose($outputHandle);
        }
        if (! rename($temporaryAssetsPath, $outputRoot.'/assets.jsonl')) {
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_WRITE_FAILED');
        }

        $assembledAssetsSha = hash_file('sha256', $outputRoot.'/assets.jsonl')
            ?: throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_WRITE_FAILED');
        if (! hash_equals($legacyAssetsShaBefore, $assembledAssetsSha)
            || $counts !== [
                'public_projection_hash_identical' => self::EXPECTED_LOCALE_PAGES,
                'seo_hash_identical' => self::EXPECTED_LOCALE_PAGES,
                'faq_and_schema_hash_identical' => self::EXPECTED_LOCALE_PAGES,
                'sources_and_claim_bindings_hash_identical' => self::EXPECTED_LOCALE_PAGES,
                'cta_and_internal_links_identical' => self::EXPECTED_LOCALE_PAGES,
                'component_order_and_payload_identical' => self::EXPECTED_LOCALE_PAGES,
                'zh_presentation_v1_projection_identical' => self::EXPECTED_SLUGS,
            ]) {
            throw new CareerShardedCurrentAssemblyFailure('PROJECTION_EQUIVALENCE_MISMATCH');
        }

        $equivalenceReport = [
            'assembled_rows' => $assembledRows,
            'candidate_aggregate_sha256' => $manifest['aggregate_sha256'],
            'candidate_line_aggregate_sha256' => $lineAggregateSha,
            'component_count' => 28,
            'contract_version' => 'career.sharded_current.full_equivalence_report.v1',
            ...$counts,
            'derived_dependency_validation' => [
                'breadcrumb_list' => self::EXPECTED_LOCALE_PAGES,
                'claim_bindings' => self::EXPECTED_LOCALE_PAGES,
                'cta' => self::EXPECTED_LOCALE_PAGES,
                'faq_page' => self::EXPECTED_LOCALE_PAGES,
                'internal_links' => self::EXPECTED_LOCALE_PAGES,
                'occupation' => self::EXPECTED_LOCALE_PAGES,
                'source_card' => self::EXPECTED_LOCALE_PAGES,
            ],
            'legacy_assets_sha256' => $legacyAssetsShaBefore,
            'assembled_assets_sha256' => $assembledAssetsSha,
            'locale_pages' => self::EXPECTED_LOCALE_PAGES,
            'row_bytes_identical' => true,
        ];
        $assemblyManifest = [
            'assets' => [
                'path' => 'assets.jsonl',
                'row_count' => self::EXPECTED_SLUGS,
                'sha256' => $assembledAssetsSha,
            ],
            'candidate_aggregate_sha256' => $manifest['aggregate_sha256'],
            'candidate_line_aggregate_sha256' => $lineAggregateSha,
            'contract_version' => 'career.sharded_current.assembly_manifest.v1',
            'modules' => CareerLegacyCurrentSharder::MODULES,
            'projection_equivalence_sha256' => hash('sha256', CareerLegacyCurrentSharder::canonicalJson($equivalenceReport)),
        ];
        $this->atomicWrite($outputRoot.'/assembly-manifest.json', $this->prettyJson($assemblyManifest));
        $this->atomicWrite($outputRoot.'/equivalence-report.json', $this->prettyJson($equivalenceReport));

        if (! hash_equals($legacyAssetsShaBefore, hash_file('sha256', $legacyAssetsPath) ?: '')) {
            throw new CareerShardedCurrentAssemblyFailure('LEGACY_BASELINE_DRIFT');
        }
        if (! hash_equals($repositoryStatusBefore, $this->repositoryStatus($repoRoot))) {
            throw new CareerShardedCurrentAssemblyFailure('REPOSITORY_WRITE_DETECTED');
        }
        $receipt = [
            'cache_writes' => 0,
            'cms_writes' => 0,
            'contract_version' => 'career.sharded_current.assembly_zero_write_receipt.v1',
            'current_writes' => 0,
            'database_writes' => 0,
            'discoverability_writes' => 0,
            'output_confined_to_system_temporary_root' => true,
            'publisher_writes' => 0,
            'repository_status_unchanged' => true,
            'repository_writes' => 0,
            'search_submissions' => 0,
        ];
        $this->atomicWrite($outputRoot.'/repository-zero-write-receipt.json', $this->prettyJson($receipt));

        return [
            'assembly_manifest' => $assemblyManifest,
            'equivalence_report' => $equivalenceReport,
            'repository_zero_write_receipt' => $receipt,
        ];
    }

    /**
     * @param  array<string,array<string,array<string,mixed>>>  $records
     * @return array<string,mixed>
     */
    public function assembleRecords(array $records): array
    {
        if (array_keys($records) !== ['en', 'zh-CN']) {
            throw new CareerShardedCurrentAssemblyFailure('LOCALE_PAIR_INVALID');
        }
        $slug = null;
        $components = [];
        foreach (['en', 'zh-CN'] as $locale) {
            if (array_keys($records[$locale]) !== CareerLegacyCurrentSharder::MODULES) {
                throw new CareerShardedCurrentAssemblyFailure('MODULE_COMPLETENESS_INVALID');
            }
            foreach (CareerLegacyCurrentSharder::MODULES as $module) {
                $record = $records[$locale][$module];
                $this->assertRecord($record, $locale, $module);
                $recordSlug = $record['canonical_slug'];
                $slug ??= $recordSlug;
                if ($slug !== $recordSlug) {
                    throw new CareerShardedCurrentAssemblyFailure('CROSS_MODULE_SLUG_CONFLICT');
                }
                foreach ($record['content']['page'] as $component => $_value) {
                    if (isset($components[$locale][$component])) {
                        throw new CareerShardedCurrentAssemblyFailure('DUPLICATE_PAGE_COMPONENT');
                    }
                    $components[$locale][$component] = $module;
                }
                $this->assertBindingOwnership($record);
            }
            if ($components[$locale] != CareerLegacyCurrentSharder::componentModuleMap()) {
                throw new CareerShardedCurrentAssemblyFailure('COMPONENT_OWNERSHIP_INVALID');
            }
        }
        $this->assertCrossModuleDependencies($records);

        try {
            return (new CareerLegacyCurrentSharder)->reconstructRow($records);
        } catch (CareerLegacyCurrentSplitFailure $failure) {
            throw new CareerShardedCurrentAssemblyFailure($failure->safeCode);
        }
    }

    /** @param array<string,mixed> $record */
    private function assertRecord(array $record, string $locale, string $module): void
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== ['canonical_slug', 'claim_bindings', 'content', 'locale', 'module', 'source_bindings']) {
            throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_FIELD_SET_INVALID');
        }
        if (($record['locale'] ?? null) !== $locale || ($record['module'] ?? null) !== $module) {
            throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_IDENTITY_INVALID');
        }
        $content = $record['content'] ?? null;
        if (! is_array($content) || array_is_list($content)
            || ($content['contract'] ?? null) !== 'career.sharded_current.module_content.v1') {
            throw new CareerShardedCurrentAssemblyFailure('MODULE_CONTENT_INVALID');
        }
        $expectedContentKeys = ['contract', 'page'];
        if ($module === 'definition') {
            $expectedContentKeys[] = 'claim_locale_contract';
            if ($locale === 'en') {
                $expectedContentKeys[] = 'claim_envelope';
            }
        } elseif ($module === 'geo' && $locale === 'en') {
            $expectedContentKeys = isset($content['legacy_sources_json'])
                ? ['contract', 'legacy_sources_json', 'page']
                : ['contract', 'page', 'source_binding_order', 'sources_contract'];
        } elseif ($module === 'faq') {
            $expectedContentKeys[] = 'structured_faq_page';
            if ($locale === 'en') {
                $expectedContentKeys[] = 'structured_common';
            }
        } elseif ($module === 'page-meta') {
            $expectedContentKeys[] = 'seo';
            $expectedContentKeys[] = 'presentation_v2';
            if ($locale === 'en') {
                array_push($expectedContentKeys, 'assembly', 'metadata', 'row');
            } else {
                $expectedContentKeys[] = 'presentation_v1';
            }
        }
        sort($expectedContentKeys, SORT_STRING);
        $contentKeys = array_keys($content);
        sort($contentKeys, SORT_STRING);
        if ($contentKeys !== $expectedContentKeys) {
            throw new CareerShardedCurrentAssemblyFailure('MODULE_CONTENT_FIELD_SET_INVALID');
        }
        if (! is_array($content['page'] ?? null)) {
            throw new CareerShardedCurrentAssemblyFailure('MODULE_PAGE_INVALID');
        }
        $expectedComponents = array_keys(array_filter(
            CareerLegacyCurrentSharder::componentModuleMap(),
            static fn (string $owner): bool => $owner === $module,
        ));
        $actualComponents = array_keys($content['page']);
        sort($expectedComponents, SORT_STRING);
        sort($actualComponents, SORT_STRING);
        if ($actualComponents !== $expectedComponents) {
            throw new CareerShardedCurrentAssemblyFailure('MODULE_COMPONENT_SET_INVALID');
        }
        if (! is_array($record['source_bindings'] ?? null) || ! array_is_list($record['source_bindings'])
            || ! is_array($record['claim_bindings'] ?? null) || ! array_is_list($record['claim_bindings'])) {
            throw new CareerShardedCurrentAssemblyFailure('BINDING_LIST_INVALID');
        }
    }

    /** @param array<string,mixed> $record */
    private function assertBindingOwnership(array $record): void
    {
        $seen = [];
        foreach (['source_bindings', 'claim_bindings'] as $bindingType) {
            foreach ($record[$bindingType] as $binding) {
                if (! is_array($binding) || array_is_list($binding)) {
                    throw new CareerShardedCurrentAssemblyFailure('BINDING_INVALID');
                }
                $hash = hash('sha256', CareerLegacyCurrentSharder::canonicalJson($binding));
                if (isset($seen[$bindingType][$hash])) {
                    throw new CareerShardedCurrentAssemblyFailure('DUPLICATE_BINDING_FACT');
                }
                $seen[$bindingType][$hash] = true;
                if ($bindingType === 'claim_bindings') {
                    $paths = $binding['input_jsonpaths'] ?? null;
                    if (! is_array($paths) || $paths === []) {
                        throw new CareerShardedCurrentAssemblyFailure('CLAIM_BINDING_INVALID');
                    }
                    foreach ($paths as $path) {
                        if (! is_string($path)
                            || preg_match('/\A\$\.([a-z][a-z-]*)\./', $path, $matches) !== 1
                            || $matches[1] !== $record['module']) {
                            throw new CareerShardedCurrentAssemblyFailure('CLAIM_MODULE_CONFLICT');
                        }
                    }
                }
            }
        }
    }

    /** @param array<string,array<string,array<string,mixed>>> $records */
    private function assertCrossModuleDependencies(array $records): void
    {
        $row = $records['en']['page-meta']['content']['row'];
        $rowKeys = array_keys($row);
        sort($rowKeys, SORT_STRING);
        $expectedRowKeys = array_values(array_diff(
            CareerLegacyCurrentSharder::expectedRowKeys(),
            ['canonical_slug', 'page_payload_json', 'seo_payload_json', 'sources_json', 'structured_data_json', 'metadata_json'],
        ));
        sort($expectedRowKeys, SORT_STRING);
        if ($rowKeys !== $expectedRowKeys
            || ($row['component_order_json'] ?? null) !== array_slice(array_keys(CareerLegacyCurrentSharder::componentModuleMap()), 0, 28)
            || ! in_array($records['en']['page-meta']['content']['assembly']['legacy_page_wrapper'] ?? null, ['page', 'direct'], true)) {
            throw new CareerShardedCurrentAssemblyFailure('ROW_CONTRACT_INVALID');
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $visibleItems = $records[$locale]['faq']['content']['page']['faq_block']['items'] ?? null;
            $structured = $records[$locale]['faq']['content']['structured_faq_page'] ?? null;
            if (! is_array($visibleItems) || ! array_is_list($visibleItems)
                || ! is_array($structured) || ($structured['@type'] ?? null) !== 'FAQPage') {
                throw new CareerShardedCurrentAssemblyFailure('FAQ_DERIVATION_INVALID');
            }
            $derivedEntities = [];
            foreach ($visibleItems as $item) {
                if (! is_array($item) || ! is_string($item['question'] ?? null) || ! is_string($item['answer'] ?? null)) {
                    throw new CareerShardedCurrentAssemblyFailure('FAQ_DERIVATION_INVALID');
                }
                $derivedEntities[] = [
                    '@type' => 'Question',
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                    'name' => $item['question'],
                ];
            }
            if (($structured['mainEntity'] ?? null) !== $derivedEntities) {
                throw new CareerShardedCurrentAssemblyFailure('FAQ_DERIVATION_CONFLICT');
            }
        }

        $sourceOrder = $records['en']['geo']['content']['source_binding_order'] ?? null;
        if (is_array($sourceOrder)) {
            $available = [];
            foreach (CareerLegacyCurrentSharder::MODULES as $module) {
                foreach ($records['en'][$module]['source_bindings'] as $binding) {
                    $hash = hash('sha256', CareerLegacyCurrentSharder::canonicalJson($binding));
                    if (isset($available[$hash])) {
                        throw new CareerShardedCurrentAssemblyFailure('DUPLICATE_SOURCE_FACT');
                    }
                    $available[$hash] = true;
                }
            }
            if (count($sourceOrder) !== count($available)
                || count(array_unique($sourceOrder)) !== count($sourceOrder)
                || array_diff($sourceOrder, array_keys($available)) !== []) {
                throw new CareerShardedCurrentAssemblyFailure('SOURCE_BINDING_DEPENDENCY_INVALID');
            }
        }
    }

    /** @param array<string,mixed> $legacy @param array<string,mixed> $assembled @param array<string,int> $counts */
    private function assertProjectionEquality(array $legacy, array $assembled, string $locale, array &$counts): void
    {
        $legacyLocale = $locale === 'zh-CN' ? 'zh' : 'en';
        $legacyPages = array_keys($legacy['page_payload_json']) === ['page']
            ? $legacy['page_payload_json']['page']
            : $legacy['page_payload_json'];
        $assembledPages = array_keys($assembled['page_payload_json']) === ['page']
            ? $assembled['page_payload_json']['page']
            : $assembled['page_payload_json'];
        $pairs = [
            'public_projection_hash_identical' => [$legacyPages[$legacyLocale], $assembledPages[$legacyLocale]],
            'seo_hash_identical' => [$legacy['seo_payload_json'][$legacyLocale], $assembled['seo_payload_json'][$legacyLocale]],
            'faq_and_schema_hash_identical' => [[
                $legacyPages[$legacyLocale]['faq_block'],
                $legacy['structured_data_json']['faq_page'][$legacyLocale],
            ], [
                $assembledPages[$legacyLocale]['faq_block'],
                $assembled['structured_data_json']['faq_page'][$legacyLocale],
            ]],
            'sources_and_claim_bindings_hash_identical' => [[
                $legacy['sources_json'],
                $legacy['metadata_json']['structured_components_v1'] ?? null,
            ], [
                $assembled['sources_json'],
                $assembled['metadata_json']['structured_components_v1'] ?? null,
            ]],
            'cta_and_internal_links_identical' => [[
                $legacyPages[$legacyLocale]['primary_cta'],
                $legacyPages[$legacyLocale]['secondary_cta'],
                $legacyPages[$legacyLocale]['final_cta'],
                $legacyPages[$legacyLocale]['adjacent_career_comparison_table'],
                $legacyPages[$legacyLocale]['related_next_pages'],
            ], [
                $assembledPages[$legacyLocale]['primary_cta'],
                $assembledPages[$legacyLocale]['secondary_cta'],
                $assembledPages[$legacyLocale]['final_cta'],
                $assembledPages[$legacyLocale]['adjacent_career_comparison_table'],
                $assembledPages[$legacyLocale]['related_next_pages'],
            ]],
            'component_order_and_payload_identical' => [[
                $legacy['component_order_json'], $legacyPages[$legacyLocale],
            ], [
                $assembled['component_order_json'], $assembledPages[$legacyLocale],
            ]],
        ];
        foreach ($pairs as $counter => [$before, $after]) {
            if (! hash_equals($this->valueHash($before), $this->valueHash($after))) {
                throw new CareerShardedCurrentAssemblyFailure('PROJECTION_EQUIVALENCE_MISMATCH');
            }
            $counts[$counter]++;
        }
        if ($locale === 'zh-CN') {
            if (! hash_equals(
                $this->valueHash($legacy['metadata_json']['presentation_v1'] ?? null),
                $this->valueHash($assembled['metadata_json']['presentation_v1'] ?? null),
            )) {
                throw new CareerShardedCurrentAssemblyFailure('ZH_PRESENTATION_EQUIVALENCE_MISMATCH');
            }
            $counts['zh_presentation_v1_projection_identical']++;
        }
        if (! hash_equals(
            $this->valueHash($legacy['metadata_json']['presentation_v2'][$legacyLocale] ?? null),
            $this->valueHash($assembled['metadata_json']['presentation_v2'][$legacyLocale] ?? null),
        )) {
            throw new CareerShardedCurrentAssemblyFailure('PRESENTATION_V2_EQUIVALENCE_MISMATCH');
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{array<string,array<string,array<string,array<string,mixed>>>>,string}
     */
    private function loadAndValidateCandidate(string $candidateRoot, array $manifest): array
    {
        if (($manifest['contract_version'] ?? null) !== 'career.sharded_current.manifest.v1'
            || ($manifest['modules'] ?? null) !== CareerLegacyCurrentSharder::MODULES
            || ($manifest['coverage'] ?? null) != [
                'slugs' => self::EXPECTED_SLUGS,
                'locales' => ['en', 'zh-CN'],
                'locale_pages' => self::EXPECTED_LOCALE_PAGES,
                'module_rows' => self::EXPECTED_MODULE_ROWS,
            ]
            || ($manifest['module_completeness'] ?? null) != [
                'rows_per_module' => self::EXPECTED_LOCALE_PAGES,
                'modules_per_slug_locale' => 10,
            ]) {
            throw new CareerShardedCurrentAssemblyFailure('MANIFEST_CONTRACT_INVALID');
        }
        $shards = $manifest['shards'] ?? null;
        if (! is_array($shards) || count($shards) !== 640 || ($manifest['registries'] ?? null) !== []) {
            throw new CareerShardedCurrentAssemblyFailure('SHARD_INVENTORY_INVALID');
        }
        $projection = array_intersect_key($manifest, array_flip([
            'contract_version', 'modules', 'shards', 'registries', 'coverage', 'module_completeness',
        ]));
        if (($manifest['aggregate_sha256'] ?? null) !== hash('sha256', CareerLegacyCurrentSharder::canonicalJson($projection))) {
            throw new CareerShardedCurrentAssemblyFailure('AGGREGATE_HASH_MISMATCH');
        }

        $records = [];
        $identities = [];
        foreach ($shards as $position => $declaration) {
            $module = CareerLegacyCurrentSharder::MODULES[intdiv($position, 64)] ?? null;
            $index = $position % 64;
            $relative = sprintf('%s/shard-%02d.jsonl', $module, $index);
            $declarationKeys = is_array($declaration) ? array_keys($declaration) : [];
            sort($declarationKeys, SORT_STRING);
            if (! is_array($declaration)
                || $declarationKeys !== ['module', 'path', 'row_count', 'sha256', 'shard_index']
                || ($declaration['module'] ?? null) !== $module
                || ($declaration['shard_index'] ?? null) !== $index
                || ($declaration['path'] ?? null) !== $relative
                || ! is_string($declaration['sha256'] ?? null)
                || ! is_int($declaration['row_count'] ?? null)) {
                throw new CareerShardedCurrentAssemblyFailure('SHARD_DECLARATION_INVALID');
            }
            $path = $candidateRoot.'/'.$relative;
            $raw = (string) file_get_contents($path);
            if ($raw === '' || ! str_ends_with($raw, "\n")) {
                throw new CareerShardedCurrentAssemblyFailure('EMPTY_OR_UNTERMINATED_SHARD');
            }
            if (! hash_equals((string) $declaration['sha256'], hash('sha256', $raw))) {
                throw new CareerShardedCurrentAssemblyFailure('SHARD_HASH_MISMATCH');
            }
            $lines = explode("\n", substr($raw, 0, -1));
            if (($declaration['row_count'] ?? null) !== count($lines)) {
                throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_COUNT_MISMATCH');
            }
            $previous = null;
            foreach ($lines as $line) {
                $record = $this->decodeCanonicalRow($line);
                $slug = $record['canonical_slug'] ?? null;
                $locale = $record['locale'] ?? null;
                if (! is_string($slug)
                    || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                    || $slug === 'software-developers'
                    || ! in_array($locale, ['en', 'zh-CN'], true)
                    || ($record['module'] ?? null) !== $module
                    || CareerLegacyCurrentSharder::shardIndex($slug) !== $index) {
                    throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_IDENTITY_INVALID');
                }
                $sortKey = $slug."\0".$locale;
                if ($previous !== null && strcmp($previous, $sortKey) >= 0) {
                    throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_ORDER_INVALID');
                }
                $previous = $sortKey;
                $identity = $slug."\0".$locale."\0".$module;
                if (isset($identities[$identity])) {
                    throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_IDENTITY_DUPLICATE');
                }
                $identities[$identity] = hash('sha256', $line);
                $records[$slug][$locale][$module] = $record;
            }
        }
        if (count($records) !== self::EXPECTED_SLUGS || count($identities) !== self::EXPECTED_MODULE_ROWS) {
            throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_COVERAGE_INVALID');
        }
        foreach ($records as &$locales) {
            ksort($locales, SORT_STRING);
            foreach ($locales as &$modules) {
                $ordered = [];
                foreach (CareerLegacyCurrentSharder::MODULES as $module) {
                    if (! isset($modules[$module])) {
                        throw new CareerShardedCurrentAssemblyFailure('MODULE_COMPLETENESS_INVALID');
                    }
                    $ordered[$module] = $modules[$module];
                }
                $modules = $ordered;
            }
        }
        unset($locales, $modules);

        ksort($identities, SORT_STRING);

        return [$records, hash('sha256', CareerLegacyCurrentSharder::canonicalJson($identities))];
    }

    /** @param array<string,mixed> $manifest */
    private function assertCandidateReports(
        string $repoRoot,
        string $candidateRoot,
        array $manifest,
        string $legacyAssetsSha,
    ): void {
        $coverage = $this->decodeObjectFile($candidateRoot.'/coverage-report.json', 'COVERAGE_REPORT_INVALID');
        if (($coverage['contract_version'] ?? null) !== 'career.sharded_current.coverage_report.v1'
            || ($coverage['slugs'] ?? null) !== self::EXPECTED_SLUGS
            || ($coverage['locales'] ?? null) !== self::EXPECTED_LOCALE_PAGES
            || ($coverage['module_rows'] ?? null) !== self::EXPECTED_MODULE_ROWS
            || ($coverage['rows_per_module'] ?? null) != array_fill_keys(CareerLegacyCurrentSharder::MODULES, self::EXPECTED_LOCALE_PAGES)
            || ($coverage['modules_per_slug_locale'] ?? null) !== 10
            || ($coverage['shard_files'] ?? null) !== 640
            || ($coverage['empty_shards'] ?? null) !== 0
            || ($coverage['duplicate'] ?? null) !== 0
            || ($coverage['missing'] ?? null) !== 0
            || ($coverage['legacy_wrapper_counts'] ?? null) != ['page' => 1045, 'direct' => 1]) {
            throw new CareerShardedCurrentAssemblyFailure('COVERAGE_REPORT_INVALID');
        }

        $ownership = $this->decodeObjectFile($candidateRoot.'/field-ownership-report.json', 'OWNERSHIP_REPORT_INVALID');
        $ownershipContract = $repoRoot.'/backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json';
        if (($ownership['contract_version'] ?? null) !== 'career.sharded_current.field_ownership_report.v1'
            || ! hash_equals((string) ($ownership['ownership_contract_sha256'] ?? ''), hash_file('sha256', $ownershipContract) ?: '')
            || ($ownership['unowned_fields'] ?? null) !== 0
            || ($ownership['duplicate_fields'] ?? null) !== 0
            || ($ownership['missing_fields'] ?? null) !== 0
            || ($ownership['lossless_reconstruction_mismatch_count'] ?? null) !== 0) {
            throw new CareerShardedCurrentAssemblyFailure('OWNERSHIP_REPORT_INVALID');
        }

        $integrity = $this->decodeObjectFile($candidateRoot.'/integrity-report.json', 'INTEGRITY_REPORT_INVALID');
        if (($integrity['contract_version'] ?? null) !== 'career.sharded_current.split_integrity_report.v1'
            || ! hash_equals((string) ($integrity['legacy_assets_sha256'] ?? ''), $legacyAssetsSha)
            || ! hash_equals((string) ($integrity['candidate_aggregate_sha256'] ?? ''), (string) ($manifest['aggregate_sha256'] ?? ''))
            || ($integrity['canonical_json'] ?? null) !== true
            || ($integrity['fixed_shard_formula'] ?? null) !== true
            || ($integrity['sorted_rows'] ?? null) !== true
            || ($integrity['baseline_stable'] ?? null) !== true
            || ($integrity['lossless_reconstruction'] ?? null) !== true) {
            throw new CareerShardedCurrentAssemblyFailure('INTEGRITY_REPORT_INVALID');
        }

        $receipt = $this->decodeObjectFile($candidateRoot.'/repository-zero-write-receipt.json', 'CANDIDATE_ZERO_WRITE_RECEIPT_INVALID');
        foreach (['repository_writes', 'current_writes', 'database_writes', 'cache_writes', 'cms_writes', 'publisher_writes', 'discoverability_writes', 'search_submissions'] as $field) {
            if (($receipt[$field] ?? null) !== 0) {
                throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_ZERO_WRITE_RECEIPT_INVALID');
            }
        }
        if (($receipt['contract_version'] ?? null) !== 'career.sharded_current.repository_zero_write_receipt.v1'
            || ($receipt['repository_status_unchanged'] ?? null) !== true
            || ($receipt['output_confined_to_system_temporary_root'] ?? null) !== true) {
            throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_ZERO_WRITE_RECEIPT_INVALID');
        }
    }

    private function assertCandidateInventory(string $root): void
    {
        $allowedRootFiles = [
            'manifest.json', 'coverage-report.json', 'field-ownership-report.json',
            'integrity-report.json', 'repository-zero-write-receipt.json',
        ];
        $seenRootFiles = [];
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_SYMLINK_FORBIDDEN');
            }
            if ($entry->isFile()) {
                if (! in_array($entry->getFilename(), $allowedRootFiles, true)) {
                    throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_UNKNOWN_FILE');
                }
                $seenRootFiles[] = $entry->getFilename();

                continue;
            }
            if (! $entry->isDir() || ! in_array($entry->getFilename(), CareerLegacyCurrentSharder::MODULES, true)) {
                throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_UNKNOWN_FILE');
            }
            $files = [];
            foreach (new DirectoryIterator($entry->getPathname()) as $shard) {
                if ($shard->isDot()) {
                    continue;
                }
                if ($shard->isLink() || ! $shard->isFile()
                    || preg_match('/\Ashard-(?:0[0-9]|[1-5][0-9]|6[0-3])\.jsonl\z/', $shard->getFilename()) !== 1) {
                    throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_UNKNOWN_FILE');
                }
                $files[] = $shard->getFilename();
            }
            if (count($files) !== 64) {
                throw new CareerShardedCurrentAssemblyFailure('SHARD_INVENTORY_INVALID');
            }
        }
        sort($seenRootFiles, SORT_STRING);
        $expectedRootFiles = $allowedRootFiles;
        sort($expectedRootFiles, SORT_STRING);
        if ($seenRootFiles !== $expectedRootFiles) {
            throw new CareerShardedCurrentAssemblyFailure('CANDIDATE_REPORT_INVENTORY_INVALID');
        }
    }

    private function assertOutputInventory(string $root): void
    {
        $allowed = ['assets.jsonl', 'assembly-manifest.json', 'equivalence-report.json', 'repository-zero-write-receipt.json'];
        foreach (new DirectoryIterator($root) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || ! $entry->isFile() || ! in_array($entry->getFilename(), $allowed, true)) {
                throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_OUTPUT_UNKNOWN_FILE');
            }
        }
    }

    /** @return array<string,mixed> */
    private function decodeCanonicalRow(string $line): array
    {
        try {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_INVALID');
        }
        if (! is_array($row) || array_is_list($row) || CareerLegacyCurrentSharder::canonicalJson($row) !== $line) {
            throw new CareerShardedCurrentAssemblyFailure('SHARD_ROW_NOT_CANONICAL');
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeObjectFile(string $path, string $safeCode): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerShardedCurrentAssemblyFailure($safeCode);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerShardedCurrentAssemblyFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerShardedCurrentAssemblyFailure($safeCode);
        }

        return $value;
    }

    private function guardDirectory(string $path, string $safeCode): string
    {
        if (! is_dir($path) || is_link($path) || ($real = realpath($path)) === false) {
            throw new CareerShardedCurrentAssemblyFailure($safeCode);
        }

        return rtrim($real, '/');
    }

    private function guardTemporaryDirectory(string $repoRoot, string $path, string $safeCode): string
    {
        $real = $this->guardDirectory($path, $safeCode);
        $temporaryRoots = array_values(array_unique(array_filter([
            realpath(sys_get_temp_dir()), realpath('/tmp'),
        ], static fn (mixed $root): bool => is_string($root) && $root !== '/')));
        $inside = false;
        foreach ($temporaryRoots as $temporaryRoot) {
            $inside = $inside || str_starts_with($real.'/', rtrim($temporaryRoot, '/').'/');
        }
        if (! $inside || str_starts_with($real.'/', $repoRoot.'/')) {
            throw new CareerShardedCurrentAssemblyFailure($safeCode);
        }

        return $real;
    }

    private function guardLegacyAssets(string $repoRoot, string $path): string
    {
        if (! is_file($path) || is_link($path) || ($real = realpath($path)) === false
            || $real !== $repoRoot.'/backend/content_assets/career/current/assets.jsonl') {
            throw new CareerShardedCurrentAssemblyFailure('LEGACY_ASSETS_INVALID');
        }

        return $real;
    }

    private function repositoryStatus(string $repoRoot): string
    {
        $lines = [];
        $exitCode = 0;
        exec(sprintf('git -C %s status --porcelain=v1 --untracked-files=all', escapeshellarg($repoRoot)), $lines, $exitCode);
        if ($exitCode !== 0) {
            throw new CareerShardedCurrentAssemblyFailure('REPOSITORY_STATUS_UNAVAILABLE');
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        if (is_link($path)) {
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_OUTPUT_SYMLINK_FORBIDDEN');
        }
        $temporary = $path.'.assembly.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_WRITE_FAILED');
        }
    }

    /** @param array<string,mixed> $value */
    private function prettyJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    private function valueHash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}

function careerShardedCurrentAssemblerMain(array $argv): int
{
    $options = getopt('', ['repo-root::', 'candidate-root::', 'legacy-assets::', 'output-root::']);
    $repoRoot = (string) ($options['repo-root'] ?? dirname(__DIR__, 4));
    $candidateRoot = (string) ($options['candidate-root'] ?? getenv('CAREER_SHARDED_CANDIDATE_ROOT'));
    $legacyAssets = (string) ($options['legacy-assets'] ?? $repoRoot.'/backend/content_assets/career/current/assets.jsonl');
    $outputRoot = (string) ($options['output-root'] ?? getenv('CAREER_SHARDED_ASSEMBLY_OUTPUT_ROOT'));
    try {
        if ($candidateRoot === '' || $outputRoot === '') {
            throw new CareerShardedCurrentAssemblyFailure('ASSEMBLY_ROOT_REQUIRED');
        }
        $result = (new CareerShardedCurrentAssembler)->assemble($repoRoot, $candidateRoot, $legacyAssets, $outputRoot);
        fwrite(STDOUT, CareerLegacyCurrentSharder::canonicalJson([
            'assembled_assets_sha256' => $result['assembly_manifest']['assets']['sha256'],
            'contract_version' => 'career.sharded_current.assembly_cli_receipt.v1',
            'locale_pages' => $result['equivalence_report']['locale_pages'],
            'repository_writes' => 0,
            'status' => 'PASS',
        ])."\n");

        return 0;
    } catch (CareerShardedCurrentAssemblyFailure $failure) {
        fwrite(STDOUT, CareerLegacyCurrentSharder::canonicalJson([
            'contract_version' => 'career.sharded_current.assembly_cli_receipt.v1',
            'repository_writes' => 0,
            'safe_error_code' => $failure->safeCode,
            'status' => 'FAIL',
        ])."\n");

        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(careerShardedCurrentAssemblerMain($argv));
}
