<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Support\PublicProjectionCache as Cache;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class CareerCurrentAuthorityParity
{
    public const PACKAGE_SCAN_CONTRACT_VERSION = 'career.current_authority_package_scan.v1';

    public const CONTRACT_VERSION = 'career.current_authority_parity.v2';

    public const MODE_PACKAGE = 'package';

    public const MODE_PRODUCTION_PREACTIVATION = 'production-preactivation';

    public const LOCKED_REDIS_MAXMEMORY_BYTES = 2_147_483_648;

    public const LOCKED_CAREER_BUDGET_BYTES = 1_717_986_918;

    private const PACKAGE_SLICE_SLUGS = ['accountants-and-auditors', 'actors'];

    private const PRODUCTION_VALIDATION_SLUGS = ['accountants-and-auditors'];

    public function __construct(
        private readonly CareerCurrentAuthorityPackageLoader $loader,
        private readonly CareerJobDetailCanonicalCacheReader $reader,
        private readonly CareerPageProjector $pages,
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

        $authority = $this->loader->indexForPublish($backendRoot);
        $slugs = $authority['slugs'];
        $this->assertAuthorityShape($authority);
        $redis = $this->redisContract($redisMode);
        $full = $this->scanPages($authority, $slugs, true, $redisMode);
        if ($redisMode === 'readonly') {
            self::assertCapacityWithinBudget($full['bytes']['worst_state_amplification'] + $redis['used_memory_bytes'], 0, self::LOCKED_CAREER_BUDGET_BYTES);
        }
        $slice = $this->scanPages($authority, self::PACKAGE_SLICE_SLUGS, false, 'none');
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
        if ($mode === self::MODE_PRODUCTION_PREACTIVATION) {
            $receipt['active_sha'] = $activeSha;
            $receipt['validation_scope'] = [
                'canonical_slugs' => $slugs,
                'slug_count' => count($slugs),
                'locales' => CareerCurrentAuthorityPackage::LOCALES,
                'locale_page_count' => count($slugs) * count(CareerCurrentAuthorityPackage::LOCALES),
            ];
        }
        $receipt['receipt_digest'] = CareerCurrentAuthorityPackage::hashValue($receipt);

        return $receipt;
    }

    /**
     * @param  array{root:string,entries:array<string,array<string,array<string,mixed>>>}  $authority
     * @param  list<string>  $slugs
     * @return array<string,mixed>
     */
    private function scanPages(array $authority, array $slugs, bool $includeCapacity, string $redisMode): array
    {
        $hashes = array_fill_keys(['content_v3', 'codec_roundtrip'], []);
        $counts = [
            'slugs' => count($slugs), 'locales' => count(CareerCurrentAuthorityPackage::LOCALES),
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
        foreach (array_chunk($slugs, 32) as $chunk) {
            foreach ($chunk as $slug) {
                foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                    $content = $this->loader->pageFromPublishIndex($authority, $slug, $locale);
                    $payload = $this->pages->project($content);
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
                    $serialized = strlen(serialize($payload));
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
                            Cache::forever($key, $payload);
                            $redisMemory['disposable_probe_write_count']++;
                            $usage = $this->memoryUsage($key);
                            $redisMemory['memory_usage_total'] += $usage;
                            $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                        }
                    } elseif ($includeCapacity && $redisMode === 'readonly') {
                        // A first file release has no derived entry yet; installed files are validated above.
                        $key = \App\Services\Career\CareerFilePageReader::cacheKey($payload);
                        $usage = $this->memoryUsage($key);
                        $redisMemory['memory_usage_total'] += $usage;
                        $redisMemory['memory_usage_max_key'] = max($redisMemory['memory_usage_max_key'], $usage);
                    }
                    unset($content, $payload, $stored);
                }
            }
            gc_collect_cycles();
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
            || count((array) ($authority['entries'] ?? [])) !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || array_diff(self::PACKAGE_SLICE_SLUGS, (array) ($authority['slugs'] ?? [])) !== []) {
            throw new RuntimeException('CAREER_PARITY_AUTHORITY_INCOMPLETE');
        }
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
        $config = Cache::store()->getStore()->connection()->command('config', ['get', 'maxmemory']);
        $maxmemory = (int) (($config['maxmemory'] ?? null) ?? (is_array($config) ? end($config) : 0));
        $policy = Cache::store()->getStore()->connection()->command('config', ['get', 'maxmemory-policy']);
        $policyValue = (string) (($policy['maxmemory-policy'] ?? null) ?? (is_array($policy) ? end($policy) : ''));
        if ($maxmemory !== (int) config('career_current_authority_parity.redis_maxmemory_baseline_bytes', self::LOCKED_REDIS_MAXMEMORY_BYTES)
            || $policyValue !== (string) config('career_current_authority_parity.redis_policy', 'noeviction')) {
            throw new RuntimeException('CAREER_PARITY_REDIS_CAPACITY_MISMATCH');
        }

        $memory = Cache::store()->getStore()->connection()->command('info', ['memory']);
        $used = is_array($memory) ? ($memory['used_memory'] ?? $memory['Memory']['used_memory'] ?? null) : null;
        if (! is_numeric($used)) {
            throw new RuntimeException('CAREER_PARITY_REDIS_MEMORY_USAGE_MISSING');
        }

        return [
            'mode' => $mode,
            'used_memory_bytes' => (int) $used,
            'maxmemory_bytes' => $maxmemory,
            'budget_percent' => (int) config('career_current_authority_parity.career_budget_percent', 80),
            'budget_bytes' => (int) config('career_current_authority_parity.career_budget_bytes', self::LOCKED_CAREER_BUDGET_BYTES),
            'policy' => $policyValue,
        ];
    }

    private function memoryUsage(string $cacheKey): int
    {
        $key = (string) config('database.redis.options.prefix').(string) config('cache.prefix').$cacheKey;
        $client = Cache::store()->getStore()->connection()->client();
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
