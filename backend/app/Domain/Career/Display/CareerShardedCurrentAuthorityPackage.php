<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;

final class CareerShardedCurrentAuthorityPackage
{
    public const CONTRACT_VERSION = 'career.sharded_current.manifest.v1';

    /** @var list<string> */
    private const MODULES = [
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

    /** @var list<string> */
    private const ROW_KEYS = [
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

    public function __construct(private readonly CareerCurrentAuthorityPackage $legacyContract) {}

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{manifest:array<string,mixed>,rows:array<string,array<string,mixed>>,slugs:list<string>,summary:array<string,mixed>}
     */
    public function load(string $backendRoot, array $manifest): array
    {
        $root = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        if (! is_dir($root) || is_link($root)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }
        $this->assertManifest($manifest);
        $this->assertRegistries($root, $manifest);

        $records = [];
        $identities = [];
        $shards = $manifest['shards'];
        foreach ($shards as $position => $declaration) {
            $module = self::MODULES[intdiv($position, 64)] ?? null;
            $index = $position % 64;
            $relativePath = sprintf('%s/shard-%02d.jsonl', $module, $index);
            if (! is_array($declaration)
                || ($declaration['module'] ?? null) !== $module
                || ($declaration['shard_index'] ?? null) !== $index
                || ($declaration['path'] ?? null) !== $relativePath
                || ! is_int($declaration['row_count'] ?? null)
                || ! is_string($declaration['sha256'] ?? null)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_DECLARATION_INVALID');
            }
            $path = $root.'/'.$relativePath;
            if (! is_file($path) || is_link($path)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_FILE_MISSING');
            }
            $bytes = (string) file_get_contents($path);
            if ($bytes === '' || ! str_ends_with($bytes, "\n")
                || ! hash_equals($declaration['sha256'], hash('sha256', $bytes))) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_HASH_MISMATCH');
            }
            $lines = explode("\n", substr($bytes, 0, -1));
            if (count($lines) !== $declaration['row_count']) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_ROW_COUNT_MISMATCH');
            }
            $previous = null;
            foreach ($lines as $line) {
                $record = $this->canonicalObject($line, 'CURRENT_SHARDED_ROW_INVALID');
                $this->assertRecord($record, $module, $index);
                $slug = $record['canonical_slug'];
                $locale = $record['locale'];
                $sortKey = $slug."\0".$locale;
                if ($previous !== null && strcmp($previous, $sortKey) >= 0) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_ROW_ORDER_INVALID');
                }
                $previous = $sortKey;
                $identity = $slug."\0".$locale."\0".$module;
                if (isset($identities[$identity])) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_IDENTITY_DUPLICATE');
                }
                $identities[$identity] = true;
                $records[$slug][$locale][$module] = $line;
            }
        }
        if (count($records) !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || count($identities) !== CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES * count(self::MODULES)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_COVERAGE_INVALID');
        }

        ksort($records, SORT_STRING);
        $rows = [];
        $assetLines = [];
        $publicContentHashes = [];
        foreach ($records as $slug => $locales) {
            $orderedLocales = [];
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                if (! is_array($locales[$locale] ?? null)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_LOCALE_PAIR_INVALID');
                }
                $orderedModules = [];
                foreach (self::MODULES as $module) {
                    if (! is_string($locales[$locale][$module] ?? null)) {
                        throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_MODULE_INCOMPLETE');
                    }
                    $orderedModules[$module] = $this->canonicalObject(
                        $locales[$locale][$module],
                        'CURRENT_SHARDED_ROW_INVALID',
                    );
                }
                $orderedLocales[$locale] = $orderedModules;
            }
            unset($records[$slug]);
            $row = $this->assembleRow($orderedLocales);
            if (($row['canonical_slug'] ?? null) !== $slug) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SLUG_CONFLICT');
            }
            $this->assertAssembledRow($row);
            $rows[$slug] = $row;
            $assetLines[] = CareerCurrentAuthorityPackage::encodeCanonical($row);
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $publicContentHashes[] = $this->legacyContract->publicContentHash($row, $locale);
            }
        }

        $assetsBytes = implode("\n", $assetLines)."\n";
        $slugs = array_keys($rows);
        sort($publicContentHashes, SORT_STRING);

        return [
            'manifest' => $manifest,
            'rows' => $rows,
            'slugs' => $slugs,
            'summary' => [
                'assets_sha256' => hash('sha256', $assetsBytes),
                'career_count' => count($rows),
                'components_per_page' => count(CareerDisplayAssetComponentContract::CURRENT_ORDER),
                'full_asset_set_sha256' => CareerCurrentAuthorityPackage::hashValue(array_values($rows)),
                'locale_page_count' => CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES,
                'manifest_sha256' => hash_file('sha256', $root.'/manifest.json'),
                'numeric_rating_statement_residue_count' => 0,
                'public_content_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($publicContentHashes),
                'sharded_aggregate_sha256' => $manifest['aggregate_sha256'],
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($slugs),
                'source_format' => 'sharded',
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest): void
    {
        if (($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($manifest['authority_path'] ?? null) !== 'backend/content_assets/career/current'
            || ($manifest['modules'] ?? null) !== self::MODULES
            || ($manifest['coverage'] ?? null) != [
                'slugs' => CareerCurrentAuthorityPackage::EXPECTED_CAREERS,
                'locales' => CareerCurrentAuthorityPackage::LOCALES,
                'locale_pages' => CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES,
                'module_rows' => CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES * count(self::MODULES),
            ]
            || ($manifest['module_completeness'] ?? null) != [
                'rows_per_module' => CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES,
                'modules_per_slug_locale' => count(self::MODULES),
            ]
            || ! is_array($manifest['shards'] ?? null)
            || count($manifest['shards']) !== 640
            || ! is_array($manifest['registries'] ?? null)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_MANIFEST_INVALID');
        }
        $projection = array_intersect_key($manifest, array_flip([
            'contract_version', 'modules', 'shards', 'registries', 'coverage', 'module_completeness',
        ]));
        if (! hash_equals(
            (string) ($manifest['aggregate_sha256'] ?? ''),
            CareerCurrentAuthorityPackage::hashValue($projection),
        )) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_AGGREGATE_MISMATCH');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertRegistries(string $root, array $manifest): void
    {
        $seen = [];
        foreach ($manifest['registries'] as $registry) {
            $path = is_array($registry) ? ($registry['path'] ?? null) : null;
            $sha256 = is_array($registry) ? ($registry['sha256'] ?? null) : null;
            if (! is_string($path)
                || preg_match('/\Aregistries\/[a-z0-9]+(?:-[a-z0-9]+)*\.json\z/', $path) !== 1
                || isset($seen[$path])
                || ! is_string($sha256)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_REGISTRY_INVALID');
            }
            $seen[$path] = true;
            $absolute = $root.'/'.$path;
            if (! is_file($absolute) || is_link($absolute)
                || ! hash_equals($sha256, (string) hash_file('sha256', $absolute))) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_REGISTRY_INVALID');
            }
        }
    }

    /** @param array<string,mixed> $record */
    private function assertRecord(array $record, string $module, int $shardIndex): void
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $slug = $record['canonical_slug'] ?? null;
        $locale = $record['locale'] ?? null;
        if ($keys !== ['canonical_slug', 'claim_bindings', 'content', 'locale', 'module', 'source_bindings']
            || ! is_string($slug)
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
            || $slug === 'software-developers'
            || ! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)
            || ($record['module'] ?? null) !== $module
            || self::shardIndex($slug) !== $shardIndex
            || ! is_array($record['content'] ?? null)
            || ! is_array($record['source_bindings'] ?? null)
            || ! array_is_list($record['source_bindings'])
            || ! is_array($record['claim_bindings'] ?? null)
            || ! array_is_list($record['claim_bindings'])) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_ROW_INVALID');
        }
    }

    /**
     * @param  array<string,array<string,array<string,mixed>>>  $records
     * @return array<string,mixed>
     */
    private function assembleRow(array $records): array
    {
        $components = [];
        foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
            foreach (self::MODULES as $module) {
                $record = $records[$locale][$module];
                if (($record['canonical_slug'] ?? null) !== $records['en']['identity']['canonical_slug']) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SLUG_CONFLICT');
                }
                $page = $record['content']['page'] ?? null;
                if (! is_array($page)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_MODULE_CONTENT_INVALID');
                }
                foreach ($page as $component => $value) {
                    if ((self::COMPONENT_MODULE[$component] ?? null) !== $module
                        || array_key_exists($component, $components[$locale] ?? [])) {
                        throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_COMPONENT_OWNERSHIP_INVALID');
                    }
                    $components[$locale][$component] = $value;
                }
            }
            $keys = array_keys($components[$locale]);
            sort($keys, SORT_STRING);
            $expected = array_keys(self::COMPONENT_MODULE);
            sort($expected, SORT_STRING);
            if ($keys !== $expected) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_COMPONENT_SET_INVALID');
            }
        }

        $pageMeta = $records['en']['page-meta']['content'];
        $row = $pageMeta['row'] ?? null;
        if (! is_array($row) || array_is_list($row)
            || ! in_array($pageMeta['assembly']['legacy_page_wrapper'] ?? null, ['page', 'direct'], true)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_ROW_ENVELOPE_INVALID');
        }
        $row['canonical_slug'] = $records['en']['identity']['canonical_slug'];
        $row['seo_payload_json'] = [
            'en' => $records['en']['page-meta']['content']['seo'] ?? null,
            'zh' => $records['zh-CN']['page-meta']['content']['seo'] ?? null,
        ];
        $row['sources_json'] = $this->assembleSources($records);
        $row['structured_data_json'] = $records['en']['faq']['content']['structured_common'] ?? [];
        $row['structured_data_json']['faq_page'] = [
            'en' => $records['en']['faq']['content']['structured_faq_page'] ?? null,
            'zh' => $records['zh-CN']['faq']['content']['structured_faq_page'] ?? null,
        ];
        $row['metadata_json'] = $this->assembleMetadata($records);

        $pages = [];
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $legacyLocale) {
            $page = [];
            foreach (array_keys(self::COMPONENT_MODULE) as $component) {
                $page[$component] = $components[$locale][$component];
            }
            $pages[$legacyLocale] = $page;
        }
        $row['page_payload_json'] = $pageMeta['assembly']['legacy_page_wrapper'] === 'page'
            ? ['page' => $pages]
            : $pages;

        return $row;
    }

    /** @param array<string,array<string,array<string,mixed>>> $records @return array<string,mixed> */
    private function assembleSources(array $records): array
    {
        $geo = $records['en']['geo']['content'];
        if (isset($geo['legacy_sources_json'])) {
            return $geo['legacy_sources_json'];
        }
        if (! is_array($geo['sources_contract'] ?? null) || ! is_array($geo['source_binding_order'] ?? null)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SOURCE_DEPENDENCY_INVALID');
        }
        $bindings = [];
        foreach (self::MODULES as $module) {
            foreach ($records['en'][$module]['source_bindings'] as $binding) {
                if (! is_array($binding)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SOURCE_DEPENDENCY_INVALID');
                }
                $hash = CareerCurrentAuthorityPackage::hashValue($binding);
                if (isset($bindings[$hash])) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SOURCE_DEPENDENCY_INVALID');
                }
                $bindings[$hash] = $binding;
            }
        }
        $sources = $geo['sources_contract'];
        $sources['references'] = [];
        foreach ($geo['source_binding_order'] as $hash) {
            if (! is_string($hash) || ! isset($bindings[$hash])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SOURCE_DEPENDENCY_INVALID');
            }
            $sources['references'][] = $bindings[$hash];
            unset($bindings[$hash]);
        }
        if ($bindings !== []) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_SOURCE_DEPENDENCY_INVALID');
        }

        return $sources;
    }

    /** @param array<string,array<string,array<string,mixed>>> $records @return array<string,mixed> */
    private function assembleMetadata(array $records): array
    {
        $metadata = $records['en']['page-meta']['content']['metadata'] ?? null;
        if (! is_array($metadata)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_METADATA_DEPENDENCY_INVALID');
        }
        $presentation = $records['zh-CN']['page-meta']['content']['presentation_v1'] ?? null;
        if ($presentation !== null) {
            $metadata['presentation_v1'] = $presentation;
        }
        $claimEnvelope = $records['en']['definition']['content']['claim_envelope'] ?? null;
        if ($claimEnvelope !== null) {
            if (! is_array($claimEnvelope)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_CLAIM_DEPENDENCY_INVALID');
            }
            $claimEnvelope['locales'] = [];
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $localeEnvelope = $records[$locale]['definition']['content']['claim_locale_contract'] ?? null;
                if (! is_array($localeEnvelope)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_CLAIM_DEPENDENCY_INVALID');
                }
                $localeEnvelope['bindings'] = [];
                foreach (self::MODULES as $module) {
                    foreach ($records[$locale][$module]['claim_bindings'] as $binding) {
                        if (! is_array($binding)) {
                            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_CLAIM_DEPENDENCY_INVALID');
                        }
                        $localeEnvelope['bindings'][] = $binding;
                    }
                }
                $claimEnvelope['locales'][$locale] = $localeEnvelope;
            }
            $metadata['structured_components_v1'] = $claimEnvelope;
        }

        return $metadata;
    }

    /** @param array<string,mixed> $row */
    private function assertAssembledRow(array $row): void
    {
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== self::ROW_KEYS
            || ($row['asset_version'] ?? null) !== CareerCurrentAuthorityPackage::ASSET_VERSION
            || ($row['template_version'] ?? null) !== CareerCurrentAuthorityPackage::ASSET_VERSION
            || ($row['component_order_json'] ?? null) !== CareerDisplayAssetComponentContract::CURRENT_ORDER
            || ! CareerDisplayAssetComponentContract::hasExactCurrentPages((array) ($row['page_payload_json'] ?? []))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SHARDED_ASSEMBLY_INVALID');
        }
        foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
            $this->legacyContract->publicProjection($row, $locale);
        }
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $line, string $safeCode): array
    {
        try {
            $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)
            || CareerCurrentAuthorityPackage::encodeCanonical($value) !== $line) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }

        return $value;
    }

    private static function shardIndex(string $slug): int
    {
        $bytes = hex2bin(substr(hash('sha256', $slug), 0, 8));
        $value = is_string($bytes) ? unpack('Nvalue', $bytes) : false;

        return ((int) (is_array($value) ? ($value['value'] ?? 0) : 0)) % 64;
    }
}
