<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Display\CareerCurrentAuthorityParity;
use App\Domain\Career\Display\CareerJobDetailCanonicalCacheReader;
use App\Domain\Career\Display\CareerPageProjector;
use App\Services\Career\CareerFilePageReader;
use App\Services\Career\CareerPageCacheCompactor;
use App\Support\PublicProjectionCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CareerPageCacheCompactorTest extends TestCase
{
    private function isolatedRedis(callable $test): void
    {
        $lookup = new Process(['sh', '-c', 'command -v redis-server']);
        $lookup->run();
        if (! $lookup->isSuccessful() || ! extension_loaded('redis')) {
            self::markTestSkipped('Isolated Redis server and phpredis required.');
        }
        $directory = sys_get_temp_dir().'/career-compaction-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory, 0700);
        $socket = $directory.'/redis.sock';
        $server = new Process([trim($lookup->getOutput()), '--port', '0', '--unixsocket', $socket, '--save', '', '--appendonly', 'no']);
        $server->start();
        $originalStorage = storage_path();
        try {
            for ($attempt = 0; $attempt < 100 && ! file_exists($socket); $attempt++) {
                usleep(20000);
            }
            config(['cache.prefix' => 'compaction-test:',
                'database.redis.public_projection.host' => $socket,
                'database.redis.public_projection.port' => 0,
                'database.redis.public_projection.password' => null,
                'database.redis.public_projection.database' => 0,
                'database.redis.public_projection.url' => null,
                'database.redis.options.prefix' => '']);
            app('redis')->purge('public_projection');
            Cache::purge('public_projection');
            $this->app->useStoragePath($directory.'/storage');
            PublicProjectionCache::writeState(['version' => 1, 'mode' => 'isolated']);
            $store = PublicProjectionCache::store();
            $redis = $store->getStore()->connection()->client();
            $redis->config('SET', 'maxmemory', (string) CareerCurrentAuthorityParity::LOCKED_REDIS_MAXMEMORY_BYTES);
            $redis->config('SET', 'maxmemory-policy', 'noeviction');
            $test($store, $redis);
        } finally {
            $this->app->useStoragePath($originalStorage);
            Cache::purge('public_projection');
            app('redis')->purge('public_projection');
            $server->stop();
            File::deleteDirectory($directory);
        }
    }

    private function page(string $slug = 'test-role'): array
    {
        $hash = hash('sha256', $slug);

        return ['contract_version' => CareerPageProjector::VERSION,
            'subject' => ['canonical_slug' => $slug], 'locale' => 'en', 'source_content_sha256' => $hash,
            'content' => ['subject' => ['canonical_slug' => $slug], 'source_content_sha256' => $hash,
                'blocks' => [str_repeat('Reader-visible content stays identical. ', 3000)]]];
    }

    public function test_legacy_pages_shrink_without_losing_content_ttl_or_other_namespaces(): void
    {
        $this->isolatedRedis(function ($store, $redis): void {
            $page = $this->page();
            $key = CareerFilePageReader::cacheKey($page);
            $store->put($key, $page, 120);
            $permanent = $this->page('rollback-role');
            $permanentKey = CareerFilePageReader::cacheKey($permanent);
            $store->forever($permanentKey, $permanent);
            $store->forever('career:public-authority:job-detail:v3:test-role:en:active', 'active-sentinel');
            $store->forever('career:public-authority:job-detail:v3:test-role:en:lkg', 'rollback-sentinel');
            $store->forever('personality:sentinel', ['untouched' => true]);
            $physical = 'compaction-test:'.$key;
            $ttl = $redis->pttl($physical);
            $size = $redis->rawCommand('MEMORY', 'USAGE', $physical);
            $compactor = app(CareerPageCacheCompactor::class);
            $result = $compactor->compact();
            self::assertSame('complete', $result['status']);
            self::assertSame(2, $result['rewritten']);
            self::assertGreaterThan(0, $result['serialized_bytes_saved']);
            $codec = app(CareerJobDetailCanonicalCacheReader::class);
            self::assertTrue($codec->isSupportedEnvelope($store->get($key)));
            self::assertSame($page, $codec->decode($store->get($key)));
            self::assertSame($permanent, $codec->decode($store->get($permanentKey)));
            self::assertLessThan($size, $redis->rawCommand('MEMORY', 'USAGE', $physical));
            self::assertGreaterThan($ttl - 5000, $redis->pttl($physical));
            self::assertLessThanOrEqual($ttl, $redis->pttl($physical));
            self::assertSame(-1, $redis->pttl('compaction-test:'.$permanentKey));
            self::assertSame('active-sentinel', $store->get('career:public-authority:job-detail:v3:test-role:en:active'));
            self::assertSame('rollback-sentinel', $store->get('career:public-authority:job-detail:v3:test-role:en:lkg'));
            self::assertSame(['untouched' => true], $store->get('personality:sentinel'));
            self::assertSame(0, $compactor->compact()['rewritten']);
        });
    }

    public function test_configured_redis_and_cache_prefixes_are_applied_once(): void
    {
        $this->isolatedRedis(function ($store, $redis): void {
            $redis->setOption(\Redis::OPT_PREFIX, 'outer:');
            $page = $this->page();
            $key = CareerFilePageReader::cacheKey($page);
            $store->forever($key, $page);
            self::assertSame(1, app(CareerPageCacheCompactor::class)->compact()['rewritten']);
            self::assertSame($page, app(CareerJobDetailCanonicalCacheReader::class)->decode($store->get($key)));
            self::assertSame(1, $redis->rawCommand('EXISTS', 'outer:compaction-test:'.$key));
            self::assertSame(0, $redis->rawCommand('EXISTS', 'outer:outer:compaction-test:'.$key));
        });
    }

    public function test_identity_mismatch_and_corrupt_envelope_are_rejected_without_replacement(): void
    {
        $compactor = app(CareerPageCacheCompactor::class);
        $page = $this->page();
        foreach ([array_merge($page, ['locale' => 'zh-CN']), ['codec' => 'unknown', 'payload' => 'corrupt']] as $invalid) {
            try {
                $compactor->candidate($invalid, 'test-role', 'en', $page['source_content_sha256']);
                self::fail('Invalid cached identity must fail closed.');
            } catch (\RuntimeException $error) {
                self::assertSame('CAREER_PAGE_COMPACTION_PAYLOAD_INVALID', $error->getMessage());
            }
        }
    }

    public function test_atomic_replacement_preserves_a_concurrent_writer_and_expired_key(): void
    {
        $this->isolatedRedis(function ($store, $redis): void {
            $compactor = app(CareerPageCacheCompactor::class);
            $replace = new \ReflectionMethod($compactor, 'replace');
            $store->forever('race', ['newer' => true]);
            self::assertSame(0, $replace->invoke($compactor, $redis, 'compaction-test:race', serialize(['old' => true]), serialize(['candidate' => true])));
            self::assertSame(['newer' => true], $store->get('race'));
            self::assertSame(0, $replace->invoke($compactor, $redis, 'compaction-test:missing', 'old', 'candidate'));
            self::assertNull($store->get('missing'));
        });
    }

    public function test_production_completion_uses_fresh_memory_and_keeps_the_fixed_capacity_guard(): void
    {
        $this->isolatedRedis(function ($store, $redis): void {
            $parity = app(CareerCurrentAuthorityParity::class);
            $loader = app(\App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader::class);
            $validated = ['authority' => $loader->indexForPublish(base_path()),
                'full' => ['counts' => ['verified_files' => 2092], 'cache_write_count' => 0],
                'releaseSha' => str_repeat('a', 40), 'activeSha' => str_repeat('b', 40)];
            $receipt = $parity->completeProduction($validated);
            self::assertSame('pass', $receipt['status']);
            $initial = $receipt['redis']['used_memory_bytes'];
            $store->forever('pressure-sentinel', str_repeat('x', 200000));
            $next = $parity->completeProduction($validated);
            self::assertGreaterThan($initial, $next['redis']['used_memory_bytes']);
            try {
                CareerCurrentAuthorityParity::assertCapacityWithinBudget(0, CareerCurrentAuthorityParity::LOCKED_CAREER_BUDGET_BYTES + 1, CareerCurrentAuthorityParity::LOCKED_CAREER_BUDGET_BYTES);
                self::fail('Compaction does not grant a capacity bypass.');
            } catch (\RuntimeException $error) {
                self::assertSame('CAREER_PARITY_REDIS_BUDGET_EXCEEDED', $error->getMessage());
            }
        });
    }

    public function test_invalid_source_files_stop_before_any_page_maintenance(): void
    {
        $this->isolatedRedis(function ($store): void {
            $root = \Tests\Support\CareerCurrentPackageFixture::create(base_path());
            $page = $this->page();
            $key = CareerFilePageReader::cacheKey($page);
            $store->forever($key, $page);
            try {
                $path = $root.'/content_assets/career/current/careers/accountants-and-auditors/en.json';
                file_put_contents($path, '{}');
                $parity = app(CareerCurrentAuthorityParity::class);
                try {
                    $validated = $parity->validateProductionFiles($root, str_repeat('a', 40), str_repeat('b', 40));
                    app(CareerPageCacheCompactor::class)->compact();
                    $parity->completeProduction($validated);
                    self::fail('Invalid source must prevent cache maintenance.');
                } catch (\App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure $error) {
                    self::assertStringContainsString('CURRENT_CONTENT_V3_', $error->getMessage());
                }
                self::assertSame($page, $store->get($key));
            } finally {
                File::deleteDirectory($root);
            }
        });
    }
}
