<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerPageProjector;
use App\Support\PublicProjectionCache;
use Illuminate\Cache\RedisStore;
use Redis;
use RuntimeException;

/** Lossless maintenance of existing page bytes, never a source publisher or cache warmer. */
final class CareerPageCacheCompactor
{
    private array $statistics = ['status' => 'not_started', 'scanned' => 0, 'rewritten' => 0, 'serialized_bytes_saved' => 0];

    public function __construct(private readonly CareerJobDetailCanonicalCacheReader $codec) {}

    public function statistics(): array
    {
        return $this->statistics;
    }

    public function compact(int $maxSeconds = 180): array
    {
        $this->statistics = ['status' => 'not_started', 'scanned' => 0, 'rewritten' => 0, 'serialized_bytes_saved' => 0];
        if (PublicProjectionCache::state()['mode'] !== 'isolated') {
            return $this->statistics = [...$this->statistics, 'status' => 'skipped_nonisolated'];
        }
        $store = PublicProjectionCache::store()->getStore();
        if (! $store instanceof RedisStore || ! ($redis = $store->connection()->client()) instanceof Redis
            || $redis->getOption(Redis::OPT_SERIALIZER) !== Redis::SERIALIZER_NONE
            || (defined('Redis::OPT_COMPRESSION') && $redis->getOption(Redis::OPT_COMPRESSION) !== Redis::COMPRESSION_NONE)) {
            throw new RuntimeException('CAREER_PAGE_COMPACTION_STORE_UNSUPPORTED');
        }
        $this->statistics['status'] = 'in_progress';
        $prefix = (string) $redis->getOption(Redis::OPT_PREFIX).$store->getPrefix();
        $cursor = null;
        $seen = [];
        $deadline = microtime(true) + min(180, max(1, $maxSeconds));
        do {
            $keys = $redis->scan($cursor, $prefix.'career:page:career.detail.page.v1:*', 100);
            foreach ($keys ?: [] as $physical) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('CAREER_PAGE_COMPACTION_TIMEOUT');
                }
                if (isset($seen[$physical]) || ! str_starts_with($physical, $prefix)) {
                    continue;
                }
                $seen[$physical] = true;
                $key = substr($physical, strlen($prefix));
                if (! preg_match('~^career:page:career\.detail\.page\.v1:([a-z0-9-]+):(en|zh-CN):([a-f0-9]{64})$~D', $key, $identity)) {
                    continue;
                }
                $this->statistics['scanned']++;
                PublicProjectionCache::mutation(function () use ($redis, $store, $key, $physical, $identity): void {
                    // Raw bytes bind the atomic compare; application decoding stays with the configured store.
                    $raw = $redis->rawCommand('GET', $physical);
                    if ($raw === false) {
                        return;
                    }
                    $stored = $store->get($key);
                    $candidate = $this->candidate($stored, $identity[1], $identity[2], $identity[3]);
                    if ($candidate === null) {
                        return;
                    }
                    if ($raw !== serialize($stored)) {
                        return; // Expiry or a concurrent replacement wins.
                    }
                    $encoded = serialize($candidate);
                    if (strlen($encoded) >= strlen($raw)) {
                        return;
                    }
                    $changed = $this->replace($redis, $physical, $raw, $encoded);
                    if ($changed !== 0 && $changed !== 1) {
                        throw new RuntimeException('CAREER_PAGE_COMPACTION_CAS_FAILED');
                    }
                    if ($changed === 1) {
                        $this->statistics['rewritten']++;
                        $this->statistics['serialized_bytes_saved'] += strlen($raw) - strlen($encoded);
                        if ($this->codec->decode($store->get($key)) !== $stored) {
                            throw new RuntimeException('CAREER_PAGE_COMPACTION_READBACK_FAILED');
                        }
                    }
                });
            }
        } while ($cursor !== 0);

        return $this->statistics = [...$this->statistics, 'status' => 'complete'];
    }

    private function replace(Redis $redis, string $physical, string $original, string $candidate): mixed
    {
        return $redis->rawCommand('EVAL', <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('SET', KEYS[1], ARGV[2], 'KEEPTTL')
return 1
LUA, 1, $physical, $original, $candidate);
    }

    /** Only a legacy page with exactly the same identity can be rewritten. */
    public function candidate(mixed $stored, string $slug, string $locale, string $hash): ?array
    {
        $page = $this->codec->decode($stored);
        if (! is_array($page) || ($page['contract_version'] ?? null) !== CareerPageProjector::VERSION
            || ($page['locale'] ?? null) !== $locale
            || data_get($page, 'subject.canonical_slug') !== $slug
            || ($page['source_content_sha256'] ?? null) !== $hash
            || data_get($page, 'content.subject.canonical_slug') !== $slug
            || data_get($page, 'content.source_content_sha256') !== $hash) {
            throw new RuntimeException('CAREER_PAGE_COMPACTION_PAYLOAD_INVALID');
        }
        if ($this->codec->isSupportedEnvelope($stored)) {
            return null;
        }
        $candidate = $this->codec->encode($page);
        if ($this->codec->decode($candidate) !== $page) {
            throw new RuntimeException('CAREER_PAGE_COMPACTION_ROUNDTRIP_FAILED');
        }

        return $candidate;
    }
}
