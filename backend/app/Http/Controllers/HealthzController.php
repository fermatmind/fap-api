<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HealthzController extends Controller
{
    public function show(Request $request)
    {
        $service = 'Fermat Assessment Platform API';
        $version = config('app.version', env('APP_VERSION', 'unknown'));
        $nowIso = now()->toIso8601String();
        $verbose = (bool) config('healthz.verbose', false) && app()->environment(['local', 'testing']);

        $region = (string) $request->query('region', 'CN_MAINLAND');
        $locale = (string) $request->query('locale', 'zh-CN');

        $deps = [];
        $deps['db'] = $this->checkDb();
        $deps['cache_store'] = $this->checkCacheStore();
        $deps['redis'] = $this->checkRedis();
        $deps['queue'] = $this->checkQueue();
        $deps['cache_dirs'] = $this->checkCacheDirs();
        $deps['content_source'] = $this->checkContentSource($region, $locale);

        $allOk = true;
        foreach ($deps as $value) {
            if (($value['ok'] ?? false) !== true) {
                $allOk = false;
                break;
            }
        }

        if (!$verbose) {
            return response()->json([
                'ok' => $allOk,
                'service' => $service,
                'version' => $version,
                'time' => $nowIso,
            ]);
        }

        return response()->json([
            'ok' => $allOk,
            'service' => $service,
            'version' => $version,
            'time' => $nowIso,
            'deps' => $this->sanitizeDeps($deps),
        ]);
    }

    private function sanitizeDeps(array $deps): array
    {
        $out = [];

        foreach ($deps as $name => $dep) {
            if (!is_array($dep)) {
                $out[$name] = ['ok' => false, 'error_code' => 'INVALID_DEP_SHAPE'];
                continue;
            }

            $out[$name] = [
                'ok' => (bool) ($dep['ok'] ?? false),
                'error_code' => (string) ($dep['error_code'] ?? ''),
            ];
        }

        return $out;
    }

    private function checkDb(): array
    {
        $t0 = microtime(true);
        try {
            DB::select('select 1 as ok');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => true,
                'driver' => (string) config('database.default'),
                'latency_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => false,
                'driver' => (string) config('database.default'),
                'latency_ms' => $ms,
                'error_code' => 'DB_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkRedis(): array
    {
        $cacheStore = (string) config('cache.default', 'array');
        $cacheDriver = (string) config("cache.stores.{$cacheStore}.driver", $cacheStore);
        $queueDriver = (string) config('queue.default', 'sync');

        $needsRedis = ($cacheDriver === 'redis' || $queueDriver === 'redis');
        if (!$needsRedis) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'redis_not_in_use',
                'client' => (string) config('database.redis.client', 'redis'),
            ];
        }

        $t0 = microtime(true);
        try {
            $client = (string) config('database.redis.client', 'redis');
            $pong = Redis::connection()->ping();
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => (string) $pong === 'PONG' || $pong === true,
                'client' => $client,
                'latency_ms' => $ms,
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => false,
                'client' => (string) config('database.redis.client', 'redis'),
                'latency_ms' => $ms,
                'error_code' => 'REDIS_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkCacheStore(): array
    {
        $storeName = (string) config('cache.default', 'array');
        $driver = (string) config("cache.stores.{$storeName}.driver", $storeName);

        $t0 = microtime(true);
        try {
            $store = Cache::store($storeName);
            $key = 'healthz:cache:' . Str::uuid()->toString();
            $value = 'ok:' . (string) time();
            $store->put($key, $value, 30);
            $read = $store->get($key);
            $store->forget($key);
            $ms = (int) round((microtime(true) - $t0) * 1000);

            $ok = ($read === $value);

            return [
                'ok' => $ok,
                'store' => $storeName,
                'driver' => $driver,
                'latency_ms' => $ms,
                'error_code' => $ok ? '' : 'CACHE_STORE_MISMATCH',
                'message' => $ok ? '' : 'cache read/write mismatch',
            ];
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return [
                'ok' => false,
                'store' => $storeName,
                'driver' => $driver,
                'latency_ms' => $ms,
                'error_code' => 'CACHE_STORE_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $connection = config("queue.connections.{$driver}");

        try {
            if (!is_array($connection)) {
                return [
                    'ok' => false,
                    'driver' => $driver,
                    'error_code' => 'QUEUE_CONFIG_MISSING',
                    'message' => 'queue connection config missing',
                ];
            }

            if ($driver === 'database') {
                $hasJobs = Schema::hasTable('jobs');
                $hasFailed = Schema::hasTable('failed_jobs');

                if (!$hasJobs) {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'error_code' => 'QUEUE_TABLE_MISSING',
                        'message' => 'jobs table not found',
                    ];
                }

                DB::select('select 1 as ok');

                return [
                    'ok' => true,
                    'driver' => $driver,
                    'connection' => (string) ($connection['connection'] ?? ''),
                    'tables' => [
                        'jobs' => $hasJobs,
                        'failed_jobs' => $hasFailed,
                    ],
                ];
            }

            if ($driver === 'redis') {
                $redisConnection = (string) ($connection['connection'] ?? 'default');
                $pong = Redis::connection($redisConnection)->ping();
                $ok = ((string) $pong === 'PONG' || $pong === true);

                return [
                    'ok' => $ok,
                    'driver' => $driver,
                    'connection' => $redisConnection,
                ];
            }

            return [
                'ok' => true,
                'driver' => $driver,
                'connection' => (string) ($connection['connection'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'driver' => $driver,
                'error_code' => 'QUEUE_UNAVAILABLE',
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkCacheDirs(): array
    {
        $paths = [
            'storage_framework_cache' => storage_path('framework/cache'),
            'storage_framework_sessions' => storage_path('framework/sessions'),
            'storage_framework_views' => storage_path('framework/views'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        $items = [];
        $allOk = true;

        foreach ($paths as $name => $path) {
            $exists = is_dir($path);
            if (!$exists) {
                @mkdir($path, 0775, true);
                $exists = is_dir($path);
            }

            $writable = $exists ? is_writable($path) : false;

            $probeOk = false;
            $probeErr = '';
            if ($writable) {
                $probeFile = rtrim($path, '/').'/.__healthz_probe';
                try {
                    @file_put_contents($probeFile, (string) time());
                    @unlink($probeFile);
                    $probeOk = true;
                } catch (\Throwable $e) {
                    $probeOk = false;
                    $probeErr = $e->getMessage();
                }
            }

            $ok = $exists && $writable && $probeOk;
            if (!$ok) {
                $allOk = false;
            }

            $items[$name] = [
                'ok' => $ok,
                'path' => $path,
                'exists' => $exists,
                'writable' => $writable,
                'probe_ok' => $probeOk,
                'message' => $probeErr,
                'error_code' => $ok ? '' : 'CACHE_DIR_NOT_WRITABLE',
            ];
        }

        return [
            'ok' => $allOk,
            'items' => $items,
        ];
    }

    private function checkContentSource(string $region, string $locale): array
    {
        $base = realpath(base_path('..'.DIRECTORY_SEPARATOR.'content_packages'))
            ?: base_path('..'.DIRECTORY_SEPARATOR.'content_packages');

        $defaultDir = rtrim($base, '/').'/default/'.$region.'/'.$locale;

        $existsBase = is_dir($base);
        $existsDefault = is_dir($defaultDir);

        $readableBase = $existsBase ? is_readable($base) : false;
        $readableDefault = $existsDefault ? is_readable($defaultDir) : false;

        $ok = $existsBase && $readableBase && $existsDefault && $readableDefault;

        return [
            'ok' => $ok,
            'base_path' => $base,
            'default_path' => $defaultDir,
            'region' => $region,
            'locale' => $locale,
            'error_code' => $ok ? '' : 'CONTENT_SOURCE_NOT_READY',
            'message' => $ok ? '' : 'content_packages default path not readable',
        ];
    }
}
