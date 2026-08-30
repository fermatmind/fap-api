<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PersonalityCurrentAuthorityPackage
{
    public const RELATIVE_PATH = 'content_assets/personality_public/current';

    public const AUTHORITY_PATH = 'backend/content_assets/personality_public/current';

    public const CONTRACT_VERSION = 'personality.page.content.current.manifest.v1';

    public const COMPILER_VERSION = 'personality.page.per_page.compiler.v1';

    public const EXPECTED_FILES = 364;

    public const EXPECTED_PAGES_PER_LOCALE = 182;

    /** @return array{root:string,manifest:array<string,mixed>,entries:array<string,array<string,mixed>>} */
    public function manifestIndex(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH;
        $resolvedRoot = realpath($root);
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot) || is_link($root)) {
            self::fail('PERSONALITY_CURRENT_ROOT_INVALID');
        }
        $manifest = $this->readObject($resolvedRoot.'/manifest.json', 'PERSONALITY_CURRENT_MANIFEST_INVALID');
        $this->assertManifestEnvelope($manifest);

        $declared = ['manifest.json' => true];
        $entries = [];
        $identities = [];
        $routes = [];
        $semanticHashes = [];
        $compatibilityHashes = [];
        $localeCounts = array_fill_keys(PersonalityPageContentContract::LOCALES, 0);
        $frameworkCounts = [];
        $pageKindCounts = [];
        $baseline = 0;
        $enhanced = 0;

        foreach ($manifest['files'] as $entry) {
            $this->assertFileEntry($entry);
            $path = $entry['path'];
            $identityKey = $entry['identity_key'];
            $routeKey = $entry['locale'].'|'.$entry['canonical_path'];
            if (isset($declared[$path]) || isset($identities[$identityKey]) || isset($routes[$routeKey])) {
                self::fail('PERSONALITY_CURRENT_DUPLICATE_BINDING');
            }
            $absolute = $resolvedRoot.'/'.$path;
            $real = realpath($absolute);
            if (! is_file($absolute) || is_link($absolute) || ! is_string($real) || ! str_starts_with($real, $resolvedRoot.'/')) {
                self::fail('PERSONALITY_CURRENT_FILE_INVALID');
            }
            $bytes = file_get_contents($real);
            if (! is_string($bytes)
                || strlen($bytes) !== $entry['bytes']
                || ! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
                self::fail('PERSONALITY_CURRENT_FILE_HASH_MISMATCH');
            }
            $page = $this->decodeObject($bytes, 'PERSONALITY_CURRENT_PAGE_INVALID');
            PersonalityPageContentContract::assert($page);
            $this->assertPageBinding($page, $entry);

            $declared[$path] = true;
            $identities[$identityKey] = true;
            $routes[$routeKey] = true;
            $entries[$identityKey] = $entry;
            $semanticHashes[] = $entry['source_content_sha256'];
            $compatibilityHashes[] = $entry['compatibility_projection_sha256'];
            $localeCounts[$entry['locale']]++;
            $frameworkCounts[$entry['framework']] = ($frameworkCounts[$entry['framework']] ?? 0) + 1;
            $pageKindCounts[$entry['page_kind']] = ($pageKindCounts[$entry['page_kind']] ?? 0) + 1;
            $entry['content_state'] === 'enhanced' ? $enhanced++ : $baseline++;
        }
        $this->assertInventory($resolvedRoot, $declared);
        ksort($entries, SORT_STRING);
        ksort($frameworkCounts, SORT_STRING);
        ksort($pageKindCounts, SORT_STRING);
        $identitySet = array_keys($identities);
        $routeSet = array_keys($routes);
        sort($identitySet, SORT_STRING);
        sort($routeSet, SORT_STRING);

        $coverage = $manifest['coverage'];
        if (count($entries) !== self::EXPECTED_FILES
            || $localeCounts !== ['en' => self::EXPECTED_PAGES_PER_LOCALE, 'zh-CN' => self::EXPECTED_PAGES_PER_LOCALE]
            || $frameworkCounts !== ['big_five' => 104, 'enneagram' => 116, 'mbti' => 144]
            || $pageKindCounts !== [
                'center' => 6, 'comparison_at' => 32, 'comparison_cross' => 14, 'core_type' => 18,
                'domain' => 10, 'facet_detail' => 60, 'facet_hub' => 2, 'hub' => 6,
                'instinctual_subtype' => 54, 'polarity' => 30, 'profile' => 32, 'variant' => 64,
                'wing' => 36,
            ]
            || $coverage['baseline_locale_pages'] !== $baseline
            || $coverage['enhanced_locale_pages'] !== $enhanced
            || $coverage['by_framework'] !== $frameworkCounts
            || $coverage['by_page_kind'] !== $pageKindCounts
            || ! hash_equals($manifest['set_hashes']['locale_page_identity_set_sha256'], self::hashValue($identitySet))
            || ! hash_equals($manifest['set_hashes']['route_set_sha256'], self::hashValue($routeSet))
            || ! hash_equals($manifest['set_hashes']['source_semantic_aggregate_sha256'], self::hashValue($semanticHashes))
            || ! hash_equals($manifest['set_hashes']['compatibility_projection_aggregate_sha256'], self::hashValue($compatibilityHashes))) {
            self::fail('PERSONALITY_CURRENT_COVERAGE_INVALID');
        }
        $aggregate = $manifest;
        unset($aggregate['aggregate_sha256']);
        if (! hash_equals($manifest['aggregate_sha256'], self::hashValue($aggregate))) {
            self::fail('PERSONALITY_CURRENT_AGGREGATE_MISMATCH');
        }

        return ['root' => $resolvedRoot, 'manifest' => $manifest, 'entries' => $entries];
    }

    /** @param array{root:string,entries:array<string,array<string,mixed>>} $index @return array<string,mixed> */
    public function pageFromIndex(array $index, string $framework, string $pageKind, string $entityKey, string $locale): array
    {
        $identityKey = strtolower(trim($framework)).'|'.strtolower(trim($pageKind)).'|'.strtolower(trim($entityKey)).'|'.$this->locale($locale);
        $entry = $index['entries'][$identityKey] ?? null;
        if (! is_array($entry)) {
            self::fail('PERSONALITY_CURRENT_PAGE_MISSING');
        }
        $bytes = file_get_contents($index['root'].'/'.$entry['path']);
        if (! is_string($bytes) || strlen($bytes) !== $entry['bytes'] || ! hash_equals($entry['sha256'], hash('sha256', $bytes))) {
            self::fail('PERSONALITY_CURRENT_FILE_HASH_MISMATCH');
        }
        $page = $this->decodeObject($bytes, 'PERSONALITY_CURRENT_PAGE_INVALID');
        PersonalityPageContentContract::assert($page);
        $this->assertPageBinding($page, $entry);

        return $page;
    }

    public static function hashValue(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function encodePrettyCanonical(mixed $value): string
    {
        return json_encode(self::canonicalize($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifestEnvelope(array $manifest): void
    {
        $this->exactKeys($manifest, [
            'aggregate_sha256', 'authority_path', 'compiler_version', 'contract_version', 'coverage',
            'files', 'locales', 'schema_version', 'set_hashes',
        ]);
        if (($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($manifest['schema_version'] ?? null) !== PersonalityPageContentContract::CONTRACT_VERSION
            || ($manifest['compiler_version'] ?? null) !== self::COMPILER_VERSION
            || ($manifest['authority_path'] ?? null) !== self::AUTHORITY_PATH
            || ($manifest['locales'] ?? null) !== PersonalityPageContentContract::LOCALES
            || ! $this->isHash($manifest['aggregate_sha256'] ?? null)
            || ! is_array($manifest['files'] ?? null) || ! array_is_list($manifest['files'])
            || count($manifest['files']) !== self::EXPECTED_FILES
            || ! is_array($manifest['coverage'] ?? null) || array_is_list($manifest['coverage'])
            || ! is_array($manifest['set_hashes'] ?? null) || array_is_list($manifest['set_hashes'])) {
            self::fail('PERSONALITY_CURRENT_MANIFEST_INVALID');
        }
        $this->exactKeys($manifest['coverage'], [
            'files', 'locale_pages', 'locales', 'pages_per_locale', 'baseline_locale_pages',
            'enhanced_locale_pages', 'by_framework', 'by_page_kind',
        ]);
        $this->exactKeys($manifest['set_hashes'], [
            'compatibility_projection_aggregate_sha256', 'locale_page_identity_set_sha256',
            'route_set_sha256', 'source_semantic_aggregate_sha256',
        ]);
        foreach ($manifest['set_hashes'] as $hash) {
            if (! $this->isHash($hash)) {
                self::fail('PERSONALITY_CURRENT_MANIFEST_INVALID');
            }
        }
        if (($manifest['coverage']['files'] ?? null) !== self::EXPECTED_FILES
            || ($manifest['coverage']['locale_pages'] ?? null) !== self::EXPECTED_FILES
            || ($manifest['coverage']['locales'] ?? null) !== 2
            || ($manifest['coverage']['pages_per_locale'] ?? null) !== self::EXPECTED_PAGES_PER_LOCALE) {
            self::fail('PERSONALITY_CURRENT_COVERAGE_INVALID');
        }
    }

    /** @param array<string,mixed> $entry */
    private function assertFileEntry(array $entry): void
    {
        $this->exactKeys($entry, [
            'bytes', 'canonical_path', 'compatibility_projection_sha256', 'content_state', 'entity_key',
            'entity_type', 'framework', 'identity_key', 'locale', 'page_kind', 'path', 'sha256', 'slug',
            'source_content_sha256',
        ]);
        foreach (['canonical_path', 'entity_key', 'entity_type', 'framework', 'identity_key', 'page_kind', 'path', 'slug'] as $field) {
            if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                self::fail('PERSONALITY_CURRENT_FILE_ENTRY_INVALID');
            }
        }
        if (! is_int($entry['bytes'] ?? null) || $entry['bytes'] < 1
            || ! in_array($entry['locale'] ?? null, PersonalityPageContentContract::LOCALES, true)
            || ! in_array($entry['content_state'] ?? null, ['baseline', 'enhanced'], true)
            || ! $this->isHash($entry['sha256'] ?? null)
            || ! $this->isHash($entry['source_content_sha256'] ?? null)
            || ! $this->isHash($entry['compatibility_projection_sha256'] ?? null)
            || preg_match('#\Apages/(?:mbti|big-five|enneagram)/[a-z0-9-]+/[a-z0-9-]+/(?:en|zh-CN)\.json\z#', $entry['path']) !== 1) {
            self::fail('PERSONALITY_CURRENT_FILE_ENTRY_INVALID');
        }
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $entry */
    private function assertPageBinding(array $page, array $entry): void
    {
        $identity = $page['identity'];
        $expectedIdentity = $entry['framework'].'|'.$entry['page_kind'].'|'.$entry['entity_key'].'|'.$entry['locale'];
        if ($page['locale'] !== $entry['locale']
            || $identity['framework'] !== $entry['framework']
            || $identity['page_kind'] !== $entry['page_kind']
            || $identity['entity_type'] !== $entry['entity_type']
            || $identity['entity_key'] !== $entry['entity_key']
            || $identity['slug'] !== $entry['slug']
            || $identity['canonical_path'] !== $entry['canonical_path']
            || $entry['identity_key'] !== $expectedIdentity
            || $page['content_state'] !== $entry['content_state']
            || ! hash_equals($page['source_content_sha256'], $entry['source_content_sha256'])
            || ! hash_equals(self::hashValue($page['payload']), $entry['compatibility_projection_sha256'])) {
            self::fail('PERSONALITY_CURRENT_PAGE_BINDING_INVALID');
        }
    }

    /** @param array<string,true> $declared */
    private function assertInventory(string $root, array $declared): void
    {
        $actual = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                self::fail('PERSONALITY_CURRENT_INVENTORY_INVALID');
            }
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            $actual[$relative] = true;
        }
        ksort($actual, SORT_STRING);
        ksort($declared, SORT_STRING);
        if (array_keys($actual) !== array_keys($declared)) {
            self::fail('PERSONALITY_CURRENT_UNDECLARED_FILE');
        }
    }

    /** @return array<string,mixed> */
    private function readObject(string $path, string $code): array
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            self::fail($code);
        }

        return $this->decodeObject($bytes, $code);
    }

    /** @return array<string,mixed> */
    private function decodeObject(string $bytes, string $code): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::fail($code);
        }
        if (! is_array($value) || array_is_list($value)) {
            self::fail($code);
        }

        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::fail('PERSONALITY_CURRENT_KEYS_INVALID');
        }
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private function locale(string $locale): string
    {
        $normalized = trim($locale) === 'zh' ? 'zh-CN' : trim($locale);
        if (! in_array($normalized, PersonalityPageContentContract::LOCALES, true)) {
            self::fail('PERSONALITY_CURRENT_LOCALE_INVALID');
        }

        return $normalized;
    }

    private static function fail(string $code): never
    {
        throw new PersonalityCurrentAuthorityFailure($code);
    }
}
