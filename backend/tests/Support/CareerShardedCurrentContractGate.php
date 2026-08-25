<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonException;
use RuntimeException;

final class CareerShardedCurrentContractGate
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

    public const SHARDS_PER_MODULE = 64;

    public const EXPECTED_SHARD_FILES = 640;

    public const EXPECTED_SLUGS = 1046;

    public const EXPECTED_LOCALE_PAGES = 2092;

    public const EXPECTED_MODULE_ROWS = 20920;

    /** @return array<string,mixed> */
    public static function decodeJsonFile(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('CONTRACT_JSON_INVALID');
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('CONTRACT_JSON_INVALID');
        }

        return $decoded;
    }

    public static function shardIndex(string $canonicalSlug): int
    {
        $prefix = substr(hash('sha256', $canonicalSlug), 0, 8);
        $bytes = hex2bin($prefix);
        if ($bytes === false) {
            throw new RuntimeException('SHARD_DIGEST_INVALID');
        }
        $value = unpack('Nvalue', $bytes);

        return ((int) ($value['value'] ?? 0)) % self::SHARDS_PER_MODULE;
    }

    /** @param array<string,mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string,mixed> $manifest */
    public static function aggregateHash(array $manifest): string
    {
        $projection = [];
        foreach (['contract_version', 'modules', 'shards', 'registries', 'coverage', 'module_completeness'] as $key) {
            $projection[$key] = $manifest[$key] ?? null;
        }

        return hash('sha256', self::canonicalJson($projection));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,string>  $files  keyed by package-relative path
     */
    public static function assertCandidate(array $manifest, array $files): void
    {
        if (($manifest['contract_version'] ?? null) !== 'career.sharded_current.manifest.v1'
            || ($manifest['authority_path'] ?? null) !== 'backend/content_assets/career/current') {
            throw new RuntimeException('MANIFEST_CONTRACT_INVALID');
        }
        if (($manifest['modules'] ?? null) !== self::MODULES) {
            throw new RuntimeException('UNKNOWN_OR_REORDERED_MODULE');
        }
        $shards = $manifest['shards'] ?? null;
        if (! is_array($shards) || count($shards) !== self::EXPECTED_SHARD_FILES) {
            throw new RuntimeException('SHARD_INVENTORY_INCOMPLETE');
        }

        $expectedPaths = [];
        foreach (self::MODULES as $module) {
            for ($index = 0; $index < self::SHARDS_PER_MODULE; $index++) {
                $expectedPaths[] = sprintf('%s/shard-%02d.jsonl', $module, $index);
            }
        }
        $declaredPaths = array_map(static fn (mixed $row): mixed => is_array($row) ? ($row['path'] ?? null) : null, $shards);
        if ($declaredPaths !== $expectedPaths) {
            throw new RuntimeException('SHARD_INVENTORY_INVALID');
        }
        $registryPaths = [];
        foreach (($manifest['registries'] ?? []) as $registry) {
            if (! is_array($registry) || ! is_string($registry['path'] ?? null)) {
                throw new RuntimeException('REGISTRY_INVENTORY_INVALID');
            }
            $registryPaths[] = $registry['path'];
        }
        $filePaths = array_keys($files);
        sort($filePaths, SORT_STRING);
        $allowedPaths = array_merge($expectedPaths, $registryPaths);
        sort($allowedPaths, SORT_STRING);
        if ($filePaths !== $allowedPaths) {
            throw new RuntimeException('UNKNOWN_OR_MISSING_FILE');
        }

        $lineIdentities = [];
        $coverage = [];
        $moduleCounts = array_fill_keys(self::MODULES, 0);
        foreach ($shards as $position => $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('SHARD_DECLARATION_INVALID');
            }
            $module = self::MODULES[intdiv($position, self::SHARDS_PER_MODULE)];
            $index = $position % self::SHARDS_PER_MODULE;
            $path = $expectedPaths[$position];
            if (($entry['module'] ?? null) !== $module
                || ($entry['shard_index'] ?? null) !== $index
                || ($entry['path'] ?? null) !== $path) {
                throw new RuntimeException('SHARD_DECLARATION_INVALID');
            }
            $raw = $files[$path];
            if ($raw === '' || ! str_ends_with($raw, "\n")) {
                throw new RuntimeException('EMPTY_OR_UNTERMINATED_SHARD');
            }
            if (($entry['sha256'] ?? null) !== hash('sha256', $raw)) {
                throw new RuntimeException('SHARD_HASH_MISMATCH');
            }
            $lines = explode("\n", substr($raw, 0, -1));
            if (($entry['row_count'] ?? null) !== count($lines)) {
                throw new RuntimeException('SHARD_ROW_COUNT_MISMATCH');
            }
            $previous = null;
            foreach ($lines as $line) {
                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('SHARD_ROW_INVALID');
                }
                if (! is_array($row) || array_is_list($row) || self::canonicalJson($row) !== $line) {
                    throw new RuntimeException('SHARD_ROW_NOT_CANONICAL');
                }
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== ['canonical_slug', 'claim_bindings', 'content', 'locale', 'module', 'source_bindings']) {
                    throw new RuntimeException('SHARD_ROW_FIELD_SET_INVALID');
                }
                $slug = $row['canonical_slug'] ?? null;
                $locale = $row['locale'] ?? null;
                if (! is_string($slug)
                    || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                    || ! in_array($locale, ['en', 'zh-CN'], true)
                    || ($row['module'] ?? null) !== $module
                    || ! is_array($row['content'] ?? null)
                    || ! is_array($row['source_bindings'] ?? null)
                    || ! is_array($row['claim_bindings'] ?? null)) {
                    throw new RuntimeException('SHARD_ROW_IDENTITY_INVALID');
                }
                if (self::shardIndex($slug) !== $index) {
                    throw new RuntimeException('SHARD_ROW_MISPLACED');
                }
                $sortKey = $slug."\0".$locale;
                if ($previous !== null && strcmp($previous, $sortKey) >= 0) {
                    throw new RuntimeException('SHARD_ROW_ORDER_OR_DUPLICATE_INVALID');
                }
                $previous = $sortKey;
                $identity = $slug."\0".$locale."\0".$module;
                if (isset($lineIdentities[$identity])) {
                    throw new RuntimeException('SHARD_ROW_IDENTITY_DUPLICATE');
                }
                $lineIdentities[$identity] = true;
                $coverage[$slug][$locale][$module] = true;
                $moduleCounts[$module]++;
            }
        }

        if (count($coverage) !== self::EXPECTED_SLUGS
            || count($lineIdentities) !== self::EXPECTED_MODULE_ROWS
            || $moduleCounts !== array_fill_keys(self::MODULES, self::EXPECTED_LOCALE_PAGES)) {
            throw new RuntimeException('COVERAGE_COUNT_INVALID');
        }
        foreach ($coverage as $localeRows) {
            if (array_keys($localeRows) !== ['en', 'zh-CN']) {
                throw new RuntimeException('LOCALE_PAIR_INVALID');
            }
            foreach ($localeRows as $moduleRows) {
                if (array_keys($moduleRows) !== self::MODULES) {
                    throw new RuntimeException('MODULE_COMPLETENESS_INVALID');
                }
            }
        }
        if (($manifest['coverage'] ?? null) !== [
            'slugs' => self::EXPECTED_SLUGS,
            'locales' => ['en', 'zh-CN'],
            'locale_pages' => self::EXPECTED_LOCALE_PAGES,
            'module_rows' => self::EXPECTED_MODULE_ROWS,
        ] || ($manifest['module_completeness'] ?? null) !== [
            'rows_per_module' => self::EXPECTED_LOCALE_PAGES,
            'modules_per_slug_locale' => count(self::MODULES),
        ]) {
            throw new RuntimeException('MANIFEST_COVERAGE_INVALID');
        }
        if (($manifest['aggregate_sha256'] ?? null) !== self::aggregateHash($manifest)) {
            throw new RuntimeException('AGGREGATE_HASH_MISMATCH');
        }
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
