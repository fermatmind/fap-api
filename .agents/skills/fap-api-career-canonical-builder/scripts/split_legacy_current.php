<?php

declare(strict_types=1);

final class CareerLegacyCurrentSplitFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerLegacyCurrentSharder
{
    /** @var list<string> */
    public const MODULES = [
        'identity',
        'definition',
        'salary',
        'geo',
        'ai-impact',
        'fit-personality',
        'risk',
        'compare-links',
        'faq',
        'page-meta',
    ];

    /** @var array<string,string> */
    private const COMPONENT_MODULE = [
        'breadcrumb' => 'identity',
        'hero' => 'page-meta',
        'fermat_decision_card' => 'page-meta',
        'primary_cta' => 'page-meta',
        'career_snapshot_primary_locale' => 'salary',
        'career_snapshot_secondary_locale' => 'salary',
        'fit_decision_checklist' => 'fit-personality',
        'riasec_fit_block' => 'identity',
        'personality_fit_block' => 'fit-personality',
        'definition_block' => 'definition',
        'career_ai_description_block' => 'ai-impact',
        'responsibilities_block' => 'definition',
        'work_context_block' => 'definition',
        'career_quick_answers_block' => 'definition',
        'onet_structured_fields_block' => 'definition',
        'market_signal_card' => 'geo',
        'adjacent_career_comparison_table' => 'compare-links',
        'ai_impact_table' => 'ai-impact',
        'career_risk_cards' => 'risk',
        'career_path_block' => 'risk',
        'contract_project_risk_block' => 'risk',
        'next_steps_block' => 'risk',
        'faq_block' => 'faq',
        'related_next_pages' => 'compare-links',
        'source_card' => 'geo',
        'review_validity_card' => 'geo',
        'boundary_notice' => 'fit-personality',
        'final_cta' => 'page-meta',
        'path' => 'page-meta',
        'secondary_cta' => 'page-meta',
    ];

    private const EXPECTED_ROW_KEYS = [
        'asset_role',
        'asset_type',
        'asset_version',
        'canonical_slug',
        'component_order_json',
        'implementation_contract_json',
        'metadata_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'status',
        'structured_data_json',
        'surface_version',
        'template_version',
    ];

    /** @return array<string,string> */
    public static function componentModuleMap(): array
    {
        return self::COMPONENT_MODULE;
    }

    /** @return list<string> */
    public static function expectedRowKeys(): array
    {
        return self::EXPECTED_ROW_KEYS;
    }

    private const EXPECTED_SLUGS = 1046;

    private const EXPECTED_LOCALE_PAGES = 2092;

    private const EXPECTED_MODULE_ROWS = 20920;

    /** @return array<string,mixed> */
    public function split(string $repoRoot, string $assetsPath, string $legacyManifestPath, string $outputRoot): array
    {
        $repoRoot = $this->guardDirectory($repoRoot, 'REPOSITORY_ROOT_INVALID');
        $outputRoot = $this->guardOutputRoot($repoRoot, $outputRoot);
        $assetsPath = $this->guardInputFile($repoRoot, $assetsPath, 'LEGACY_ASSETS_INVALID');
        $legacyManifestPath = $this->guardInputFile($repoRoot, $legacyManifestPath, 'LEGACY_MANIFEST_INVALID');
        $expectedAssetsPath = $repoRoot.'/backend/content_assets/career/current/assets.jsonl';
        $expectedManifestPath = $repoRoot.'/backend/content_assets/career/current/manifest.json';
        if ($assetsPath !== $expectedAssetsPath || $legacyManifestPath !== $expectedManifestPath) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_AUTHORITY_PATH_INVALID');
        }

        $repositoryStatusBefore = $this->repositoryStatus($repoRoot);
        $assetsShaBefore = hash_file('sha256', $assetsPath) ?: throw new CareerLegacyCurrentSplitFailure('LEGACY_ASSETS_UNREADABLE');
        $manifestShaBefore = hash_file('sha256', $legacyManifestPath) ?: throw new CareerLegacyCurrentSplitFailure('LEGACY_MANIFEST_UNREADABLE');
        $legacyManifest = $this->decodeObject((string) file_get_contents($legacyManifestPath), 'LEGACY_MANIFEST_INVALID');
        $manifestContract = $legacyManifest['contract_version'] ?? null;
        $isLegacyManifest = $manifestContract === 'career.current_authority_manifest.v1';
        $isInstalledShardedManifest = $manifestContract === 'career.sharded_current.manifest.v1';
        if (! $isLegacyManifest && ! $isInstalledShardedManifest) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_MANIFEST_BINDING_INVALID');
        }
        if ($isLegacyManifest
            && (($legacyManifest['files'][0]['sha256'] ?? null) !== $assetsShaBefore
                || ($legacyManifest['files'][0]['row_count'] ?? null) !== self::EXPECTED_SLUGS)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_MANIFEST_BINDING_INVALID');
        }
        if ($isInstalledShardedManifest) {
            $this->assertInstalledShardedPackage($repoRoot, $legacyManifest);
        }

        $this->assertOutputInventory($outputRoot);
        $temporaryPaths = $this->prepareTemporaryShards($outputRoot);
        $handle = fopen($assetsPath, 'rb');
        if ($handle === false) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_ASSETS_UNREADABLE');
        }
        $previousSlug = null;
        $slugs = [];
        $rowCount = 0;
        $moduleRows = array_fill_keys(self::MODULES, 0);
        $wrapperCounts = ['page' => 0, 'direct' => 0];
        $reconstructionMismatchCount = 0;
        $ownedLeafCount = 0;
        $derivedLeafCount = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $canonicalLine = rtrim($line, "\r\n");
                if ($canonicalLine === '') {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_BLANK_LINE');
                }
                $row = $this->decodeCanonicalRow($canonicalLine);
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== self::EXPECTED_ROW_KEYS) {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_ROW_FIELD_SET_INVALID');
                }
                $slug = $row['canonical_slug'] ?? null;
                if (! is_string($slug)
                    || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                    || $slug === 'software-developers') {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_SLUG_INVALID');
                }
                $this->acceptSlug($slug, $previousSlug, $slugs);
                $rowCount++;

                [$records, $wrapper] = $this->splitRow($row);
                $wrapperCounts[$wrapper]++;
                if (! hash_equals(self::canonicalJson($row), self::canonicalJson($this->reconstructRow($records)))) {
                    $reconstructionMismatchCount++;
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_RECONSTRUCTION_MISMATCH');
                }
                $shardIndex = self::shardIndex($slug);
                foreach (['en', 'zh-CN'] as $locale) {
                    foreach (self::MODULES as $module) {
                        $record = $records[$locale][$module];
                        $path = sprintf('%s/%s/shard-%02d.jsonl.candidate.tmp', $outputRoot, $module, $shardIndex);
                        if (file_put_contents($path, self::canonicalJson($record)."\n", FILE_APPEND | LOCK_EX) === false) {
                            throw new CareerLegacyCurrentSplitFailure('CANDIDATE_WRITE_FAILED');
                        }
                        $moduleRows[$module]++;
                        $ownedLeafCount += $this->leafCount($record['content']);
                        $derivedLeafCount += count($record['source_bindings']) + count($record['claim_bindings']);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $slugList = array_keys($slugs);
        if ($rowCount !== self::EXPECTED_SLUGS
            || count($slugList) !== self::EXPECTED_SLUGS
            || $wrapperCounts !== ['page' => 1045, 'direct' => 1]
            || $moduleRows !== array_fill_keys(self::MODULES, self::EXPECTED_LOCALE_PAGES)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_COVERAGE_INVALID');
        }
        if ($isLegacyManifest) {
            $this->assertSlugSet($slugList, (string) ($legacyManifest['set_hashes']['slug_set_sha256'] ?? ''));
        }
        $this->assertStableInputs($assetsShaBefore, $manifestShaBefore, $assetsPath, $legacyManifestPath);

        $shards = [];
        $emptyShards = 0;
        foreach (self::MODULES as $module) {
            for ($index = 0; $index < 64; $index++) {
                $relativePath = sprintf('%s/shard-%02d.jsonl', $module, $index);
                $temporaryPath = $temporaryPaths[$relativePath];
                $raw = (string) file_get_contents($temporaryPath);
                if ($raw === '') {
                    $emptyShards++;
                    throw new CareerLegacyCurrentSplitFailure('CANDIDATE_EMPTY_SHARD');
                }
                $finalPath = $outputRoot.'/'.$relativePath;
                if (! rename($temporaryPath, $finalPath)) {
                    throw new CareerLegacyCurrentSplitFailure('CANDIDATE_ACTIVATION_FAILED');
                }
                $shards[] = [
                    'module' => $module,
                    'shard_index' => $index,
                    'path' => $relativePath,
                    'sha256' => hash('sha256', $raw),
                    'row_count' => substr_count($raw, "\n"),
                ];
            }
        }

        $manifest = [
            'aggregate_sha256' => str_repeat('0', 64),
            'authority_path' => 'backend/content_assets/career/current',
            'contract_version' => 'career.sharded_current.manifest.v1',
            'coverage' => [
                'slugs' => self::EXPECTED_SLUGS,
                'locales' => ['en', 'zh-CN'],
                'locale_pages' => self::EXPECTED_LOCALE_PAGES,
                'module_rows' => self::EXPECTED_MODULE_ROWS,
            ],
            'module_completeness' => [
                'rows_per_module' => self::EXPECTED_LOCALE_PAGES,
                'modules_per_slug_locale' => count(self::MODULES),
            ],
            'modules' => self::MODULES,
            'registries' => [],
            'shards' => $shards,
        ];
        $manifest['aggregate_sha256'] = $this->aggregateHash($manifest);
        if ($isInstalledShardedManifest
            && ! hash_equals(self::canonicalJson($legacyManifest), self::canonicalJson($manifest))) {
            throw new CareerLegacyCurrentSplitFailure('INSTALLED_SHARDED_PROJECTION_MISMATCH');
        }
        $coverageReport = [
            'contract_version' => 'career.sharded_current.coverage_report.v1',
            'slugs' => $rowCount,
            'locales' => self::EXPECTED_LOCALE_PAGES,
            'module_rows' => array_sum($moduleRows),
            'rows_per_module' => $moduleRows,
            'modules_per_slug_locale' => count(self::MODULES),
            'shard_files' => count($shards),
            'empty_shards' => $emptyShards,
            'duplicate' => 0,
            'missing' => 0,
            'legacy_wrapper_counts' => $wrapperCounts,
        ];
        $ownershipReport = [
            'contract_version' => 'career.sharded_current.field_ownership_report.v1',
            'ownership_contract_sha256' => hash_file('sha256', $repoRoot.'/backend/docs/career/contracts/career-sharded-current-field-ownership.v1.json'),
            'owned_leaf_occurrences' => $ownedLeafCount,
            'binding_occurrences' => $derivedLeafCount,
            'unowned_fields' => 0,
            'duplicate_fields' => 0,
            'missing_fields' => 0,
            'lossless_reconstruction_mismatch_count' => $reconstructionMismatchCount,
        ];
        $integrityReport = [
            'contract_version' => 'career.sharded_current.split_integrity_report.v1',
            'legacy_assets_sha256' => $assetsShaBefore,
            'legacy_manifest_sha256' => $manifestShaBefore,
            'candidate_aggregate_sha256' => $manifest['aggregate_sha256'],
            'canonical_json' => true,
            'fixed_shard_formula' => true,
            'sorted_rows' => true,
            'baseline_stable' => true,
            'lossless_reconstruction' => true,
        ];
        foreach ([
            'manifest.json' => $manifest,
            'coverage-report.json' => $coverageReport,
            'field-ownership-report.json' => $ownershipReport,
            'integrity-report.json' => $integrityReport,
        ] as $filename => $document) {
            $this->atomicWrite($outputRoot.'/'.$filename, self::prettyJson($document));
        }

        $repositoryStatusAfter = $this->repositoryStatus($repoRoot);
        if (! hash_equals($repositoryStatusBefore, $repositoryStatusAfter)) {
            throw new CareerLegacyCurrentSplitFailure('REPOSITORY_WRITE_DETECTED');
        }
        $zeroWriteReceipt = [
            'contract_version' => 'career.sharded_current.repository_zero_write_receipt.v1',
            'repository_status_unchanged' => true,
            'repository_writes' => 0,
            'current_writes' => 0,
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'publisher_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
            'output_confined_to_system_temporary_root' => true,
        ];
        $this->atomicWrite($outputRoot.'/repository-zero-write-receipt.json', self::prettyJson($zeroWriteReceipt));

        return [
            'manifest' => $manifest,
            'coverage_report' => $coverageReport,
            'field_ownership_report' => $ownershipReport,
            'integrity_report' => $integrityReport,
            'repository_zero_write_receipt' => $zeroWriteReceipt,
        ];
    }

    /** @param array<string,mixed> $row @return array{array<string,array<string,array<string,mixed>>>,string} */
    public function splitRow(array $row): array
    {
        $payload = $row['page_payload_json'] ?? null;
        if (! is_array($payload) || array_is_list($payload)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_PAGE_PAYLOAD_INVALID');
        }
        if (array_keys($payload) === ['page']) {
            $wrapper = 'page';
            $pages = $payload['page'];
        } elseif (array_keys($payload) === ['en', 'zh']) {
            $wrapper = 'direct';
            $pages = $payload;
        } else {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_LOCALE_WRAPPER_INVALID');
        }
        if (! is_array($pages) || array_keys($pages) !== ['en', 'zh']) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_LOCALE_SET_INVALID');
        }
        $expectedComponentOrder = array_slice(array_keys(self::COMPONENT_MODULE), 0, 28);
        if (($row['component_order_json'] ?? null) !== $expectedComponentOrder) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_COMPONENT_ORDER_INVALID');
        }

        $records = [];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (self::MODULES as $module) {
                $records[$locale][$module] = [
                    'canonical_slug' => $row['canonical_slug'],
                    'claim_bindings' => [],
                    'content' => ['contract' => 'career.sharded_current.module_content.v1'],
                    'locale' => $locale,
                    'module' => $module,
                    'source_bindings' => [],
                ];
            }
        }
        $records['en']['page-meta']['content']['assembly'] = ['legacy_page_wrapper' => $wrapper];
        $records['en']['page-meta']['content']['row'] = array_diff_key($row, array_flip([
            'canonical_slug', 'page_payload_json', 'seo_payload_json', 'sources_json', 'structured_data_json', 'metadata_json',
        ]));

        foreach (['en' => 'en', 'zh' => 'zh-CN'] as $legacyLocale => $locale) {
            $page = $pages[$legacyLocale];
            if (! is_array($page) || array_is_list($page)) {
                throw new CareerLegacyCurrentSplitFailure('LEGACY_PAGE_INVALID');
            }
            $pageKeys = array_keys($page);
            $expectedPageKeys = array_keys(self::COMPONENT_MODULE);
            sort($pageKeys, SORT_STRING);
            sort($expectedPageKeys, SORT_STRING);
            if ($pageKeys !== $expectedPageKeys) {
                throw new CareerLegacyCurrentSplitFailure('LEGACY_PAGE_COMPONENT_SET_INVALID');
            }
            foreach ($page as $component => $value) {
                $module = self::COMPONENT_MODULE[$component] ?? null;
                if ($module === null) {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_UNKNOWN_COMPONENT');
                }
                $records[$locale][$module]['content']['page'][$component] = $value;
            }
            $seo = $row['seo_payload_json'][$legacyLocale] ?? null;
            if (! is_array($seo)) {
                throw new CareerLegacyCurrentSplitFailure('LEGACY_SEO_LOCALE_INVALID');
            }
            $records[$locale]['page-meta']['content']['seo'] = $seo;
        }

        $sources = $row['sources_json'] ?? null;
        if (! is_array($sources) || array_is_list($sources)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_SOURCES_INVALID');
        }
        if (is_array($sources['references'] ?? null)) {
            $references = $sources['references'];
            unset($sources['references']);
            $records['en']['geo']['content']['sources_contract'] = $sources;
            $records['en']['geo']['content']['source_binding_order'] = array_map(
                static fn (array $source): string => hash('sha256', self::canonicalJson($source)),
                $references,
            );
            foreach ($references as $reference) {
                if (! is_array($reference)) {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_SOURCE_BINDING_INVALID');
                }
                $records['en'][$this->sourceModule($reference)]['source_bindings'][] = $reference;
            }
        } else {
            $records['en']['geo']['content']['legacy_sources_json'] = $sources;
        }

        $structured = $row['structured_data_json'] ?? null;
        if (! is_array($structured) || ! is_array($structured['faq_page'] ?? null)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_STRUCTURED_DATA_INVALID');
        }
        $faqPage = $structured['faq_page'];
        unset($structured['faq_page']);
        $records['en']['faq']['content']['structured_common'] = $structured;
        foreach (['en' => 'en', 'zh' => 'zh-CN'] as $legacyLocale => $locale) {
            $records[$locale]['faq']['content']['structured_faq_page'] = $faqPage[$legacyLocale] ?? throw new CareerLegacyCurrentSplitFailure('LEGACY_STRUCTURED_LOCALE_INVALID');
        }

        $metadata = $row['metadata_json'] ?? null;
        if (! is_array($metadata)) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_METADATA_INVALID');
        }
        $presentation = $metadata['presentation_v1'] ?? null;
        unset($metadata['presentation_v1']);
        if ($presentation !== null) {
            $records['zh-CN']['page-meta']['content']['presentation_v1'] = $presentation;
        }
        $claimEnvelope = $metadata['structured_components_v1'] ?? null;
        unset($metadata['structured_components_v1']);
        $records['en']['page-meta']['content']['metadata'] = $metadata;
        if (is_array($claimEnvelope)) {
            $locales = $claimEnvelope['locales'] ?? null;
            unset($claimEnvelope['locales']);
            $records['en']['definition']['content']['claim_envelope'] = $claimEnvelope;
            foreach (['en', 'zh-CN'] as $locale) {
                $localeEnvelope = is_array($locales) ? ($locales[$locale] ?? null) : null;
                if (! is_array($localeEnvelope) || ! is_array($localeEnvelope['bindings'] ?? null)) {
                    throw new CareerLegacyCurrentSplitFailure('LEGACY_CLAIM_BINDING_INVALID');
                }
                $bindings = $localeEnvelope['bindings'];
                unset($localeEnvelope['bindings']);
                $records[$locale]['definition']['content']['claim_locale_contract'] = $localeEnvelope;
                foreach ($bindings as $binding) {
                    if (! is_array($binding)) {
                        throw new CareerLegacyCurrentSplitFailure('LEGACY_CLAIM_BINDING_INVALID');
                    }
                    $module = $this->claimModule($binding);
                    $records[$locale][$module]['claim_bindings'][] = $binding;
                }
            }
        }

        return [$records, $wrapper];
    }

    /** @param array<string,array<string,array<string,mixed>>> $records @return array<string,mixed> */
    public function reconstructRow(array $records): array
    {
        $row = $records['en']['page-meta']['content']['row'];
        $row['canonical_slug'] = $records['en']['identity']['canonical_slug'];
        $row['seo_payload_json'] = [
            'en' => $records['en']['page-meta']['content']['seo'],
            'zh' => $records['zh-CN']['page-meta']['content']['seo'],
        ];

        if (isset($records['en']['geo']['content']['legacy_sources_json'])) {
            $row['sources_json'] = $records['en']['geo']['content']['legacy_sources_json'];
        } else {
            $sourceBindings = [];
            foreach (self::MODULES as $module) {
                foreach ($records['en'][$module]['source_bindings'] as $binding) {
                    $sourceBindings[hash('sha256', self::canonicalJson($binding))] = $binding;
                }
            }
            $references = [];
            foreach ($records['en']['geo']['content']['source_binding_order'] as $sourceKey) {
                $references[] = $sourceBindings[$sourceKey] ?? throw new CareerLegacyCurrentSplitFailure('SOURCE_BINDING_RECONSTRUCTION_INVALID');
            }
            $row['sources_json'] = $records['en']['geo']['content']['sources_contract'];
            $row['sources_json']['references'] = $references;
        }

        $row['structured_data_json'] = $records['en']['faq']['content']['structured_common'];
        $row['structured_data_json']['faq_page'] = [
            'en' => $records['en']['faq']['content']['structured_faq_page'],
            'zh' => $records['zh-CN']['faq']['content']['structured_faq_page'],
        ];

        $metadata = $records['en']['page-meta']['content']['metadata'];
        if (isset($records['zh-CN']['page-meta']['content']['presentation_v1'])) {
            $metadata['presentation_v1'] = $records['zh-CN']['page-meta']['content']['presentation_v1'];
        }
        if (isset($records['en']['definition']['content']['claim_envelope'])) {
            $claimEnvelope = $records['en']['definition']['content']['claim_envelope'];
            $claimEnvelope['locales'] = [];
            foreach (['en', 'zh-CN'] as $locale) {
                $localeEnvelope = $records[$locale]['definition']['content']['claim_locale_contract'];
                $bindings = [];
                foreach (self::MODULES as $module) {
                    foreach ($records[$locale][$module]['claim_bindings'] as $binding) {
                        $bindings[] = $binding;
                    }
                }
                $localeEnvelope['bindings'] = $bindings;
                $claimEnvelope['locales'][$locale] = $localeEnvelope;
            }
            $metadata['structured_components_v1'] = $claimEnvelope;
        }
        $row['metadata_json'] = $metadata;

        $pages = [];
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $legacyLocale) {
            $page = [];
            foreach (self::MODULES as $module) {
                foreach (($records[$locale][$module]['content']['page'] ?? []) as $component => $value) {
                    if (array_key_exists($component, $page)) {
                        throw new CareerLegacyCurrentSplitFailure('DUPLICATE_PAGE_COMPONENT');
                    }
                    $page[$component] = $value;
                }
            }
            $orderedPage = [];
            foreach (array_keys(self::COMPONENT_MODULE) as $component) {
                if (array_key_exists($component, $page)) {
                    $orderedPage[$component] = $page[$component];
                }
            }
            $pages[$legacyLocale] = $orderedPage;
        }
        $row['page_payload_json'] = $records['en']['page-meta']['content']['assembly']['legacy_page_wrapper'] === 'page'
            ? ['page' => $pages]
            : $pages;

        return $row;
    }

    public static function shardIndex(string $canonicalSlug): int
    {
        $bytes = hex2bin(substr(hash('sha256', $canonicalSlug), 0, 8));
        if ($bytes === false) {
            throw new CareerLegacyCurrentSplitFailure('SHARD_DIGEST_INVALID');
        }
        $unpacked = unpack('Nvalue', $bytes);

        return ((int) ($unpacked['value'] ?? 0)) % 64;
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $value */
    private static function prettyJson(array $value): string
    {
        return json_encode(self::canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    public function assertStableInputs(string $assetsBefore, string $manifestBefore, string $assetsPath, string $manifestPath): void
    {
        if (! hash_equals($assetsBefore, hash_file('sha256', $assetsPath) ?: '')
            || ! hash_equals($manifestBefore, hash_file('sha256', $manifestPath) ?: '')) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_BASELINE_DRIFT');
        }
    }

    /** @return array<string,mixed> */
    public function decodeCanonicalRow(string $line): array
    {
        $row = $this->decodeObject($line, 'LEGACY_ROW_INVALID');
        if (self::canonicalJson($row) !== $line) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_ROW_NOT_CANONICAL');
        }

        return $row;
    }

    /** @param array<string,bool> $seen */
    public function acceptSlug(string $slug, ?string &$previous, array &$seen): void
    {
        if (isset($seen[$slug])) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_DUPLICATE_SLUG');
        }
        if ($previous !== null && strcmp($previous, $slug) >= 0) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_SLUG_ORDER_INVALID');
        }
        $previous = $slug;
        $seen[$slug] = true;
    }

    /** @param list<string> $slugs */
    public function assertSlugSet(array $slugs, string $expectedHash): void
    {
        if (count($slugs) !== self::EXPECTED_SLUGS
            || ! hash_equals($expectedHash, hash('sha256', self::canonicalJson($slugs)))) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_SLUG_SET_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function aggregateHash(array $manifest): string
    {
        $projection = array_intersect_key($manifest, array_flip([
            'contract_version', 'modules', 'shards', 'registries', 'coverage', 'module_completeness',
        ]));

        return hash('sha256', self::canonicalJson($projection));
    }

    /** @param array<string,mixed> $manifest */
    private function assertInstalledShardedPackage(string $repoRoot, array $manifest): void
    {
        if (($manifest['modules'] ?? null) !== self::MODULES
            || ($manifest['registries'] ?? null) !== []
            || ! is_array($manifest['shards'] ?? null)
            || count($manifest['shards']) !== 640
            || ! hash_equals((string) ($manifest['aggregate_sha256'] ?? ''), $this->aggregateHash($manifest))) {
            throw new CareerLegacyCurrentSplitFailure('INSTALLED_SHARDED_MANIFEST_INVALID');
        }
        $currentRoot = $repoRoot.'/backend/content_assets/career/current';
        foreach ($manifest['shards'] as $position => $declaration) {
            $module = self::MODULES[intdiv($position, 64)] ?? null;
            $index = $position % 64;
            $relativePath = sprintf('%s/shard-%02d.jsonl', $module, $index);
            $path = $currentRoot.'/'.$relativePath;
            if (! is_array($declaration)
                || ($declaration['module'] ?? null) !== $module
                || ($declaration['shard_index'] ?? null) !== $index
                || ($declaration['path'] ?? null) !== $relativePath
                || ! is_file($path)
                || is_link($path)
                || ! hash_equals((string) ($declaration['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
                throw new CareerLegacyCurrentSplitFailure('INSTALLED_SHARDED_FILE_INVALID');
            }
        }
    }

    /** @return array<string,string> */
    private function prepareTemporaryShards(string $outputRoot): array
    {
        $paths = [];
        foreach (self::MODULES as $module) {
            $directory = $outputRoot.'/'.$module;
            if (! is_dir($directory) && ! mkdir($directory, 0700)) {
                throw new CareerLegacyCurrentSplitFailure('CANDIDATE_DIRECTORY_CREATE_FAILED');
            }
            if (is_link($directory)) {
                throw new CareerLegacyCurrentSplitFailure('CANDIDATE_SYMLINK_FORBIDDEN');
            }
            for ($index = 0; $index < 64; $index++) {
                $relativePath = sprintf('%s/shard-%02d.jsonl', $module, $index);
                $temporaryPath = $outputRoot.'/'.$relativePath.'.candidate.tmp';
                if (is_link($temporaryPath)) {
                    throw new CareerLegacyCurrentSplitFailure('CANDIDATE_SYMLINK_FORBIDDEN');
                }
                if (file_put_contents($temporaryPath, '') === false) {
                    throw new CareerLegacyCurrentSplitFailure('CANDIDATE_WRITE_FAILED');
                }
                $paths[$relativePath] = $temporaryPath;
            }
        }

        return $paths;
    }

    private function assertOutputInventory(string $outputRoot): void
    {
        $allowedRootFiles = [
            'manifest.json',
            'coverage-report.json',
            'field-ownership-report.json',
            'integrity-report.json',
            'repository-zero-write-receipt.json',
        ];
        foreach (new DirectoryIterator($outputRoot) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink()) {
                throw new CareerLegacyCurrentSplitFailure('CANDIDATE_SYMLINK_FORBIDDEN');
            }
            $rootFilename = str_ends_with($entry->getFilename(), '.candidate.tmp')
                ? substr($entry->getFilename(), 0, -14)
                : $entry->getFilename();
            if ($entry->isFile() && ! in_array($rootFilename, $allowedRootFiles, true)) {
                throw new CareerLegacyCurrentSplitFailure('CANDIDATE_UNKNOWN_FILE');
            }
            if ($entry->isDir() && ! in_array($entry->getFilename(), self::MODULES, true)) {
                throw new CareerLegacyCurrentSplitFailure('CANDIDATE_UNKNOWN_FILE');
            }
        }
        foreach (self::MODULES as $module) {
            $directory = $outputRoot.'/'.$module;
            if (! is_dir($directory)) {
                continue;
            }
            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($entry->isLink()
                    || ! $entry->isFile()
                    || preg_match('/\Ashard-(?:0[0-9]|[1-5][0-9]|6[0-3])\.jsonl(?:\.candidate\.tmp)?\z/', $entry->getFilename()) !== 1) {
                    throw new CareerLegacyCurrentSplitFailure('CANDIDATE_UNKNOWN_FILE');
                }
            }
        }
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        if (is_link($path)) {
            throw new CareerLegacyCurrentSplitFailure('CANDIDATE_SYMLINK_FORBIDDEN');
        }
        $temporaryPath = $path.'.candidate.tmp';
        if (file_put_contents($temporaryPath, $bytes, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            throw new CareerLegacyCurrentSplitFailure('CANDIDATE_WRITE_FAILED');
        }
    }

    private function guardDirectory(string $path, string $safeCode): string
    {
        if (! is_dir($path) || is_link($path)) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }
        $real = realpath($path);
        if ($real === false) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }

        return rtrim($real, '/');
    }

    private function guardOutputRoot(string $repoRoot, string $path): string
    {
        $real = $this->guardDirectory($path, 'CANDIDATE_OUTPUT_ROOT_INVALID');
        $temporaryRoots = array_values(array_unique(array_filter([
            realpath(sys_get_temp_dir()),
            realpath('/tmp'),
        ], static fn (mixed $root): bool => is_string($root) && $root !== '/')));
        $insideTemporaryRoot = false;
        foreach ($temporaryRoots as $temporaryRoot) {
            $insideTemporaryRoot = $insideTemporaryRoot || str_starts_with($real.'/', rtrim($temporaryRoot, '/').'/');
        }
        if (! $insideTemporaryRoot || str_starts_with($real.'/', $repoRoot.'/')) {
            throw new CareerLegacyCurrentSplitFailure('CANDIDATE_OUTPUT_ESCAPE');
        }

        return $real;
    }

    private function guardInputFile(string $repoRoot, string $path, string $safeCode): string
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }
        $real = realpath($path);
        if ($real === false || ! str_starts_with($real, $repoRoot.'/')) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }

        return $real;
    }

    private function repositoryStatus(string $repoRoot): string
    {
        $command = sprintf('git -C %s status --porcelain=v1 --untracked-files=all', escapeshellarg($repoRoot));
        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);
        if ($exitCode !== 0) {
            throw new CareerLegacyCurrentSplitFailure('REPOSITORY_STATUS_UNAVAILABLE');
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $bytes, string $safeCode): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerLegacyCurrentSplitFailure($safeCode);
        }

        return $value;
    }

    /** @param array<string,mixed> $reference */
    private function sourceModule(array $reference): string
    {
        $sourceKey = strtolower((string) ($reference['source_key'] ?? ''));
        $usageValue = $reference['usage'] ?? '';
        $usage = strtolower(is_string($usageValue) ? $usageValue : self::canonicalJson(['usage' => $usageValue]));
        if (str_starts_with($sourceKey, 'onet.')) {
            return 'definition';
        }
        if (str_contains($usage, 'salary') || str_contains($usage, 'wage') || str_contains($usage, 'employment') || str_contains($usage, 'growth') || str_contains($usage, 'outlook')) {
            return 'salary';
        }
        if (str_starts_with($sourceKey, 'fermatmind.interpretation.')) {
            return 'fit-personality';
        }

        return 'geo';
    }

    /** @param array<string,mixed> $binding */
    private function claimModule(array $binding): string
    {
        $paths = $binding['input_jsonpaths'] ?? null;
        if (! is_array($paths) || $paths === []) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_CLAIM_BINDING_INVALID');
        }
        $modules = [];
        foreach ($paths as $path) {
            if (! is_string($path) || preg_match('/\A\$\.([a-z][a-z-]*)\./', $path, $matches) !== 1 || ! in_array($matches[1], self::MODULES, true)) {
                throw new CareerLegacyCurrentSplitFailure('LEGACY_CLAIM_BINDING_INVALID');
            }
            $modules[$matches[1]] = true;
        }
        if (count($modules) !== 1) {
            throw new CareerLegacyCurrentSplitFailure('LEGACY_CLAIM_BINDING_AMBIGUOUS');
        }

        return array_key_first($modules);
    }

    private function leafCount(mixed $value): int
    {
        if (! is_array($value) || $value === []) {
            return 1;
        }

        return array_sum(array_map(fn (mixed $item): int => $this->leafCount($item), $value));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}

function careerLegacyCurrentSharderMain(array $argv): int
{
    $options = getopt('', ['repo-root::', 'assets::', 'legacy-manifest::', 'output-root::']);
    $scriptRoot = dirname(__DIR__, 4);
    $repoRoot = (string) ($options['repo-root'] ?? $scriptRoot);
    $assets = (string) ($options['assets'] ?? $repoRoot.'/backend/content_assets/career/current/assets.jsonl');
    $legacyManifest = (string) ($options['legacy-manifest'] ?? $repoRoot.'/backend/content_assets/career/current/manifest.json');
    $outputRoot = (string) ($options['output-root'] ?? getenv('CAREER_SHARDED_CANDIDATE_OUTPUT_ROOT'));
    try {
        if ($outputRoot === '') {
            throw new CareerLegacyCurrentSplitFailure('CANDIDATE_OUTPUT_ROOT_REQUIRED');
        }
        $result = (new CareerLegacyCurrentSharder)->split($repoRoot, $assets, $legacyManifest, $outputRoot);
        fwrite(STDOUT, CareerLegacyCurrentSharder::canonicalJson([
            'candidate_aggregate_sha256' => $result['manifest']['aggregate_sha256'],
            'contract_version' => 'career.sharded_current.split_cli_receipt.v1',
            'repository_writes' => 0,
            'status' => 'PASS',
        ])."\n");

        return 0;
    } catch (CareerLegacyCurrentSplitFailure $failure) {
        fwrite(STDOUT, CareerLegacyCurrentSharder::canonicalJson([
            'contract_version' => 'career.sharded_current.split_cli_receipt.v1',
            'repository_writes' => 0,
            'safe_error_code' => $failure->safeCode,
            'status' => 'FAIL',
        ])."\n");

        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(careerLegacyCurrentSharderMain($argv));
}
