<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Models\CareerJobDisplayAsset;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class CareerCurrentAuthorityParity
{
    public const PACKAGE_SCAN_CONTRACT_VERSION = 'career.current_authority_package_scan.v1';

    public const CONTRACT_VERSION = 'career.current_authority_parity.v2';

    public const MODE_PACKAGE = 'package';

    public const MODE_PRODUCTION_PREACTIVATION = 'production-preactivation';

    public const LOCKED_REDIS_MAXMEMORY_BYTES = 2_147_483_648;

    public const LOCKED_CAREER_BUDGET_BYTES = 1_717_986_918;

    private const SLICE_SLUGS = ['accountants-and-auditors', 'actors'];

    public function __construct(
        private readonly CareerCurrentAuthorityPackageLoader $loader,
        private readonly CareerCurrentAuthorityStateMachine $stateMachine,
        private readonly CareerJobDetailCanonicalCacheReader $reader,
        private readonly CareerCurrentAuthorityPackage $legacyProjection,
        private readonly CareerCurrentAuthorityCacheGateway $cache,
    ) {}

    /** @return array<string,mixed> */
    public function run(
        string $backendRoot,
        string $mode = self::MODE_PACKAGE,
        string $redisMode = 'none',
        string $releaseSha = '',
        string $activeSha = '',
    ): array {
        if (preg_match('/\A[0-9a-f]{40}\z/', $releaseSha) !== 1) {
            throw new RuntimeException('CAREER_PARITY_RELEASE_SHA_INVALID');
        }
        if (! in_array($mode, [self::MODE_PACKAGE, self::MODE_PRODUCTION_PREACTIVATION], true)) {
            throw new RuntimeException('CAREER_PARITY_MODE_INVALID');
        }
        if ($mode === self::MODE_PRODUCTION_PREACTIVATION
            && preg_match('/\A[0-9a-f]{40}\z/', $activeSha) !== 1) {
            throw new RuntimeException('CAREER_PARITY_ACTIVE_SHA_INVALID');
        }
        if (($mode === self::MODE_PRODUCTION_PREACTIVATION && $redisMode !== 'readonly')
            || ($mode === self::MODE_PACKAGE && ! in_array($redisMode, ['none', 'disposable'], true))) {
            throw new RuntimeException('CAREER_PARITY_MODE_REDIS_MISMATCH');
        }
        $databaseMutationCount = 0;
        $cacheMutationCount = 0;
        DB::listen(static function (QueryExecuted $query) use (&$databaseMutationCount): void {
            if (preg_match('/\A(?:insert|update|delete|replace|alter|create|drop|truncate|rename)\b/i', ltrim($query->sql)) === 1) {
                $databaseMutationCount++;
            }
        });
        if ($mode === self::MODE_PRODUCTION_PREACTIVATION) {
            foreach ([KeyWritten::class, KeyForgotten::class, CacheFlushed::class] as $event) {
                Event::listen($event, static function () use (&$cacheMutationCount): void {
                    $cacheMutationCount++;
                });
            }
        }

        $authority = $this->loader->loadForPublish($backendRoot);
        $slugs = $authority['slugs'];
        $this->assertAuthorityShape($authority);
        $rows = null;
        if ($mode === self::MODE_PRODUCTION_PREACTIVATION) {
            $this->assertDatabaseCompatibility($slugs);
            $rows = $this->compatibilityRows($authority);
        }

        $redis = $this->redisContract($redisMode);
        $slice = is_array($rows)
            ? $this->scan(array_intersect_key($rows, array_flip(self::SLICE_SLUGS)), false, 'none')
            : $this->scanPages(array_intersect_key($authority['pages'], array_flip(self::SLICE_SLUGS)), false, 'none');
        if (($slice['content_states']['enhanced'] ?? null) !== 2
            || ($slice['content_states']['legacy'] ?? null) !== 2
            || ($slice['counts']['locale_pages'] ?? null) !== 4) {
            throw new RuntimeException('CAREER_PARITY_ARCHITECTURE_SLICE_FAILED');
        }

        $full = is_array($rows)
            ? $this->scan($rows, true, $redisMode)
            : $this->scanPages($authority['pages'], true, $redisMode);
        if ($databaseMutationCount !== 0) {
            throw new RuntimeException('CAREER_PARITY_DATABASE_WRITE_DETECTED');
        }
        if ($cacheMutationCount !== 0) {
            throw new RuntimeException('CAREER_PARITY_CACHE_WRITE_DETECTED');
        }

        $zeroWrites = [
            'database_write_count' => 0,
            'cache_write_count' => 0,
            'discoverability_write_count' => 0,
            'search_write_count' => 0,
        ];
        $receipt = [
            'contract_version' => $mode === self::MODE_PACKAGE
                ? self::PACKAGE_SCAN_CONTRACT_VERSION
                : self::CONTRACT_VERSION,
            'status' => 'pass',
            'safe_error_code' => null,
            'mode' => $mode,
            'release_sha' => $releaseSha,
            'package' => [
                'digest' => $authority['summary']['aggregate_sha256'],
                'projection_digest' => $authority['summary']['versionless_projection_sha256'],
                'compiler_version' => CareerJobDetailCanonicalCacheReader::COMPILER_VERSION,
                'compiler_digest' => CareerJobDetailCanonicalCacheReader::compilerDigest(),
                'codec_version' => CareerJobDetailCanonicalCacheReader::CODEC_VERSION,
                'codec_digest' => CareerJobDetailCanonicalCacheReader::codecDigest(),
                'state_machine_version' => CareerCurrentAuthorityStateMachine::VERSION,
            ],
            'architecture_slice' => $slice,
            'full_scan' => $full,
            'redis' => $redis + $full['redis'],
            'write_counts' => $zeroWrites,
        ];
        if (is_array($rows)) {
            $receipt['active_sha'] = $activeSha;
            $receipt['database'] = [
                'compatibility_row_count' => count($rows),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue(array_keys($rows)),
                'row_set_sha256' => CareerCurrentAuthorityPackage::hashValue(array_values($rows)),
            ];
        }
        $receipt['receipt_digest'] = CareerCurrentAuthorityPackage::hashValue($receipt);

        return $receipt;
    }

    /** @param array<string,array<string,mixed>> $rows @return array<string,mixed> */
    private function scan(array $rows, bool $includeCapacity, string $redisMode): array
    {
        ksort($rows, SORT_STRING);
        $hashes = array_fill_keys(['candidate', 'active', 'lkg', 'legacy', 'api', 'snapshot'], []);
        $counts = [
            'slugs' => count($rows),
            'locales' => count(CareerCurrentAuthorityPackage::LOCALES),
            'locale_pages' => 0,
            'candidate' => 0,
            'active' => 0,
            'lkg' => 0,
            'legacy' => 0,
            'api' => 0,
            'snapshot' => 0,
        ];
        $states = ['enhanced' => 0, 'legacy' => 0];
        $bytes = [
            'serialized_total' => 0,
            'max_single_key' => 0,
            'gzip_before_total' => 0,
            'gzip_after_total' => 0,
            'candidate_total' => 0,
            'active_total' => 0,
            'lkg_total' => 0,
            'worst_state_amplification' => 0,
        ];
        $redisMemory = [
            'memory_usage_total' => 0,
            'memory_usage_max_key' => 0,
            'disposable_probe_write_count' => 0,
        ];

        $publication = [];
        if ($includeCapacity && $redisMode === 'readonly') {
            foreach (array_chunk(array_keys($rows), 50) as $chunk) {
                $publication += $this->cache->publicationSnapshot($chunk, CareerCurrentAuthorityPackage::LOCALES);
            }
        }

        foreach ($rows as $slug => $row) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $candidate = $this->stateMachine->assembleCandidate($row, $locale);
                $this->stateMachine->assertPreparedTransition([
                    'status' => 'ready',
                    'classification' => 'ready_staged',
                    'version' => 'candidate',
                ]);
                $this->stateMachine->assertActivationTransition([
                    'status' => 'pass',
                    'entries' => [['version' => 'candidate']],
                    'failures' => [],
                ], 1);
                $stored = $candidate['stored'];
                $payload = $candidate['payload'];
                $legacyStored = $this->reader->withoutDerivedContentV3($payload, $slug, $locale);
                $legacy = $this->reader->read($legacyStored, $slug, $locale);
                $active = $this->reader->read($stored, $slug, $locale);
                $lkg = $this->reader->read($stored, $slug, $locale);
                $api = $this->reader->read($stored, $slug, $locale);
                $snapshot = $this->reader->read($stored, $slug, $locale);
                if ($includeCapacity && $redisMode === 'readonly') {
                    $snapshotEntry = $publication[$slug][$locale] ?? null;
                    $published = is_array($snapshotEntry) && ($snapshotEntry['published'] ?? null) === true;
                    if (! is_array($snapshotEntry)
                        || ($snapshotEntry['classification'] ?? null) !== 'ready_active'
                        || ! is_array($snapshotEntry['payload'] ?? null)) {
                        throw new RuntimeException('CAREER_PARITY_SNAPSHOT_READBACK_FAILED');
                    }
                    $snapshot = $snapshotEntry['payload'];
                    $active = $snapshot;
                    $apiRead = $this->cache->verifyOnlyRead($slug, $locale);
                    if ($published
                        ? (($apiRead['state'] ?? null) !== 'fresh' || ! is_array($apiRead['payload'] ?? null))
                        : (($apiRead['state'] ?? null) !== 'not_found' || ($apiRead['payload'] ?? null) !== null)) {
                        throw new RuntimeException('CAREER_PARITY_API_READBACK_FAILED');
                    }
                    $api = $published ? $apiRead['payload'] : null;
                    $lkgVersion = Cache::get($this->pointerKey($slug, $locale, 'lkg'));
                    if (! is_string($lkgVersion) || trim($lkgVersion) === '') {
                        throw new RuntimeException('CAREER_PARITY_REDIS_STATE_INCOMPLETE');
                    }
                    $lkg = $this->reader->read(
                        Cache::get($this->payloadKey($slug, $locale, $lkgVersion)),
                        $slug,
                        $locale,
                    );
                }
                foreach ([$legacy, $active, $lkg, $snapshot] as $statePayload) {
                    $this->stateMachine->assertPayload($statePayload, $row, $locale);
                }
                if ($api !== null) {
                    $this->stateMachine->assertPayload($api, $row, $locale);
                }

                $contentState = data_get($payload, 'display_surface_v1.content_v3.content_state');
                if (! isset($states[$contentState])) {
                    throw new RuntimeException('CAREER_PARITY_CONTENT_STATE_INVALID');
                }
                $states[$contentState]++;
                $counts['locale_pages']++;
                foreach (array_keys($hashes) as $state) {
                    $statePayload = match ($state) {
                        'candidate' => $payload,
                        'active' => $active,
                        'lkg' => $lkg,
                        'api' => $api ?? ['state' => 'not_found', 'payload' => null],
                        'snapshot' => $snapshot,
                        'legacy' => $legacy,
                    };
                    $hashes[$state][] = CareerCurrentAuthorityPackage::hashValue($statePayload);
                    $counts[$state]++;
                }

                $compactJson = json_encode($legacyStored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $serializedBytes = strlen(serialize($stored));
                $gzipBytes = strlen(base64_decode((string) $stored['payload'], true) ?: '');
                $bytes['serialized_total'] += $serializedBytes;
                $bytes['max_single_key'] = max($bytes['max_single_key'], $serializedBytes);
                $bytes['gzip_before_total'] += strlen($compactJson);
                $bytes['gzip_after_total'] += $gzipBytes;
                foreach (['candidate_total', 'active_total', 'lkg_total'] as $stateTotal) {
                    $bytes[$stateTotal] += $serializedBytes;
                }

                if ($includeCapacity && $redisMode === 'disposable') {
                    foreach (['candidate', 'active', 'lkg'] as $version) {
                        $key = $this->payloadKey($slug, $locale, $version);
                        Cache::forever($key, $stored);
                        $redisMemory['disposable_probe_write_count']++;
                        $usage = $this->memoryUsage($key);
                        $redisMemory['memory_usage_total'] += $usage;
                        $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                    }
                } elseif ($includeCapacity && $redisMode === 'readonly') {
                    foreach (['active', 'lkg'] as $state) {
                        $version = Cache::get($this->pointerKey($slug, $locale, $state));
                        if (! is_string($version) || trim($version) === '') {
                            throw new RuntimeException('CAREER_PARITY_REDIS_STATE_INCOMPLETE');
                        }
                        $key = $this->payloadKey($slug, $locale, $version);
                        $usage = $this->memoryUsage($key);
                        if ($usage <= 0) {
                            throw new RuntimeException('CAREER_PARITY_REDIS_MEMORY_USAGE_MISSING');
                        }
                        $redisMemory['memory_usage_total'] += $usage;
                        $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                    }
                }

                if ($includeCapacity && $redisMode === 'readonly') {
                    $canonicalHash = CareerCurrentAuthorityPackage::hashValue($payload);
                    $hydratedStates = [$active, $lkg, $snapshot];
                    if ($api !== null) {
                        $hydratedStates[] = $api;
                    }
                    foreach ($hydratedStates as $statePayload) {
                        if (! hash_equals($canonicalHash, CareerCurrentAuthorityPackage::hashValue($statePayload))) {
                            throw new RuntimeException('CAREER_PARITY_HYDRATION_MISMATCH');
                        }
                    }
                }
            }
        }
        foreach ($hashes as &$stateHashes) {
            sort($stateHashes, SORT_STRING);
            $stateHashes = CareerCurrentAuthorityPackage::hashValue($stateHashes);
        }
        unset($stateHashes);
        $bytes['worst_state_amplification'] = $bytes['candidate_total'] + $bytes['active_total'] + $bytes['lkg_total'];
        $budget = (int) config('career_current_authority_parity.career_budget_bytes', self::LOCKED_CAREER_BUDGET_BYTES);
        if ($budget !== self::LOCKED_CAREER_BUDGET_BYTES) {
            throw new RuntimeException('CAREER_PARITY_REDIS_CAPACITY_MISMATCH');
        }
        if ($includeCapacity) {
            self::assertCapacityWithinBudget(
                $bytes['worst_state_amplification'],
                $redisMemory['memory_usage_total'],
                $budget,
            );
        }

        return [
            'status' => 'pass',
            'counts' => $counts,
            'content_states' => $states,
            'aggregate_hashes' => $hashes,
            'bytes' => $bytes,
            'redis' => $redisMemory,
        ];
    }

    /** @param array<string,array<string,array<string,mixed>>> $pages @return array<string,mixed> */
    private function scanPages(array $pages, bool $includeCapacity, string $redisMode): array
    {
        ksort($pages, SORT_STRING);
        $hashes = array_fill_keys(['content_v3', 'codec_roundtrip'], []);
        $counts = [
            'slugs' => count($pages), 'locales' => count(CareerCurrentAuthorityPackage::LOCALES),
            'locale_pages' => 0, 'encoded' => 0, 'decoded' => 0,
        ];
        $states = ['enhanced' => 0, 'legacy' => 0];
        $bytes = [
            'serialized_total' => 0, 'max_single_key' => 0, 'gzip_before_total' => 0,
            'gzip_after_total' => 0, 'candidate_total' => 0, 'active_total' => 0,
            'lkg_total' => 0, 'worst_state_amplification' => 0,
        ];
        $redisMemory = [
            'memory_usage_total' => 0,
            'memory_usage_max_key' => 0,
            'disposable_probe_write_count' => 0,
        ];
        foreach ($pages as $slug => $localized) {
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $content = $localized[$locale] ?? null;
                if (! is_array($content)) {
                    throw new RuntimeException('CAREER_PARITY_AUTHORITY_INCOMPLETE');
                }
                CareerContentV3Contract::assert($content);
                $payload = ['display_surface_v1' => ['content_v3' => $content]];
                $stored = $this->reader->encode($payload);
                if ($this->reader->decode($stored) !== $payload) {
                    throw new RuntimeException('CAREER_PARITY_CODEC_MISMATCH');
                }
                $state = $content['content_state'];
                if (! isset($states[$state])) {
                    throw new RuntimeException('CAREER_PARITY_CONTENT_STATE_INVALID');
                }
                $states[$state]++;
                $counts['locale_pages']++;
                $hashes['content_v3'][] = CareerCurrentAuthorityPackage::hashValue($content);
                $hashes['codec_roundtrip'][] = CareerCurrentAuthorityPackage::hashValue($payload);
                $counts['encoded']++;
                $counts['decoded']++;
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $serialized = strlen(serialize($stored));
                $gzip = strlen(base64_decode((string) $stored['payload'], true) ?: '');
                $bytes['serialized_total'] += $serialized;
                $bytes['max_single_key'] = max($bytes['max_single_key'], $serialized);
                $bytes['gzip_before_total'] += strlen($json);
                $bytes['gzip_after_total'] += $gzip;
                foreach (['candidate_total', 'active_total', 'lkg_total'] as $name) {
                    $bytes[$name] += $serialized;
                }
                if ($includeCapacity && $redisMode === 'disposable') {
                    foreach (['candidate', 'active', 'lkg'] as $version) {
                        $key = $this->payloadKey($slug, $locale, $version);
                        Cache::forever($key, $stored);
                        $redisMemory['disposable_probe_write_count']++;
                        $usage = $this->memoryUsage($key);
                        $redisMemory['memory_usage_total'] += $usage;
                        $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                    }
                } elseif ($includeCapacity && $redisMode === 'readonly') {
                    foreach (['active', 'lkg'] as $pointer) {
                        $version = Cache::get($this->pointerKey($slug, $locale, $pointer));
                        if (! is_string($version) || $version === '') {
                            throw new RuntimeException('CAREER_PARITY_REDIS_STATE_INCOMPLETE');
                        }
                        $usage = $this->memoryUsage($this->payloadKey($slug, $locale, $version));
                        if ($usage <= 0) {
                            throw new RuntimeException('CAREER_PARITY_REDIS_MEMORY_USAGE_MISSING');
                        }
                        $redisMemory['memory_usage_total'] += $usage;
                        $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                    }
                }
            }
        }
        foreach ($hashes as &$values) {
            sort($values, SORT_STRING);
            $values = CareerCurrentAuthorityPackage::hashValue($values);
        }
        unset($values);
        $bytes['worst_state_amplification'] = $bytes['candidate_total'] + $bytes['active_total'] + $bytes['lkg_total'];
        $budget = (int) config('career_current_authority_parity.career_budget_bytes', self::LOCKED_CAREER_BUDGET_BYTES);
        if ($includeCapacity) {
            self::assertCapacityWithinBudget($bytes['worst_state_amplification'], $redisMemory['memory_usage_total'], $budget);
        }

        return [
            'status' => 'pass', 'counts' => $counts, 'content_states' => $states,
            'aggregate_hashes' => $hashes, 'bytes' => $bytes, 'redis' => $redisMemory,
        ];
    }

    public static function assertCapacityWithinBudget(
        int $worstStateBytes,
        int $redisMemoryUsageBytes,
        int $budgetBytes,
    ): void {
        if ($budgetBytes <= 0 || $worstStateBytes > $budgetBytes || $redisMemoryUsageBytes > $budgetBytes) {
            throw new RuntimeException('CAREER_PARITY_REDIS_BUDGET_EXCEEDED');
        }
    }

    /** @param array<string,mixed> $authority */
    private function assertAuthorityShape(array $authority): void
    {
        if (($authority['summary']['career_count'] ?? null) !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || ($authority['summary']['locale_page_count'] ?? null) !== CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES
            || count((array) ($authority['pages'] ?? [])) !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || array_diff(self::SLICE_SLUGS, (array) ($authority['slugs'] ?? [])) !== []) {
            throw new RuntimeException('CAREER_PARITY_AUTHORITY_INCOMPLETE');
        }
    }

    /** @param list<string> $expectedSlugs */
    private function assertDatabaseCompatibility(array $expectedSlugs): void
    {
        $rows = CareerJobDisplayAsset::query()
            ->select(['canonical_slug', 'asset_type', 'asset_role', 'status'])
            ->orderBy('canonical_slug')
            ->get();
        $actualSlugs = $rows->map(static fn (CareerJobDisplayAsset $row): string => strtolower(trim((string) $row->canonical_slug)))->all();
        sort($expectedSlugs, SORT_STRING);
        if ($rows->count() !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || $actualSlugs !== $expectedSlugs
            || $rows->contains(static fn (CareerJobDisplayAsset $row): bool => $row->asset_type !== CareerCurrentAuthorityPackage::ASSET_TYPE
                || $row->asset_role !== CareerCurrentAuthorityPackage::ASSET_ROLE
                || $row->status !== CareerCurrentAuthorityPackage::READY_STATUS)) {
            throw new RuntimeException('CAREER_PARITY_COMPATIBILITY_ROWS_INCOMPLETE');
        }
    }

    /** @param array<string,mixed> $authority @return array<string,array<string,mixed>> */
    private function compatibilityRows(array $authority): array
    {
        $entries = [];
        foreach ((array) data_get($authority, 'manifest.files', []) as $entry) {
            if (is_array($entry)) {
                $entries[$entry['canonical_slug']][$entry['locale']] = $entry;
            }
        }
        $rows = [];
        foreach (CareerJobDisplayAsset::query()->runtimeColumns()->orderBy('canonical_slug')->get() as $asset) {
            $slug = strtolower(trim((string) $asset->canonical_slug));
            $row = ['canonical_slug' => $slug];
            foreach ([
                'surface_version', 'asset_type', 'asset_role', 'status', 'component_order_json',
                'page_payload_json', 'seo_payload_json', 'sources_json', 'structured_data_json',
                'implementation_contract_json', 'metadata_json',
            ] as $field) {
                $row[$field] = $asset->getAttribute($field);
            }
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $entry = $entries[$slug][$locale] ?? null;
                $surface = $this->legacyProjection->publicProjection($row, $locale);
                unset($surface['content_v3']);
                if (! is_array($entry)
                    || ! hash_equals((string) $entry['legacy_row_sha256'], CareerCurrentAuthorityPackage::hashValue($row))
                    || ! hash_equals((string) $entry['legacy_projection_sha256'], CareerCurrentAuthorityPackage::hashValue($surface))) {
                    throw new RuntimeException('CAREER_PARITY_COMPATIBILITY_MISMATCH');
                }
            }
            $rows[$slug] = $row;
        }
        ksort($rows, SORT_STRING);

        return $rows;
    }

    /** @return array<string,mixed> */
    private function redisContract(string $mode): array
    {
        if (! in_array($mode, ['none', 'disposable', 'readonly'], true)) {
            throw new RuntimeException('CAREER_PARITY_REDIS_MODE_INVALID');
        }
        if ($mode === 'none') {
            return ['mode' => 'none'];
        }
        $config = Redis::connection('cache')->command('config', ['get', 'maxmemory']);
        $maxmemory = (int) (($config['maxmemory'] ?? null) ?? (is_array($config) ? end($config) : 0));
        $policy = Redis::connection('cache')->command('config', ['get', 'maxmemory-policy']);
        $policyValue = (string) (($policy['maxmemory-policy'] ?? null) ?? (is_array($policy) ? end($policy) : ''));
        if ($maxmemory !== (int) config('career_current_authority_parity.redis_maxmemory_baseline_bytes', self::LOCKED_REDIS_MAXMEMORY_BYTES)
            || $policyValue !== (string) config('career_current_authority_parity.redis_policy', 'noeviction')) {
            throw new RuntimeException('CAREER_PARITY_REDIS_CAPACITY_MISMATCH');
        }

        return [
            'mode' => $mode,
            'maxmemory_bytes' => $maxmemory,
            'budget_percent' => (int) config('career_current_authority_parity.career_budget_percent', 80),
            'budget_bytes' => (int) config('career_current_authority_parity.career_budget_bytes', self::LOCKED_CAREER_BUDGET_BYTES),
            'policy' => $policyValue,
        ];
    }

    private function memoryUsage(string $cacheKey): int
    {
        $key = (string) config('database.redis.options.prefix').(string) config('cache.prefix').$cacheKey;
        $client = Redis::connection('cache')->client();
        $usage = $client->rawCommand('MEMORY', 'USAGE', $key);

        return is_numeric($usage) ? (int) $usage : 0;
    }

    private function pointerKey(string $slug, string $locale, string $state): string
    {
        return sprintf('%s:%s:%s:%s', config('career_current_authority_parity.cache_key_prefix'), $slug, $locale, $state);
    }

    private function payloadKey(string $slug, string $locale, string $version): string
    {
        return sprintf('%s:%s:%s:versions:%s', config('career_current_authority_parity.cache_key_prefix'), $slug, $locale, $version);
    }
}
