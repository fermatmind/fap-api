<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CareerContentV3AuthorityPackage
{
    public const CONTRACT_VERSION = 'career.content_v3_current.manifest.v1';

    public const SCHEMA_VERSION = 'career.detail.content.v3';

    public const COMPILER_VERSION = 'career.content_v3.per_page.compiler.v1';

    public const CANONICAL_SLUG_SET_SHA256 = '19a7e22cd0c854276efb3f9d15927bd034e7b2ecd6917fbe841e9dd0632fc988';

    public function __construct(
        private readonly int $expectedCareers = CareerCurrentAuthorityPackage::EXPECTED_CAREERS,
        private readonly int $expectedLocalePages = CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES,
        private readonly int $expectedEnhancedLocalePages = 2,
        private readonly string $expectedSlugSetSha256 = self::CANONICAL_SLUG_SET_SHA256,
    ) {}

    /**
     * @return array{
     *   manifest:array<string,mixed>,
     *   pages:array<string,array<string,array<string,mixed>>>,
     *   slugs:list<string>,
     *   summary:array<string,mixed>
     * }
     */
    public function load(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;

        return $this->loadRoot($root);
    }

    /**
     * Validate the complete root contract and declared inventory without retaining all page bodies.
     *
     * @return array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,array<string,mixed>>>,slugs:list<string>}
     */
    public function manifestIndex(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $resolvedRoot = realpath($root);
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot) || is_link($root)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_ROOT_INVALID');
        }
        $manifest = $this->readObject($resolvedRoot.'/manifest.json', 'CURRENT_CONTENT_V3_MANIFEST_INVALID');
        $this->assertManifest($manifest);
        $declaredPaths = ['manifest.json' => true];
        $entries = [];
        $localePages = [];
        $semanticHashes = [];
        $compatibilityHashes = [];
        foreach ($manifest['files'] as $entry) {
            $this->assertFileEntry($entry);
            $slug = $entry['canonical_slug'];
            $locale = $entry['locale'];
            $path = $entry['path'];
            $identity = $slug.'|'.$locale;
            if (isset($declaredPaths[$path]) || isset($localePages[$identity])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_DUPLICATE_BINDING');
            }
            $absolute = $resolvedRoot.'/'.$path;
            $real = realpath($absolute);
            if (! is_file($absolute) || is_link($absolute)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_MISSING');
            }
            if (! is_string($real) || ! str_starts_with($real, $resolvedRoot.'/')) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PATH_TRAVERSAL');
            }
            $declaredPaths[$path] = true;
            $localePages[$identity] = true;
            $entries[$slug][$locale] = $entry;
            $semanticHashes[] = $entry['source_content_sha256'];
            $compatibilityHashes[] = $entry['legacy_projection_sha256'];
        }
        $this->assertInventory($resolvedRoot, $declaredPaths);
        ksort($entries, SORT_STRING);
        $slugs = array_keys($entries);
        foreach ($entries as $localized) {
            if (array_keys($localized) !== CareerCurrentAuthorityPackage::LOCALES) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_LOCALE_PAIR_INVALID');
            }
        }
        $localePageSet = array_keys($localePages);
        sort($localePageSet, SORT_STRING);
        if (count($slugs) !== $this->expectedCareers
            || count($localePageSet) !== $this->expectedLocalePages
            || ! hash_equals($manifest['set_hashes']['slug_set_sha256'], CareerCurrentAuthorityPackage::hashValue($slugs))
            || ! hash_equals($this->expectedSlugSetSha256, $manifest['set_hashes']['slug_set_sha256'])
            || ! hash_equals($manifest['set_hashes']['locale_page_set_sha256'], CareerCurrentAuthorityPackage::hashValue($localePageSet))
            || ! hash_equals($manifest['set_hashes']['source_semantic_aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($semanticHashes))
            || ! hash_equals($manifest['set_hashes']['legacy_projection_aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($compatibilityHashes))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_COVERAGE_INVALID');
        }
        $aggregateProjection = array_intersect_key($manifest, array_flip([
            'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
            'schema_version', 'set_hashes', 'source_registry_sha256',
        ]));
        if (! hash_equals($manifest['aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($aggregateProjection))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_AGGREGATE_MISMATCH');
        }

        return ['root' => $resolvedRoot, 'manifest' => $manifest, 'entries' => $entries, 'slugs' => $slugs];
    }

    /** @param array{root:string,entries:array<string,array<string,array<string,mixed>>>} $index @return array<string,mixed> */
    public function pageFromIndex(array $index, string $slug, string $locale): array
    {
        $page = $this->pageFromIndexForRuntime($index, $slug, $locale);
        CareerContentV3Contract::assert($page);
        (new CareerContentV3FactResolver)->resolve($page);

        return $page;
    }

    /**
     * Validate the immutable file envelope while leaving block isolation to the canonical reader.
     *
     * @param  array{root:string,entries:array<string,array<string,array<string,mixed>>>}  $index
     * @return array<string,mixed>
     */
    public function pageFromIndexForRuntime(array $index, string $slug, string $locale): array
    {
        $entry = $index['entries'][$slug][$locale] ?? null;
        if (! is_array($entry)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_MISSING');
        }
        $absolute = $index['root'].'/'.$entry['path'];
        if (! is_file($absolute) || is_link($absolute)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_MISSING');
        }
        $bytes = file_get_contents($absolute);
        if (! is_string($bytes)
            || strlen($bytes) !== $entry['bytes']
            || ! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_HASH_MISMATCH');
        }
        $page = $this->decodeCanonicalPretty($bytes);
        if (($page['locale'] ?? null) !== $locale
            || data_get($page, 'subject.canonical_slug') !== $slug
            || ! hash_equals((string) $entry['source_content_sha256'], (string) $page['source_content_sha256'])) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_IDENTITY_MISMATCH');
        }

        return $page;
    }

    /**
     * @return array{
     *   manifest:array<string,mixed>,
     *   pages:array<string,array<string,array<string,mixed>>>,
     *   slugs:list<string>,
     *   summary:array<string,mixed>
     * }
     */
    public function loadRoot(string $root): array
    {
        $resolvedRoot = realpath($root);
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot) || is_link($root)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_ROOT_INVALID');
        }
        $root = $resolvedRoot;
        $manifest = $this->readObject($root.'/manifest.json', 'CURRENT_CONTENT_V3_MANIFEST_INVALID');
        $this->assertManifest($manifest);

        $declaredPaths = ['manifest.json' => true];
        $pages = [];
        $slugs = [];
        $localePages = [];
        $fileProjection = [];
        $semanticHashes = [];
        $compatibilityHashes = [];
        $enhanced = 0;
        $legacy = 0;
        $blockCount = 0;
        $itemCount = 0;

        foreach ($manifest['files'] as $entry) {
            $this->assertFileEntry($entry);
            $slug = $entry['canonical_slug'];
            $locale = $entry['locale'];
            $path = $entry['path'];
            $identity = $slug.'|'.$locale;
            if (isset($declaredPaths[$path]) || isset($localePages[$identity])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_DUPLICATE_BINDING');
            }
            $declaredPaths[$path] = true;
            $localePages[$identity] = true;
            $absolute = $root.'/'.$path;
            if (! is_file($absolute) || is_link($absolute)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_MISSING');
            }
            $real = realpath($absolute);
            $realRoot = realpath($root);
            if (! is_string($real) || ! is_string($realRoot) || ! str_starts_with($real, $realRoot.'/')) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PATH_TRAVERSAL');
            }
            $bytes = file_get_contents($real);
            if (! is_string($bytes)
                || strlen($bytes) !== $entry['bytes']
                || ! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_HASH_MISMATCH');
            }
            $page = $this->decodeCanonicalPretty($bytes);
            CareerContentV3Contract::assert($page);
            (new CareerContentV3FactResolver)->resolve($page);
            if (($page['locale'] ?? null) !== $locale
                || data_get($page, 'subject.canonical_slug') !== $slug
                || ! hash_equals((string) $entry['source_content_sha256'], (string) $page['source_content_sha256'])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_IDENTITY_MISMATCH');
            }
            if (isset($pages[$slug][$locale])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_DUPLICATE_BINDING');
            }
            $pages[$slug][$locale] = $page;
            $slugs[$slug] = true;
            $semanticHashes[] = $page['source_content_sha256'];
            $compatibilityHashes[] = $entry['legacy_projection_sha256'];
            $page['content_state'] === 'enhanced' ? $enhanced++ : $legacy++;
            $blockCount += count($page['blocks']);
            foreach ($page['blocks'] as $block) {
                $itemCount += count($block['items']);
            }
            $fileProjection[] = $entry;
        }

        $this->assertInventory($root, $declaredPaths);
        ksort($pages, SORT_STRING);
        $sortedSlugs = array_keys($pages);
        foreach ($pages as $slug => $localized) {
            if (array_keys($localized) !== CareerCurrentAuthorityPackage::LOCALES) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_LOCALE_PAIR_INVALID');
            }
            if (! isset($slugs[$slug])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_SLUG_SET_INVALID');
            }
        }
        $localePageSet = array_keys($localePages);
        sort($localePageSet, SORT_STRING);
        if (count($sortedSlugs) !== $this->expectedCareers
            || count($localePageSet) !== $this->expectedLocalePages
            || ! hash_equals($manifest['set_hashes']['slug_set_sha256'], CareerCurrentAuthorityPackage::hashValue($sortedSlugs))
            || ! hash_equals($this->expectedSlugSetSha256, $manifest['set_hashes']['slug_set_sha256'])
            || ! hash_equals($manifest['set_hashes']['locale_page_set_sha256'], CareerCurrentAuthorityPackage::hashValue($localePageSet))
            || ! hash_equals($manifest['set_hashes']['source_semantic_aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($semanticHashes))
            || ! hash_equals($manifest['set_hashes']['legacy_projection_aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($compatibilityHashes))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_COVERAGE_INVALID');
        }

        $aggregateProjection = array_intersect_key($manifest, array_flip([
            'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
            'schema_version', 'set_hashes', 'source_registry_sha256',
        ]));
        if (! hash_equals($manifest['aggregate_sha256'], CareerCurrentAuthorityPackage::hashValue($aggregateProjection))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_AGGREGATE_MISMATCH');
        }

        return [
            'manifest' => $manifest,
            'pages' => $pages,
            'slugs' => $sortedSlugs,
            'summary' => [
                'aggregate_sha256' => $manifest['aggregate_sha256'],
                'assets_sha256' => $manifest['aggregate_sha256'],
                'career_count' => count($sortedSlugs),
                'file_count' => count($localePageSet),
                'locale_page_count' => count($localePageSet),
                'enhanced_locale_page_count' => $enhanced,
                'legacy_locale_page_count' => $legacy,
                'block_count' => $blockCount,
                'item_count' => $itemCount,
                'manifest_sha256' => hash_file('sha256', $root.'/manifest.json'),
                'source_format' => 'content_v3_per_page',
                'slug_set_sha256' => $manifest['set_hashes']['slug_set_sha256'],
                'locale_page_set_sha256' => $manifest['set_hashes']['locale_page_set_sha256'],
                'versionless_projection_sha256' => $manifest['set_hashes']['legacy_versionless_projection_sha256'],
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest): void
    {
        $keys = array_keys($manifest);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'aggregate_sha256', 'authority_path', 'compiler_version', 'contract_version', 'coverage',
            'files', 'locales', 'schema_version', 'set_hashes', 'source_registry_sha256',
        ]
            || ($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($manifest['authority_path'] ?? null) !== 'backend/content_assets/career/current'
            || ($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($manifest['compiler_version'] ?? null) !== self::COMPILER_VERSION
            || ($manifest['locales'] ?? null) !== CareerCurrentAuthorityPackage::LOCALES
            || ($manifest['coverage'] ?? null) != [
                'slugs' => $this->expectedCareers,
                'locales' => count(CareerCurrentAuthorityPackage::LOCALES),
                'locale_pages' => $this->expectedLocalePages,
                'files' => $this->expectedLocalePages,
                'enhanced_locale_pages' => $this->expectedEnhancedLocalePages,
                'legacy_locale_pages' => $this->expectedLocalePages - $this->expectedEnhancedLocalePages,
            ]
            || ! is_array($manifest['files'] ?? null)
            || count($manifest['files']) !== $this->expectedLocalePages
            || ! $this->hash((string) ($manifest['aggregate_sha256'] ?? ''))
            || ! $this->hash((string) ($manifest['source_registry_sha256'] ?? ''))
            || ! is_array($manifest['set_hashes'] ?? null)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_MANIFEST_INVALID');
        }
        $setKeys = array_keys($manifest['set_hashes']);
        sort($setKeys, SORT_STRING);
        if ($setKeys !== [
            'legacy_projection_aggregate_sha256', 'legacy_versionless_projection_sha256',
            'locale_page_set_sha256', 'slug_set_sha256', 'source_semantic_aggregate_sha256',
        ]) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_MANIFEST_INVALID');
        }
        foreach ($manifest['set_hashes'] as $hash) {
            if (! is_string($hash) || ! $this->hash($hash)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_MANIFEST_INVALID');
            }
        }
    }

    private function assertFileEntry(mixed $entry): void
    {
        if (! is_array($entry) || array_is_list($entry)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_DECLARATION_INVALID');
        }
        $keys = array_keys($entry);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'bytes', 'canonical_slug', 'legacy_projection_sha256', 'legacy_row_sha256', 'locale',
            'path', 'sha256', 'source_content_sha256',
        ]) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_DECLARATION_INVALID');
        }
        $slug = $entry['canonical_slug'] ?? null;
        $locale = $entry['locale'] ?? null;
        $path = $entry['path'] ?? null;
        if (! is_string($slug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
            || ! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)
            || $path !== 'careers/'.$slug.'/'.$locale.'.json'
            || ! is_int($entry['bytes'] ?? null) || $entry['bytes'] <= 0) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_DECLARATION_INVALID');
        }
        foreach (['sha256', 'source_content_sha256', 'legacy_projection_sha256', 'legacy_row_sha256'] as $key) {
            if (! is_string($entry[$key] ?? null) || ! $this->hash($entry[$key])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FILE_DECLARATION_INVALID');
            }
        }
    }

    /** @param array<string,true> $declaredPaths */
    private function assertInventory(string $root, array $declaredPaths): void
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_UNDECLARED_FILE');
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $actual[$relative] = true;
        }
        ksort($actual, SORT_STRING);
        ksort($declaredPaths, SORT_STRING);
        if ($actual !== $declaredPaths) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_UNDECLARED_FILE');
        }
    }

    /** @return array<string,mixed> */
    private function readObject(string $path, string $safeCode): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function decodeCanonicalPretty(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_JSON_INVALID');
        }
        if (! is_array($value) || array_is_list($value)
            || CareerCurrentAuthorityPackage::encodePrettyCanonical($value) !== $bytes) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_JSON_INVALID');
        }

        return $value;
    }

    private function hash(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
